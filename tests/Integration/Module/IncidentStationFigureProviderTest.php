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

use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Kpi\DepartmentKpi;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Kpi\StationFigureProviderInterface;
use Uhifadhi\Contracts\Kpi\StationFigureRequest;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Module\IncidentStationFigureProvider;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE INCIDENTS HEADLINE A STATION DOCK PRINTS, against real PostGIS ground.
 *
 * THE DISTANCE IS THE THING UNDER TEST, and it is measured by the database and
 * not by this file: two posts stand 30 km apart on one latitude and the
 * incidents are filed at real coordinates 5 km and 20 km east of the first, so
 * the near one is inside the first post's radius and outside the second's, and
 * the far one the other way round. Nothing here asserts a distance it computed
 * itself; the assertions are about which post ends up with which filing.
 */
final class IncidentStationFigureProviderTest extends IntegrationTestCase
{
    /** The latitude everything in these fixtures stands on. */
    private const float LATITUDE = -3.21;

    /** The first post, and the longitude every offset below is measured from. */
    private const float FIRST_STATION = -29.90;

    /**
     * Degrees of longitude in a kilometre at {@see LATITUDE} — the only
     * geodesy the fixtures do, and only to PLACE the points. Whether a placed
     * point is within the radius is PostGIS's answer, and the offsets are far
     * enough from the 12 km edge that the approximation cannot decide a case.
     */
    private const float DEGREES_PER_KM = 0.0089972;

    public function testItAnswersForTheIncidentsModule(): void
    {
        self::assertSame('incidents', $this->provider()->moduleSlug());
    }

    /**
     * EVERY POST IN ONE ANSWER, each counting the filings inside its own
     * radius — and a filing near enough to two posts counts for both, because
     * a radius is not a partition of the ground.
     */
    public function testEachStationCountsTheIncidentsFiledWithinItsRadius(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $second = $this->aStation($area, 'Rim', self::longitudeKmEast(30.0));
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        // 5 km from the first post: inside its radius, 25 km from the second.
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-03 07:00:00'), position: self::pointKmEast(5.0));
        // 20 km from the first post: outside its radius, 10 km from the second.
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-11 07:00:00'), position: self::pointKmEast(20.0));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $first, $second));

        self::assertSame(1.0, self::headline($figures->forStation((string) $first->getUuidString()))->value);
        self::assertSame(1.0, self::headline($figures->forStation((string) $second->getUuidString()))->value);
    }

    /** A filing beyond the radius of every post is nobody's headline. */
    public function testAnIncidentBeyondEveryRadiusCountsForNoStation(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $second = $this->aStation($area, 'Rim', self::longitudeKmEast(30.0));
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        // Halfway between the two, and 15 km from each.
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-15 07:00:00'), position: self::pointKmEast(15.0));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $first, $second));

        self::assertTrue($figures->isEmpty(), 'A filing nobody stands near gives nobody a headline.');
    }

    /** Filings outside the window are filings nobody asked about. */
    public function testOnlyTheAskedPeriodIsCounted(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-31 23:00:00'), position: self::pointKmEast(5.0));
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-01 00:00:00'), position: self::pointKmEast(5.0));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $first));

        self::assertSame(1.0, self::headline($figures->forStation((string) $first->getUuidString()))->value);
    }

    /**
     * ONE ROW, AND THE REST IN THE CAPTION: the radius the number was measured
     * with, and how many of the post's incidents were still open WHEN THE
     * PERIOD CLOSED — whenever they were filed, because a backlog is not a
     * month's filings.
     */
    public function testTheCaptionCarriesTheRadiusAndWhatWasStillOpen(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-05 07:00:00'), position: self::pointKmEast(5.0));
        $resolved = $this->anIncident($area, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-06 07:00:00'), position: self::pointKmEast(5.0));
        $at = new \DateTimeImmutable('2026-08-07 07:00:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($resolved, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();

        $headline = self::headline($this->provider()->figuresFor(self::request($period, $first))->forStation((string) $first->getUuidString()));

        self::assertSame(2.0, $headline->value, 'Both were filed in August within 12 km.');
        self::assertSame('within 12 km · 1 open', $headline->caption);
    }

    /** A post that saw nothing is left out, never published at zero. */
    public function testAStationWithNothingNearbyIsLeftOut(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $second = $this->aStation($area, 'Rim', self::longitudeKmEast(30.0));
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-03 07:00:00'), position: self::pointKmEast(5.0));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $first, $second));

        self::assertSame([], $figures->forStation((string) $second->getUuidString()));
    }

    /** An area whose posts saw nothing is an empty answer, never a row of zeros. */
    public function testAnAreaWithNoIncidentsIsAnsweredWithNothing(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $figures = $this->provider()->figuresFor(self::request($period, $first));

        self::assertSame([], $figures->byStation);
        self::assertTrue($figures->isEmpty());
        self::assertSame($period, $figures->period);
    }

    /** An area with no posts at all asks about nothing, and is answered about nothing. */
    public function testAnAreaWithNoStationsIsAnsweredWithNothing(): void
    {
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        self::assertTrue($this->provider()->figuresFor(new StationFigureRequest([], $period))->isEmpty());
    }

    /** The answer states the window it measured, under the key the dock reads. */
    public function testTheAnswerStatesThePeriodItMeasuredUnderTheDocksKey(): void
    {
        $area = $this->anAreaWithKinds();
        $first = $this->aStation($area, 'Gate', self::FIRST_STATION);
        $period = FigurePeriod::month(new \DateTimeImmutable('2026-08-22 09:00:00'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-03 07:00:00'), position: self::pointKmEast(5.0));
        $this->em->flush();

        $figures = $this->provider()->figuresFor(self::request($period, $first));
        $forStation = $figures->forStation((string) $first->getUuidString());
        $headline = self::headline($forStation);

        self::assertSame($period, $figures->period);
        self::assertCount(1, $forStation, 'The dock draws one row per module.');
        self::assertSame(StationFigureProviderInterface::HEADLINE, $headline->key);
        self::assertSame('incidents', $headline->moduleSlug);
        self::assertNull($headline->areaName, 'A station figure is nobody’s share of a roll-up.');
        self::assertNull($headline->previous, 'Nothing here is scored against the month before.');
    }

    private function provider(): IncidentStationFigureProvider
    {
        /** @var IncidentRepository $incidents */
        $incidents = static::getContainer()->get(IncidentRepository::class);

        return new IncidentStationFigureProvider($incidents, 'incidents', 'Incidents');
    }

    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');

        return $transitions;
    }

    private static function request(FigurePeriod $period, Station ...$stations): StationFigureRequest
    {
        $refs = [];
        foreach ($stations as $station) {
            $area = $station->getArea();
            self::assertNotNull($area);
            $refs[] = new StationRef((string) $station->getUuidString(), (string) $area->getUuidString(), (string) $station->getName());
        }

        return new StationFigureRequest($refs, $period);
    }

    private static function longitudeKmEast(float $km): float
    {
        return self::FIRST_STATION + $km * self::DEGREES_PER_KM;
    }

    private static function pointKmEast(float $km): string
    {
        return \sprintf('{"type":"Point","coordinates":[%F,%F]}', self::longitudeKmEast($km), self::LATITUDE);
    }

    /** @param list<DepartmentKpi> $figures */
    private static function headline(array $figures): DepartmentKpi
    {
        foreach ($figures as $figure) {
            if (StationFigureProviderInterface::HEADLINE === $figure->key) {
                return $figure;
            }
        }

        self::fail('No headline figure was published for that station.');
    }
}
