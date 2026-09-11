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

namespace Uhifadhi\Incident\Tests\Integration\Repository;

use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE TREND LINE'S SERIES — filings per calendar month across the six months up
 * to and including the one the dashboard is read in. The line the map cannot
 * draw, and the one figure on the surface whose window is not the page's month:
 * a line needs more than one month, so this aggregate carries its own six.
 *
 * ONE FILTER STILL DRIVES IT. The category chips, the lens and the search narrow
 * the trend exactly as they narrow the register — only the WINDOW is the chart's
 * own.
 */
final class IncidentMonthlyTrendTest extends IntegrationTestCase
{
    private const string ANCHOR = '2026-08-15 09:00:00';

    private function incidents(): IncidentRepository
    {
        /** @var IncidentRepository $incidents */
        $incidents = $this->service('incident.repository');

        return $incidents;
    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** Six months, oldest first, every one present — the quiet months at zero. */
    public function testItReturnsSixMonthsOldestFirstEveryMonthPresent(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-03-10 08:00:00'));
        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-20 08:00:00'));
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-05 08:00:00'));
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-12 08:00:00'));

        $counts = $this->incidents()->monthlyFiledCounts(
            new IncidentFilter(area: $area),
            new \DateTimeImmutable(self::ANCHOR),
        );

        self::assertSame(
            ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
            array_keys($counts),
        );
        self::assertSame(
            ['2026-03' => 1, '2026-04' => 0, '2026-05' => 0, '2026-06' => 0, '2026-07' => 1, '2026-08' => 2],
            $counts,
        );
    }

    /** A filing older than the six-month window is not on the line. */
    public function testFilingsBeforeTheWindowAreExcluded(): void
    {
        $area = $this->anAreaWithKinds();
        // February is one month before March, the first month in the window.
        $this->anIncident($area, at: new \DateTimeImmutable('2026-02-15 08:00:00'));
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-05 08:00:00'));

        $counts = $this->incidents()->monthlyFiledCounts(
            new IncidentFilter(area: $area),
            new \DateTimeImmutable(self::ANCHOR),
        );

        self::assertArrayNotHasKey('2026-02', $counts);
        self::assertSame(1, array_sum($counts));
    }

    /** The category filter narrows the trend the way it narrows everything else. */
    public function testTheCategoryFilterNarrowsTheTrend(): void
    {
        $area = $this->anAreaWithKinds();
        // 'snaring' is poaching; 'livestock-depredation' is human–wildlife conflict.
        $this->anIncident($area, 'snaring', 'Snare line', at: new \DateTimeImmutable('2026-08-05 08:00:00'));
        $this->anIncident($area, 'livestock-depredation', 'Lion took goats', at: new \DateTimeImmutable('2026-08-06 08:00:00'));

        $counts = $this->incidents()->monthlyFiledCounts(
            new IncidentFilter(area: $area, kindCodes: ['poaching']),
            new \DateTimeImmutable(self::ANCHOR),
        );

        self::assertSame(1, $counts['2026-08']);
        self::assertSame(1, array_sum($counts));
    }

    /** An area with nothing filed still gets six zeroed months, never an empty map. */
    public function testAQuietAreaStillGetsSixZeroedMonths(): void
    {
        $area = $this->anAreaWithKinds('Quiet Area');

        $counts = $this->incidents()->monthlyFiledCounts(
            new IncidentFilter(area: $area),
            new \DateTimeImmutable(self::ANCHOR),
        );

        self::assertCount(6, $counts);
        self::assertSame(0, array_sum($counts));
    }
}
