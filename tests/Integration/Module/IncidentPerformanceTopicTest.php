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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Entity\Module;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Incident\Module\IncidentPerformanceTopic;
use Uhifadhi\Incident\Tests\Integration\Fixtures\CollectedTopicProviders;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE INCIDENTS TOPIC, against real rows in a real PostGIS database.
 *
 * FOUR THINGS ARE UNDER TEST HERE and nothing that a unit test already proves:
 * that five figures come out whatever the records say, that the rows are ONLY
 * the departments that attach this module, that a scope narrows both the
 * figures and the row set, and that the three absences the contract insists on
 * stay apart — a hole in a history, a figure nobody can compute, and a column
 * a department was never asked.
 */
final class IncidentPerformanceTopicTest extends IntegrationTestCase
{
    private const string AUGUST = '2026-08-19 09:00:00';

    /** The topic is the wired one, so the tag and the arguments are under test too. */
    private function topic(): IncidentPerformanceTopic
    {
        /** @var IncidentPerformanceTopic $topic */
        $topic = $this->service('incident.performance_topic');

        return $topic;
    }

    private static function period(string $at = self::AUGUST): FigurePeriod
    {
        return FigurePeriod::month(new \DateTimeImmutable($at));
    }

    /**
     * THE CATALOGUE ROW THIS MODULE IS ATTACHED BY. In an installation the
     * registry reconciles itself on a cache warm-up; the schema here is rebuilt
     * after the kernel booted, so the reconciliation is run again by hand.
     */
    private function catalogueRow(): Module
    {
        /** @var RegistrySyncService $registry */
        $registry = $this->service('registry.sync');
        $registry->sync();

        foreach ($this->em->getRepository(Module::class)->findAll() as $module) {
            if ('incidents' === (string) $module->getSlug()) {
                return $module;
            }
        }

        self::fail('The registry holds no incidents row, so no department could attach the module.');
    }

    /** A department that READS Incidents — the only kind this topic has a row for. */
    private function aReadingDepartment(string $name, ?AreaOfInterest $area = null): Department
    {
        $department = $this->aDepartment($name);
        $department->attachModule($this->catalogueRow());
        if (null !== $area) {
            $department->setArea($area);
        }
        $this->em->flush();

        return $department;
    }

    /** @return array<string, TopicKpi> */
    private function kpis(PerformanceScope $scope, ?FigurePeriod $period = null): array
    {
        $byKey = [];
        foreach ($this->topic()->kpis($scope, $period ?? self::period()) as $kpi) {
            $byKey[$kpi->key] = $kpi;
        }

        return $byKey;
    }

    /** @return array<string, MatrixRow> */
    private function rows(PerformanceScope $scope, ?FigurePeriod $period = null): array
    {
        $byName = [];
        foreach ($this->topic()->matrix($scope, $period ?? self::period())->rows as $row) {
            $byName[$row->departmentName] = $row;
        }

        return $byName;
    }

    public function testItPublishesTheIncidentsTopic(): void
    {
        self::assertSame('incidents', $this->topic()->moduleSlug());
        self::assertSame('incidents', $this->topic()->key());
        self::assertSame('Incidents', $this->topic()->title());
    }

    /** The tag is applied by hand; this is what proves it stuck. */
    public function testTheTopicReachesThePerformancePageThroughItsTag(): void
    {
        /** @var CollectedTopicProviders $collected */
        $collected = static::getContainer()->get(CollectedTopicProviders::class);

        self::assertArrayHasKey('incidents', $collected->byKey());
        self::assertInstanceOf(IncidentPerformanceTopic::class, $collected->byKey()['incidents']);
    }

    /**
     * FIVE, AND ALWAYS FIVE — with nothing recorded anywhere, so the row of
     * five is not something the data happened to produce.
     */
    public function testFiveHeadlineFiguresEvenWithNothingRecorded(): void
    {
        $kpis = $this->kpis(PerformanceScope::organisation());

        self::assertSame([
            IncidentPerformanceTopic::FILED,
            IncidentPerformanceTopic::OPEN_PAST_TARGET,
            IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE,
            IncidentPerformanceTopic::CLAIMS_OPEN,
            IncidentPerformanceTopic::RESOLVED,
        ], array_keys($kpis));
        self::assertCount(5, $kpis);
    }

    public function testTheHeadlineCountsEveryIncidentInScope(): void
    {
        $area = $this->anAreaWithKinds('North Sector');
        $ranger = $this->aUser('ps@example.test', 'N', 'Kileo', $this->aReadingDepartment('Protection Service'));

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'), reportedBy: $ranger);
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $this->em->flush();

        $kpis = $this->kpis(PerformanceScope::organisation());

        // Two filed, one of them by nobody seated — still in the headline.
        self::assertSame(2.0, $kpis[IncidentPerformanceTopic::FILED]->value);
        self::assertSame('1 Protection Service', $kpis[IncidentPerformanceTopic::FILED]->caption);
    }

