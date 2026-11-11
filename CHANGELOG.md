# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- `Detector` for every known Google News wrapper shape (S1 to S8), offline.
- `Unwrapper` with six methods in order: `cache`, `query_param`,
  `offline_decode`, `batch_execute`, `http_redirect`, `html_parse`; each can be
  switched off.
- `LegacyIdDecoder`: bounded protobuf decode of legacy ids, with the AMP URL
  exposed as `Result::$ampUrl`.
- `BatchExecute`: the undocumented Google endpoint, isolated in one class.
- Network limits: redirect cap, loop detection, connect and request timeouts,
  a 15 s per-link budget, a 512 KB body cap, consent and login walls, and a
  circuit breaker on rate limits.
- Acceptance rules for targets: no Google hosts, no own hosts, no IP or
  private targets, length and control-character checks.
- Optional generic redirect mode with SSRF checks.
- `resolveMany()`, concurrent through curl_multi when available.
- `HostNormaliser` for outlet keys, with a built-in multi-part suffix list,
  optional Public Suffix List file, IDN handling and aliases.
- `ArticleKeyBuilder` for dedup keys: tracking parameters, AMP markers,
  percent-encoding, dot segments, identity parameters.
- `CurlTransport`, `StreamTransport`, `FakeTransport`, `ArrayCache`,
  `NullCache`, counters and a PSR-3-shaped logger hook.
- `bootstrap.php` for use without Composer.

[Unreleased]: https://github.com/IndianOpinion/gnews-unwrap/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/gnews-unwrap/releases/tag/v0.1.0
