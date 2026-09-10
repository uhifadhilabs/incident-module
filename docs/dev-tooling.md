# Dev tooling

## Contents

- [The demo month is a declaration, not a command](#the-demo-month-is-a-declaration-not-a-command)
- [What the declaration cannot write yet](#what-the-declaration-cannot-write-yet)
- [The two commands that stay, and why](#the-two-commands-that-stay-and-why)

## The demo month is a declaration, not a command

This module ships no seeding command. It ships an inert declaration —
`src/Devkit/IncidentContentProvider.php`, tagged `uhifadhi.devkit.content_provider`
— and `uhifadhi/devkit-module` is what collects it and turns it into something a
person can run:

```bash
bin/console fixtures:demo
```

devkit installs through `require-dev`, so it is absent from a production build.
That dependency graph is the firewall: nothing collects the declaration there and
it is an ordinary service nobody ever asks anything of. There is no environment
check and no config flag, because there is nothing left for one to gate.

The month it seeds is a table of its own: `src/Devkit/DemoMonth.php`. A demo data
table is devkit's kind of class, not a model — nothing in the product reads it and
no screen renders it — so it sits beside the declaration that consumes it, under
`Devkit/`, and the class-category table files it as **Demo data table ·
`XxxDemoMonth`/`DemoMonth` · `Devkit/`**.

The declaration seeds the design's sample month — 47 incidents across four
categories, walked to the states the register shows, with their parties, their
responders and their photographs — into the first area the installation has, and
it declares `dependsOn(['team'])`, so it is seeded after the people who record
its incidents. An installation with no area files nothing and does not fail:
devkit seeds every module in one run, and one with nothing to hang its records on
must not stop the others.

Everything it writes goes through the doors a person uses — the filing service,
the case service, the money service and the evidence service, one legal move at a
time. Demo content written straight to the tables is demo content that can be
shaped in ways the product cannot produce, and every such row is a bug report
about a screen that is working correctly.

**The sample month ends today.** It is a shape, not a date: pinned to a fixed
month it landed entirely outside the dashboard's default window — the current
month — so a freshly seeded installation opened on "0 filed" and an empty
register with forty-seven incidents just out of view. `DemoMonth::reportedAt()`
spreads the rows back over six weeks ending today, with 28 of the 47 inside the
current calendar month, keeping the order, the funnel, the states and the money
distribution the table declares.

## What the declaration cannot write yet

Three of the four have their door now, and are seeded through it:

| Was not seeded | Seeded through |
|---|---|
| Parties beyond the reporter — the claimant, the witness, the suspect, the animal | `IncidentCaseService::addParty()` |
| Evidence — photographs, with real bytes through the platform's `EvidenceStorage` | `IncidentEvidenceService::attach()` |
| The assignee | `IncidentCaseService::assign()`, on the `respond` transition |

Two things are still not written, and both are findings rather than omissions:

| Not seeded | Why |
|---|---|
| Money on an incident still at `reported` | Money enters at a different place per direction — a claim from `verified`, a fine from `in progress` — and six rows of the table carry an amount while the incident is still only a report, which neither direction allows. Their figures are left out rather than written past the rule. |
| The signed document a money case carries | The platform's default accepted types are images, so a PDF is refused before a key is built. Photographs are seeded with real bytes; the signed form waits on a deployment that accepts one. |

The retired command wrote all of it straight to the entity manager, which kept
the demo looking complete and kept those facts invisible for as long as it kept
working.

The reference is likewise the register's rather than the month's: filing mints
the next one, exactly as it does for a person at the form.

## The two commands that stay, and why

Neither is dev tooling, and both are registered in every environment.

```bash
bin/console incidents:close-due
```

`closed` is reached by TIME, never by a person: an incident closes itself 30 days
after it was resolved. This is the hand that turns — a daily cron
(`0 2 * * *`), or a recurring task on a deployment that installs
`symfony/scheduler`. A workflow whose last step never runs is a workflow that
lies about being finished. It sweeps once and exits, asks the repository only for
rows already due, and then asks the service — the same guard a person's refused
Close hits — so a second run in the same minute closes nothing a first run did
not.

```bash
bin/console incidents:taxonomy:sync
```

The one install step that cannot honestly be automatic: without a taxonomy there
is nothing to file an incident against, and a bundle that wrote rows into a
deployment's database on boot would be making that decision for them. Run it once
on install and again after any change to `incident.taxonomy`. Idempotent and
non-destructive — a kind of incident that has left the configuration is left
alone, never deleted, because case files are filed against it.

It is entangled with a decision that is still open: the module carries
[two taxonomies](the-model.md#two-taxonomies-coexist-for-now), the seeded
org-wide one this command installs and the area-scoped one the admin screen
writes. Converging them is the step that follows, and this command's behaviour is
deliberately unchanged until that is ruled.
