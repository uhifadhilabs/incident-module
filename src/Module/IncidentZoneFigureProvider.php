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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\ZoneFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneFigures;
use Uhifadhi\Contracts\Kpi\ZoneRef;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * THE INCIDENTS FIGURES A ZONE SURFACE PRINTS — every zone of an area answered
 * in one call.
 *
 * THE GROUND DECIDES, AND NOTHING ELSE DOES. A figure here is about incidents
 * whose POINT falls inside the ring: not the zone stamped on the row when it was
 * filed, and not who filed it. An area that redraws its zones therefore reads
 * its history through the new geography immediately, which is the only reading
 * that can be true of a lens. An incident whose point lies in no zone counts for
 * no zone — it is still the area's, and the area's own figures still hold it.
 *
 * ── WHICH FIGURES, AND WHY THESE ─────────────────────────────────────────────
 *
 *  - **Incidents recorded** — filings whose point is in the zone, in the period.
 *    The same key the department seam publishes, because it is the same count
 *    over a different shape of ground.
 *  - **Open incidents** — how many were still somebody's work WHEN THE PERIOD
 *    CLOSED, whenever they were filed. A backlog is not a month's filings, and
 *    an instant is the only thing "open" can honestly be read at.
 *  - **Fines assessed** and **Compensation approved** — the department seam's
 *    own money figures, key for key, over the zone's ground: two plates, never
 *    one, because the design refuses to add the two directions together
 *    anywhere.
 *
 * NOTHING IS SCORED AGAINST THE MONTH BEFORE. Every figure leaves `previous`
 * null, so no delta chip is drawn — and that is deliberate for more than
 * economy: {@see DepartmentKpi::direction()} reads every figure as better when
 * larger, which is true of filings and false of a backlog, and a zone with a
 * growing open count must not be painted green or red by a contract that cannot
 * tell the two apart.
 *
 * A ZONE THIS MODULE HAS NOTHING FOR IS LEFT OUT, never present at zero: the
 * surfaces render an absence as an absence, and an area that has never had an
 * incident gets {@see ZoneFigures::none()}.
 *
 * {@see ZoneFigureProviderInterface::COVERED} is not published here. Incidents
 * are points, and a point covers no ground; whoever measures ground worked is
 * the module that walks it.
 */
final class IncidentZoneFigureProvider implements ZoneFigureProviderInterface
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        /** The slug this module is registered under in the host's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Incidents',
        private readonly string $currency = 'TZS',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * THE WHOLE SET IN ONE CALL, and three queries for it however many zones
     * are asked about — never a query per zone.
     *
     * The period answered is the period asked for: every figure here is read
     * off timestamps this module records itself, so there is no window it can
     * be asked for and cannot measure.
     */
    public function figuresFor(ZoneFigureRequest $request): ZoneFigures
    {
        if ($request->isEmpty()) {
            return ZoneFigures::none($request->period);
        }

        $zoneUuids = $request->zoneUuids();
        $period = $request->period;

        $filed = $this->incidents->countFiledByZoneBetween($zoneUuids, $period->from, $period->until);
        // The instant the window closes, which is where "still open" is read.
        $open = $this->incidents->countOpenByZoneAt($zoneUuids, $period->until);
        $money = $this->incidents->moneyByZoneBetween($zoneUuids, $period->from, $period->until);

        $byZone = [];
        foreach ($request->zones as $zone) {
            $figures = $this->figuresForZone($zone, $filed, $open, $money);
            if ([] !== $figures) {
                $byZone[$zone->zoneUuid] = $figures;
            }
        }

        return new ZoneFigures($byZone, $period);
    }

    /**
     * One zone's plates, or NOTHING where its ground held neither a filing in
     * the period nor an open incident when the period closed. A zone that held
     * either gets both counts, zeros included: a zone with filings and no
     * backlog has a backlog of nought, and that is a reading rather than a gap.
     *
     * @param array<string, int>                $filed
     * @param array<string, int>                $open
     * @param array<string, array<string, int>> $money
     *
     * @return list<DepartmentKpi>
     */
    private function figuresForZone(ZoneRef $zone, array $filed, array $open, array $money): array
    {
        $recorded = $filed[$zone->zoneUuid] ?? 0;
        $stillOpen = $open[$zone->zoneUuid] ?? 0;
        if (0 === $recorded && 0 === $stillOpen) {
            return [];
        }

        $caption = \sprintf('%s module · %s', $this->name, $zone->name);
        $figures = [
            new DepartmentKpi('incidents', 'Incidents recorded', $this->slug, $this->name, (float) $recorded, '', caption: $caption),
            new DepartmentKpi('incidents_open', 'Open incidents', $this->slug, $this->name, (float) $stillOpen, '', caption: $caption),
        ];

        // A list of pairs, not a map: an enum case cannot be an array key, and
        // the two directions stay two plates.
        foreach ([
            [MoneyDirectionEnum::Fine, 'Fines assessed'],
            [MoneyDirectionEnum::Compensation, 'Compensation approved'],
        ] as [$direction, $label]) {
            $total = $money[$zone->zoneUuid][$direction->value] ?? null;
            // Absent entirely rather than dashed, where nothing on this ground
            // carries money of this kind: a dashed slot means "we could not
            // measure", not "this is not our work".
            if (null === $total) {
                continue;
            }

            $figures[] = new DepartmentKpi(
                'incidents_'.$direction->value,
                $label,
                $this->slug,
                $this->name,
                (float) $total,
                $this->currency,
                caption: $caption,
            );
        }

        return $figures;
    }
}
