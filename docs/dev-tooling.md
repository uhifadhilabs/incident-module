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

The declaration seeds the design's sample month — 47 incidents across four
categories, walked to the states the register shows — into the first area the
installation has, and it declares `dependsOn(['team'])`, so it is seeded after
the people who record its incidents. An installation with no area files nothing
and does not fail: devkit seeds every module in one run, and one with nothing to
hang its records on must not stop the others.

Everything it writes goes through the doors a person uses — the filing service,
the money service and the transition service, one legal move at a time. Demo
content written straight to the tables is demo content that can be shaped in ways
the product cannot produce, and every such row is a bug report about a screen
that is working correctly.

## What the declaration cannot write yet

The discipline above has a price, and the price is the finding. The sample month
describes four things this module has no service for, so none of them is seeded:

| Not seeded | Why | What would have to exist |
|---|---|---|
| Parties beyond the reporter — the claimant, the witness, the suspect | Filing names the filer; nothing names anybody else | a service that adds a party to an incident |
| Evidence — photographs, and the signed document a money case carries | Nothing in this module attaches evidence; the case file only reads it | an evidence-attachment service, and the screen behind it |
| The assignee | No service assigns an incident to anybody | assignment on the transition, or a service of its own |
| Money on an incident below `in progress` | The money service records money once response has started, which is the product's rule | nothing — the sample month is what disagrees with the rule |

Each of the first three is a screen this product does not have. The retired
command wrote all four straight to the entity manager, which kept the demo
looking complete and kept that fact invisible for as long as it kept working.

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
