# gnews-unwrap: product requirements

Status: draft for review. Package: `indianopinion/gnews-unwrap`. Namespace:
`IndianOpinion\GnewsUnwrap`. Licence: MIT.

This document says what the library must do. It does not say how to write
it. The builder answers the open questions in `docs/ARCHITECTURE.md`.

Words used here:

- **Wrapper link**: a URL on a Google host that stands in for a publisher's
  article and sends the reader on to it.
- **Publisher URL**: the article's own URL on the outlet's site.
- **Article id**: the opaque path segment after `/articles/` or `/read/` in a
  Google News link.
- **Outlet key**: the normalised host used to count an outlet once.
- **Article key**: the canonical form of a publisher URL used to spot the
  same article twice.

---

## 1. Problem

Google News feeds do not link to articles. Each item links to a Google News
wrapper such as `https://news.google.com/rss/articles/CBMi...?oc=5`. A
system that stores that link as the item's source gets three things wrong at
once:

1. **Attribution.** Every item is credited to `news.google.com`. Counts of
   distinct outlets per story, outlet weighting and any per-outlet history
   collapse onto one fake outlet.
2. **De-duplication.** The same article arriving from a direct publisher
   feed and from a Google News feed has two different URLs, so URL-based
   dedup never matches them.
3. **Reader links.** A source link shown to readers lands on a Google
   interstitial that needs JavaScript, consent or both, and often dead-ends.

A second, smaller fault sits beside it. Outlets serve the same article from
several hosts: `www.`, `amp.`, `m.`, `mobile.`. Counted as raw host strings,
one outlet counts twice and its history forks.

Measured in production (one line each):

- On a broad Google News feed, about 21% of resolved items turned out to be
  duplicates of items already taken from direct feeds, all of which URL dedup
  had missed while the wrapper was stored.
- A one-off repair after the fix healed 49 stored item rows and corrected
  the source lists and body links of 43 published posts.
- The network resolver cost about 1.2 seconds per link, which is why each
  link must be resolved once and the result stored.

Doing nothing means wrong attribution, silent duplicate stories, and a
backlog of stored rows that later need self-healing.

## 2. Goals and non-goals

### Goals

1. Detect every known Google News wrapper shape (section 4.1) from the URL
   alone, without network access.
2. Resolve a wrapper to the publisher URL, offline where the link format
   allows it, and over HTTP where it does not.
3. Report how each link was resolved, or why it was not.
4. Fail safe: on any failure return the input unchanged with a reason. Never
   throw for ordinary bad input.
5. Do nothing to a URL that is not a Google News wrapper, unless the caller
   asks for generic redirect following.
6. Resolve each link once: a pluggable cache, with an in-memory default.
7. Pluggable HTTP transport: curl by default, an interface for anything else.
8. A host normaliser that yields one outlet key per outlet.
9. An article key that drops tracking parameters, fragments and trailing
   slashes, so the same article is recognised whichever way it was linked.
10. Zero runtime dependencies. PHP 8.1+. Usable with or without Composer.
11. A short WordPress note showing how an importer calls it.

### Non-goals

- Fetching or extracting article bodies.
- Parsing RSS, Atom or any feed format.
- Searching Google News or building feed URLs.
- Bypassing rate limits, CAPTCHAs or consent walls (no proxy rotation, no
  headless browser, no consent-cookie forging).
- A full Public Suffix List implementation (see 4.6 and open question Q3).
- Mapping an outlet's vertical subdomains to the parent outlet (for example
  a business desk on its own subdomain). The normaliser exposes a hook so
  the caller can add such aliases; it ships none.
- Long-term storage. The library returns results; the caller stores them.

## 3. Users and setting

- **Who installs it:** PHP developers who ingest news links: aggregators,
  newsroom tools, WordPress importers, research scrapers.
- **Where it runs:** in a cron job, queue worker or import hook. Not in the
  request path of a page view; resolution can take seconds.
- **Typical volume:** tens to a few thousand links per hour. Each link is
  resolved once and then served from the cache or the caller's own store.
- **Environment:** PHP 8.1, 8.2 or 8.3 with `ext-json` (core). `ext-curl` is
  needed only for the default transport. `ext-intl` is optional and, when
  present, used for IDN conversion.

## 4. Behaviour

### 4.1 Link shapes: detection

Detection is pure: no network, no cache. A URL is a **Google News wrapper**
when it matches one of these shapes. Host comparison is case-insensitive and
ignores a trailing dot. Scheme may be `http` or `https`.

