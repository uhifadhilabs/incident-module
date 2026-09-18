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

namespace Uhifadhi\Incident\Tests\Integration\Module;

use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\ZoneFigureRequest;
use Uhifadhi\Contracts\Kpi\ZoneRef;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Module\IncidentZoneFigureProvider;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE INCIDENTS FIGURES A ZONE SURFACE PRINTS, against real PostGIS ground.
 *
 * THE GROUND IS THE THING UNDER TEST, not the zone the row was stamped with at
 * filing: every figure here is decided by where the point actually falls, which
 * is why two zones are drawn as real polygons and the incidents are filed at
 * real coordinates inside, between and outside them.
 *
 * THERE IS NO "INCIDENT WITH NO POSITION" CASE because this module cannot
 * produce one: `incident.position` is `geometry(POINT,4326) NOT NULL`. A point
 * inside no zone is the representable neighbour of that case, and it is the one
 * asserted.
 */
final class IncidentZoneFigureProviderTest extends IntegrationTestCase
{
    /** The western zone's ground, and a point well inside it. */
    private const float WEST_EDGE = -30.0;
    private const float WEST_INNER_EDGE = -29.7;
    private const float IN_WEST = -29.85;

    /** The eastern zone's ground, a gap away, and a point well inside it. */
    private const float EAST_INNER_EDGE = -29.4;
    private const float EAST_EDGE = -29.1;
    private const float IN_EAST = -29.25;

    /** The gap between the two: inside the area, inside no zone. */
    private const float IN_NEITHER = -29.55;

    public function testItAnswersForTheIncidentsModule(): void
    {
        self::assertSame('incidents', $this->provider()->moduleSlug());
    }

    /**
     * EVERY ZONE IN ONE ANSWER, each counting the ground it holds — and the
     * incident between them counting for neither.
     */
    public function testEachZoneCountsTheIncidentsFiledOnItsOwnGround(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $east = $this->aZone($area, 'East', self::EAST_INNER_EDGE, self::EAST_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-03 07:00:00'), position: self::point(self::IN_WEST));
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-11 07:00:00'), position: self::point(self::IN_WEST));
        $this->anIncident($area, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-14 07:00:00'), position: self::point(self::IN_EAST));
        $this->anIncident($area, 'roadkill', 'Impala roadkill', new \DateTimeImmutable('2026-08-15 07:00:00'), position: self::point(self::IN_NEITHER));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $west, $east));

        self::assertSame(2.0, self::figure($figures->forZone((string) $west->getUuidString()), 'incidents')->value);
        self::assertSame(1.0, self::figure($figures->forZone((string) $east->getUuidString()), 'incidents')->value);
    }

