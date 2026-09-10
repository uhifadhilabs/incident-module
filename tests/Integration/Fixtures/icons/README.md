# The application's icon directory

An installation always has one, and it is what answers a **bare** icon name —
never a prefixed one. A prefix maps to a single directory and is answered only
from it, so `incident:*` comes from this bundle's own `assets/icons/incident`
and `shell:*` from ShellBundle's, whatever is or is not in here.

It is deliberately empty. Nothing this module draws is a bare name, so an icon
that resolved from here would be an icon drawn under a prefix the module may not
use — which the vocabulary conformance test already refuses. Keeping the
directory real, and on-demand fetching off in the kernel, is what makes the
functional suite prove that the glyphs SHIP rather than that the machine has a
network.