| # | Shape | Example | Era | Notes |
|---|---|---|---|---|
| S1 | `news.google.com/rss/articles/<id>` | `/rss/articles/CBMi...?oc=5` | 2017 to now | What RSS feeds carry today. Query (`oc`, `hl`, `gl`, `ceid`) is noise. |
| S2 | `news.google.com/articles/<id>` | `/articles/CBMi...?hl=en-IN&gl=IN&ceid=IN:en` | 2017 to now | Web app article link. |
| S3 | `news.google.com/read/<id>` | `/read/CBMi...` | about 2024 to now | Newer web app link, same id format. |
| S4 | `news.google.com/__i/rss/rd/articles/<id>` | `/__i/rss/rd/articles/CBMi...?oc=5` | about 2018 to 2020 | Older RSS redirect path. |
| S5 | `news.google.com/news/url?...&url=<publisher>` | `/news/url?sa=t&fd=R&url=https%3A%2F%2F...` | before December 2017 | Legacy feed redirector; target in `url`. |
| S6 | `news.url.google.com/url?...&url=<publisher>` or `q=` | | before 2018 | Legacy desktop and RSS redirector host. |
| S7 | `www.google.<tld>/url?...&url=<publisher>` or `q=` | `google.com/url?rct=j&sa=t&url=...&ct=ga&usg=...` | still live | Google Alerts and Google web redirector. Only treated as a wrapper when `gnews` scope is widened (see 4.5). |
| S8 | Relative forms `./articles/<id>`, `./read/<id>` | | now | Seen in scraped Google News HTML. Accepted only when the caller passes a base URL on a Google News host. |
| S9 | Locale or edition prefixes before the path, for example `news.google.com/<locale>/...` if they appear | | uncertain | Accepted when the remaining path matches S1 to S4. See Q6. |

Rules:

1. A URL on `news.google.com` that matches none of S1 to S5 (topic pages,
   `/topics/`, `/stories/`, `/search`, `/rss/search`, `/home`, the front
   page) is **not a wrapper**. It is returned unchanged with reason
   `not_an_article_link`. These pages have no single publisher target.
2. A URL on any other host is **not Google News**. Reason
   `not_google_news`. No network call is made. No exception is thrown.
3. A string that is not a URL (empty, no scheme, no host, whitespace, binary
   junk, over 8 KB) is returned unchanged with reason `invalid_url`.
4. A URL that is already a publisher URL is case 2: returned unchanged with
   method `none` and reason `not_google_news`. This is the "already resolved"
   case and must be cheap.
5. Uppercase in the host (`NEWS.Google.COM`) or a trailing dot
   (`news.google.com.`) does not change detection.
6. The article id is taken from the path segment only, with any query and
   fragment removed. An id with characters outside the URL-safe base64
   alphabet plus `-` and `_` is reason `malformed_id`.

### 4.2 Resolution methods and their order

For a detected wrapper the resolver tries these methods in order and stops at
the first that yields an acceptable target (4.4). Each method is a separate,
testable unit, and the caller can switch any of them off.

| Order | Method id | Applies to | Network | Still works (as of November 2026) |
|---|---|---|---|---|
| 0 | `cache` | all | no | yes |
| 1 | `query_param` | S5, S6, S7 | no | yes, for the links that still exist |
| 2 | `offline_decode` | S1 to S4 with a legacy id | no | yes for legacy ids only; new ids since July 2024 are opaque |
| 3 | `batch_execute` | S1 to S4 | yes, two requests | yes at time of writing, but undocumented, rate-limited, and has broken several times |
| 4 | `http_redirect` | all wrappers | yes | partial: works for S5 to S7 and some older ids; current S1 links usually answer 200 with a JavaScript page, not a 3xx |
| 5 | `html_parse` | the body fetched in 3 or 4 | no extra request | partial: works when the interstitial carries the target in a readable form |

Notes on each:

1. **`cache`.** Look up the normalised wrapper (scheme, host lowercased,
   query and fragment dropped for S1 to S4; full query kept for S5 to S7).
   A hit returns the stored result with method `cache` and the original
   method recorded alongside. Negative results are cached too, with a
   shorter lifetime (section 7).
2. **`query_param`.** Read the `url` parameter, else `q`. URL-decode it once.
   If the decoded value itself is a Google redirector, unwrap again, at most
   3 levels. Drop Google's own trailing parameters only if they leaked into
   the value (`ct`, `cd`, `usg`, `ved`, `sa`). The value must pass 4.4.
3. **`offline_decode`.** The legacy article id is URL-safe base64 of a small
   protobuf message. Decode it (pad as needed, accept both base64 alphabets).
   The message carries the publisher URL as a length-prefixed string (field
   4, wire type 2, so the decoded bytes start `0x08 <n> 0x22` and the id
   text begins `CBMi` for the common case) and, on some ids, a second string
   holding the AMP URL. Parse it as protobuf (varint lengths, which may take
   two bytes), not by stripping fixed bytes. Take the first string that is
   an absolute `http` or `https` URL. If the string begins `AU_yqL`, the id
   is the new opaque form: stop with reason `opaque_id` and move on. Never
   prefer the AMP URL over the canonical one.
4. **`batch_execute`.** The method used by every public decoder since
   September 2024. Fetch the article page
   (`https://news.google.com/rss/articles/<id>` is reported to draw fewer
   429s than `/articles/<id>`), read the signature and timestamp from the
   `data-n-a-sg` and `data-n-a-ts` attributes, then POST a form-encoded
   `f.req` body naming the RPC `Fbv4je` with a `garturlreq` payload (id,
   timestamp, signature) to
   `https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je`.
   The response starts with the anti-JSON prefix `)]}'` and nests the target
   inside a JSON string marked `garturlres`. Parse JSON, never regex the raw
   body for a URL. Missing attributes give reason `signature_missing`; a 429
   or a `google.com/sorry` redirect gives `rate_limited`; an unexpected
   response shape gives `format_changed`. This method is isolated in its own
   class so it can be fixed or disabled in one place when Google changes it.
