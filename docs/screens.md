# Screens

## Contents

- [The routes](#the-routes)
- [The module frame](#the-module-frame)
- [Parking closes every one of them](#parking-closes-every-one-of-them)
- [The five directions are presets, not pages](#the-five-directions-are-presets-not-pages)
- [Filing from another module](#filing-from-another-module)
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
per-kind colour, the "shown first to" departments, and a sub-category's term and
form fields are **not editable there yet** — the section says so, and the kinds
section is where a colour and a money direction are edited today.

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
```

Everything there is a guess the filer may overrule — except `record` and `label`,
which become the incident's provenance and are never editable again.

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
