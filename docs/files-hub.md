# Evidence on the Files hub

## Contents

- [How a file gets onto a case file](#how-a-file-gets-onto-a-case-file)
- [How an incident's evidence reaches the hub](#how-an-incidents-evidence-reaches-the-hub)
- [Which keys are ours, asked in one place](#which-keys-are-ours-asked-in-one-place)
- [What the hub is told is only what this module knows](#what-the-hub-is-told-is-only-what-this-module-knows)
- [The guard is the case file's own answer](#the-guard-is-the-case-files-own-answer)

## How a file gets onto a case file

Through the platform's ONE upload component, which storage owns. This module
wrote two things for it and nothing else:

`src/Upload/IncidentEvidenceTarget.php` — an `UploadTargetInterface`, tagged
`storage.upload_target` under the security guard, answering the four questions
only this module can:

| Question | This module's answer |
|---|---|
| which case | by **uuid**, never by reference — evidence filed under a label would move when the label did, the same choice `IncidentEvidenceKey::prefixFor()` already made |
| who may | `incidents.manage` — the same tier as moving a case through its workflow, and NOT the cheaper `incidents.record`. Filing a report is cheap; putting a photograph onto somebody else's case file is not |
| what and how big | the deployment's own, unnarrowed. A case file takes whatever this installation accepts as evidence |
| what it became | evidence, and the chip on the finished tile says so |

…and one line in `templates/incident/show.html.twig`:

```twig
{{ render_upload('incident:' ~ incident.uuid, 'tile', {label: 'Add evidence'}) }}
```

No controller, no route, no JavaScript, no stylesheet. The component is not drawn
for somebody who may not use it — storage asks `mayUpload()` at render time — so
the template carries no permission check of its own.

**A kept tile is the component's own finished state.** The evidence card draws
each attached file as `.upl-tile.done`, exactly as the controller draws a file
that landed a second ago, hover remove and all. Two kinds of tile for the same
thing on one card is precisely the drift the single component exists to end.

**Removal is a recorded event.** `IncidentEvidenceService::detach()` drops the
row and writes the case a timeline line saying the file went — the platform's
upload service calls it BEFORE deleting the bytes, so a refusal thrown from here
leaves the file exactly where it was. The trail is append-only: a removal adds an
event, it never erases one.

The full contract, the endpoint and the refusal sentences are in
storage-module's `docs/uploads.md`.

## How an incident's evidence reaches the hub

`uhifadhi/storage-module` is a requirement of this module, so its cross-module
hub at `/files` is always there to appear on: `IncidentFileSource` is tagged
`storage.file_source` and hands over one entry per `IncidentEvidence`, carrying
the case file it belongs to (`INC-0313`, linked to its own page), the incident's
area, the handset's `capturedAt` for a photograph, and the record's caption.
There is no registration step and no guard — an incident filed by hand carries
photographs, and a deployment that could not store one would not be running this
module.

## Which keys are ours, asked in one place

Every evidence key this module writes begins `incident/`, and that first segment
is the whole of the contract between three collaborators:
`IncidentEvidenceService` writes under it, `IncidentEvidenceVoter` claims it back
so storage-module's deny-by-default rule does not swallow it, and
`IncidentFileSource` lists it here. So the prefix is named once, in
`src/Service/IncidentEvidenceKey.php` — `PREFIX`, `prefixFor()` for a case file's
own namespace (`incident/<uuid>`, the uuid and never the reference), and
`claims()` for the question a voter asks. A prefix remembered in three places is
a prefix that eventually differs in one, and the failure there is silent: a
photograph nobody is allowed to look at, on a page the reader is entitled to.

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