5. **`http_redirect`.** Request the wrapper and follow `Location` headers
   within the limits of 4.3. The target is the first hop whose host is not a
   Google host. Do not fetch that publisher page: as soon as a hop leaves
   Google, stop and return it (one request to the publisher is never made).
   A 200 from a Google host ends this method and hands its body to
   `html_parse`.
6. **`html_parse`.** From an interstitial body already in hand, look in this
   order: `<meta http-equiv="refresh" content="0;url=...">`, `<link
   rel="canonical">` pointing off Google, a single `<a>` whose
   `href` points off Google, a `window.location` or
   `location.replace` string literal. Only the first 512 KB of the body is
   read. Every candidate must pass 4.4.

If every enabled method fails, the result is the input unchanged with
`resolved = false`, method `none`, and the reason from the last method that
ran, plus the reasons of the others in a list.

### 4.3 Network limits and failure modes

| Case | Limit or behaviour | Reason code |
|---|---|---|
| Too many redirects | at most 5 hops per resolution (configurable, 0 to 10) | `too_many_redirects` |
| Redirect loop | a hop seen before in this resolution stops it at once | `redirect_loop` |
| Connect timeout | 3 s default | `timeout` |
| Total timeout per request | 8 s default | `timeout` |
| Total budget per link | 15 s across all methods and hops | `budget_exceeded` |
| DNS failure, TLS failure, connection refused | stop the method | `network_error` |
| HTTP 429, or redirect to `google.com/sorry` | stop all network methods for this link, and open a short circuit breaker (section 7) so later links skip the network | `rate_limited` |
| HTTP 403 | stop the method | `forbidden` |
| HTTP 404 or 410 from Google | the article id is dead; cache the negative | `gone` |
| HTTP 5xx | stop the method; do not cache | `upstream_error` |
| Redirect to `consent.google.com` or `consent.youtube.com` | try the `continue` parameter only if it already holds a non-Google target, else stop. Do not post consent forms or forge consent cookies. | `consent_wall` |
| `accounts.google.com` or other sign-in hop | stop | `login_wall` |
| Non-HTML 200 (image, PDF, JSON other than the batch response, binary) | stop; do not read beyond the first 512 KB | `unexpected_content_type` |
| Body larger than 512 KB | read 512 KB, then parse what was read | none, unless parse fails |
| `Location` relative, protocol-relative or with spaces | resolve against the current hop per RFC 3986 | none |
| `Location` to `http:` from `https:` | allowed for the final publisher hop only; recorded in the result | none |
| `Location` with a non-HTTP scheme (`javascript:`, `data:`, `file:`, `intent:`) | rejected | `bad_target` |
| Circuit breaker open | network methods skipped, offline methods still run | `circuit_open` |
| Transport throws | caught; turned into a result | `network_error` |

Failure stance: **fail open on the item, closed on the target.** A link that
cannot be resolved is still returned (as the wrapper) so the caller keeps
the item and can retry later; nothing is dropped. But a doubtful target is
never returned as resolved: when in doubt, return the wrapper. A wrong
publisher URL is worse than an unresolved one, because it attributes a story
to the wrong outlet and is then trusted.

### 4.4 Accepting a target

A candidate target is accepted only if all of these hold. Otherwise the
method fails with `bad_target` and the next method runs.

1. It is an absolute URL with scheme `http` or `https` and a host.
2. Its host is not a Google host (`news.google.com`, `news.url.google.com`,
   `google.<tld>`, `www.google.<tld>`, `consent.google.com`,
   `accounts.google.com`, `gstatic.com` and subdomains). A list of these is
   configurable.
3. Its host is not one of the caller's own hosts, if the caller configured
   any. (In production a resolver once returned the importing site's own
   URL; storing it credited the story to ourselves.)
4. Its host is not a bare IP literal, `localhost`, or a private, loopback or
   link-local address, unless the caller allows it.
5. It is no longer than 2048 characters and holds no control characters or
   whitespace after trimming.
6. It is not the wrapper itself.

### 4.5 Scope and non-Google URLs

1. By default only `news.google.com` and `news.url.google.com` shapes are
   wrappers. Google web redirector links (S7) are wrappers only when the
   caller widens scope to include them, because they are not Google News
   and a caller may not expect them to change.
2. A URL that is not a wrapper is never fetched, never cached and never
   changed. The result says `resolved = false`, method `none`, reason
   `not_google_news` (or `not_an_article_link`, `invalid_url`).
3. An optional generic mode, off by default, follows HTTP redirects for any
   URL (for example a `feedproxy` or link-shortener hop). It applies the
   same limits and acceptance rules. It must be asked for per call or in
   configuration, never implied.
4. A wrapper whose target turns out to be a non-news host (a video site, an
   app store, a PDF, a Google property such as YouTube) is still a valid
   resolution and is returned. Whether to keep such items is the caller's
   choice. The result includes the target's outlet key so the caller can
   filter.

### 4.6 Host normaliser (outlet key)

Input: a URL or a bare host. Output: a lowercased host string, or the empty
string when no host can be found. Pure, no network.

Cases, in the order they apply:

1. Extract the host: from a URL via a strict parse; a bare host is accepted
   as is. Drop userinfo (`user:pass@`).
