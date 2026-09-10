# Screens

## Contents

- [The routes](#the-routes)
- [Parking closes every one of them](#parking-closes-every-one-of-them)
- [The five directions are presets, not pages](#the-five-directions-are-presets-not-pages)
- [Filing from another module](#filing-from-another-module)
- [The maps](#the-maps)

## The routes

All under `/areas/{uuid}/modules/incidents`, the same shape as patrols.

| Route | Path | What it is |
|---|---|---|
| `incident_dashboard` | `` | The widget surface: this person's own composition of the module's sixteen widgets. |
| `incident_widgets` | `/widgets` | The widget library — the shell's preset component over this surface's catalogue. |
| `incident_new` | `/new` | The report flow, as its own page. |
| `incident_create` | `` (POST) | Files it. |
| `incident_show` | `/{reference}` | One case file. |
| `incident_transition` | `/{reference}/transition/{name}` (POST) | Moves it on. Both the case file's buttons and the status board's drag-and-drop post here. |

Plus the eight widget-library write endpoints the shell's `WidgetEndpoint`
answers (`/widgets/save`, `/widgets/reset`, `/widgets/preset/{id}`, …).

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

The filter row goes in the plate's filter slot, so it is one row above the map
and comes along into fullscreen. The module writes no map JavaScript: the
imagery, the control stack, the legend and fullscreen belong to the atlas.
