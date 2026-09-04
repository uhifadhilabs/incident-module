# The model

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
[the report flow](screens.md).
