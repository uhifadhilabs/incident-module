# Screens

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
