# gnews-unwrap

Google News feeds link to Google wrapper pages, not to articles, so every item is credited to `news.google.com` and URL dedup never matches the same story from a direct feed. gnews-unwrap turns those wrapper links back into publisher URLs, and gives you an outlet key and an article key so attribution and dedup work.

Measured in production: on a broad Google News feed, about 21% of resolved items were duplicates of items already taken from direct feeds, all missed while the wrapper was stored.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

[![CI](https://github.com/IndianOpinion/gnews-unwrap/actions/workflows/ci.yml/badge.svg)](https://github.com/IndianOpinion/gnews-unwrap/actions/workflows/ci.yml)

- PHP 8.1 or newer, zero runtime dependencies.
- Detects every known wrapper shape offline. Decodes legacy ids offline.
- Resolves current (opaque) ids over HTTP, with a budget, a circuit breaker and a cache.
- Never throws for bad input. On any failure you get the input back with a reason.

## Install

With Composer:

```sh
composer require indianopinion/gnews-unwrap
```

Without Composer, download a release, then:

```php
require '/path/to/gnews-unwrap/bootstrap.php';
```

`ext-curl` is used when present (and allows concurrent `resolveMany()` when you set `concurrency` above 1); without it the library falls back to PHP streams. `ext-intl` is used for IDN hosts when present.

## Quick start

```php
use IndianOpinion\GnewsUnwrap\Unwrapper;

$unwrapper = new Unwrapper();

$result = $unwrapper->resolve('https://news.google.com/rss/articles/CBMi...?oc=5');

if ($result->resolved) {
    $url    = $result->output;                    // publisher URL
    $outlet = $result->outletKey;                 // "example.com"
    $key    = $unwrapper->articleKey($url);       // "example.com/news/story-1"
} else {
    // Keep the item. $result->output is the wrapper, $result->reason says why.
    // Hold it from outlet counts and retry later.
}

// Just the string: publisher URL when resolved, else the input unchanged.
$url = $unwrapper->resolveOrSame($link);

// A list: results keyed by input, order kept, duplicates resolved once.
$results = $unwrapper->resolveMany($links);
```

Unwrap at ingest, before any hash, key, outlet count or dedup check is computed. Store the resolved URL with the item. Never re-resolve on read.

A URL that is not a Google wrapper comes back unchanged with reason `not_google_news`, with no request and no cache entry. That makes it safe to run every stored URL through the resolver (see [Self-healing old rows](#self-healing-old-rows)).

### Outlet keys and article keys

```php
use IndianOpinion\GnewsUnwrap\ArticleKeyBuilder;
use IndianOpinion\GnewsUnwrap\HostNormaliser;

$hosts = new HostNormaliser();
$hosts->key('https://m.amp.example.co.uk/a');      // "example.co.uk"
$hosts->key('www2.example.com:8080');              // "example.com"
$hosts->key('m.com');                              // "m.com" (never past the registrable domain)

$keys = new ArticleKeyBuilder($hosts);
$keys->key('https://www.example.com/news/story-1/?utm_source=x#top');  // "example.com/news/story-1"
$keys->key('http://amp.example.com/news/story-1/amp');                 // "example.com/news/story-1/amp"

$ampKeys = new ArticleKeyBuilder($hosts, stripAmpMarkers: true);
$ampKeys->key('http://amp.example.com/news/story-1/amp');              // "example.com/news/story-1"
```

Unwrap first, then normalise. A wrapper passed to either function keys on `news.google.com`.

## What it handles

| Shape | Example | Resolved by |
|---|---|---|
| S1 | `news.google.com/rss/articles/<id>?oc=5` | offline decode (legacy ids), else network |
| S2 | `news.google.com/articles/<id>` | same |
| S3 | `news.google.com/read/<id>` | same |
| S4 | `news.google.com/__i/rss/rd/articles/<id>` | same |
| S5 | `news.google.com/news/url?url=<publisher>` | `url` parameter, no request |
| S6 | `news.url.google.com/url?q=<publisher>` | `url` or `q` parameter, no request |
| S7 | `www.google.<tld>/url?url=<publisher>` (Google Alerts) | parameter, only when `webRedirectorScope` is on |
| S8 | `./articles/<id>`, `./read/<id>` | as S2/S3, when you pass a Google News base URL |

Google News pages that are not article links (topics, stories, search, the front page) come back with `not_an_article_link`.

## Configuration

`Config` is immutable. Pass named arguments, or start from the defaults and use the `with...()` copiers. Bad values throw `InvalidConfigException`.

```php
use IndianOpinion\GnewsUnwrap\Config;
use IndianOpinion\GnewsUnwrap\Method;
use IndianOpinion\GnewsUnwrap\Unwrapper;

$config = (new Config())
    ->withOwnHosts(['mysite.example'])
    ->withPauseMs(500)
    ->withoutMethods(Method::BatchExecute);

$unwrapper = new Unwrapper($config, transport: null, cache: $myCache, logger: $myLogger);
```

| Setting | Default | Notes |
|---|---|---|
| `methods` | all six | `cache`, `query_param`, `offline_decode`, `batch_execute`, `http_redirect`, `html_parse`; switch any off |
| `webRedirectorScope` | off | treat Google web redirector links (S7) as wrappers |
| `genericMode` | off | follow redirects for any URL (shorteners); also per call: `resolve($url, null, true)` |
| `maxRedirects` | 5 | 0 to 10 hops per resolution |
| `connectTimeout` | 3 s | |
| `requestTimeout` | 8 s | per request |
| `budget` | 15 s | per link, across all methods and hops |
| `maxBodyBytes` | 512 KB | bytes read per response |
| `userAgent` | `gnews-unwrap/0.1.0 (+repo URL)` | |
| `acceptLanguage` | `en` | |
| `positiveTtl` | 30 days | cache lifetime of a resolved result |
| `negativeTtl` | 1 hour | cache lifetime of an unresolved result; transient reasons are never cached |
| `breakerThreshold`, `breakerWindow`, `breakerCooldown` | 3, 60 s, 300 s | open the circuit breaker for 5 minutes after 3 `rate_limited` in 60 s |
| `pauseMs` | 0 | pause between network resolutions; set 250 to 1000 for bulk work |
| `concurrency` | 1 | links in flight in `resolveMany()` on curl; 1 is strictly sequential, raise it with care (rate limits) |
| `ownHosts` | empty | your own sites, never a valid target |
| `extraGoogleHosts` | empty | added to the built-in Google list |
| `allowPrivateTargets` | off | accept IP literal, `localhost` and private targets |
| `stripLabels`, `stripNumberedWww` | `www`, `amp`, `m`, `mobile`; on | leading labels stripped for the outlet key, plus `www2`, `ww2` |
| `extraSuffixes` | empty | more multi-part suffixes such as `co.ke` |
| `publicSuffixListFile` | none | path to a Public Suffix List file; replaces the built-in list |
| `outletAliases` | empty | `['business.example.com' => 'example.com', '*.example.org' => 'example.org']` |
| `trackingParams` | `utm_*`, `fbclid`, `gclid` and 30 more | dropped from article keys |
| `allowParams` | none | keep only these query names in article keys |
| `stripAmpMarkers` | off | drop `/amp`, `.amp`, `?amp=1`, `outputType=amp` from article keys |
| `identityParams` | empty | `['example.org' => ['id']]`: never drop these on that host |

## The result

`resolve()` always returns a `Result`. It never returns null.

| Field | Meaning |
|---|---|
| `input` | the URL exactly as given |
| `output` | publisher URL when resolved, else the input |
| `resolved` | true only for an accepted publisher URL |
| `method` | `cache`, `query_param`, `offline_decode`, `batch_execute`, `http_redirect`, `html_parse` or `none` |
| `originalMethod` | for cache hits, the method that first resolved it |
| `reason` | a `Reason` when not resolved |
| `attempts` | every method tried, each with its own reason |
| `requests`, `elapsedMs` | cost of this call |
| `outletKey` | outlet key of `output` |
| `fromNegativeCache` | a cached unresolved result was served |
| `shape` | `S1` to `S8` for wrappers |
| `ampUrl` | AMP URL carried inside a legacy id, when present |
| `insecureTarget` | the final hop went from https to http |

`toArray()` gives a JSON-safe array for storage and logs; `Result::fromArray()` reads it back.

Reason codes: `invalid_url`, `not_google_news`, `not_an_article_link`, `malformed_id`, `opaque_id`, `unknown_id_format`, `decode_failed`, `signature_missing`, `format_changed`, `too_many_redirects`, `redirect_loop`, `timeout`, `budget_exceeded`, `network_error`, `rate_limited`, `forbidden`, `gone`, `upstream_error`, `unexpected_status`, `consent_wall`, `login_wall`, `unexpected_content_type`, `circuit_open`, `private_address`, `bad_target`, `no_candidate`, `not_redirected`.

## How it works

1. **Detect.** The URL is parsed and matched against the shapes above. Pure, no network.
2. **Cache.** The normalised wrapper (no query, no fragment for S1 to S4) is looked up.
3. **query_param.** S5 to S7 carry the target in `url` or `q`. Nested redirectors are unwrapped up to 3 levels.
4. **offline_decode.** Legacy ids are base64 of a small protobuf message whose field 4 is the publisher URL. The decoder parses it as protobuf with bounds on every length. Ids issued since July 2024 hold an opaque `AU_yqL` token instead; those go to the network.
5. **batch_execute.** Fetches the article page, reads the signature and timestamp, and posts them to Google's internal `batchexecute` endpoint. **This endpoint is undocumented and liable to break.** It is isolated in one class (`BatchExecute`), it reports `format_changed` loudly when the answer changes shape, and you can switch it off.
6. **http_redirect.** Requests the wrapper and follows `Location` headers between Google hosts. It stops at the first hop that leaves Google and never requests the publisher.
7. **html_parse.** Reads an interstitial page already fetched: meta refresh, canonical link, a single off-Google anchor, a `location` string literal.

Every candidate must pass the acceptance rules: absolute `http` or `https`, not a Google host, not one of your own hosts, not an IP literal or private address, at most 2048 characters, no control characters, and not the wrapper itself. When in doubt it returns the wrapper: a wrong publisher URL is worse than an unresolved one.

A 429 or a redirect to `google.com/sorry` stops all network methods for that link and counts towards the circuit breaker. While the breaker is open, network methods are skipped (`circuit_open`) and offline methods still run.

More detail, including every design decision: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## How to tell it is working

1. `php examples/fixtures-demo.php` resolves three recorded links with no network. You should see `offline_decode`, `batch_execute` and `http_redirect` in the method column.
2. `php examples/resolve-live.php '<one wrapper link from a Google News feed>'` resolves one live link.
3. In production, read the counters: `$unwrapper->stats()` gives links seen, wrappers seen, requests, cache hits, resolved by method and unresolved by reason. A sudden rise in `format_changed` or `signature_missing` means Google changed the page. A rise in `rate_limited` means slow down (`pauseMs`, lower `concurrency`).

Logging is off by default. Any object implementing `Log\Logger` gets one `debug` line per resolution and a `warning` for `rate_limited`, `format_changed` and `circuit_open`. To use a PSR-3 logger:

```php
use IndianOpinion\GnewsUnwrap\Log\CallableLogger;

$logger = new CallableLogger(fn (string $level, string $message, array $context) => $psr3->log($level, $message, $context));
```

## Caching

The default cache is in memory, bounded at 10,000 entries, least recently used out. It dies with the process. For anything longer, implement `Cache\Cache` (three methods). A PSR-16 adapter:

```php
use IndianOpinion\GnewsUnwrap\Cache\Cache;
use Psr\SimpleCache\CacheInterface;

final class Psr16Cache implements Cache
{
    public function __construct(private CacheInterface $inner) {}

    public function get(string $key): ?array
    {
        $value = $this->inner->get(sha1($key));
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->inner->set(sha1($key), $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->inner->delete(sha1($key));
    }
}
```

A WordPress transients adapter is in [docs/WORDPRESS.md](docs/WORDPRESS.md).

## Transports

`CurlTransport` is the default when `ext-curl` is loaded, `StreamTransport` otherwise. Both verify TLS (there is no switch to turn that off), keep no cookies, never follow redirects themselves, refuse non-HTTP schemes and cut the body at the byte cap. For an outbound proxy, pass curl options: `new CurlTransport([CURLOPT_PROXY => 'http://proxy:3128'])`. Options that would weaken those rules are refused.

A PSR-18 client can be wrapped in a few lines. It must be configured not to follow redirects:

```php
use IndianOpinion\GnewsUnwrap\Exception\TransportException;
use IndianOpinion\GnewsUnwrap\Transport\{Request, Response, Transport};
use Psr\Http\Client\{ClientInterface, ClientExceptionInterface};
use Psr\Http\Message\{RequestFactoryInterface, StreamFactoryInterface};

final class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    public function send(Request $request): Response
    {
        $psr = $this->requests->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psr = $psr->withBody($this->streams->createStream($request->body));
        }
        try {
            $answer = $this->client->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw TransportException::network($e->getMessage(), $e);
        }
        $body = $answer->getBody()->read($request->maxBodyBytes);

        return new Response($answer->getStatusCode(), $answer->getHeaders(), $body, $request->url, !$answer->getBody()->eof());
    }
}
```

`FakeTransport` replays recorded responses for tests. Load JSON fixtures (see `tests/fixtures/`) or add routes with `on()` and `redirect()`.

## Self-healing old rows

Rows stored before you unwrapped are wrong. Re-run them and write back the URL and every value derived from it:

```php
foreach ($rows as $row) {
    $result = $unwrapper->resolve($row->source_url);
    if ($result->resolved && $result->changed()) {
        $row->source_url  = $result->output;
        $row->outlet_key  = $result->outletKey;
        $row->article_key = $unwrapper->articleKey($result->output);
        $row->save();
    }
}
```

Non-wrappers come back unchanged at no cost. See `examples/self-heal.php`, and [docs/WORDPRESS.md](docs/WORDPRESS.md) for a WP-CLI version.

## Limitations

- **Current ids need the network.** No offline decode for `AU_yqL` ids is known (checked 11 November 2026). Resolving them costs about a second and two requests per link. Resolve once, store the result.
- **batch_execute is undocumented.** Google does not document it, rate-limits it, and has changed it several times. Expect breakage. When it breaks you will see `format_changed` or `signature_missing` and the link comes back unresolved, not wrong. Complying with Google's terms of service is your responsibility; switch the method off if in doubt.
- **Consent walls.** From EU and UK addresses Google sends requests to `consent.google.com`. The library does not post consent forms or forge consent cookies, so network methods return `consent_wall` there. Offline decode and the parameter methods still work. Our own live check from a UK host on 11 November 2026 got `consent_wall`; run network resolution from a host outside those regions.
- **Rate limits.** Bulk resolution draws HTTP 429. Use `pauseMs`, keep `concurrency` low, and let the circuit breaker work.
- **Locale-prefixed paths** such as `news.google.com/en-IN/articles/<id>` are not detected. No public evidence was found that they exist. They come back as `not_an_article_link`.
- **The built-in suffix list is short.** It covers common multi-part suffixes in the main news markets. Load a Public Suffix List file for full coverage. The library never downloads it.
- **IDN without ext-intl.** Unicode hosts are lowercased and kept in Unicode, and `HostNormaliser::normalise()` flags `idnSkipped`. A Unicode host and its punycode form then give different keys.
- **Streams transport.** PHP's http wrapper uses one timeout for connecting and for each read, so the separate connect timeout is not enforced there; the total deadline is checked between body chunks.
- **Generic mode DNS checks.** Every hop is checked before it is requested: private, loopback, link-local, metadata and other special addresses are refused, as literals and after an A and AAAA lookup. But the transport does its own lookup afterwards, so a hostile DNS server could still answer differently the second time (DNS rebinding). Do not enable generic mode on untrusted input without an egress firewall. With an outbound proxy, the checks see your DNS, not the proxy's.
- **Concurrency and budgets.** In concurrent `resolveMany()`, a link's 15 s budget includes time spent waiting for other links in the same round.

## FAQ

**Does it fetch the article?** No. It stops at the first hop that leaves Google. Only generic mode, which you must ask for, requests a non-Google host.

**Why did a link come back unresolved?** Read `reason` and `attempts`. Transient reasons (`rate_limited`, `timeout`, `network_error`, `upstream_error`, `circuit_open`, `budget_exceeded`) are not cached, so a retry later may work.

**Why not a headless browser?** It is heavy, slow and fragile, and it would mean getting past consent walls. Out of scope.

**Why are AMP markers kept in article keys by default?** Some outlets serve a different page at `/amp` paths, and merging two different articles is worse than missing one duplicate. The `amp.` host label is always folded by the outlet key. If your sources use `/amp` paths only for AMP copies, set `stripAmpMarkers: true`.

**Can it run in a page request?** It can, but do not. Resolution can take seconds. Run it in a cron job, a queue worker or an import hook.

**Is it safe on untrusted input?** Yes for the default mode: it only ever sends requests to Google hosts, it validates every target, and junk input returns `invalid_url`. Generic mode adds SSRF checks but see the limitation above.

## Development

```sh
composer install
composer check        # phpcs, phpstan (level 8), phpunit
GNEWS_UNWRAP_LIVE=1 vendor/bin/phpunit --group live   # opt-in live smoke test
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE](LICENSE).
