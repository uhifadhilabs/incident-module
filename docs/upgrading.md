# Upgrading

What an installation has to do — or knowingly not do — when it takes a new
release of this module. Nothing here is automatic: a release that needed a
hand is a release with a note under it.

## Contents

- [The rule for anything this module ships to a host](#the-rule-for-anything-this-module-ships-to-a-host)
- [0.3.0 — the filter dropdowns became the shell's](#030--the-filter-dropdowns-became-the-shells)
- [0.4.0 — the shared taxonomy is dropped](#040--the-shared-taxonomy-is-dropped)

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
