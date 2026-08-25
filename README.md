# uhifadhilabs/incident-module

What happened in an area, recorded once: poaching, human–wildlife conflict
(with the fines and compensation that follow), unauthorized construction and
roadkill. A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

> **Status: infrastructure only.** This repository currently contains the
> bundle, its configuration seam and its module registration — and no domain
> model. See [What is not here yet](#what-is-not-here-yet).

## Contents

- [Charter](#charter)
- [What is not here yet](#what-is-not-here-yet)
- [What is here](#what-is-here)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)

## Charter

**One record type, many readers.** An incident is a thing that happened in an
area. The same record serves **Protection** (poaching, unauthorized
construction) and **Ecology** (roadkill, human–wildlife conflict outcomes) —
not by copying the data into two modules, but by reading **subsets of one
taxonomy**. A poaching incident and a roadkill incident are the same kind of
record with different classifications.

The scope the module answers for:

- **Poaching** — offences against wildlife, and what followed.
- **Human–wildlife conflict** — crop raiding, livestock predation, injury and
  loss of life, together with the **fines and compensation** that attach to
  them. The money is part of the incident, not a separate ledger.
- **Unauthorized construction** — encroachment and unpermitted structures.
- **Roadkill** — vehicle-caused wildlife mortality.

**Departments are a lens, never a fence.** A department attaching this module
changes what a team *sees first* — it never gates what data exists or who may
read it. Incidents belong to the area; the department view is a reading of
them. Any design that makes a department the owner of a subset of incidents is
wrong for this module.

**Dashboard surfaces ride the host's widget framework.** The module's dashboard
is composed on the HOST's `WidgetService` / `WidgetCatalog` preset component —
the same technique behind the host's departments, team and zones surfaces —
rather than a second widget implementation inside this bundle. The module's own
widgets and presets land together with the domain, after the design ruling.

## What is not here yet

**No entities, no repositories, no screens** — deliberately. The incidents UI
design is being produced in parallel, and in this project **the design drives
the data model**: the fields a design needs are the fields that get built, and
nothing is invented ahead of that ruling. Guessing at an incident schema now
would mean either shrinking the design to fit an invented model, or migrating
the model away the week the design lands.

What arrives with the design ruling:

- the incident entity/entities, its taxonomy and its repositories
- the recording and reading screens, and the routes they need
- the module's widgets and presets on the host's widget framework
- the module's declared permissions (declared alongside the routes that check
  them, never before)
- the PostGIS geometry columns and the `fundistadi/postgis-bundle` dependency
  that carries them
- a real database in the test suite and in CI

Until then `IncidentModuleProvider::entryRoute()` returns `null`, so the host
renders the module through its generic module page.

## What is here

| Piece | File |
|---|---|
| The Symfony plug | `src/UhifadhiLabsIncidentBundle.php` |
| Config tree (`incidents:`) | `src/DependencyInjection/IncidentConfiguration.php` |
| Catalogue registration | `src/Module/IncidentModuleProvider.php` |
| Static service wiring (empty, ready) | `config/services.php` |
| Test host app | `tests/Integration/TestKernel.php` |

The bundle maps its own entity directory (`src/Entity`, empty for now), so a
host will never need to write a doctrine mappings block for incident tables.

## Installation

Not yet — the host installs this module once the domain lands. For the record,
the steps will be:

```bash
composer require uhifadhilabs/incident-module
```

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`UhifadhiLabs\Incident\UhifadhiLabsIncidentBundle` to `config/bundles.php`.
No further host wiring is required: entity mapping is prepended by the bundle,
and the module reaches the catalogue through the `uhifadhi.module` tag.

## Configuration

```yaml
# config/packages/incidents.yaml
incidents:
    module_category: pressure   # catalogue category for the module tile
    dev_tools: false            # dev-only tooling; enable via when@dev / when@test
```

Both keys have defaults; the tree is closed, so an unknown key fails loudly
rather than being ignored. The incident taxonomy will be configured here too —
after the design ruling, as deployment vocabulary rather than code.

## Development

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

- PHP 8.4+, PHPStan level **max**, php-cs-fixer `@Symfony` + `@Symfony:risky`.
- **Tests first, always.** The tests in this repo were written before the code
  they cover, and that does not relax when the domain arrives.
- The integration suite boots a real kernel (`tests/Integration/TestKernel.php`)
  and opens no database connection — there is nothing to persist yet.