2. Drop the port, any port. An outlet on `:8080` is still the same outlet.
3. Drop one trailing dot (`example.com.`).
4. Lowercase. ASCII lowercasing always; for non-ASCII input see case 9.
5. IP literals (IPv4, bracketed IPv6) are returned lowercased and otherwise
   untouched.
6. Strip leading labels from the configured strip list, default `www`,
   `amp`, `m`, `mobile`, **repeatedly**, so `m.amp.example.com`,
   `www.m.example.com` and `amp.www.example.com` all become `example.com`.
7. Numbered web labels such as `www1`, `www2`, `ww2` are stripped too
   (pattern `www\d*` and `ww\d+`). Configurable.
8. Never strip down past the registrable domain. Stop when the host has two
   labels left, or three when the last two form a known two-level public
   suffix (`co.uk`, `co.in`, `com.au`, `co.nz`, `co.za`, `com.br`,
   `org.uk`, `ac.in`, `gov.in`, `net.in`, `org.in`, and a short documented
   list, extendable by the caller). So `m.com` stays `m.com`, `www.co.uk`
   stays `www.co.uk`, `amp.example.co.uk` becomes `example.co.uk`.
9. IDN: a host given in Unicode and the same host in punycode
   (`xn--...`) must give the same key. The key is always the ASCII
   (punycode) form. With `ext-intl` loaded, convert with UTS 46 rules. Without
   it, Unicode hosts are lowercased with `mb_strtolower` and returned in
   Unicode, and the result carries a flag saying IDN conversion was skipped.
   This difference is documented.
10. Labels that only look similar are not merged: `mobile-news.example.com`,
    `amplify.example.com`, `mtv.com` stay as they are. Only whole labels from
    the list are stripped.
11. Strip-list labels in the middle of a host are kept:
    `news.m.example.com` stays `news.m.example.com`.
12. Caller aliases run last: a map from a host (or a suffix pattern) to an
    outlet key, for vertical subdomains the caller wants folded into the
    parent. Empty by default.
13. A Google wrapper URL passed to the normaliser gives `news.google.com`.
    The normaliser does not resolve. The docs say plainly: unwrap first,
    then normalise.

### 4.7 Article key

Input: a URL. Output: a canonical string used for dedup, or the empty string
for invalid input. Pure, no network. The key is meant for comparison and
hashing, not for display or fetching.

1. Scheme is dropped from the key (`http` and `https` versions of one
   article are the same article).
2. Host is the outlet key from 4.6 (so `www.`, `amp.`, `m.` variants
   match).
3. Path: percent-encoding normalised (decode unreserved characters, upper
   case hex in the rest), dot segments removed, repeated slashes collapsed,
   one trailing slash removed (but `/` alone stays `/`). Case is kept: paths
   are case-sensitive on many servers.
4. Fragment dropped.
5. Query: tracking parameters dropped, the rest sorted by name then value,
   and kept. Default drop list: every `utm_*`, `fbclid`, `gclid`, `dclid`,
   `gbraid`, `wbraid`, `msclkid`, `mc_cid`, `mc_eid`, `igshid`, `yclid`,
   `_ga`, `_gl`, `ref`, `ref_src`, `referrer`, `cmpid`, `ocid`, `oc`, `ito`,
   `s_cid`, `smid`, `src`, `source`, `at_medium`, `at_campaign`, `ns_mchannel`,
   `ns_campaign`, `ncid`, `taid`, `ved`, `usg`, `ei`. The list is
   configurable and the caller can also give an allow list (keep only these).
   Parameters are matched case-insensitively.
6. An empty query after dropping leaves no `?`.
7. A parameter with no value (`?amp`) is kept as a name only.
8. AMP path markers (`/amp`, `/amp/`, `.amp`, `?amp=1`, `outputType=amp`)
   are **not** removed by default, because some outlets serve different
   articles at those paths. An option turns this on (see Q4).
9. Hosts the caller declared as "query is identity" (for example a site
   whose article id is `?id=123`) never lose that parameter even if it is on
   the drop list.
10. A Google wrapper URL passed to the key function gets a key on
    `news.google.com`. The docs say: unwrap first.

### 4.8 Result object

Every call to resolve returns a result. It never returns null and never
throws for bad input. It holds:

- the input URL exactly as given;
- the output URL (publisher URL when resolved, else the input);
- a resolved flag;
- the method that produced the output (`cache`, `query_param`,
  `offline_decode`, `batch_execute`, `http_redirect`, `html_parse`, `none`),
  and, for cache hits, the method that originally resolved it;
- a reason code when not resolved (codes from 4.1, 4.3 and 4.4, listed in
  one enum);
- the list of methods tried, each with its own reason;
- the number of HTTP requests made and elapsed milliseconds;
- the outlet key of the output URL;
- whether the cached result was a negative.

A result is immutable and can be turned into an array for storage or logs.

### 4.9 The production lesson, as behaviour

1. Unwrap at ingest, before any key, hash, outlet count or dedup check is
   computed. Every derived value must come from the resolved URL.
2. Store the resolved URL with the item. Never re-resolve on read.
3. Support self-healing of rows stored before unwrapping existed: the docs
   show a batch job that reads stored source URLs, runs them through the
   resolver, and when output differs from input writes back both the URL and
   the recomputed outlet key and article key. The library makes this safe
   because a non-wrapper input comes back unchanged and costs nothing.
