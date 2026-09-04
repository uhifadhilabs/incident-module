# Development

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

- PHP 8.4+, PHPStan level **max**, php-cs-fixer `@Symfony` + `@Symfony:risky`.
- The integration and functional suites talk to a **real PostGIS** database
  (`INCIDENTS_TEST_DATABASE_URL`), rebuild the schema per test, and drive the
  screens over HTTP through a test kernel that plays the host — including the
  host's own widget framework, so "it rides the host framework" is demonstrated
  rather than asserted.
