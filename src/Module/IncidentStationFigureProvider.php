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
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationFigures;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * THE ONE INCIDENTS ROW A STATION DOCK PRINTS — every station of an area
 * answered in one call.
 *
 * A POST IS A POINT AND HOLDS NO GROUND, so "the incidents of this post" is a
 * distance and nothing else: the filings whose POINT lies within
 * {@see NEAR_M} of the post's point, in the period asked for. Not the
 * incidents somebody posted there recorded, and not the ones stamped with the
 * post's zone — who filed a row and which ring it fell in are other questions
 * with their own seams.
 *
 * WHAT "NEAR" MEANS IS THIS MODULE'S TO SAY, which is why the radius is stated
 * in the caption the dock prints and not left for a reader to guess. The core
 * prints the qualifier without interpreting it.
 *
 * ONE KEY, WHATEVER ELSE THERE IS TO SAY. The dock draws one row per module,
 * so the figure is published under {@see StationFigureProviderInterface::HEADLINE}
 * and the backlog rides in the caption — "within 12 km · 1 open" — rather than
 * as a second key that the dock has nowhere to draw. The open count is read at
 * the instant the period CLOSED and covers whenever the work was filed: a
 * backlog is not a month's filings.
 *
 * NOTHING IS SCORED AGAINST THE MONTH BEFORE. The figure leaves `previous`
 * null, so no delta chip is drawn — deliberately, because
 * {@see DepartmentKpi::direction()} reads every figure as better when larger,
 * and more incidents near a post is not news that is good or bad on its own.
 * `areaName` stays null too: the ref already says which area, and that field
 * means "one area's share of a roll-up", which a station figure never is.
 *
 * NO URL IS CARRIED. The core resolves the dock's link from this module's entry
 * route and the slug below.
 *
 * A POST THIS MODULE HAS NOTHING FOR IS LEFT OUT, never present at zero: the
 * dock renders an absence as an absence, and an area whose posts saw nothing
 * gets {@see StationFigures::none()}.
 */
final class IncidentStationFigureProvider implements StationFigureProviderInterface
{
    /**
     * HOW FAR FROM A POST STILL COUNTS AS NEAR IT, in metres on the spheroid —
     * the one place the radius is written, read by both the query and the
     * caption so the number the dock prints cannot drift from the number it
     * was measured with. Twelve kilometres is the design's figure; see
     * docs/the-model.md.
     */
    public const int NEAR_M = 12_000;

    public function __construct(
        private readonly IncidentRepository $incidents,
        /** The slug this module is registered under in the host's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Incidents',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * THE WHOLE SET IN ONE CALL, and two queries for it however many posts are
     * asked about — never a spatial query per post.
     *
     * The period answered is the period asked for: every figure here is read
     * off timestamps this module records itself, so there is no window it can
     * be asked for and cannot measure.
     */
    public function figuresFor(StationFigureRequest $request): StationFigures
    {
        if ($request->isEmpty()) {
            return StationFigures::none($request->period);
        }

        $stationUuids = $request->stationUuids();
        $period = $request->period;

        $filed = $this->incidents->countFiledNearStationsBetween($stationUuids, self::NEAR_M, $period->from, $period->until);
        // The instant the window closes, which is where "still open" is read.
        $open = $this->incidents->countOpenNearStationsAt($stationUuids, self::NEAR_M, $period->until);

        $byStation = [];
        foreach ($request->stations as $station) {
            $figures = $this->figuresForStation($station, $filed, $open);
            if ([] !== $figures) {
                $byStation[$station->stationUuid] = $figures;
            }
        }

        return new StationFigures($byStation, $period);
    }

    /**
     * One post's row, or NOTHING where neither a filing in the period nor an
     * open incident at its close lay within the radius. A post that had either
     * gets the row, zeros included: a post with filings and no backlog has a
     * backlog of nought, and that is a reading rather than a gap.
     *
     * @param array<string, int> $filed
     * @param array<string, int> $open
     *
     * @return list<DepartmentKpi>
     */
    private function figuresForStation(StationRef $station, array $filed, array $open): array
    {
        $recorded = $filed[$station->stationUuid] ?? 0;
        $stillOpen = $open[$station->stationUuid] ?? 0;
        if (0 === $recorded && 0 === $stillOpen) {
            return [];
        }

        return [new DepartmentKpi(
            self::HEADLINE,
            'Incidents recorded',
            $this->slug,
            $this->name,
            (float) $recorded,
            '',
            caption: \sprintf('within %d km · %d open', intdiv(self::NEAR_M, 1000), $stillOpen),
        )];
    }
}
