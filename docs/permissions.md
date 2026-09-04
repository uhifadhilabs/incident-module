# Permissions

Declared, never granted: the host folds these into its permission catalogue for
admins to assign, and they vanish with the module on uninstall. Each carries the
sentence the host's permission matrix prints under the name, because "Incidents ·
Manage" says which words this module chose and not what ticking the box hands
over.

| Value | Name | Printed under it |
|---|---|---|
| `incidents.record` | Incidents · Record | File an incident: what happened, where, and the evidence for it. |
| `incidents.manage` | Incidents · Manage | Move an incident through verification, response and closure, and settle the fines and compensation on it. |

The split is the design's own economics — *a report is cheap and a verification
is expensive*. The design's IN·R1 card says filing should need no permission of
its own, so a deployment that agrees grants `incidents.record` to everyone who
can reach the module; it exists because a POST that creates a record must be
guarded by something a host can see and assign.
