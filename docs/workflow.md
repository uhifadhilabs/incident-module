# The workflow, and the seam under it

```
reported → verified → in progress → resolved → closed
```

- **Nothing skips verification.** It is not a check; there is simply no
  transition from `reported` to anywhere but `verified`.
- **`resolve` requires the money settled or waived** — where there is money at
  all. An incident whose claim is outstanding is not resolved, it is *unpaid*.
  A waiver passes the guard, because somebody wrote down why.
- **`closed` is reached by TIME, never by a person** — 30 days after resolution.
  It is refused to every actor without exception; the only caller that gets
  through is the clock, and it gets through by not being an actor at all.
- **A community or SMS report is an ordinary `reported` incident wearing a
  SOURCE badge.** There is no sixth place for an untrusted reporter: a place is a
  stage of *work*, and "we have not met this reporter" is a property of the
  *report*.

## The seam

`src/Workflow/IncidentWorkflow.php` **is** the seam for a future platform
workflow module. When one lands, that class is what it replaces, and nothing else
in the bundle has to move — which is only true because of three constraints:

1. **The definition is DATA, expressed there and nowhere else.** No other class
   names a place-to-place move. Templates ask the incident for its status and ask
   `IncidentTransitionService` what is available.
2. **Guards are named** (`IncidentGuardEnum`) **and each answers with a REASON,
   not a boolean.** A workflow engine's guard listener returns a blocked message;
   so does this. The UI needs the sentence anyway — the case file prints it beside
   the moves that *are* allowed.
3. **The marking is a single column** (`incident.status`), which is what Symfony's
   Workflow calls a single-state marking store.

The mapping to a Symfony `state_machine` is one-to-one: places are
`IncidentStatusEnum` values, transitions are `IncidentTransitionEnum` values with
`from`/`to` as declared, the marking store is the `status` property, and
`IncidentWorkflow::guardFor()` becomes two guard listeners.
