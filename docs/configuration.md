# Configuration

## Contents

- [The file](#the-file)
- [The classification is not configuration](#the-classification-is-not-configuration)
- [What `leads` does not decide](#what-leads-does-not-decide)

## The file

```yaml
# config/packages/incident.yaml
incident:
    module_category: operations   # catalogue category for the module tile
    currency: TZS                 # what money on an incident is denominated in
```

Two keys, both optional. The tree is closed, so an unknown key fails loudly
rather than being ignored.

## The classification is not configuration

Kinds of incident and their sub-categories are **each area's own**, stored per
area and written in the **Incident kinds** editor
(`/areas/{uuid}/modules/incidents/kinds`, permission `incidents.manage`). The
module ships none, seeds none and suggests none: a new area starts empty and
names its own words before the first incident is filed there.

A colour, the departments a lens leads with, the behaviour blocks a sub-category
switches on, which way its money runs, the term it promises and the fields its
form asks for all live on those rows and are all edited in that section.

A configuration file that still carries an `incident.taxonomy` tree is **refused
at container build**, with a message naming the editor — silently dropping a
deployment's classification scheme would be discovered on the first filing
screen. Remove the key; an area that had already filed incidents was given its
own copy of the words it was using by the module's migration (see the README's
Upgrading section).

## What `leads` does not decide

`leads` is **ordering only**. It decides which categories a lens puts first and
has no other power — every department can open every category, and one click on
"Every category" shows the whole register to anybody.
