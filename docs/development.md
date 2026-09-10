# Development

## Contents

- [Running the checks](#running-the-checks)
- [What the suites talk to](#what-the-suites-talk-to)

## Running the checks

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

## What the suites talk to

- PHP 8.4+, PHPStan level **max**, php-cs-fixer `@Symfony` + `@Symfony:risky`.
- The integration and functional suites talk to a **real PostGIS** database
  (`INCIDENTS_TEST_DATABASE_URL`), rebuild the schema per test, and drive the
  screens over HTTP through a test kernel that boots five of the core's own
  bundles — including the shell's widget machinery, so "it rides the shell's
  framework" is demonstrated rather than asserted.
