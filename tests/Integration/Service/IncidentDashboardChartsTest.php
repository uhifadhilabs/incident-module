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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THE THREE MAP-FIRST CHARTS READ. The trend line, the category donut and
 * the severity bars are three readings of the month the surface already loaded,
 * plus the trend's own six-month window — computed once, on the same rows, so
 * the charts can never disagree with the register beside them.
 */
final class IncidentDashboardChartsTest extends IntegrationTestCase
{
    private const string ANCHOR = '2026-08-20 09:00:00';

    private function dashboard(): IncidentDashboardService
    {
        /** @var IncidentDashboardService $dashboard */
        $dashboard = $this->service('incident.dashboard');

        return $dashboard;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTaxonomy();
    }

    private function fileWithSeverity(
        \Uhifadhi\Area\Entity\AreaOfInterest $area,
        string $subcategory,
        string $title,
        IncidentSeverityEnum $severity,
        \DateTimeImmutable $at,
    ): void {
        /** @var IncidentReportService $reports */
        $reports = static::getContainer()->get('test_public.incident.report');
        $reports->file(
            area: $area,
            subcategory: $this->subcategory($subcategory),
            title: $title,
            position: '{"type":"Point","coordinates":[35.25,-3.21]}',
            now: $at,
            severity: $severity,
        );
    }

    /** The category donut reads the month's mix, biggest share first, zeroes dropped. */
    public function testCategorySharesAreBiggestFirstAndCarryTheirColour(): void
    {
        $area = $this->anArea();
        $august = new \DateTimeImmutable('2026-08-10 08:00:00');
        // Two conflict, one poaching — HWC is the larger share.
        $this->anIncident($area, 'livestock-depredation', 'Goats taken', at: $august);
        $this->anIncident($area, 'crop-raiding', 'Maize destroyed', at: $august);
        $this->anIncident($area, 'snaring', 'Snare line', at: $august);

        $window = new IncidentFilter(
            area: $area,
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-09-01'),
        );
        $shares = $this->dashboard()->build($window, new \DateTimeImmutable(self::ANCHOR))->categoryShares();

        self::assertSame(['hwc', 'poach'], array_map(static fn (array $s) => $s['category']->getColourKey(), $shares));
        self::assertSame([2, 1], array_map(static fn (array $s) => $s['count'], $shares));
    }

    /**
     * The severity bars read the month, one count per level the model has —
     * now four, worst-first, every level present even at zero so the bars never
     * collapse to three.
     */
    public function testSeverityCountsCoverTheModelsFourLevels(): void
    {
        $area = $this->anArea();
        $august = new \DateTimeImmutable('2026-08-10 08:00:00');
        $this->fileWithSeverity($area, 'snaring', 'A', IncidentSeverityEnum::Critical, $august);
        $this->fileWithSeverity($area, 'snaring', 'B', IncidentSeverityEnum::High, $august);
        $this->fileWithSeverity($area, 'snaring', 'C', IncidentSeverityEnum::High, $august);
        $this->fileWithSeverity($area, 'crop-raiding', 'D', IncidentSeverityEnum::Moderate, $august);
        $this->fileWithSeverity($area, 'roadkill', 'E', IncidentSeverityEnum::Low, $august);

        $window = new IncidentFilter(
            area: $area,
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-09-01'),
        );
        $built = $this->dashboard()->build($window, new \DateTimeImmutable(self::ANCHOR));

        self::assertSame(1, $built->severityCount(IncidentSeverityEnum::Critical));
        self::assertSame(2, $built->severityCount(IncidentSeverityEnum::High));
        self::assertSame(1, $built->severityCount(IncidentSeverityEnum::Moderate));
        self::assertSame(1, $built->severityCount(IncidentSeverityEnum::Low));

        // Worst-first is the order the bars draw in: critical then down to low.
        self::assertSame(
            ['critical', 'high', 'moderate', 'low'],
            array_map(static fn (IncidentSeverityEnum $s) => $s->value, $built->severitiesWorstFirst()),
        );
    }

    /** The trend carries its own six months, not the page's one. */
    public function testTheTrendCarriesSixMonths(): void
    {
        $area = $this->anArea();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-10 08:00:00'));

        $window = new IncidentFilter(
            area: $area,
            from: new \DateTimeImmutable('2026-08-01'),
            to: new \DateTimeImmutable('2026-09-01'),
        );
        $built = $this->dashboard()->build($window, new \DateTimeImmutable(self::ANCHOR));

        self::assertCount(6, $built->monthlyCounts);
        self::assertSame(1, $built->monthlyCounts['2026-08']);
    }
}