    /** A median over nothing finished is NULL, and says so. */
    public function testTheMedianIsNullWhileNothingWasFinishedInThePeriod(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->em->flush();

        $median = $this->kpis(PerformanceScope::organisation())[IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE];

        self::assertFalse($median->isKnown());
        self::assertSame('nothing finished in this period', $median->caption);
        self::assertSame('d', $median->unit);
    }

    /**
     * ONLY THE DEPARTMENTS THAT ATTACH INCIDENTS ARE ROWS. A department that
     * does not read this module is not a row of dashes; it is not a row.
     */
    public function testTheRowsAreOnlyTheDepartmentsThatAttachTheModule(): void
    {
        $this->aReadingDepartment('Protection Service');
        $this->aDepartment('Human Resource');

        self::assertSame(['Protection Service'], array_keys($this->rows(PerformanceScope::organisation())));
    }

    /**
     * DEPARTMENT-AS-A-LENS: a department's figures are the incidents whose
     * RECORDING POSITION sits in it — and a row nobody seated filed is in the
     * headline and in nobody's row.
     */
    public function testADepartmentsFiguresAreTheIncidentsItsOwnRecordersFiled(): void
    {
        $area = $this->anAreaWithKinds();
        $protection = $this->aReadingDepartment('Protection Service');
        $community = $this->aReadingDepartment('Community Development');
        $ranger = $this->aUser('ranger@example.test', 'N', 'Kileo', $protection);
        $officer = $this->aUser('officer@example.test', 'A', 'Mollel', $community);

        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'), reportedBy: $ranger);
        $this->anIncident($area, 'snaring', 'Snare one', new \DateTimeImmutable('2026-08-05 09:00:00'), $ranger);
        $this->anIncident($area, 'crop-raiding', 'Maize trampled', new \DateTimeImmutable('2026-08-06 09:00:00'), $officer);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill', new \DateTimeImmutable('2026-08-07 09:00:00'));
        $this->em->flush();

        $rows = $this->rows(PerformanceScope::organisation());

        self::assertSame(2.0, $rows['Protection Service']->cells[IncidentPerformanceTopic::FILED]->value);
        self::assertSame(1.0, $rows['Community Development']->cells[IncidentPerformanceTopic::FILED]->value);
        self::assertSame(4.0, $this->kpis(PerformanceScope::organisation())[IncidentPerformanceTopic::FILED]->value);
    }

    /**
     * A SCOPE NARROWS BOTH ENDS: the figures read that area's incidents, and
     * the rows are that area's departments plus the organisation-wide ones.
     */
    public function testAnAreaScopeNarrowsTheFiguresAndTheRowSet(): void
    {
        $north = $this->anAreaWithKinds('North Sector');
        $south = $this->anAreaWithKinds('South Sector');

        $orgWide = $this->aReadingDepartment('Protection Service');
        $northDepartment = $this->aReadingDepartment('North Ecology', $north);
        $this->aReadingDepartment('South Ecology', $south);

        $ranger = $this->aUser('ranger@example.test', 'N', 'Kileo', $orgWide);
        $ecologist = $this->aUser('eco@example.test', 'A', 'Mollel', $northDepartment);

        $this->anIncident($north, at: new \DateTimeImmutable('2026-08-04 09:00:00'), reportedBy: $ranger);
        $this->anIncident($south, 'snaring', 'Snare line', new \DateTimeImmutable('2026-08-05 09:00:00'), $ecologist);
        $this->em->flush();

        $scope = PerformanceScope::area((string) $north->getUuidString(), 'North Sector');

        self::assertSame(1.0, $this->kpis($scope)[IncidentPerformanceTopic::FILED]->value);
        // The area's own department AND the organisation-wide one, in the
        // order the host keeps departments in.
        self::assertSame(
            ['North Ecology', 'Protection Service'],
            array_keys($this->rows($scope)),
        );
        // The org-wide department's own row is that area's incidents too.
        self::assertSame(1.0, $this->rows($scope)['Protection Service']->cells[IncidentPerformanceTopic::FILED]->value);
        // And the incident the ecologist filed in the SOUTH is in no northern row.
        self::assertSame(0.0, $this->rows($scope)['North Ecology']->cells[IncidentPerformanceTopic::FILED]->value);
    }

    /** The band is what a placing runs inside: the area's name, or org-wide. */
    public function testEachRowSaysWhatItIsPlacedAmong(): void
    {
        $area = $this->anAreaWithKinds('North Sector');
        $this->aReadingDepartment('Protection Service');
        $this->aReadingDepartment('North Ecology', $area);

        $rows = $this->rows(PerformanceScope::organisation());

        self::assertSame('Org-wide', $rows['Protection Service']->band);
        self::assertSame('North Sector', $rows['North Ecology']->band);
    }