4. Keep the item when unwrapping fails. Mark it unresolved so the caller can
   hold it from outlet counts, or retry later.
5. Count outlets by outlet key, not by host string.

### 4.10 Edge cases summary

- Wrapper with extra query (`?oc=5&hl=en-IN&gl=IN&ceid=IN:en`): ignored for
  resolution and for the cache key.
- Wrapper with a fragment: fragment ignored.
- Wrapper with trailing slash after the id: accepted.
- Wrapper id padded or unpadded: both accepted.
- Wrapper on `http:`: accepted, requests go over `https:`.
- Double-wrapped link (a Google redirector whose `url` is a Google News
  wrapper): unwrapped level by level up to 3 levels.
- Publisher target that is itself a redirect (a shortener): returned as is;
  generic mode can follow it if enabled.
- Target with uppercase host: returned as given; the outlet key lowercases.
- Target with an internationalised host: returned as given; the outlet key
  handles IDN.
- Same wrapper resolved twice in one process: second call is a cache hit
  with zero requests.
- Two different wrappers for the same article (an RSS id and a web id):
  both resolve to the same publisher URL and the same article key.

## 5. Public interface

Described by role. Final names are the builder's, within the namespace
`IndianOpinion\GnewsUnwrap`. No global functions except an optional
bootstrap file for non-Composer use.

1. **Detector.** Given a URL string, says whether it is a wrapper, which
   shape (S1 to S9), and the extracted article id or target parameter. Pure.
2. **Unwrapper (resolver).** Built with a configuration, a transport and a
   cache, all optional. Methods: resolve one URL; resolve a list (returns
   results keyed by input, order kept); a "resolve or same" convenience that
   returns just the output string. Never throws for bad input.
3. **Result.** Immutable value object, section 4.8. Has a to-array method.
4. **Reason.** An enum of reason codes. **Method.** An enum of method ids.
5. **Config.** Immutable value object with named constructor arguments for
   every setting in section 7, and `with...` copiers.
6. **Transport interface.** One method: send a request (method, URL,
   headers, body, timeouts, max body bytes) and return a response (status,
   headers, body, final URL of this single hop). The transport must **not**
   follow redirects itself; the resolver drives hops so it can apply limits
   and stop at the first non-Google host. Shipped implementations: a curl
   transport (default when `ext-curl` is loaded), a PHP streams transport
   (fallback when it is not), and an in-memory fake for tests that replays
   recorded fixtures. A PSR-18 adapter is documented in the README, not
   shipped (zero dependencies).
7. **Cache interface.** get, set with a lifetime, delete. Shipped: an
   in-memory array cache (bounded, default 10,000 entries, least recently
   used out) and a null cache. A PSR-16 adapter and a WordPress transients
   adapter are shown in the docs, not shipped.
8. **Host normaliser.** Given a URL or host, returns the outlet key. Built
   with a strip list, suffix list and alias map, all with defaults.
9. **Article key builder.** Given a URL, returns the article key. Built with
   a drop list, allow list and options from 4.7.
10. **Typed exceptions** exist only for programming errors: an invalid
    configuration value, a transport that returns something that is not a
    response. They extend one base exception of the package.

Default object graph with no arguments: in-memory cache, curl transport if
available else streams, all methods on except generic mode and S7 scope.

## 6. Data

- The library stores nothing on disk and holds no global state, apart from
  the in-memory cache and circuit breaker inside the objects the caller
  creates.
- Cache entries: key is a hash of the normalised wrapper; value is the
  result as an array. Positive lifetime 30 days, negative lifetime 1 hour,
  rate-limited results not cached at all.
- Retention and deletion are the caller's: with the in-memory cache, data
  dies with the process.
- Nothing to uninstall. The WordPress note says that if the caller used
  transients, a cleanup on plugin uninstall removes them.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Methods enabled | all six | each can be switched off |
| Scope: Google web redirector (S7) | off | |
| Generic redirect mode | off | |
| Max redirects | 5 | 0 to 10 |
| Connect timeout | 3 s | |
| Request timeout | 8 s | |
| Budget per link | 15 s | |
| Max body bytes read | 512 KB | |
| User-Agent | `gnews-unwrap/<version> (+repo URL)` | caller can override |
| Accept-Language | `en` | some interstitials vary by language |
| Positive cache lifetime | 30 days | |
| Negative cache lifetime | 1 hour | `rate_limited` and `upstream_error` never cached |
| Circuit breaker | open for 5 minutes after 3 `rate_limited` results within 60 s | |
| Pause between network resolutions | 0 ms | callers resolving in bulk should set 250 to 1000 ms |
| Own hosts (never a valid target) | empty | |
| Extra Google hosts | empty | added to the built-in list |
| Allow private and IP targets | off | |
| Strip labels | `www`, `amp`, `m`, `mobile`, plus `www\d*`, `ww\d+` | |
| Two-level suffixes | short built-in list | extendable |
| Outlet aliases | empty | |
| Tracking parameter drop list | list in 4.7 | replaceable or extendable |
| Strip AMP markers in article key | off | |

## 8. Security and privacy

1. **SSRF.** The resolver only ever sends requests to Google hosts. It does
   not request the publisher target. In generic mode it refuses private,
   loopback, link-local and metadata addresses (for example
   `169.254.169.254`) at every hop, checked after DNS resolution where the
   transport allows, and refuses non-HTTP schemes.
