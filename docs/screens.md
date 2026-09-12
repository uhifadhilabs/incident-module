# Screens

## Contents

- [The routes](#the-routes)
- [The module frame](#the-module-frame)
- [Parking closes every one of them](#parking-closes-every-one-of-them)
- [The five directions are presets, not pages](#the-five-directions-are-presets-not-pages)
- [Filing from another module](#filing-from-another-module)
- [The rail beside the report form](#the-rail-beside-the-report-form)
- [The maps](#the-maps)

## The routes

All under `/areas/{uuid}/modules/incidents`, the same shape as patrols.

| Route | Path | What it is |
|---|---|---|
| `incident_dashboard` | `` | The Overview tab: this person's own composition of the module's widgets. |
| `incident_list` | `/incidents` | The Incidents tab: every incident the window holds, twenty a page, driven by the one filter. |
| `incident_widgets` | `/widgets` | The widget library — the first section of the configure page, on an address of its own. |
| `incident_kinds` | `/kinds` | The incident kinds — the second section of the configure page, on an address of its own. `/taxonomy` permanently redirects here. |
| `incident_settings_save` | `/configure/settings` (POST) | Saves the module's per-area settings. |
| `incident_new` | `/new` | The report flow, as its own page. |
| `incident_create` | `` (POST) | Files it. |
| `incident_show` | `/{reference}` | One case file. |
| `incident_transition` | `/{reference}/transition/{name}` (POST) | Moves it on. Both the case file's buttons and the status board's drag-and-drop post here. |

Plus the eight widget-library write endpoints the shell's `WidgetEndpoint`
answers (`/widgets/save`, `/widgets/reset`, `/widgets/preset/{id}`, …).

## The module frame

This module draws no navigation of its own. It declares two lists and the shell
draws both:

- **Two data tabs** — `Overview` and `Incidents` — through `ModuleTabsInterface`
  (`Uhifadhi\Incident\Shell\IncidentModuleTabs`). A tab is a place where DATA
  lives; the case file keeps the `Incidents` tab lit, because opening a case does
  not leave the place cases live in.
- **Three configure sections** — `Widget library`, `Incident kinds`, `Settings` —
  through `ConfigurationSectionsInterface`
  (`Uhifadhi\Incident\Shell\IncidentConfigurationSections`). The first two keep
  an address of their own, exactly as the settled design draws them; `Settings` is
  a body the shell renders inside its own configure page, at the bare
  `/configure` address.

There is one configuration entry per surface — the shell's `Configure` action —
and no `Settings`, `Incident kinds` or `Widget library` button anywhere else, and
no "Back to dashboard": the first data tab, the lit `Configure` and the crumb are
the three ways back.

The `Settings` section reads and writes one row per area (`incident_settings`).
An area that has never saved counts money in the installation's own `incident:`
currency, so an untouched default and a chosen one stay distinguishable. The
per-kind colour, the "shown first to" departments and a sub-category's term are
**not editable there yet** — the section says so, and the kinds section is where a
colour, a money direction, the behaviour blocks and the term are edited today.

## Parking closes every one of them

**Where an area is not running this module, every page above answers 404.** The
module writes no check for it and cannot forget it: RegistryBundle owns the
per-area ledger, so RegistryBundle enforces it, in one `kernel.request` listener
that runs after the router and before any controller. It is 404 rather than 403
because a parked module is not withheld — the area is simply not running it,
which is what the area's own screens already say with the module sitting in the
shop rather than the sub-nav.

Each controller carries one class-level default naming the module its routes
belong to:

```php
#[Route(defaults: [RegistryBundle::MODULE_ROUTE_DEFAULT => IncidentModuleProvider::SLUG])]
```

Without it a route is not exempt, it is **guessed at**: the gate falls back to
reading `/areas/{uuid}/modules/{slug}/…` and matching the segment against the
catalogue, which happens to land here only because the segment and the slug are
both `incidents`. That is an accident of naming, and it would end the moment a
path moved.

The area's uuid rides in a parameter called `uuid`, which is the gate's own
default, so no `_uhifadhi_module_area` is stated.

## The five directions are presets, not pages

**The five design directions are PRESETS, not pages.** Incidents was explored as
case files, a map, a live feed, a board of counts and a board of statuses. None
became a separate screen: each is a headed section of the widget catalogue and a
preset that composes it. The composition the module *ships* with is a sixth,
named built-in — the counts, then where, then what, then the money.

## Filing from another module

**Filing from another module.** The report flow reads a query string, so a module
with something worth filing can send a person to `incident_new` carrying what it
knows, without either bundle naming the other's classes or routes:

```
/areas/{uuid}/modules/incidents/new
    ?source=patrol_observation
    &record=<uuid of the observation>&label=observation 2 of patrol P-0142
    &back=<url of that observation's page>
    &at=2026-08-22T08:15:00+03:00&lat=-3.2014&lng=-29.5378
    &category=<sub-category slug it guesses>&note=<the field note, verbatim>
    &patrol=P-0142 · foot patrol · North Block&ranger=<who recorded it>
```

Everything there is a guess the filer may overrule — except `record` and `label`,
which become the incident's provenance and are never editable again.

`patrol` and `ranger` are **prose the sending module composes**, not identifiers to
resolve, and they exist for the rail: the record's own card states which patrol and
whose eyes, and neither bundle may name the other's classes to find that out. A
module that has neither — or one that predates the two parameters — sends neither,
and the rail draws no row for what it was not told.

**A richer record-summary contract is the obvious next step, and is not this
module's to invent.** Today the rail knows a record through three separate
channels: a query string (the label, the words, the position, the patrol, the
observer), the platform's file registry (the photographs), and nothing at all (the
record's amendment history, which the design's card shows and this one cannot).
A contract that let an asking module say *"describe record X"* and get back a
titled list of facts would replace the first and the third at once. It belongs
beside `FileSourceInterface`, in the package that owns cross-module record access —
not here.

## The rail beside the report form

The report page is two columns inside one `.i-rwrap`: the form at 860px, and a
340px rail 18px to its right. **One page scroller** — neither column is a scroll
region of its own, so there is one scrollbar to find and nothing in the rail can be
stranded beside a form that has grown long. Below 1160px the two columns cannot
both stand, the rail stacks under the form, and the form keeps its width: the rail
is extra help, never a reason for the form to get narrower.

| Card | When | What is on it |
|---|---|---|
| **The observation** | only where the filing carries a source record | its own words, quoted; an atlas plate of where it was recorded; its photographs at the rail's width; then the record itself — patrol, observer, time, place, source |
| **What `<word>` asks** | always | one row per answer the filing owes, a progress line, and the blocks the word left off named as absent |

**The rail informs; it never gates.** The marks on the checklist are **the gate's**:
every row carries the missing-answer label the gate uses on
`data-incident-report-need`, and the Stimulus controller marks the rows from the
very list it has just written into the footer. There is no second computation of
"is this answered" anywhere, because the day two of them disagreed the rail would
be telling somebody they may file while the File control refused to.
`tests/Unit/Template/ReportRailSeamTest` holds both sides of that seam to the same
strings.

**One card per sub-category, all but the chosen one hidden** — the arrangement
step 2 already uses for its field sets, and for the same reason: choosing a word
swaps the questions without a round trip, and the card that names the word has to
swap with them. `Model/ReportChecklist` builds each card from the very
`BlockQuestionSet` list the form renders step 2 from, so the rail cannot describe a
form this page did not draw.

**The three shared answers are rows too.** The gate holds a filing on the category,
the line and the place exactly as it holds it on a block, so the rail counts all
three: a progress line that left one out would disagree with the footer by one.
(The design workspace's mock shows two, because its gate has no place requirement;
this page's does, and the rail follows the page.)

**Where the observation was recorded is the atlas's plate**, built by
`IncidentMapService::forObservation()` — the same service that builds the
dashboard's and the case file's, so "where" reads identically on the observation's
own page, in this rail, and on the case file the filing becomes. One mark under its
own legend heading and never a category's: nothing has been filed yet, and drawing
it in a category's hue would claim a classification the filer has not made.

**Two things the rail does not draw, and why.** The design's card shows a
`filename · time · offset` caption under each photograph and an amendment-history
row; the hand-off carries neither an offset from the pin nor a history, so the
caption is `filename · time` and there is no history row. Both are waiting on the
record-summary contract above.

**A short plate should put its legend below the imagery, and the atlas does not yet
do it.** The house map contract puts a legend below the plate; the atlas floats it
over the bottom-right corner, which is right on a plate that is the page's subject
and wrong on a 176px one, where it covers the ground it is describing. **This is
not an incidents defect and must not be fixed with an incidents rule** — every
module's thumbnail plate has it, and a module that restyled the plate's internals
would be a module whose map read differently from every other one
(`tests/Unit/Template/CaseFilePlateSizingTest` fails the build for exactly that).
The fix is one rule in the atlas's own sheet, keyed off the plate's height or off a
`legend: below` option on `render_map()`. **Open, and needs the core's ruling.**

## The maps

Three screens draw one: the `map` widget, the `maplist` widget and the case
file's **Where** card. All three are the atlas's plate, stated by
`Service/IncidentMapService` and rendered with `render_map()`.

| What is on it | How it is stated |
|---|---|
| one layer per category, in that category's hue | `GeoJsonLayer`, `shape: LayerShape::Point`, `swatch: IncidentHues::of(...)` |
| the area's zones, quiet, wearing their names | `GeoJsonLayer`, `shape: LayerShape::Line`, a `label` on each feature |
| the area boundary and its scrim | `Boundary` |
| the legend, one switching row per layer | the layers' own rows, under one group |
| filled = open · hollow = resolved or closed | `StyleRule::when('open', false)->fillOpacity(0.0)` |
| a dashed ring at the serious end | `StyleRule::when('severity', ['high', 'critical'])->…->dashArray('3 3')` |
| what a mark says on hover | the layer's `tooltip: 'summary'`, a property `IncidentMapService::featuresFor()` composes |
| what a click opens | `FeaturePopup(title: 'title', lines: [...], href: 'href', linkLabel: 'Open the case file →')` |
| a hit in the docked list spotlighting its own mark | `data-atlas-highlight="<layer>:<reference>"` on the row, `featureId: 'reference'` on the layer |
| how tall the map is on each screen | `--map-plate-height`, set on the card in `incidents.css` |

**One service builds every mark.** The dashboard's plate, the map+results plate,
the case file's Where card and the two layers this module puts on the area
overview all get their features from `IncidentMapService::featuresFor()`. There
is no second builder, because the map-legend contract says the same layer renders
identically everywhere it is drawn, and the only way to guarantee that is for
there to be one place a mark is described.

**What a mark means is stated, not drawn.** Hue is the category, filled is still
open, hollow is resolved or closed, and a dashed ring is the serious end — high
or critical, never high alone. All four are the legend's own promise, and all
four are `LayerStyle`/`StyleRule` statements the atlas evaluates per feature. The
tooltip line and the case-file url travel as feature properties; the atlas reads
the property, writes the markup and escapes the value, so nothing this module
produces is ever rendered HTML on a map.

**How tall a plate is, is a number and nothing else.** A plate carries a real
height off `--map-plate-height` and refuses to stretch to its row; this module
sets the number per screen (460px on the map and map+results widgets, the design's
`min(46vh,440px)` on the case file's Where card) and states not one word about the
plate's own layout.

The filter row goes in the plate's filter slot, so it is one row above the map
and comes along into fullscreen. The module writes no map JavaScript: the
imagery, the control stack, the legend and fullscreen belong to the atlas.
