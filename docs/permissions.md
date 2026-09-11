# Permissions

## Contents

- [The two this module declares](#the-two-this-module-declares)
- [Attaching evidence rides on managing](#attaching-evidence-rides-on-managing)
- [Why filing and managing are split](#why-filing-and-managing-are-split)

## The two this module declares

Declared, never granted: RegistryBundle folds these into the permission
catalogue for admins to assign, and they vanish with the module on uninstall.
Each carries the sentence the matrix prints under the name, because "Incidents ·
Manage" says which words this module chose and not what ticking the box hands
over.

| Value | Name | Printed under it |
|---|---|---|
| `incidents.record` | Incidents · Record | File an incident: what happened, where, and the evidence for it. |
| `incidents.manage` | Incidents · Manage | Move an incident through verification, response and closure, settle the fines and compensation on it, and **manage the area's incident kinds** — the kinds, their sub-categories and the behaviour blocks, and what the area counts money in. |

## Attaching evidence rides on managing

Putting a file onto a case file, and taking one back off, both check
`incidents.manage` — not `incidents.record`. Evidence is what a claim rests on,
so it is the same tier of decision as moving the case through its workflow. The
check is `IncidentEvidenceTarget`'s, asked by the platform's upload endpoint on
every call and again at render time so the add tile is not drawn for somebody who
may not use it.

## Why filing and managing are split

The split is the design's own economics — *a report is cheap and a verification
is expensive*. The design's IN·R1 card says filing should need no permission of
its own, so a deployment that agrees grants `incidents.record` to everyone who
can reach the module; it exists because a POST that creates a record must be
guarded by something a host can see and assign.