2. **No cookies kept** between links, no consent forging, no login.
3. **TLS verification on** in every shipped transport, with no option to
   turn it off.
4. **Input limits:** URLs over 8 KB rejected; bodies capped at 512 KB;
   protobuf decoding bounded (no allocation larger than the input, varints
   capped at 5 bytes) so a crafted id cannot exhaust memory.
5. **No `eval`, no shell, no `@` error suppression, no `unserialize`.** JSON
   decode with depth limit and exceptions turned into reason codes.
6. **Privacy:** the only data sent to Google is the article id and the
   values Google itself provided (signature, timestamp). Nothing about the
   caller's users. The User-Agent identifies the library honestly.
7. **Terms of use:** the README notes that `batch_execute` uses an
   undocumented Google endpoint, that heavy use draws rate limits, and that
   the caller is responsible for complying with Google's terms. The method
   can be switched off.

## 9. Performance budget

- Detection, host normalisation and article key: under 50 microseconds each
  on a typical server, no allocation beyond the strings involved.
- Offline decode: under 200 microseconds per id.
- Cache hit: one cache lookup, zero requests.
- Network resolution: two requests for `batch_execute`, at most 1 plus the
  redirect limit for `http_redirect`. Typical wall time 0.5 to 2 seconds;
  hard ceiling is the per-link budget (15 s).
- Memory: under 2 MB per resolution beyond the body cap.
- List resolution runs links one after another with the configured pause.
  No parallel requests in 0.1.0 (see Q5).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3; tested on all three in CI. `declare(strict_types=1)`.
- Required extensions: none beyond core (`json`, `pcre`). `ext-curl`
  optional (default transport), `ext-intl` optional (IDN), `ext-mbstring`
  optional (Unicode lowercasing fallback; without it non-ASCII hosts are
  left as given and flagged).
- Composer package `indianopinion/gnews-unwrap`, PSR-4, PSR-12. Also a
  single bootstrap file for use without Composer.
- WordPress: no dependency on it. Works from a plugin, a mu-plugin or
  WP-CLI. The docs include a note (`docs/WORDPRESS.md`) showing: where to
  call it in an importer (before the post or item is saved), how to store
  the resolved URL and outlet key as post meta, how to back the cache with
  transients, how to run the self-heal batch from WP-CLI, and how to keep
  the call out of page views.
- Hosts and caches: works behind outbound proxies when the transport is
  configured for them (curl options pass-through). No shared cache is
  assumed.

## 11. Observability

1. Every result carries method, reason, list of attempts, request count and
   elapsed time. That is the primary signal.
2. An optional PSR-3-shaped logger (an interface with the same method
   signatures, no dependency) receives one line per resolution at `debug`,
   and one at `warning` for `rate_limited`, `format_changed` and
   `circuit_open`. Off by default.
3. Counters held by the resolver object: links seen, wrappers seen, resolved
   by each method, unresolved by each reason, requests made. Readable as an
   array so a caller can push them to its own metrics.
4. The README has a "how to tell it is working" section: run the bundled
   example on three fixture links, then on one live link, and read the
   method column. A sudden rise in `format_changed` or `signature_missing`
   means Google changed the page.

## 12. Acceptance tests

Fixtures live in `tests/fixtures/`: recorded HTTP exchanges (status,
headers, body) as JSON or plain files, and article ids. Ids for the offline
decoder are built by the test suite itself from known URLs, so no real
Google id is needed for those. Recorded interstitials and batch responses
are reduced to the minimum markup the parser needs and use example
publisher URLs (`https://example.com/...`, `https://example.co.uk/...`).
No test touches the network; one opt-in live smoke test runs only when an
environment variable is set and is excluded from CI.

