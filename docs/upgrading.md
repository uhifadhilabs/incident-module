# Upgrading

What an installation has to do — or knowingly not do — when it takes a new
release of this module. Nothing here is automatic: a release that needed a
hand is a release with a note under it.

## Contents

- [The rule for anything this module ships to a host](#the-rule-for-anything-this-module-ships-to-a-host)
- [0.3.0 — the filter dropdowns became the shell's](#030--the-filter-dropdowns-became-the-shells)

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
