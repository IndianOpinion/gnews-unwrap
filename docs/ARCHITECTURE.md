# Architecture

This file explains how gnews-unwrap is built and records every design
decision, including the answers to the open questions in
[PRD.md](PRD.md) section 13.

## Modules

| Class | Role | Network |
|---|---|---|
| `Detector`, `Detection`, `Shape` | Says whether a URL is a wrapper, which shape, and what it carries | no |
| `LegacyIdDecoder`, `DecodedId` | Bounded protobuf decode of legacy article ids | no |
| `BatchExecute` | Builds the batchexecute requests and parses the answers. Pure; the resolver sends | (builds requests) |
| `HtmlTargetExtractor` | Finds a target in an interstitial page already fetched | no |
| `TargetValidator` | Acceptance rules of PRD 4.4 | no |
| `AddressGuard` | SSRF checks for generic mode (literals and DNS) | DNS only |
| `GoogleHosts` | The built-in list of Google hosts, plus caller extras | no |
| `HostNormaliser`, `HostKey`, `PublicSuffixList` | Outlet keys | no |
| `ArticleKeyBuilder` | Article keys | no |
| `Unwrapper` | Runs the method chain, drives transports, cache, breaker, counters, logging | yes |
| `Config` | Immutable settings, validated on construction | no |
| `Result`, `Attempt`, `Reason`, `Method` | Output types | no |
| `CircuitBreaker` | Opens after N rate limits in a window | no |
| `Transport\*` | `Transport` and `ConcurrentTransport` contracts; curl, streams and fake implementations | yes |
| `Cache\*` | `Cache` contract; `ArrayCache` (bounded LRU) and `NullCache` | no |
| `Clock\*` | `SystemClock`, `FakeClock` | no |
| `Log\*` | PSR-3-shaped `Logger`, `CallableLogger`, `MemoryLogger` | no |
| `Internal\*` | URL toolkit and per-link run state. Not public API | no |

There are no global functions. `bootstrap.php` registers a PSR-4 autoloader
for use without Composer and does nothing else.

## Data flow

```
resolve(url)
  -> Detector.detect               invalid_url / not_google_news / not_an_article_link / malformed_id
  -> (generic mode, non-Google)    genericRedirect with AddressGuard at every hop
  -> cache lookup                  key: normalised wrapper
  -> S5 to S7: query_param         nested up to 3 levels; an inner article wrapper re-enters the chain
  -> S1 to S4, S8: offline_decode  legacy ids only
  -> network (unless breaker open)
       batch_execute               GET article page, POST batchexecute
       http_redirect               GET wrapper, follow Google hops, stop at first non-Google hop
  -> html_parse                    on the page fetched by http_redirect, else by batch_execute
  -> TargetValidator on every candidate
  -> Result, cache store, counters, log
```

### Each link is a generator

The method chain for one link is a PHP generator. It yields a `Request`
whenever it needs the network and receives a `Response` (or a
`TransportException`) back, then returns a `Result`. Everything else
(budgets, the breaker, the per-link GET memo, attempts) lives inside it.

Two drivers run these generators:

- `resolve()` drives one generator with `Transport::send()`.
- `resolveMany()` with a `ConcurrentTransport` and `concurrency > 1` keeps up
  to that many generators in flight, collects the request each one is
  waiting on, sends them all through `sendMany()` (curl_multi), and feeds
  each response back. Links that need no network finish as soon as they
  start and never occupy a slot.

This keeps the resolution logic in one place for both paths, so the
sequential and concurrent results are identical by construction.

### Per-link GET memo

Inside one resolution a GET to the same URL is made once. For S1 links the
batch_execute page (`/rss/articles/<id>`) and the http_redirect start URL are
the same, so when batch_execute fails (for example `signature_missing`, or the
page answers with a redirect) http_redirect reuses that response instead of
asking Google again.

## Failure stance

Fail open on the item, closed on the target. A link that cannot be resolved
comes back unchanged with `resolved = false`, a reason, and every attempt
listed. A candidate that fails any acceptance rule is never returned as
resolved. Only programming errors throw: `InvalidConfigException` for a bad
setting and `TransportContractException` for a transport that returns
something other than a `Response`. Both implement `GnewsUnwrapException`.

Transient reasons (`rate_limited`, `upstream_error`, `circuit_open`,
`timeout`, `network_error`, `budget_exceeded`) are never cached, and a
negative result is not cached if any attempt on the way hit one of them. The
PRD names only `rate_limited` and `upstream_error`; the other four are added
because they say nothing about the link itself.

## Decisions

### Q1. Offline decode for opaque ids

No public work shows an offline decode for `AU_yqL` ids. Checked 11 November
2026 against the sources the PRD cites: every public decoder uses the
batchexecute endpoint. So:

- `LegacyIdDecoder` reports `opaque_id` for them and the chain moves on.
- The network method is implemented as `BatchExecute`, a pure class the
  resolver drives through the transport. It is labelled in code and in the
  README as undocumented and liable to break.
- A 429 or a redirect to `google.com/sorry` stops all network methods for the
  link and records a hit on the circuit breaker (3 in 60 s opens it for 5
  minutes). Results are cached (30 days positive, 1 hour negative), so a
  link is resolved once.
- When the method fails for any reason the library keeps working: the result
  is unresolved with a reason (`signature_missing`, `format_changed`,
  `rate_limited`, `consent_wall`, ...) and the input comes back unchanged.

The garturlreq payload follows the public decoders cited in the PRD (huksley
gist, SSujitX decoder, google_news_decoder docs). If the page names its own id
in `data-n-a-id`, that id is sent; otherwise the id from the path.

### Q2. Legacy id prefixes

