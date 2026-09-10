# uhifadhi/incident-module

What happened in an area, recorded once: poaching, human–wildlife conflict
(with the fines and compensation that follow), compliance and encroachment, and
wildlife mortality. A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [What it is](#what-it-is)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Upgrading](#upgrading)
- [Learn more](#learn-more)
- [License](#license)

## What it is

An **incident** is one event, in one area, at one place, in one category, at one
point in a five-state workflow — `reported → verified → in progress → resolved →
closed`. One record type serves every reader: Protection and Ecology read
subsets of one taxonomy rather than each keeping their own copy.

The module ships ten `incident*` tables, the report flow, the case file, a
sixteen-widget dashboard surface composed on the shell's widget machinery, and a
seeded, configurable taxonomy of four kinds and sixteen sub-categories.

## Installation

```bash
composer require uhifadhi/incident-module
```

Neither this package nor the core it requires is on Packagist yet, and neither
carries a stable tag, so an installation names where both come from. Composer
reads `repositories` from the ROOT package only — an entry in a dependency's own
`composer.json` is ignored — so these lines belong in the application's:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/uhifadhilabs/uhifadhi" },
    { "type": "vcs", "url": "https://github.com/uhifadhilabs/incident-module" },
    { "type": "vcs", "url": "https://github.com/uhifadhilabs/storage-module" }
]
```

The third line is needed only where the installation also wants incident
evidence on the Files hub; the second can go once this package is published.

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`Uhifadhi\Incident\UhifadhiIncidentBundle` to `config/bundles.php`.

## Getting started

Then, in the host:

1. **Answer the user contract.** Five columns name a person — who reported the
   incident, who it is assigned to, who acted on the event, who linked it to
   another, and the team member behind a party to it — and none of them names an
   account class. They are mapped to
   `Uhifadhi\Contracts\Entity\UserInterface`, and the installation resolves
   that interface to whatever it calls its people. Install
   the core (`uhifadhi/uhifadhi`) and the answer arrives with it — TeamBundle
   states the resolution from its own bundle; otherwise write one line naming
   your own class, under the `orm:` key already in
   `config/packages/doctrine.yaml`:

   ```yaml
   doctrine:
       orm:
           resolve_target_entities:
               Uhifadhi\Contracts\Entity\UserInterface: App\Entity\Person
   ```

   Until something answers it, the bundle installs and the kernel boots, but
   anything that walks the metadata stops on the unresolved interface. Deleting
   an account later sets those five columns null and leaves the incidents
   standing, which is why each of those records keeps the person's name beside
   the relation.
2. **Migrate.**

   ```bash
   bin/console doctrine:migrations:migrate
   ```

   That is the whole step. This module ships the statements that create its
   tables, so there is no mappings block to write and nothing to generate:
   `doctrine:migrations:diff` is what an installation runs for the entities IT
   owns, and after installing or updating this package it must report no
   changes. The versions add ten `incident*` tables and nothing else; they alter
   no host table, and the foreign keys into `area_of_interest`, `zone` and
   `team_user` are declared here rather than in the core.
3. **Install the taxonomy** — the one step that is not automatic, because it is a
   data decision and a bundle that wrote rows into a host's database on boot would
   be making it for them:

   ```bash
   bin/console incidents:taxonomy:sync
   ```

   Idempotent and non-destructive. Run it again after any change to
   `incident.taxonomy`; a kind of incident that has left the configuration is
   **left alone**, never deleted, because case files are filed against it.
The three Stimulus controllers — `incident-map`, `incident-board`,
`incident-report` — need no step of their own: Flex synchronises
`assets/controllers.json` from this package's own `assets/package.json` on every
`composer require`/`update`, because the package declares the `symfony-ux`
keyword.

Everything this module binds to arrives in ONE package, `uhifadhi/uhifadhi` —
the core, whose five bundles are what these screens stand on: AreaBundle for the
area an incident happens in and its zones, ShellBundle for the page frame and
the widget machinery the dashboard is, AtlasBundle for Leaflet and the map
chrome, RegistryBundle for the per-area catalogue this module registers itself
in, and TeamBundle for the account class. The contracts it implements ship
inside it. One further package is required: `uhifadhi/storage-module` stores
the photographs an incident is filed with and puts them on the Files hub.

The one thing an installation still provides is the ACCOUNT CLASS behind the
person contract — see the user contract above. TeamBundle answers it from its
own bundle; an installation with an account class of its own names it in one
line of `resolve_target_entities`.

**Icons need nothing imported.** This module registers its own set and draws
under two prefixes only: `incident:`, answered by the glyphs it ships in
`assets/icons/incident`, and `shell:`, answered by the core. No `lucide:` name is
drawn from here, so a deployment with on-demand fetching off — which is what a
deployment configures — renders every mark on these pages.

## Upgrading

```bash
composer update uhifadhi/incident-module
bin/console doctrine:migrations:migrate
```

Again, `migrate` is the whole of it. New tables and columns arrive as versions
in this package; `doctrine:migrations:diff` stays what you run for your own
entities, and after this update it must report no changes. If it does report
something, that is a bug in this package — please report it rather than
committing the version it wrote.

Before a production run:

```bash
# 1. Back up. Nothing below is a substitute for this.
pg_dump …

# 2. Read what will run, without running it.
bin/console doctrine:migrations:migrate --dry-run
```

Two hatches, for the two ways this goes wrong:

- **An installation that already has the `incident*` tables** — created by a
  `diff` written before this package shipped its own versions — must tell the
  version log they are there, or the first version will try to create them
  again:

  ```bash
  bin/console doctrine:migrations:version \
      'Uhifadhi\Incident\Migrations\Version20260910045214' --add
  ```

  That marks the version executed without running it. Check the table list in
  `docs/the-model.md` against your database first.

- **A deployment that applies SQL by hand** — a reviewed change window, a
  database somebody else administers — takes the statements instead of the run:

  ```bash
  bin/console doctrine:migrations:migrate --write-sql=incident-upgrade.sql
  ```

## Learn more

- [Charter](docs/charter.md) — one record type and many readers, why departments
  are a lens and never a fence, and why the dashboard rides the shell's framework.
- [The model](docs/the-model.md) — the ten tables, and the three rules about
  money, filing and provenance that somebody will otherwise re-argue.
- [The workflow, and the definition under it](docs/workflow.md) — the five places,
  their guards, and how `IncidentWorkflow` maps one-to-one onto a Symfony
  `state_machine`.
- [Screens](docs/screens.md) — the routes, why the five design directions are
  presets rather than pages, and the query string another module files with.
- [Permissions](docs/permissions.md) — the two declared permissions and the
  sentences the permission matrix prints under them.
- [Configuration](docs/configuration.md) — `config/packages/incident.yaml`, the
  taxonomy tree, and what `leads` does and does not decide.
- [Evidence on the Files hub](docs/files-hub.md) — the
  `uhifadhi/storage-module` contract, and what this module honestly knows about a file.
- [Dev tooling](docs/dev-tooling.md) — the demo month this module declares for
  devkit to seed, the two commands that stay, and what the declaration cannot
  write yet.
- [Development](docs/development.md) — `composer check`, the tooling levels, and
  the real-PostGIS test suites.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
