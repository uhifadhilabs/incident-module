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

namespace Uhifadhi\Incident\Overview;

use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Overview\ContributesStylesheetInterface;
use Uhifadhi\Area\Overview\OverviewContributorInterface;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Incident\Module\IncidentModuleProvider;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;
use Uhifadhi\Incident\UhifadhiIncidentBundle;
use Uhifadhi\Widget\Model\WidgetGroup;

/**
 * WHAT THIS MODULE PUTS ON `/areas/{uuid}` — four cards and a column, in the
 * host's library, under this module's own name.
 *
 * The host owns the surface, the grid and the preset framework; this owns four
 * plates and every number on them. Uninstall the bundle and the headed section,
 * the five widgets, the two right-now tiles, the attention items and the map
 * layer all leave the page together — and a saved preset that named one of them
 * degrades rather than breaking, because the host's resolver is tolerant.
 *
 * THE COLUMN MAY ONLY INCLUDE WIDGETS THIS MODULE ALREADY CONTRIBUTES ON THEIR
 * OWN. `_w_in_column` includes `_w_in_flow`, `_w_in_today` and `_w_in_money`
 * rather than restating them, so a card cannot read one way as a widget and
 * another inside the column. That rule is the module's to keep; the host cannot
 * check it.
 *
 * Tagged EXPLICITLY in the bundle's extension, exactly like `uhifadhi.module`
 * and `uhifadhi.department_kpi`: a reusable bundle is not autoconfigured, so the
 * host's `registerForAutoconfiguration` never fires for it. A contributor that
 * forgot the tag would show up only as this module's section quietly missing
 * from every area's widget library.
 */
final readonly class IncidentOverviewContributor implements ContributesStylesheetInterface, OverviewContributorInterface
{
    public function __construct(
        private IncidentOverviewFigures $figures,
    ) {
    }

    /**
     * The same slug {@see IncidentModuleProvider::slug()} declares, and it must
     * MATCH: the host asks this contributor for widgets only where an area has
     * a module of that slug switched on.
     */
    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function group(): WidgetGroup
    {
        return IncidentOverviewWidgets::group();
    }

    public function widgets(): array
    {
        return IncidentOverviewWidgets::widgets();
    }

    public function partialPattern(): string
    {
        return IncidentOverviewWidgets::PARTIAL_PATTERN;
    }

    /**
     * THE PLATES WEAR THIS MODULE'S OWN VOCABULARY, and here the HOST renders
     * them.
     *
     * Every incidents page of the module's own extends `base.html.twig`, which
     * links this same sheet; the area overview extends the host's layout, so
     * without this the category chips, the five-state flow bar and the money
     * tone on a contributed plate render naked. The host asks only contributors
     * that implement {@see ContributesStylesheetInterface} — one with no CSS of
     * its own does not, and is asked nothing.
     *
     * The path is the BUNDLE'S constant, so this and base.html.twig cannot name
     * two different files.
     */
    public function stylesheet(): string
    {
        return UhifadhiIncidentBundle::STYLESHEET;
    }

    /**
     * EVERYTHING THIS MODULE'S PARTIALS READ, for this one area, measured once.
     *
     * One key, holding one object: the four cards share a reading of the morning,
     * and the alternative — a key per figure — would let the flow bar and the
     * column's copy of it be assembled from two different measurements.
     */
    public function context(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        return ['overview' => $this->figures->for($area, $now)];
    }
}