| Test | Covers | Fixture |
|---|---|---|
| T01 detect S1 rss/articles with `oc` and locale query | 4.1 S1, 4.10 | url list |
| T02 detect S2 articles | 4.1 S2 | url list |
| T03 detect S3 read | 4.1 S3 | url list |
| T04 detect S4 `__i/rss/rd/articles` | 4.1 S4 | url list |
| T05 detect S5 `news/url?url=` | 4.1 S5 | url list |
| T06 detect S6 `news.url.google.com/url?q=` | 4.1 S6 | url list |
| T07 S7 not a wrapper by default, wrapper when scope widened | 4.1 S7, 4.5.1 | url list |
| T08 relative `./articles/` with and without base URL | 4.1 S8 | url list |
| T09 locale-prefixed path | 4.1 S9 | url list |
| T10 Google News topic, search, front page: `not_an_article_link` | 4.1 rule 1 | url list |
| T11 publisher URL: `not_google_news`, zero requests, not cached | 4.1 rules 2, 4; 4.5.2 | fake transport asserting no calls |
| T12 invalid inputs (empty, no scheme, junk, 9 KB): `invalid_url`, no throw | 4.1 rule 3 | inline |
| T13 uppercase host and trailing dot detected | 4.1 rule 5 | inline |
| T14 bad id characters: `malformed_id` | 4.1 rule 6 | inline |
| T15 query_param: `url`, `q`, percent-encoded, Google params leaked | 4.2.2 | url list |
| T16 query_param nested 2 and 4 levels (4 stops at 3) | 4.2.2, 4.10 | url list |
| T17 offline decode legacy id, one-byte length | 4.2.3 | generated ids |
| T18 offline decode, two-byte varint length (URL over 127 bytes) | 4.2.3 | generated ids |
| T19 offline decode with AMP second field returns canonical | 4.2.3 | generated ids |
| T20 offline decode `AU_yqL` id: `opaque_id`, next method runs | 4.2.3 | generated ids |
| T21 offline decode padded, unpadded, standard and URL-safe alphabet | 4.2.3, 4.10 | generated ids |
| T22 offline decode truncated or hostile protobuf: no crash, bounded memory | 4.2.3, 8.4 | generated ids |
| T23 batch_execute happy path | 4.2.4 | `batch/page-ok.html`, `batch/response-ok.txt` |
| T24 batch_execute missing signature: `signature_missing` | 4.2.4 | `batch/page-nosig.html` |
| T25 batch_execute 429 and sorry redirect: `rate_limited`, network stops | 4.2.4, 4.3 | `http/429.json`, `http/sorry.json` |
| T26 batch_execute changed response shape: `format_changed` | 4.2.4 | `batch/response-changed.txt` |
| T27 http_redirect 302 to publisher, publisher never requested | 4.2.5 | `http/302-publisher.json` |
| T28 http_redirect chain within limit | 4.3 | `http/chain-3.json` |
| T29 too many redirects | 4.3 | `http/chain-12.json` |
| T30 redirect loop | 4.3 | `http/loop.json` |
| T31 relative and protocol-relative Location | 4.3 | `http/relative.json` |
| T32 html_parse meta refresh | 4.2.6 | `html/meta-refresh.html` |
| T33 html_parse canonical, anchor, location literal; Google-only candidates rejected | 4.2.6, 4.4 | `html/*.html` |
| T34 html_parse body over 512 KB | 4.3 | generated |
| T35 timeouts (connect, request, budget) | 4.3 | fake transport with delays |
| T36 network error and transport exception become results | 4.3 | fake transport |
| T37 403, 404, 410, 5xx: reasons and caching rules | 4.3, 6 | `http/*.json` |
| T38 consent wall with and without usable `continue` | 4.3 | `http/consent-*.json` |
| T39 login wall | 4.3 | `http/accounts.json` |
| T40 non-HTML 200 | 4.3 | `http/pdf.json` |
| T41 `javascript:`, `data:`, `file:` targets rejected | 4.3, 4.4 | `http/bad-scheme.json` |
| T42 target is Google host, own host, IP, private address, wrapper itself: rejected | 4.4 | inline |
| T43 target over 2048 chars or with control characters | 4.4 | inline |
| T44 method order respected and switchable | 4.2 | fake transport |
| T45 all methods fail: input returned, reasons listed | 4.2, 4.3 stance | fake transport |
| T46 cache hit on second call, zero requests, original method recorded | 4.2.1, 4.10 | fake transport |
| T47 negative cache lifetime and no caching of `rate_limited` | 6, 7 | fake clock |
| T48 circuit breaker opens and closes | 4.3, 7 | fake clock |
| T49 generic mode off by default, on follows a shortener, SSRF refused | 4.5.3, 8.1 | fake transport |
| T50 wrapper to non-news target (video, PDF, YouTube) is resolved | 4.5.4 | `http/302-video.json` |
| T51 two wrappers, same article: same output and same article key | 4.10 | fake transport |
| T52 result object fields and to-array round trip | 4.8 | inline |
| T53 list resolution keeps order and keys | 5.2 | fake transport |
| T54 host: lowercase, port, userinfo, trailing dot | 4.6.1 to 4.6.4 | inline |
| T55 host: IPv4 and IPv6 untouched | 4.6.5 | inline |
| T56 host: `www.`, `amp.`, `m.`, `mobile.`, `m.amp.`, `amp.www.`, `www2.` | 4.6.6, 4.6.7 | inline |
| T57 host: floor at registrable domain (`m.com`, `www.co.uk`, `amp.example.co.uk`) | 4.6.8 | inline |
| T58 host: IDN Unicode and punycode give one key, with and without intl | 4.6.9 | inline, skipped part when intl absent |
| T59 host: lookalike labels and middle labels kept | 4.6.10, 4.6.11 | inline |
| T60 host: aliases | 4.6.12 | inline |
| T61 host of a wrapper is `news.google.com` | 4.6.13 | inline |
| T62 article key: scheme, host variants, fragment, trailing slash, `/` root | 4.7.1 to 4.7.4 | inline |
| T63 article key: tracking params dropped, case-insensitive, rest sorted, empty `?` gone | 4.7.5, 4.7.6 | inline |
| T64 article key: valueless parameter, identity-query hosts, allow list | 4.7.7, 4.7.9 | inline |
| T65 article key: percent-encoding and dot segments | 4.7.3 | inline |
| T66 article key: AMP markers kept by default, stripped when enabled | 4.7.8 | inline |
| T67 self-heal scenario: list of stored URLs, only wrappers change, keys recomputed | 4.9 | fake transport |
| T68 no exceptions for any input in a fuzz corpus of 500 strings | 4.1, 4.8 | generated |
| T69 config validation throws typed exception on bad values | 5.10 | inline |
| T70 live smoke test (opt-in, not in CI) | 4.2.3 to 4.2.5 | network |

