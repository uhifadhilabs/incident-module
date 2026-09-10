# The model

## Contents

- [The ten tables](#the-ten-tables)
- [Money runs in two directions](#money-runs-in-two-directions)
- [No money record is opened at filing](#no-money-record-is-opened-at-filing)
- [Each direction is recorded from its own place](#each-direction-is-recorded-from-its-own-place)
- [Two taxonomies coexist, for now](#two-taxonomies-coexist-for-now)
- [How this module references areas](#how-this-module-references-areas)
- [Provenance is written once](#provenance-is-written-once)

## The ten tables

An **incident** is one event, in one area, at one place, in one category, at one
point in a five-state workflow.

| Thing | Table | Why it exists |
|---|---|---|
| `Incident` | `incident` | The event. One area, one PostGIS point, one sub-category, one place in the workflow. |
| `IncidentCategory` / `IncidentSubcategory` | `incident_category`, `incident_subcategory` | The taxonomy, as **seeded, configurable data** — four kinds and sixteen sub-categories out of the box. Nothing in the bundle switches on a slug. |
| `TaxonomyKind` / `TaxonomySubcategory` | `incident_taxonomy_kind`, `incident_taxonomy_subcategory` | The **area-scoped** taxonomy the admin screen writes (design option B): each area owns its own kinds and sub-categories, starts **empty** (no seed), composes **behaviour blocks** per sub-category, keeps per-area **wire-codes**, and **deactivates, never deletes**. Coexists with the seeded taxonomy above while the platform converges on one — see the note below. |
| `IncidentEvent` | `incident_event` | The **append-only** timeline. Nothing on it is ever edited or removed; a correction is a new event saying what was corrected. |
| `IncidentEvidence` | `incident_evidence` | Photographs and documents, each keeping **its own** capture time and position — never the upload's. |
| `IncidentParty` | `incident_party` | A suspect, a claimant, a witness, the ranger who filed it — and the **animal**. One shape, different roles; the design refuses to build four tables. |
| `IncidentMoney` | `incident_money` | Four amounts (claimed, assessed, approved, settled) in **one** direction. |
| `IncidentLink` | `incident_link` | "These two are related" — and a link is a claim, so it carries who made it. |

Three rules are worth stating in prose, because each is a decision somebody will
otherwise re-argue:

## Money runs in two directions

**Money runs in two directions and is never added together.** A *fine* is owed
TO the authority; a *compensation claim* is owed BY it. Which direction — if any
— an incident can carry is the **sub-category's** business, which is how roadkill
carries a fine while natural mortality beside it carries nothing.

## No money record is opened at filing

**No money record is opened at filing.** A sub-category that *carries* money is
one whose form offers the fields; that is not a claim that this incident involves
any. The row appears when somebody records an amount — which is also when the
case file's money card appears, and why a roadkill where no driver was ever
identified can still be resolved rather than waiting forever for a payment nobody
is making.

## Each direction is recorded from its own place

**A compensation claim is recorded from `verified`; a fine only from
`in progress`.** The two directions are not the same kind of act, so they do not
wait for the same thing. A claim is somebody else's statement — a household asks
for compensation the moment the authority agrees the thing happened, and a
product that would not write it down until a responder had been assigned would be
losing a claim it has already been handed. A fine is the authority's own act:
assessing a penalty is enforcement, which is the work `in progress` names, and
fining somebody while the report is still only a report would be fining them on
the strength of an allegation.

`MoneyDirectionEnum::recordableFrom()` holds both answers, and it is the single
source the case file's money panel is gated on and the money service refuses on —
so the panel is never drawn where a POST would be refused. Each direction also
refuses in its own words (`refusedTooEarly()`): a claimant told "response has not
started" would be told the wrong rule.

## Two taxonomies coexist, for now

**Two taxonomies coexist, for now.** The seeded org-wide `IncidentCategory`
tree is what filed incidents currently point at; the new area-scoped
`TaxonomyKind` tree backs the area taxonomy admin (`/areas/{uuid}/modules/
incidents/taxonomy`, `incidents.manage`). They do not yet share storage — filing
against the area-scoped taxonomy, and retiring the seeded one, is the convergence
step that follows this slice. The admin's "copy from another area" gesture is
deliberately deferred: it needs to enumerate areas and read their names — which
`Uhifadhi\Contracts\Entity\AreaInterface` now exposes (`getName`,
`getUuidString`, and enumeration through the ORM against the interface; see
[the core's `area-contract.md`](https://github.com/uhifadhilabs/uhifadhi/blob/main/src/Uhifadhi/Contracts/docs/area-contract.md)) —
but the gesture itself is not yet ruled, and the empty-state template marks the
contract.

## How this module references areas

**How this module references areas.** `Incident::$area` and the area-scoped
`TaxonomyKind` are mapped to the concrete `Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest`,
not to the `AreaInterface` contract. The contract lets a module point at an area
*without* requiring AreaBundle — the way TeamBundle's `Department` does — but
this module already hard-requires AreaBundle for its PostGIS points, zones and
overview contributions, so the concrete class costs it nothing it was avoiding and keeps
the area's own accessors in hand. Loose coupling through the interface is the
right call for a module that has no other reason to depend on AreaBundle; that is
not this one.

## Provenance is written once

**Provenance is written once and never edited.** An incident filed from a patrol
observation stays linked to that observation forever
(`Incident::recordProvenance()` refuses a second call). The hand-off is a UUID, a
label and a URL rather than a foreign key, because the patrols module is a
separate bundle and a host may install either without the other — see
[the report flow](screens.md).
