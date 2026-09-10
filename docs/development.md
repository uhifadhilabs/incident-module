# Development

## Contents

- [Running the checks](#running-the-checks)
- [What the suites talk to](#what-the-suites-talk-to)
- [Writing a migration](#writing-a-migration)

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
- The four locks under `tests/Integration/Migrations` are the exception: they
  never touch SchemaTool. They empty the database, run the shipped versions, and
  assert on what is left.

## Writing a migration

This module owns its tables, so it ships the statements that create and change
them, under `migrations/` in the namespace `Uhifadhi\Incident\Migrations`. An
installation runs `doctrine:migrations:migrate` and writes no version for us.

**Every entity change lands with its migration in the same commit.** Generate it
against a database that has already been migrated, and always name the namespace
— without `--namespace` the command has to guess which of the configured
namespaces the new version belongs in:

```bash
bin/console doctrine:migrations:diff --namespace='Uhifadhi\Incident\Migrations'
```

Then read the SQL. A generated version is a draft: give it a description, say in
the docblock what the change is for, and check it against the three rules below.
Once a version is released it is never edited — a mistake ships as a corrective
version.

**One. Expand, backfill, contract — in one version.** A required column on a
table that already has rows is added nullable, filled for the rows already there,
then tightened. One version, so a failure leaves the table as it was and nobody
edits a vendor file:

```php
$this->addSql('ALTER TABLE incident ADD district VARCHAR(64) DEFAULT NULL');
$this->addSql("UPDATE incident SET district = 'unassigned' WHERE district IS NULL");
$this->addSql('ALTER TABLE incident ALTER district SET NOT NULL');
```

**Two. A column nobody can backfill ships nullable, and validation requires it.**
There is no correct value for a fact an installation has not recorded yet, so the
database stays permissive and the rule lives in the form. A later release may
tighten the column, once every installation has been through a period where the
value gets written.

**Three. A destructive statement rides a LATER release than the code that stopped
using what it drops**, and the docblock says which:

```php
/**
 * @destructive 0.5 — incident.legacy_grid stopped being read in 0.4
 */
```

`down()` is exempt from rule three by nature: undoing a `CREATE TABLE` is a
`DROP TABLE`. It still has to be real — a version that plans nothing on the way
down cannot be rehearsed, and the rehearsal is what an installation gets before
it upgrades for real.

`tests/Integration/Migrations/MigrationLint` reads all three off the SQL each
version plans, and three fixture versions under
`tests/Integration/Migrations/Fixtures/migrations` break one rule each — so a
lint that passes everything shipped has been seen failing something.
