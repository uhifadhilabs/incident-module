# uhifadhi/incident-module

What happened in an area, recorded once: poaching, human–wildlife conflict
(with the fines and compensation that follow), compliance and encroachment, and
wildlife mortality. A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## What it is

An **incident** is one event, in one area, at one place, in one category, at one
point in a five-state workflow — `reported → verified → in progress → resolved →
closed`. One record type serves every reader: Protection and Ecology read
subsets of one taxonomy rather than each keeping their own copy.

The module ships seven `incident*` tables, the report flow, the case file, a
sixteen-widget dashboard surface composed on uhifadhi/widget-module, and a
seeded, configurable taxonomy of four kinds and sixteen sub-categories.

## Installation

```bash
composer require uhifadhi/incident-module
```

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`Uhifadhi\Incident\UhifadhiIncidentBundle` to `config/bundles.php`.

## Getting started

Then, in the host:

1. **Answer the user contract.** Five columns name a person — who reported the
   incident, who it is assigned to, who acted on the event, who linked it to
   another, and the team member behind a party to it — and none of them names an
   account class. They are mapped to
   `Uhifadhi\ModuleContracts\Entity\UserInterface`, and the installation resolves
   that interface to whatever it calls its people. Install
   `uhifadhi/team-module` and the answer arrives with it (0.3.2 and later states
   the resolution from its own bundle); otherwise write one line naming your own
   class, under the `orm:` key already in `config/packages/doctrine.yaml`:

   ```yaml
   doctrine:
       orm:
           resolve_target_entities:
               Uhifadhi\ModuleContracts\Entity\UserInterface: App\Entity\Person
   ```

   Until something answers it, the bundle installs and the kernel boots, but
   anything that walks the metadata — including the `diff` below — stops on the
   unresolved interface. Deleting an account later sets those five columns null
   and leaves the incidents standing, which is why each of those records keeps
   the person's name beside the relation.
2. **Migrate.** The bundle maps its own entities, so no doctrine mappings block
   is needed — just `bin/console doctrine:migrations:diff` and review. It adds
   seven `incident*` tables and nothing else; it alters no host table.
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

Everything this module binds to now arrives as a module of its own, and composer
installs all of them: the area an incident happens in and its zones from
`uhifadhi/area-module`, the dashboard framework from `uhifadhi/widget-module`,
Leaflet and the map seam from `uhifadhi/map-module`, and the two seam contracts
from `uhifadhi/module-contracts`. Three more are suggestions rather than
requirements: `uhifadhi/shell-module` is the page frame every screen renders in,
`uhifadhi/seam-module` is the per-area catalogue this module registers itself in,
and `uhifadhi/storage-module` puts an incident's evidence on the Files hub.

The one thing an installation still provides is the ACCOUNT CLASS behind the
person contract — see the user contract above. `uhifadhi/team-module` answers it
from its own bundle; an installation with an account class of its own names it in
one line of `resolve_target_entities`. symfony/ux-icons with the `lucide` set
imported is the other standing expectation.

## Learn more

- [Charter](docs/charter.md) — one record type and many readers, why departments
  are a lens and never a fence, and why the dashboard rides the host's framework.
- [The model](docs/the-model.md) — the seven tables, and the three rules about
  money, filing and provenance that somebody will otherwise re-argue.
- [The workflow, and the seam under it](docs/workflow.md) — the five places,
  their guards, and how `IncidentWorkflow` maps one-to-one onto a Symfony
  `state_machine`.
- [Screens](docs/screens.md) — the routes, why the five design directions are
  presets rather than pages, and the query string another module files with.
- [Permissions](docs/permissions.md) — the two declared permissions and the
  sentences the host's matrix prints under them.
- [Configuration](docs/configuration.md) — `config/packages/incident.yaml`, the
  taxonomy tree, and what `leads` does and does not decide.
- [Evidence on the Files hub](docs/files-hub.md) — the optional
  `uhifadhi/storage-module` seam, and what this module honestly knows about a file.
- [Dev tooling](docs/dev-tooling.md) — `incidents:seed:demo`, the design's sample
  month, and why it is registered only where `incident.dev_tools` is on.
- [Development](docs/development.md) — `composer check`, the tooling levels, and
  the real-PostGIS test suites.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi host this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
