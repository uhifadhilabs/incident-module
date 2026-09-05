<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Incidents Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Incident\Model;

use Uhifadhi\Widget\Model\Widget;
use Uhifadhi\Widget\Model\WidgetGroup;

/**
 * WHAT THIS MODULE PUTS IN THE HOST'S AREA-OVERVIEW LIBRARY — a transcription of
 * the design's own surface declaration for `/areas/{uuid}`, the `incidents`
 * group.
 *
 * A GROUP HERE IS A CONTRIBUTOR, NOT A DESIGN DIRECTION. On this module's own
 * surface ({@see IncidentWidgets}) a headed section is one of the five
 * directions incidents was drawn in, because one module wrote every widget on
 * that page. The area overview is COMPOSED, so the division that matters there
 * is PROVENANCE: the package name is in the label, the plate's own `IN·` index
 * repeats it, and a person can therefore tell that "Open by state" came from
 * here — so that uninstalling the module reads as the system working rather than
 * as a bug.
 *
 * NOT THE INCIDENTS DASHBOARD IN MINIATURE. Four short cards and a column, and
 * only the flow bar is on: the dashboard answers "how was the month", this
 * answers "where is the open work, this morning, before I open the module".
 *
 * Static rather than a service, exactly as {@see IncidentWidgets} is: a
 * catalogue is a statement of what a surface ships, it has no dependencies, and
 * nothing may vary it at runtime. {@see \Uhifadhi\Incident\Overview\IncidentOverviewContributor}
 * is what hands it to the host.
 */
final class IncidentOverviewWidgets
{
    /** The id of the headed section, and of the module whose slug it repeats. */
    public const string GROUP = 'incidents';

    /**
     * The sprintf pattern naming one widget's partial. A PATTERN PER CONTRIBUTOR:
     * each plate is rendered from its own bundle's template namespace, which is
     * how the host's own overview template can contain no widget markup at all.
     */
    public const string PARTIAL_PATTERN = '@UhifadhiIncident/overview/_w_%s.html.twig';

    public static function group(): WidgetGroup
    {
        return new WidgetGroup(
            self::GROUP,
            'Incidents · uhifadhi/incident-module',
            'What the incidents module contributes to the area overview: the five-state flow of what is still open, today’s filings, the latest cases, and the money the area is owed or owes. The state machine reads exactly as it does on the module’s own pages, because it is the same component.',
        );
    }

    /**
     * The five, in the order the library lists them.
     *
     * The spans are the design's, with ONE addition: `in_today` and `in_column`
     * also offer a THIRD of the row (4). The design gave them a quarter as their
     * narrowest because 12/9/6/3 was the whole vocabulary; the host's grid has
     * since gained a third for exactly the module-columns case — the page as the
     * sum of its modules, one column each — and those are the two widgets of
     * this five that can BE a column.
     *
     * @return list<Widget>
     */
    public static function widgets(): array
    {
        $group = self::GROUP;

        return [
            new Widget('in_flow', 'Open by state', $group, 6, [12, 9, 6],
                note: 'The five-state flow as one bar — the same state machine the module’s own pages draw.'),
            new Widget('in_today', 'Incidents today', $group, 6, [12, 9, 6, 4, 3], on: false,
                note: 'Filed today, by category, against yesterday. Today only — the month lives on the module.'),
            new Widget('in_recent', 'Latest incidents', $group, 6, [12, 9, 6], on: false,
                note: 'The last handful filed, newest first, each opening its case.'),
            new Widget('in_money', 'Money outstanding', $group, 6, [12, 9, 6], on: false,
                note: 'Fines due and compensation unpaid — the one overview number that is not about today.'),
            new Widget('in_column', 'Incidents — the whole column', $group, 6, [12, 9, 6, 4, 3], on: false,
                note: 'The module’s entire overview section as ONE widget: its heading and its cards, stacked.'),
        ];
    }
}
