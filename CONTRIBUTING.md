# Contributing

Thanks for helping. This is a small library with a narrow job; changes that
keep it small, predictable and well tested are the ones that land.

## Setup

```sh
git clone https://github.com/IndianOpinion/gnews-unwrap.git
cd gnews-unwrap
composer install
```

PHP 8.1 or newer. `ext-curl` and `ext-intl` are optional but the test suite
covers more with them.

## Tests and checks

```sh
composer check      # all three below
composer cs         # phpcs, PSR-12
composer stan       # phpstan, level 8
composer test       # phpunit
```

- Unit tests never touch the network. Use `FakeTransport` and fixtures in
  `tests/fixtures/`. Recorded pages must be cut down to the markup the parser
  needs and use example publisher URLs (`example.com`, `example.co.uk`).
- Integration tests start PHP's built-in server on 127.0.0.1.
- The live smoke test is opt-in: `GNEWS_UNWRAP_LIVE=1 vendor/bin/phpunit --group live`.
  It is excluded from CI. Do not add other live tests.

## Style

- PSR-12, `declare(strict_types=1);` in every file.
- No runtime dependencies. No `eval`, no shell, no `@` error suppression, no
  `unserialize`.
- Bad input never throws: it becomes a `Result` with a `Reason`. Only
  programming errors throw, and only exceptions implementing
  `GnewsUnwrapException`.
- Documentation: short sentences, plain words, British spelling.

## When Google changes something

The batchexecute method lives in `src/BatchExecute.php`. If it breaks:

1. Capture the new page or response, cut it down, replace publisher URLs with
   example ones, and add it under `tests/fixtures/batch/`.
2. Write a failing test, then fix the parser.
3. Note the change and the date in `CHANGELOG.md`.

## Pull requests

- One change per pull request, with tests.
- Update `README.md`, `docs/ARCHITECTURE.md` and `CHANGELOG.md` when
  behaviour or configuration changes.
- CI must be green on PHP 8.1, 8.2 and 8.3.
- A DCO-style sign-off is not required.
