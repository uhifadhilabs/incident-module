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

**What the hub is told is only what this module actually knows.** A row written
by `IncidentEvidenceService::attach()` carries everything storage measured when
it wrote the blob — the key, the type detected from the bytes, the size and, for
a photograph, the generated preview — so the hub weighs and labels what is
actually there.

A row that carries no `path` is still possible and is still described honestly
rather than conveniently: it is a record OF a file, not a file. Such a row is
**not listed** (a tile for a key that names nothing would link at a 404 — the
module is simply shown holding nothing for that record), its size reads `0`
because nobody measured it rather than because it is empty, and a photograph
with no preview reads *waiting*, never *could not be made*: nothing ever
tried.

## The guard is the case file's own answer

**The guard is the case file's own answer.** An incident still being worked
answers `Locked` — a claim rests on the evidence. A resolved or closed one
answers `Allowed`. The design's third answer, `Denied` for another department's
upload, is not implemented because `IncidentEvidence` records no uploader; it
arrives with that column, not before it. `FileRemovalInterface` is likewise not
implemented yet, so the hub names the state but offers no control — the safe way
round.
