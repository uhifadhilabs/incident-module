# Dev tooling

```bash
bin/console incidents:seed:demo [area] [--month=2026-08]
```

Seeds the design's sample month — 47 incidents, 31 still open, TZS 8.45M assessed
in fines and 9.2M approved in compensation, across four categories and seven
zones — so a fresh host shows the module working and matches the spec. Idempotent
and non-destructive: every incident is keyed by its reference and one that already
exists is left exactly as it is.

It takes what the host already has: zones are attached **by name** where the area
has one and left null where it does not, and recorders are drawn from existing
accounts. And it walks every incident through the **real** transition service, one
legal move at a time, so it cannot produce a state the product could not.

Registered only where `incident.dev_tools` is on, so production never gets a
command that writes invented incidents.
