# uhifadhilabs/incident-module

What happened in an area, recorded once: poaching, human–wildlife conflict
(with the fines and compensation that follow), compliance and encroachment, and
wildlife mortality. A [uhifadhi](https://github.com/uhifadhilabs) module bundle.

## Contents

- [Charter](#charter)
- [The model](#the-model)
- [The workflow, and the seam under it](#the-workflow-and-the-seam-under-it)
- [Screens](#screens)
- [Permissions](#permissions)
- [Installation](#installation)
- [Configuration](#configuration)
- [Dev tooling](#dev-tooling)
- [Development](#development)

## Charter

**One record type, many readers.** An incident is a thing that happened in an
area. The same record serves **Protection** (poaching, compliance) and
**Ecology** (mortality, roadkill, human–wildlife conflict outcomes) — not by
copying the data into two modules, but by reading **subsets of one taxonomy**. A
poaching incident and a roadkill incident are the same kind of record with
different classifications.

**Departments are a lens, never a fence.** A department attaching this module
changes what a team *sees first* — it never gates what data exists or who may
read it. Incidents belong to the area; the department view is a reading of them.
Any design that makes a department the owner of a subset of incidents is wrong
for this module, which is why there is deliberately **no `incidents.view`
permission**: a view permission is exactly the tool somebody would eventually use
to hide one department's rows from another.

**Dashboard surfaces ride the host's widget framework.** The module's dashboard
is composed on the HOST's `WidgetService` / `WidgetCatalog` preset component —
the same technique behind the host's departments, team and zones surfaces —
rather than a second widget implementation inside this bundle. This module ships
a catalogue and sixteen Twig partials; it ships no widget mechanics at all.

## The model

An **incident** is one event, in one area, at one place, in one category, at one
point in a five-state workflow.

| Thing | Table | Why it exists |
|---|---|---|
| `Incident` | `incident` | The event. One area, one PostGIS point, one sub-category, one place in the workflow. |
| `IncidentCategory` / `IncidentSubcategory` | `incident_category`, `incident_subcategory` | The taxonomy, as **seeded, configurable data** — four kinds and sixteen sub-categories out of the box. Nothing in the bundle switches on a slug. |
| `IncidentEvent` | `incident_event` | The **append-only** timeline. Nothing on it is ever edited or removed; a correction is a new event saying what was corrected. |
| `IncidentEvidence` | `incident_evidence` | Photographs and documents, each keeping **its own** capture time and position — never the upload's. |
| `IncidentParty` | `incident_party` | A suspect, a claimant, a witness, the ranger who filed it — and the **animal**. One shape, different roles; the design refuses to build four tables. |
| `IncidentMoney` | `incident_money` | Four amounts (claimed, assessed, approved, settled) in **one** direction. |
| `IncidentLink` | `incident_link` | "These two are related" — and a link is a claim, so it carries who made it. |

Three rules are worth stating in prose, because each is a decision somebody will
otherwise re-argue:

**Money runs in two directions and is never added together.** A *fine* is owed
TO the authority; a *compensation claim* is owed BY it. Which direction — if any
— an incident can carry is the **sub-category's** business, which is how roadkill
carries a fine while natural mortality beside it carries nothing.

**No money record is opened at filing.** A sub-category that *carries* money is
one whose form offers the fields; that is not a claim that this incident involves
any. The row appears when somebody records an amount — which is also when the
case file's money card appears, and why a roadkill where no driver was ever
identified can still be resolved rather than waiting forever for a payment nobody
is making.

**Provenance is written once and never edited.** An incident filed from a patrol
observation stays linked to that observation forever
(`Incident::recordProvenance()` refuses a second call). The seam is a UUID, a
label and a URL rather than a foreign key, because the patrols module is a
separate bundle and a host may install either without the other — see
[the report flow](#screens).

## The workflow, and the seam under it

```
reported → verified → in progress → resolved → closed
```

- **Nothing skips verification.** It is not a check; there is simply no
  transition from `reported` to anywhere but `verified`.
- **`resolve` requires the money settled or waived** — where there is money at
  all. An incident whose claim is outstanding is not resolved, it is *unpaid*.
  A waiver passes the guard, because somebody wrote down why.
- **`closed` is reached by TIME, never by a person** — 30 days after resolution.
  It is refused to every actor without exception; the only caller that gets
  through is the clock, and it gets through by not being an actor at all.
- **A community or SMS report is an ordinary `reported` incident wearing a
  SOURCE badge.** There is no sixth place for an untrusted reporter: a place is a
  stage of *work*, and "we have not met this reporter" is a property of the
  *report*.

### The seam

`src/Workflow/IncidentWorkflow.php` **is** the seam for a future platform
workflow module. When one lands, that class is what it replaces, and nothing else
in the bundle has to move — which is only true because of three constraints:

1. **The definition is DATA, expressed there and nowhere else.** No other class
   names a place-to-place move. Templates ask the incident for its status and ask
   `IncidentTransitionService` what is available.
2. **Guards are named** (`IncidentGuardEnum`) **and each answers with a REASON,
   not a boolean.** A workflow engine's guard listener returns a blocked message;
   so does this. The UI needs the sentence anyway — the case file prints it beside
   the moves that *are* allowed.
3. **The marking is a single column** (`incident.status`), which is what Symfony's
   Workflow calls a single-state marking store.

The mapping to a Symfony `state_machine` is one-to-one: places are
`IncidentStatusEnum` values, transitions are `IncidentTransitionEnum` values with
`from`/`to` as declared, the marking store is the `status` property, and
`IncidentWorkflow::guardFor()` becomes two guard listeners.

## Screens

All under `/areas/{uuid}/modules/incidents`, the same shape as patrols.

| Route | Path | What it is |
|---|---|---|
| `incident_dashboard` | `` | The widget surface: this person's own composition of the module's sixteen widgets. |
| `incident_widgets` | `/widgets` | The widget library — the host's preset component over this surface's catalogue. |
| `incident_new` | `/new` | The report flow, as its own page. |
| `incident_create` | `` (POST) | Files it. |
| `incident_show` | `/{reference}` | One case file. |
| `incident_transition` | `/{reference}/transition/{name}` (POST) | Moves it on. Both the case file's buttons and the status board's drag-and-drop post here. |

Plus the eight widget-library write endpoints the host's `WidgetEndpoint`
answers (`/widgets/save`, `/widgets/reset`, `/widgets/preset/{id}`, …).

**The five design directions are PRESETS, not pages.** Incidents was explored as
case files, a map, a live feed, a board of counts and a board of statuses. None
became a separate screen: each is a headed section of the widget catalogue and a
preset that composes it. The composition the module *ships* with is a sixth,
named built-in — the counts, then where, then what, then the money.

**Filing from another module.** The report flow reads a query string, so a module
with something worth filing can send a person to `incident_new` carrying what it
knows, without either bundle naming the other's classes or routes:

```
/areas/{uuid}/modules/incidents/new
    ?source=patrol_observation
    &record=<uuid of the observation>&label=observation 2 of patrol P-0142
    &back=<url of that observation's page>
    &at=2026-08-22T08:15:00+03:00&lat=-3.2014&lng=35.4622
    &category=<sub-category slug it guesses>&note=<the field note, verbatim>
```

Everything there is a guess the filer may overrule — except `record` and `label`,
which become the incident's provenance and are never editable again.

## Permissions

Declared, never granted: the host folds these into its permission catalogue for
admins to assign, and they vanish with the module on uninstall.

| Value | What it guards |
|---|---|
| `incidents.record` | Filing an incident. |
| `incidents.manage` | Moving one through its workflow. |

The split is the design's own economics — *a report is cheap and a verification
is expensive*. The design's IN·R1 card says filing should need no permission of
its own, so a deployment that agrees grants `incidents.record` to everyone who
can reach the module; it exists because a POST that creates a record must be
guarded by something a host can see and assign.

## Installation

```bash
composer require uhifadhilabs/incident-module
```

The bundle registers via Flex (`"type": "symfony-bundle"`), which adds
`UhifadhiLabs\Incident\UhifadhiLabsIncidentBundle` to `config/bundles.php`.

Then, in the host:

1. **Migrate.** The bundle maps its own entities, so no doctrine mappings block
   is needed — just `bin/console doctrine:migrations:diff` and review. It adds
   the seven `incident*` tables above and nothing else; it alters no host table.
2. **Install the taxonomy** — the one step that is not automatic, because it is a
   data decision and a bundle that wrote rows into a host's database on boot would
   be making it for them:

   ```bash
   bin/console incidents:taxonomy:sync
   ```

   Idempotent and non-destructive. Run it again after any change to
   `incident.taxonomy`; a kind of incident that has left the configuration is
   **left alone**, never deleted, because case files are filed against it.
3. **Enable the Stimulus controllers** in `assets/controllers.json` (the recipe
   does this): `incident-map`, `incident-board`, `incident-report`.

The host must already provide what every uhifadhi module bundle binds to:
`Uhifadhi\Entity\{AreaOfInterest,Zone,User,Position,Department}`, the widget
framework (`Uhifadhi\Service\{WidgetService,WidgetEndpoint}` and
`templates/widgets/_library.html.twig`), `layout.html.twig`, self-hosted Leaflet
under `assets/leaflet/`, and symfony/ux-icons with the `lucide` set imported.

### Optional — evidence on the Files hub

Where a host also runs `uhifadhilabs/storage-module` and mounts its cross-module
hub at `/files`, this module puts its evidence on it: `IncidentFileSource` is
tagged `storage.file_source` and hands over one entry per `IncidentEvidence`,
carrying the case file it belongs to (`INC-0313`, linked to its own page), the
incident's area, the handset's `capturedAt` for a photograph, and the record's
caption. Registering the storage bundle is the only step; a host without it runs
every incident screen unchanged and simply has no hub to appear on.

**What the hub is told is only what this module actually knows.** Evidence rows
here are records OF files, not files: `path` is nullable, no row carries a
measured size, a detected type or a generated preview, and the demo seeder writes
rows with no path at all. So a row with no path is not listed (a tile for a key
that names nothing would link at a 404 — the module is simply shown holding
nothing), the size is `0` rather than an invented figure, and a photograph's
small picture reads *waiting*, never *could not be made*: nothing ever tried.
All three fall away when incidents adopts storage-module's upload path.

**The guard is the case file's own answer.** An incident still being worked
answers `Locked` — a claim rests on the evidence. A resolved or closed one
answers `Allowed`. The design's third answer, `Denied` for another department's
upload, is not implemented because `IncidentEvidence` records no uploader; it
arrives with that column, not before it. `FileRemovalInterface` is likewise not
implemented yet, so the hub names the state but offers no control — the safe way
round.

## Configuration

```yaml
# config/packages/incident.yaml
incident:
    module_category: operations   # catalogue category for the module tile
    currency: TZS               # what money on an incident is denominated in
    dev_tools: false            # dev-only tooling; enable via when@dev / when@test

    # OPTIONAL. Omit it and `incidents:taxonomy:sync` installs the four kinds and
    # sixteen sub-categories the module ships with.
    taxonomy:
        poaching:
            label: 'Poaching & wildlife crime'
            colour: poach       # one of: poach, hwc, comp, mort
            leads: ['Protection Service']
            subcategories:
                snaring:
                    label: snaring
                    money: fine         # fine | compensation | ~ (carries none)
                    term_hours: 72
                    fields: ['Species', 'Snares lifted']
```

The tree is closed, so an unknown key fails loudly rather than being ignored. A
configured `taxonomy` **replaces** the shipped one rather than merging with it: a
half-overridden classification scheme is nobody's scheme.

`leads` is **ordering only**. It decides which categories a lens puts first and
has no other power — every department can open every category, and one click on
"Every category" shows the whole register to anybody.

## Dev tooling

```bash
bin/console incidents:seed:demo [area] [--month=2026-08]
```

Seeds the design's sample month — 47 incidents, 31 still open, TZS 8.45M assessed
in fines and 9.2M approved in compensation, across four categories and seven
zones — so a fresh host shows the module working and matches the spec. Idempotent
and non-destructive: every incident is keyed by its reference and one that already
exists is left exactly as it is.

It takes what the host already has: zones are attached **by name** where the area
has one and left null where it does not, and recorders are drawn from existing
accounts. And it walks every incident through the **real** transition service, one
legal move at a time, so it cannot produce a state the product could not.

Registered only where `incident.dev_tools` is on, so production never gets a
command that writes invented incidents.

## Development

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