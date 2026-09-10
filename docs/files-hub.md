# Evidence on the Files hub

## Contents

- [How an incident's evidence reaches the hub](#how-an-incidents-evidence-reaches-the-hub)
- [What the hub is told is only what this module knows](#what-the-hub-is-told-is-only-what-this-module-knows)
- [The guard is the case file's own answer](#the-guard-is-the-case-files-own-answer)

## How an incident's evidence reaches the hub

`uhifadhi/storage-module` is a requirement of this module, so its cross-module
hub at `/files` is always there to appear on: `IncidentFileSource` is tagged
`storage.file_source` and hands over one entry per `IncidentEvidence`, carrying
the case file it belongs to (`INC-0313`, linked to its own page), the incident's
area, the handset's `capturedAt` for a photograph, and the record's caption.
There is no registration step and no guard — an incident filed by hand carries
photographs, and a deployment that could not store one would not be running this
module.

## What the hub is told is only what this module knows

**What the hub is told is only what this module actually knows.** Evidence rows
here are records OF files, not files: `path` is nullable and no row carries a
measured size, a detected type or a generated preview. So a row with no path is
not listed (a tile for a key that names nothing would link at a 404 — the module
is simply shown holding nothing for that record), the size is `0` rather than an
invented figure, and a photograph's small picture reads *waiting*, never *could
not be made*: nothing ever tried. The size and preview fall away when incidents
adopts storage-module's upload path. **No demo evidence is seeded**, and that is
the same absence stated a second way: nothing in this module attaches a piece of
evidence to an incident, so nothing can seed one either. See
[dev tooling](dev-tooling.md).

## The guard is the case file's own answer

**The guard is the case file's own answer.** An incident still being worked
answers `Locked` — a claim rests on the evidence. A resolved or closed one
answers `Allowed`. The design's third answer, `Denied` for another department's
upload, is not implemented because `IncidentEvidence` records no uploader; it
arrives with that column, not before it. `FileRemovalInterface` is likewise not
implemented yet, so the hub names the state but offers no control — the safe way
round.
