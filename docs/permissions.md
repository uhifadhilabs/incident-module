# Permissions

## Contents

- [The four concerns this module declares](#the-four-concerns-this-module-declares)
- [The pairs, site by site](#the-pairs-site-by-site)
- [Why the case file and the money are their own concerns](#why-the-case-file-and-the-money-are-their-own-concerns)
- [Why filing and managing are split](#why-filing-and-managing-are-split)
- [Doors](#doors)

## The four concerns this module declares

Declared, never granted. The module contributes rows to the organization's
grants matrix through `Uhifadhi\Contracts\Access\ConcernSourceInterface` (service
`incident.access.concerns`, tagged `uhifadhi.access.concerns` by hand), and they
vanish with the module on uninstall. Installing it hands nobody a new power.

Every one of them names this module, which is how the third question a check
asks — *is the person placed in a department that runs incidents* — is
answerable about them at all. All four offer **organization** or **area** and
not department: an incident happens on ground.

| Concern | Verbs | What it is about |
|---|---|---|
| `incidents` | read · record · manage · delete · export | The register, filing a report, moving a case through verification, response and closure, and taking the month away as a file. |
| `incident-vocabulary` | read · configure | How an area is set up to file: its kinds and sub-categories, the words its questions offer, and what it counts money in. |
| `case-files` **(sensitive)** | read · manage · delete | The narrative as it was taken, the suspects, claimants, informants and witnesses named on the record, and the evidence attached to it. |
| `case-money` **(sensitive)** | read · manage | The fine or the claim, what was assessed and approved, what has been paid, and waiving the rest with a reason. |

There is deliberately **no `own` scope anywhere**. It would mean "the incidents
I filed", and this module's charter forbids anything that lets one reader see a
subset of a register another reader sees in full.

## The pairs, site by site

| Pair | Where |
|---|---|
| `incidents.read` | the dashboard, the register, a case file, the kinds overview, every widget-library route |
| `incidents.record` | `incident_new`, `incident_create` |
| `incidents.manage` | `incident_transition` — and the transition token, which is not minted for somebody the endpoint would refuse |
| `incidents.export` | `incident_export` |
| `incidents.delete` | **declared, enforced by nothing yet.** Nothing in this module destroys an incident; a case is resolved and filed, never deleted. The row is here so an organization can already withhold a power the product may grow, and the first thing that deletes a record gates on it. |
| `incident-vocabulary.read` | the kinds editor and the lists editor, as screens |
| `incident-vocabulary.configure` | every write on either editor, and the Settings section's one POST |
| `case-files.read` | the Evidence, Involved parties and Narrative cards, the parties block's answers, the evidence and money lines of the timeline, and the bytes of a stored file on the Files hub |
| `case-files.manage` | attaching a file to a case |
| `case-files.delete` | taking one back off |
| `case-money.read` | the Money card, the money figures in the record's subline, the money block's answer, and money events on the timeline |
| `case-money.manage` | `incident_money`, `incident_money_waive` |

Every per-area route passes its area — `#[IsGranted('incidents.read', subject:
'area')]` — because a gate asked without the ground is a gate any placement
reaching any area at all walks through. The module's own
`tests/Unit/Access/AccessConformanceTest` (the core's
`AccessConformanceTestCase`) fails the build on one that forgot.

## Why the case file and the money are their own concerns

So that a fact can be withheld **without withholding the page it sits on**.

An incident record opens on `incidents.read`. Its case-file cards and its money
card are drawn only where their own concern is held, and what is withheld is
withheld everywhere on that page — the cards, the subline above them, the
block answers below them and the events on the timeline that would repeat them.
A reader without `case-money.read` opens the same case file everybody else
opens and there is no money on it: not greyed, not a placeholder, absent.

The two facts are separate because organizations treat them separately. An
informant named on a poaching case is endangered by the *reading*, not by the
filing; money attracts a different kind of interest again, and is routinely
held by the few who settle it while the case stays readable by everybody
working it.

## Why filing and managing are split

The split is the design's own economics — *a report is cheap and a verification
is expensive*. The design's IN·R1 card says filing should need no permission of
its own, so a deployment that agrees grants `incidents.record` to everyone who
can reach the module; it exists because a POST that creates a record must be
guarded by something an organization can see and assign.

## Doors

Every link, button and section this module draws that leads somewhere a
permission guards goes through `door('<concern>.<verb>', area)` — never
`is_granted`. That is what lets a test walk them all and hold them against the
routes, and the conformance refuses a template that asks the checker directly.

Two of them ask a second question first. The filing control and the widget
library also depend on whether the writing screens **exist** in this
installation at all (they need SecurityBundle), which is the `recordScreens`
flag — a fact about the installation, not about the viewer.
