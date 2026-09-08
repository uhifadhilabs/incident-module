# Optional — evidence on the Files hub

Where a host also runs `uhifadhi/storage-module` and mounts its cross-module
hub at `/files`, this module puts its evidence on it: `IncidentFileSource` is
tagged `storage.file_source` and hands over one entry per `IncidentEvidence`,
carrying the case file it belongs to (`INC-0313`, linked to its own page), the
incident's area, the handset's `capturedAt` for a photograph, and the record's
caption. Registering the storage bundle is the only step; a host without it runs
every incident screen unchanged and simply has no hub to appear on.

**What the hub is told is only what this module actually knows.** Evidence rows
here are records OF files, not files: `path` is nullable and no row carries a
measured size, a detected type or a generated preview. So a row with no path is
not listed (a tile for a key that names nothing would link at a 404 — the module
is simply shown holding nothing for that record), the size is `0` rather than an
invented figure, and a photograph's small picture reads *waiting*, never *could
not be made*: nothing ever tried. The size and preview fall away when incidents
adopts storage-module's upload path. **The demo seeder does assign each seeded
piece of evidence a key** (`incident/<reference>/<filename>`), so the sample
month's photographs and signed documents appear on the hub, each linked to its
case file — a realistic spread of incident-linked files, with the byte size and
preview still honestly absent.

**The guard is the case file's own answer.** An incident still being worked
answers `Locked` — a claim rests on the evidence. A resolved or closed one
answers `Allowed`. The design's third answer, `Denied` for another department's
upload, is not implemented because `IncidentEvidence` records no uploader; it
arrives with that column, not before it. `FileRemovalInterface` is likewise not
implemented yet, so the hub names the state but offers no control — the safe way
round.