Only the documented legacy layout is decoded offline: the decoded bytes start
with field 1 as a varint (`0x08 <varint>`) followed by field 4 as a
length-delimited string (`0x22`). The common case encodes as `CBMi`; other
field-1 values (`CAIi` and friends) share the layout and decode too. Anything
else is `unknown_id_format` and goes down the network path. The parse is real
protobuf (varints up to 5 bytes, lengths checked against the bytes left), not
fixed-offset stripping, so two-byte lengths and trailing fields work.

### Q3. Public suffixes

`HostNormaliser` ships a short built-in list of common multi-part suffixes
(`co.uk`, `co.in`, `com.au`, `co.za`, `com.br` and about 90 more across the
main news markets), extendable with `extraSuffixes`. A full Public Suffix
List can be loaded from disk with `PublicSuffixList::fromFile()` or the
`publicSuffixListFile` setting; it then replaces the built-in list. Exception
and wildcard rules are supported. The library never fetches the list over the
network.

### Q4. AMP markers in the article key

Stripped by default (`stripAmpMarkers: true`), with an option to keep them.
This reverses the PRD default. The reason: AMP pages and canonical pages of
the same article are far more common than outlets that serve a different
article at an AMP path, and missed dedup is the failure the library exists to
prevent. Stripped markers: a leading `/amp/` segment, a trailing `/amp` or
`/amp/`, `.amp` before an optional `.html`, the `amp` query parameter with or
without a value, and `outputType=amp`. Identity parameters are never stripped.
The `amp.` host label is handled by the outlet key in every mode.

### Q5. Concurrency in resolveMany

`resolveMany()` uses curl_multi when the transport is a `ConcurrentTransport`
(the curl transport is) and `concurrency` is above 1. Default concurrency is
4. Every other case, and `concurrency: 1`, resolves links one after another.
The pause (`pauseMs`) applies before each network resolution in sequential
mode and before each round in concurrent mode. The batchexecute endpoint can
take several ids in one POST, but public reports say results do not keep
input order, so each link still gets its own POST.

### Q6. Locale-prefixed paths (S9)

Dropped. No public evidence was found that `news.google.com/<locale>/...`
article paths exist; the public decoders handle only `/articles/`, `/read/`
and `/rss/articles/`. Such URLs come back as `not_an_article_link`. Listed
under README limitations.

### Q7. Streams transport limits

`StreamTransport`:

- Enforces timeouts through the stream context. PHP's http wrapper uses one
  context timeout for connecting and for each read, so the request timeout is
  used for both and a separate connect timeout cannot be enforced. The total
  deadline is checked between body chunks with `stream_set_timeout()`.
- Sets `follow_location` to 0. Redirects are handled by the resolver with its
  hop limit, the same as for curl.
- Reads the body in 8 KB chunks up to the byte cap and marks the response
  truncated when it stops early.
- Verifies TLS peer and name, sends `Connection: close`, keeps no cookies.

Gap: a server that drips response headers slowly can hold the stream
transport past the total deadline, because headers are read inside `fopen()`.
Curl has no such gap.

### Q8. AMP URL from legacy ids

Exposed as `Result::$ampUrl` (null when absent). It is read from a later
string field of the legacy id (field 26 in the public examples), validated
with the same acceptance rules, and never preferred over the canonical URL.

### Other decisions

- **Method on cache hits.** A positive hit reports method `cache` and the
  original method in `originalMethod`. A negative hit reports method `none`,
  the stored reason, and `fromNegativeCache = true`.
- **Extra reason codes.** The PRD's codes plus `missing_parameter`,
  `unknown_id_format`, `decode_failed`, `unexpected_status`,
  `private_address`, `no_candidate` and `not_redirected`, so that every
  attempt carries a precise reason.
- **Google hosts.** `google.<tld>` and every subdomain of it, `gstatic.com`
  and subdomains, and `consent.youtube.com`, plus `extraGoogleHosts`. YouTube
  itself is a valid target (PRD 4.5.4).
- **Own hosts** match by outlet key and by subdomain, so `m.mysite.example`
  and `blog.mysite.example` are rejected when `mysite.example` is listed.
- **Hop counting.** Every followed `Location` is a hop, including the last one
  to the publisher. `maxRedirects: 0` therefore turns http_redirect into
  "first answer only".
- **Requests to Google go over https**, even for `http:` wrappers and for
  `http:` Location headers between Google hosts.
- **Generic mode** reads at most 1 KB of each body, refuses non-HTTP schemes,
  and checks every hop with `AddressGuard` (loopback, RFC 1918, link-local,
  carrier-grade NAT, reserved, IPv6 unique-local and link-local, IPv4-mapped
  IPv6, numeric host forms, `localhost`). The DNS lookup is injectable for
  tests.
- **Fake transport in `src/`.** `FakeTransport`, `FakeConcurrentTransport`
  and `FakeClock` ship with the package so callers can test their own
  importers without the network.
- **Strict lowercasing** uses an ASCII table, not `strtolower()`, so results do
  not depend on the locale on PHP 8.1.
- **phpstan** runs at level 8 with `treatPhpDocTypesAsCertain: false`, because
  public methods validate their input at runtime for callers who do not run
  static analysis.

## Tests

`tests/Unit` maps every case in PRD section 4 and every acceptance test
T01 to T69 to at least one test. `tests/Integration` runs both shipped
transports against PHP's built-in web server on 127.0.0.1 (no outside
network). `tests/Live` holds T70, the opt-in live smoke test, which runs only
with `GNEWS_UNWRAP_LIVE=1` and is excluded from CI by group. Fixture ids for
the offline decoder are generated by the suite from known URLs; recorded
pages and batch responses use example publisher URLs only.