    /** A point outside every zone of the area belongs to no zone's figures. */
    public function testAPointInsideNoZoneCountsForNoZone(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $east = $this->aZone($area, 'East', self::EAST_INNER_EDGE, self::EAST_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-15 07:00:00'), position: self::point(self::IN_NEITHER));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $west, $east));

        self::assertTrue($figures->isEmpty(), 'An incident on nobody’s ground gives nobody a figure.');
    }

    /** Ground outside the window is ground nobody asked about. */
    public function testOnlyTheAskedPeriodIsCounted(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-31 23:00:00'), position: self::point(self::IN_WEST));
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-01 00:00:00'), position: self::point(self::IN_WEST));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $west));

        self::assertSame(1.0, self::figure($figures->forZone((string) $west->getUuidString()), 'incidents')->value);
    }

    /**
     * OPEN IS READ AT THE PERIOD'S END, not today and not "filed this month":
     * work resolved before the window closed is not open in it, and work filed
     * long before it and still unresolved is.
     */
    public function testOpenIsWhatWasStillOpenWhenThePeriodClosed(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-06-04 07:00:00'), position: self::point(self::IN_WEST));
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-05 07:00:00'), position: self::point(self::IN_WEST));
        $resolved = $this->anIncident($area, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-06 07:00:00'), position: self::point(self::IN_WEST));
        $at = new \DateTimeImmutable('2026-08-07 07:00:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($resolved, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();

        $zoneFigures = $this->provider()->figuresFor(self::request($period, $west))->forZone((string) $west->getUuidString());

        self::assertSame(2.0, self::figure($zoneFigures, 'incidents')->value, 'Two were filed in August; the June one was not.');
        self::assertSame(2.0, self::figure($zoneFigures, 'incidents_open')->value, 'The June one is still open, and one of August’s was resolved inside the window.');
    }

    /**
     * THE MONEY IS THE DEPARTMENT SEAM'S OWN: the same keys, the same payable
     * fallback, on the incidents whose point falls in the zone.
     */
    public function testMoneyIsTheSameFigureTheDepartmentSeamPublishes(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $east = $this->aZone($area, 'East', self::EAST_INNER_EDGE, self::EAST_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $fine = $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-05 07:00:00'), position: self::point(self::IN_WEST));
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $claim = $this->anIncident($area, at: new \DateTimeImmutable('2026-08-09 07:00:00'), position: self::point(self::IN_EAST));
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)->setApproved(1_200_000);
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $west, $east));
        $westFigures = $figures->forZone((string) $west->getUuidString());
        $eastFigures = $figures->forZone((string) $east->getUuidString());

        self::assertSame(450_000.0, self::figure($westFigures, 'incidents_fine')->value);
        self::assertSame('TZS', self::figure($westFigures, 'incidents_fine')->unit);
        self::assertSame(1_200_000.0, self::figure($eastFigures, 'incidents_compensation')->value);
        self::assertSame([], array_filter($westFigures, static fn (DepartmentKpi $kpi) => 'incidents_compensation' === $kpi->key));
    }

    /** An area whose zones hold nothing is an empty answer, never a row of zeros. */
    public function testAnAreaWithNoIncidentsIsAnsweredWithNothing(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $east = $this->aZone($area, 'East', self::EAST_INNER_EDGE, self::EAST_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $figures = $this->provider()->figuresFor(self::request($period, $west, $east));

        self::assertSame([], $figures->byZone);
        self::assertTrue($figures->isEmpty());
        self::assertSame($period, $figures->period);
    }

    /** An area with no zones at all asks about nothing, and is answered about nothing. */
    public function testAnAreaWithNoZonesIsAnsweredWithNothing(): void
    {
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        self::assertTrue($this->provider()->figuresFor(new ZoneFigureRequest([], $period))->isEmpty());
    }

    /** The answer states the window it measured, and a caption names the zone. */
    public function testTheAnswerStatesThePeriodItMeasuredAndNamesTheZone(): void
    {
        $area = $this->anAreaWithKinds();
        $west = $this->aZone($area, 'West', self::WEST_EDGE, self::WEST_INNER_EDGE);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-03 07:00:00'), position: self::point(self::IN_WEST));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $west));
        $filed = self::figure($figures->forZone((string) $west->getUuidString()), 'incidents');

        self::assertSame($period, $figures->period);
        self::assertSame('Incidents module · West', $filed->caption);
        self::assertNull($filed->areaName, 'A zone figure is nobody’s share of a roll-up.');
        self::assertNull($filed->previous, 'Nothing here is scored against the month before.');
    }

    private function provider(): IncidentZoneFigureProvider
    {
        /** @var IncidentRepository $incidents */
        $incidents = static::getContainer()->get(IncidentRepository::class);

        return new IncidentZoneFigureProvider($incidents, 'incidents', 'Incidents', 'TZS');
    }

    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');

        return $transitions;
    }

    private static function request(FigurePeriod $period, Zone ...$zones): ZoneFigureRequest
    {
        $refs = [];
        foreach ($zones as $zone) {
            $area = $zone->getArea();
            self::assertNotNull($area);
            $refs[] = new ZoneRef((string) $zone->getUuidString(), (string) $area->getUuidString(), (string) $zone->getName());
        }

        return new ZoneFigureRequest($refs, $period);
    }

    /** A point on the zones' shared latitude, at the longitude the caller names. */
    private static function point(float $longitude): string
    {
        return \sprintf('{"type":"Point","coordinates":[%F,-3.21]}', $longitude);
    }

    /** @param list<DepartmentKpi> $figures */
    private static function figure(array $figures, string $key): DepartmentKpi
    {
        foreach ($figures as $figure) {
            if ($key === $figure->key) {
                return $figure;
            }
        }

        self::fail(\sprintf('No figure was published under "%s".', $key));
    }
}