Every numbered case in section 4 maps to at least one test above.

## 13. Open questions

For the builder to answer in `docs/ARCHITECTURE.md`.

- **Q1.** Does any public work show an offline decode for `AU_yqL` ids?
  At the time of writing none is known; every public decoder uses the batch
  endpoint. Confirm before release and record the date checked.
- **Q2.** Is the `CBMi` prefix the only legacy one? Some ids are reported
  with other first bytes. The protobuf parse in 4.2.3 should not care, but
  the builder should test any prefix found in public examples.
- **Q3.** Is a short built-in two-level suffix list enough, or should an
  optional Public Suffix List file be loadable by path (still no runtime
  dependency)? Recommend the loadable file as an option.
- **Q4.** Should AMP path markers be stripped in the article key by default?
  The PRD says no, for safety. Revisit with data.
- **Q5.** Should list resolution offer concurrency (curl multi)? The batch
  endpoint can take several ids in one POST, but public reports say results
  do not keep input order. 0.1.0 is sequential.
- **Q6.** Do locale-prefixed paths (S9) exist on `news.google.com` today?
  If no public evidence is found, drop S9 from the detector and say so.
- **Q7.** Which streams-transport limits can be enforced without curl (body
  cap, connect timeout)? Document any gap.
- **Q8.** Should the result expose the AMP URL from a legacy id as an extra
  field? It is free to read and some callers want it.

## 14. Lessons from production

Written as rules.

1. Unwrap before anything else is derived from the URL. Outlet counts,
   article keys, dedup hashes and reader links all inherit the wrapper's
   host if you do not.
2. Store the resolved URL. A network resolver costs about a second per link;
   pay it once at ingest, never on read.
3. Rows stored before the fix will be wrong. Ship a self-heal path on day
   one: re-run the resolver over stored URLs and write back the URL and every
   value derived from it, not just the URL.
4. When resolution fails, keep the item and keep the wrapper. Losing the item
   is worse than an unresolved link, and the caller can hold unresolved items
   from outlet counts.
5. Never accept a target that points back at Google or at your own site. A
   resolver that returns your own URL makes you the source of the story.
6. Try the offline decode first. Older ids carry the URL inside and need no
   request. Only the newer opaque ids need the network.
7. The batch endpoint is undocumented and has broken several times. Keep it
   in one place, detect a changed response shape loudly, and let the caller
   turn it off.
8. Google rate-limits bulk resolution. Back off on the first 429 and stop
   hammering; a circuit breaker beats a retry loop.
9. Normalise hosts by stripping whole leading labels repeatedly (`www`,
   `amp`, `m`, `mobile`), or one outlet counts twice and its history forks.
   Never strip past the registrable domain.
10. Dedup on a canonical article key (no scheme, normalised host, no
    tracking parameters, no fragment, no trailing slash), not on the raw URL.

## Sources

Public sources consulted on 11 November 2026. All describe an undocumented
Google behaviour and may go stale.

- Stack Overflow, "Decoding encoded Google News URLs" (2018 to 2023): base64
  layout of legacy ids, `CBMi` prefix, `0x08 0x13 0x22` header and
  `0xd2 0x01` trailer, `/__i/rss/rd/articles/` shape.
  https://stackoverflow.com/questions/51131834/decoding-encoded-google-news-urls
- huksley, "Decode Google News RSS URLs" gist and comments (2024 to 2025):
  July 2024 `AU_yqL` ids, September 2024 signature and timestamp,
  `batchexecute` with RPC `Fbv4je`, 429 reports, `/read/` shape, breakages
  reported February and May 2025.
  https://gist.github.com/huksley/bc3cb046157a99cd9d1517b32f91a99e
- SSujitX, google-news-url-decoder (Python): handles `/read/`, `/articles/`,
  `/rss/articles/`; notes the id is opaque after 2024.
  https://github.com/SSujitX/google-news-url-decoder
- google_news_decoder (Elixir) docs: page fetch, `data-n-a-sg` and
  `data-n-a-ts`, batch POST.
  https://hexdocs.pm/google_news_decoder/GoogleNewsDecoder.html
- GNews (Python) docs, URL resolution: plain HTTP cannot follow current
  links, consent gates fail, returns the Google URL on failure, about 70 to
  80% success with a headless browser.
  https://gnews.readthedocs.io/en/latest/usage/url-resolution.html
- Search Engine Land, "Google News to deprecate old RSS feed URLs by
  December 1, 2017".
  https://searchengineland.com/google-news-deprecate-old-rss-feed-urls-december-1-2017-286149
- Search Engine Roundtable on `news.google.com` versus `news.url.google.com`
  referral hosts.
  https://www.seroundtable.com/google-news-referral-source-24771.html
- Drupal FeedAPI issue 590958: legacy `news.google.com/news/url?fd=R&sa=T&url=`
  items. https://www.drupal.org/project/feedapi/issues/590958
- Zapier community, Google Alerts links of the form
  `google.com/url?rct=j&sa=t&url=...&ct=ga&usg=...`.
  https://community.zapier.com/how-do-i-3/google-alerts-rss-feed-get-url-for-original-story-52828
