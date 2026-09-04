# Charter

**One record type, many readers.** An incident is a thing that happened in an
area. The same record serves **Protection** (poaching, compliance) and
**Ecology** (mortality, roadkill, human–wildlife conflict outcomes) — not by
copying the data into two modules, but by reading **subsets of one taxonomy**. A
poaching incident and a roadkill incident are the same kind of record with
different classifications.

**Departments are a lens, never a fence.** A department attaching this module
changes what a team *sees first* — it never gates what data exists or who may
read it. Incidents belong to the area; the department view is a reading of them.
Any design that makes a department the owner of a subset of incidents is wrong
for this module, which is why there is deliberately **no `incidents.view`
permission**: a view permission is exactly the tool somebody would eventually use
to hide one department's rows from another.

**Dashboard surfaces ride the host's widget framework.** The module's dashboard
is composed on the HOST's `WidgetService` / `WidgetCatalog` preset component —
the same technique behind the host's departments, team and zones surfaces —
rather than a second widget implementation inside this bundle. This module ships
a catalogue and sixteen Twig partials; it ships no widget mechanics at all.