    /**
     * A PERIOD THE SCOPE HOLDS NOTHING IN AT ALL IS A HOLE, not a nought —
     * and a movement measured against a hole is no movement at all.
     */
    public function testAPeriodNobodyRecordedInIsAHoleInTheHistory(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->em->flush();

        $filed = $this->kpis(PerformanceScope::organisation())[IncidentPerformanceTopic::FILED];

        self::assertCount(6, $filed->history);
        self::assertSame([null, null, null, null, null, 1.0], $filed->history);
        self::assertNull($filed->delta, 'July recorded nothing, so nothing moved — it began.');
    }

    /** With both periods recorded, the movement is a real one. */
    public function testAMovementIsDrawnOnceThereAreTwoPeriodsToCompare(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-04 09:00:00'));
        $this->anIncident($area, 'snaring', 'Snare one', new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->anIncident($area, 'roadkill', 'Zebra', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $this->em->flush();

        $filed = $this->kpis(PerformanceScope::organisation())[IncidentPerformanceTopic::FILED];

        self::assertSame([null, null, null, null, 1.0, 2.0], $filed->history);
        self::assertSame(1.0, $filed->delta);
    }

    /**
     * THE THIRD ABSENCE. A department whose areas have written no
     * compensation-bearing words was never asked the question, and its cell is
     * a dash rather than a nought — while a department whose areas do run
     * compensation and claimed nothing scored a real nought.
     */
    public function testTheCompensationColumnIsNotADepartmentsToAnswerWhereNothingRunsCompensation(): void
    {
        $wordless = $this->anArea('Wordless Sector');
        $spoken = $this->anAreaWithKinds('North Sector');

        $this->aReadingDepartment('Wordless Ecology', $wordless);
        $this->aReadingDepartment('North Ecology', $spoken);

        $rows = $this->rows(PerformanceScope::organisation());

        $notMine = $rows['Wordless Ecology']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS];
        self::assertEquals(MatrixCell::notMine(), $notMine);
        self::assertTrue($notMine->notMine);
        self::assertFalse($notMine->isKnown());

        $asked = $rows['North Ecology']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS];
        self::assertFalse($asked->notMine, 'This area runs compensation, so the question was asked.');
    }

    /** And a claim that arrived is counted in that column. */
    public function testAClaimThatArrivedIsCountedInTheColumnAndInTheOpenFigure(): void
    {
        $area = $this->anAreaWithKinds();
        $department = $this->aReadingDepartment('Community Development', $area);
        $officer = $this->aUser('officer@example.test', 'A', 'Mollel', $department);

        // livestock-depredation runs compensation; snaring runs a fine.
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'), reportedBy: $officer);
        $this->anIncident($area, 'snaring', 'Snare line', new \DateTimeImmutable('2026-08-05 09:00:00'), $officer);
        $this->em->flush();

        $scope = PerformanceScope::area((string) $area->getUuidString(), 'Sample Area');

        self::assertSame(
            1.0,
            $this->rows($scope)['Community Development']->cells[IncidentPerformanceTopic::COMPENSATION_CLAIMS]->value,
        );
        self::assertSame(1.0, $this->kpis($scope)[IncidentPerformanceTopic::CLAIMS_OPEN]->value);
    }

    /**
     * TWO CHARTS, both stated as shapes over the same six periods the
     * sparklines run on.
     */
    public function testItPublishesTheFlowAndTheBacklogCharts(): void
    {
        $area = $this->anAreaWithKinds();
        $this->anIncident($area, at: new \DateTimeImmutable('2026-08-04 09:00:00'));
        $this->em->flush();

        $charts = $this->topic()->charts(PerformanceScope::organisation(), self::period());

        self::assertCount(2, $charts);
        self::assertSame('incidents.flow', $charts[0]->key);
        self::assertSame(ChartKind::Line, $charts[0]->kind);
        self::assertSame(['mar', 'apr', 'may', 'jun', 'jul', 'aug'], $charts[0]->labels);
        self::assertCount(2, $charts[0]->series);
        self::assertSame([null, null, null, null, null, 1.0], $charts[0]->series[0]->points);

        self::assertSame('incidents.age', $charts[1]->key);
        self::assertSame(ChartKind::Bar, $charts[1]->kind);
        self::assertSame(['0–7 d', '8–14 d', '15–21 d', 'over 21 d'], $charts[1]->labels);
        self::assertFalse($charts[1]->isEmpty(), 'One incident is open, so the backlog has a bar.');
    }

    /** A backlog nobody has is an absence of bars, and the chart drops itself. */
    public function testTheBacklogChartDrawsNothingWhenNothingIsOpen(): void
    {
        $charts = $this->topic()->charts(PerformanceScope::organisation(), self::period());

        self::assertTrue($charts[1]->isEmpty());
    }
}
