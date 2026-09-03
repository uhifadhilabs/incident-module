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

use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Model\IncidentHues;
use Uhifadhi\Incident\Model\IncidentMapPayload;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Overview\MapLayer;
use Uhifadhi\Overview\MapLayerProviderInterface;

/**
 * THIS MODULE'S TWO LAYERS ON THE HOST'S ONE OPERATIONAL PLATE.
 *
 * One plate, many owners. The host draws the map — self-hosted Leaflet, the same
 * instrument every map in the product wears — and each layer on it belongs to the
 * module that owns the data. The geometry is built by
 * {@see IncidentMapPayload}, which is the SAME builder the module's own dashboard
 * map and case-file map use, because the map-legend contract says the same layer
 * renders identically everywhere it is drawn.
 *
 * OPEN IS ON; FINISHED IS OFF. The overview is about the morning: an open
 * incident is somewhere a person may have to go today and a closed one is a
 * record. The finished layer is not deleted — it is one click away in the
 * legend, with its own entry, exactly where it was.
 *
 * AND THE FINISHED LAYER IS WINDOWED, IN ITS LABEL. Every incident an area ever
 * closed is unbounded; a plate about today does not need last year's roadkill.
 * The window is said out loud on the legend entry rather than applied as a
 * silent cap, because a legend that quietly meant something narrower than it
 * said would be worse than no legend.
 */
final readonly class IncidentMapLayers implements MapLayerProviderInterface
{
    /** How far back the finished layer reaches. Printed on its own legend entry. */
    public const string DONE_WINDOW = '-30 days';

    public function __construct(
        private IncidentRepository $incidents,
    ) {
    }

    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function mapLayersFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $open = $this->incidents->openFor($area);
        $done = $this->incidents->closedOutBetween($area, $now->modify(self::DONE_WINDOW), $now);

        return [
            new MapLayer(
                id: 'incidents.open',
                moduleSlug: $this->moduleSlug(),
                // The legend is grouped by CONTRIBUTOR, which is the only way a
                // person can tell why a layer vanished.
                groupLabel: 'Incidents',
                label: 'Open',
                // A LAYER'S COLOUR IS DATA, so it is stated once and is the same
                // in light and dark. Open work wears the register's leading hue.
                swatch: IncidentHues::of('poach'),
                features: IncidentMapPayload::of($open),
                count: \count($open),
            ),
            new MapLayer(
                id: 'incidents.done',
                moduleSlug: $this->moduleSlug(),
                groupLabel: 'Incidents',
                label: 'Resolved & closed · 30 days',
                swatch: IncidentHues::of('mort'),
                features: IncidentMapPayload::of($done),
                count: \count($done),
                // Off before anybody touches the legend, and its entry is drawn
                // all the same: a legend is a statement about the plate, not
                // about this morning's data.
                on: false,
            ),
        ];
    }
}
