# Upgrading

What an installation has to do — or knowingly not do — when it takes a new
release of this module. Nothing here is automatic: a release that needed a
hand is a release with a note under it.

## Contents

- [The rule for anything this module ships to a host](#the-rule-for-anything-this-module-ships-to-a-host)
- [0.3.0 — the filter dropdowns became the shell's](#030--the-filter-dropdowns-became-the-shells)
- [0.4.0 — the shared taxonomy is dropped](#040--the-shared-taxonomy-is-dropped)
- [0.4.0 — the PostGIS bundle is `utafitilabs/postgis-bundle` (BREAKING)](#040--the-postgis-bundle-is-utafitilabspostgis-bundle-breaking)
- [0.4.0 — a kind's hue is its place in the list](#040--a-kinds-hue-is-its-place-in-the-list)
- [Queued: 0.5.0 — `incident_taxonomy_kind.colour_key` is dropped](#queued-050--incident_taxonomy_kindcolour_key-is-dropped)

## The rule for anything this module ships to a host

**Nothing a host has in its own tree is removed in one release.** A Stimulus
controller, a config key, a template block an installation may have overridden:
the release that stops using it ships it deprecated and inert, and the release
AFTER that deletes it. The reason is mechanical rather than polite — Flex keeps
a host's own `assets/controllers.json` entry when a package it already has is
updated, so anything this package deletes in one step stays switched on over
there with nothing behind it.

## 0.3.0 — the filter dropdowns became the shell's

The incidents filter bar now uses the shell's grouped dropdown (`.i-dd`), a
`<details>` the browser opens by itself, instead of this module's own
`.i-dd*` rules and the `incident-filters` Stimulus controller that toggled
them. **No step is required of an installation**, and the bar keeps working
either way; two things are worth knowing.

**The dropdowns read slightly differently.** The shell's chosen-option style
colours the label accent where this module tinted the whole row, the trigger
label is clipped at 15 characters, and the caret no longer rotates. That is the
point of the move — one control, one appearance, everywhere in the product —
and it is not something to report as a regression.

**`incident-filters` is deprecated and does nothing.** It is still shipped, now
`"enabled": false` by default, so an installation that already enabled it
cannot break on this update; a fresh install does not enable it at all. **It is
deleted in 0.4.0**, together with its `assets/package.json` entry. An
installation that wants to be done with it now can drop the
`@uhifadhi/incident-module/incident-filters` entry from its own
`assets/controllers.json`; nothing in this module mounts it.

## 0.4.0 — the shared taxonomy is dropped

`Uhifadhi\Incident\Migrations\Version20260919210000` collects the deferral
0.3 opened: `incident.subcategory_id`, `incident_subcategory` and
`incident_category` are dropped, the referencing key and its index first, then
the column, then the key between the two tables, then the tables. It is marked
`@destructive` and runs with `doctrine:migrations:migrate` like anything else.
**No step is required of an installation** — every record has been filed
against its own area's word since 0.3, in
`incident.taxonomy_subcategory_id`, and what goes here is the copy nothing has
read since.

**If you want to keep what the old tables held, dump them before you migrate**
— `pg_dump -t incident_category -t incident_subcategory …` — because a
`down()` brings the tables back empty and no rollback brings the rows back.

**And `doctrine:migrations:diff` goes quiet again.** Until this release it
proposed dropping those three things on every run, because the mapping had
already let them go. If an installation generated that proposal and kept the
file, **delete it**: it plans `DROP TABLE incident_subcategory` while
`incident.subcategory_id` still references the table, PostgreSQL refuses with
*cannot drop table incident_subcategory because other objects depend on it*,
and the failure blocks every later version behind it. This release's version
does the same job in the order the database accepts.

## 0.4.0 — the PostGIS bundle is `utafitilabs/postgis-bundle` (BREAKING)

The spatial types this module's points are stored in now come from
`utafitilabs/postgis-bundle` instead of `fundistadi/postgis-bundle`. The two
register the same DBAL types under the same names, so **no column, no index
and no stored geometry changes, and there is no migration**. What changes is
the class an installation registers and the config key it would configure it
under:

| | before | after |
|---|---|---|
| package | `fundistadi/postgis-bundle` | `utafitilabs/postgis-bundle` |
| namespace | `FundiStadi\PostGISBundle\` | `UtafitiLabs\PostGISBundle\` |
| bundle class | `FundiStadiPostGISBundle` | `UtafitiLabsPostGISBundle` |
| config key | `fundi_stadi_post_gis` | `utafiti_labs_post_gis` |

What an installation does:

```diff
 // config/bundles.php
-FundiStadi\PostGISBundle\FundiStadiPostGISBundle::class => ['all' => true],
+UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle::class => ['all' => true],
```

…then `composer update`, and rename `config/packages/fundi_stadi_post_gis.yaml`
and its root key if the installation wrote one (most have not: the bundle
needs no configuration). **Register only one of the two.** Both declare the
same type names, and Doctrine refuses a type registered twice.

Own code that extends `SpatialEntityRepository` or type-hints a geometry type
changes its `use` line and nothing else — the class names below the namespace
are unchanged.

## 0.4.0 — a kind's hue is its place in the list

**This module declares no colour from this release.** A kind used to carry a
`colourKey` an administrator picked from a dropdown of four, and this module's
stylesheet turned that key into one of five hues it stated itself. Both halves
are gone. A kind now wears the hue its POSITION in the area's list points at —
first kind, first hue — reached through the shell's nine `--cat-1..9` and the
`[data-cat="1".."9"]` rules that resolve them, so the chip, the dot, the donut
arc, the legend square, the matrix row and the map pin are one decision and the
product has one palette.

**No step is required of an installation.** What changes on screen:

- **A kind's hue may move.** It is now read off the order of the kinds, and the
  nine house hues are not the four this module used to state. Reordering the
  kinds in *Incident kinds* moves the hues with them, which is the only control
  there is over which kind wears which.
- **The colour dropdown is gone** from *Incident kinds*, and from the create
  form under it. In its place the kind's swatch is SHOWN, read-only, in the
  manager's head and beside every kind on the `Settings` configure section —
  which can draw it now that the swatch is the shell's `.catsw` rather than
  this module's.
- **`POST …/kinds/{uuid}/colour` (`incident_kinds_kind_colour`) is removed.**
  Nothing in the product posted to it but a form this release deletes; an
  installation that scripted it has nothing to send it.
- **The area overview's two incident layers are states, not categories.**
  `Open` wears `--warn` and `Resolved & closed · 30 days` wears `--ok`, which
  is what those tokens mean everywhere else in the product. The nine house
  hues are for the words an area wrote, and neither of those is one.
- **Money is the accent**, on the money card, the money fold, the claimant role
  and the money chip. The fifth token this sheet used to state had, in both
  themes, the same value as the accent, so nothing moves.
- **`Uhifadhi\Incident\Model\IncidentHues` is deleted.** Where a platform
  contract still takes a colour STRING — the atlas layer's `swatch`, the
  overview's `MapLayer` and `PulseEvent` — this module now hands over the house
  token BY NAME (`var(--cat-3)`), which the host resolves and the shell's plate
  rules repaint for imagery. `Uhifadhi\Incident\Model\HousePalette` is where
  that string is written, once. **It is a workaround and it is flagged**: those
  three fields are `string` colours (`GeoJsonLayer::$swatch`,
  `MapLayer::$swatch`, `PulseEvent::$swatch`), and when they take a category INDEX the
  way `AreaNavChild::$cat` and `ChartSeries::$cat` already do, `HousePalette`
  goes away.
- **The token bridge is gone from `incidents.css`.** The sheet restated a dozen
  of the shell's own aliases in a `:root` block and, loading last, won with
  them — `--shadow` among them, stated once where the shell states two, so the
  light theme wore the dark theme's shadow on every page an incidents screen
  was on. The shell's are now the only copy.

`Uhifadhi\Incident\Migrations\Version20260919230000` drops the `NOT NULL` on
`incident_taxonomy_kind.colour_key`, because nothing writes it any more. **The
values stay**, so an installation can still read what each kind used to be set
to, and a rollback to 0.3 finds them where it left them.

## Queued: 0.5.0 — `incident_taxonomy_kind.colour_key` is dropped

The deferral 0.4 opens, named here so it is collected rather than remembered.
**0.5.0 ships a migration marked `@destructive` whose `up()` is
`ALTER TABLE incident_taxonomy_kind DROP colour_key`**, and its release note
tells an installation to `pg_dump -t incident_taxonomy_kind` first if it wants
to keep what the column held.

Until that version ships, `doctrine:migrations:diff` proposes dropping the
column on every run, because the mapping let it go in 0.4. **That proposal is
the deferral working — do not keep the file it writes.** The drift lock names
`colour_key` as the whole of what may appear in such a proposal
(`MigrationsCoverSchemaTest::RETIRED_UNTIL_DROPPED`), and removing the entry is
what the 0.5.0 migration does alongside the SQL.
