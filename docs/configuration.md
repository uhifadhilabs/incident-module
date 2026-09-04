# Configuration

```yaml
# config/packages/incident.yaml
incident:
    module_category: operations   # catalogue category for the module tile
    currency: TZS               # what money on an incident is denominated in
    dev_tools: false            # dev-only tooling; enable via when@dev / when@test

    # OPTIONAL. Omit it and `incidents:taxonomy:sync` installs the four kinds and
    # sixteen sub-categories the module ships with.
    taxonomy:
        poaching:
            label: 'Poaching & wildlife crime'
            colour: poach       # one of: poach, hwc, comp, mort
            leads: ['Protection Service']
            subcategories:
                snaring:
                    label: snaring
                    money: fine         # fine | compensation | ~ (carries none)
                    term_hours: 72
                    fields: ['Species', 'Snares lifted']
```

The tree is closed, so an unknown key fails loudly rather than being ignored. A
configured `taxonomy` **replaces** the shipped one rather than merging with it: a
half-overridden classification scheme is nobody's scheme.

`leads` is **ordering only**. It decides which categories a lens puts first and
has no other power — every department can open every category, and one click on
"Every category" shows the whole register to anybody.
