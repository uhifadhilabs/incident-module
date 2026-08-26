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

namespace UhifadhiLabs\Incident\Tests\Integration\Module;

use Uhifadhi\Entity\Department;
use Uhifadhi\Module\DepartmentKpi;
use UhifadhiLabs\Incident\Entity\IncidentMoney;
use UhifadhiLabs\Incident\Enum\IncidentTransitionEnum;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;
use UhifadhiLabs\Incident\Module\IncidentDepartmentKpiProvider;
use UhifadhiLabs\Incident\Repository\IncidentRepository;
use UhifadhiLabs\Incident\Service\IncidentTransitionService;
use UhifadhiLabs\Incident\Tests\Integration\IntegrationTestCase;

/**
 * WHAT A DEPARTMENT'S PEOPLE DID WITH THIS MODULE — the host's performance seam,
 * against real rows.
 *
 * THE SLICE IS THE THING UNDER TEST. An incident is a department's because the
 * person who RECORDED it holds a position filed under that department. Two
 * departments read the same rows and get different numbers, and neither is fenced
 * out of the other's.
 */
final class IncidentDepartmentKpiProviderTest extends IntegrationTestCase
{
    private function provider(): IncidentDepartmentKpiProvider
    {
        /** @var IncidentRepository $incidents */
        $incidents = static::getContainer()->get(IncidentRepository::class);

        return new IncidentDepartmentKpiProvider($incidents, 'incidents', 'Incidents', 'TZS');
    }

    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');

        return $transitions;
    }

    /** @return array<string, DepartmentKpi> */
    private function kpisFor(Department $department, \DateTimeImmutable $now): array
    {
        $byKey = [];
        foreach ($this->provider()->kpisFor($department, $now) as $kpi) {
            $byKey[$kpi->key] = $kpi;
        }

        return $byKey;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->installTaxonomy();
    }

    /** The slug must match the module's, or the host never asks this provider anything. */
    public function testItAnswersForTheIncidentsModule(): void
    {
        self::assertSame('incidents', $this->provider()->moduleSlug());
    }

    /**
     * NOTHING RECORDED IS NOTHING REPORTED — not a row of zeros. The host draws a
     * dashed labelled slot, which is the truthful rendering of "we have no
     * reading".
     */
    public function testADepartmentThatRecordedNothingReportsNothing(): void
    {
        $department = $this->aDepartment();

        self::assertSame([], $this->provider()->kpisFor($department, new \DateTimeImmutable('2026-08-22')));
    }

    public function testItCountsWhatThisDepartmentsPeopleRecorded(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-2 days'), reportedBy: $ranger);
        $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'), $ranger);

        $kpis = $this->kpisFor($department, $now);

        self::assertSame(2.0, $kpis['incidents']->value);
        self::assertSame('Incidents recorded', $kpis['incidents']->label);
        self::assertSame('incidents', $kpis['incidents']->moduleSlug);
    }

    /**
     * TWO DEPARTMENTS, THE SAME ROWS, DIFFERENT NUMBERS — and neither is fenced
     * out of the other's. This is the whole model in one assertion.
     */
    public function testTwoDepartmentsReadTheSameRowsAndGetDifferentNumbers(): void
    {
        $area = $this->anArea();
        $protection = $this->aDepartment('Protection Service');
        $ecology = $this->aDepartment('Ecology');
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $protection);
        $ecologist = $this->aUser('ecologist@example.test', 'Anna', 'Kileo', $ecology);

        $this->anIncident($area, at: $now->modify('-3 days'), reportedBy: $ranger);
        $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-2 days'), $ranger);
        $this->anIncident($area, 'roadkill', 'Zebra roadkill', $now->modify('-1 day'), $ecologist);

        self::assertSame(2.0, $this->kpisFor($protection, $now)['incidents']->value);
        self::assertSame(1.0, $this->kpisFor($ecology, $now)['incidents']->value);
    }

    /** An incident nobody's department can claim belongs to nobody's figures. */
    public function testAnIncidentWithNoRecorderBelongsToNoDepartment(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-1 day'), reportedBy: $ranger);
        // Recorded by nobody — a seeded or imported row.
        $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'));

        self::assertSame(1.0, $this->kpisFor($department, $now)['incidents']->value);
    }

    /**
     * "RESOLVED WITHIN TERM" IS THE HONEST READING OF BREACHES: the same fact,
     * pointing the way the host's seam requires (bigger is better), and NULL —
     * never 0% — while nothing has been resolved.
     */
    public function testTheWithinTermShareIsNullUntilSomethingIsResolved(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: $now->modify('-1 day'), reportedBy: $ranger);

        $kpi = $this->kpisFor($department, $now)['incidents_in_term'];
        self::assertFalse($kpi->isKnown(), 'A month with no finished work has no compliance rate.');
        self::assertSame("\u{2014}", $kpi->display());
        self::assertTrue($kpi->isShare());
    }

    public function testResolvedWorkIsScoredAgainstItsOwnCategorysTerm(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        // A mortality carries no money, so it walks straight through.
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass', $now->modify('-3 days'), $ranger);
        $at = $now->modify('-3 days');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();

        $kpis = $this->kpisFor($department, $now);
        self::assertSame(1.0, $kpis['incidents_resolved']->value);
        // Resolved in three hours against a 168-hour term: fully within it.
        self::assertSame(100.0, $kpis['incidents_in_term']->value);
    }

    /**
     * MONEY IN TWO PLATES, NEVER ONE. The design refuses to add a fine and a
     * claim together anywhere, and a performance page is not an exception.
     */
    public function testFinesAndCompensationAreTwoPlatesAndAreNeverSummed(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $claim = $this->anIncident($area, at: $now->modify('-2 days'), reportedBy: $ranger);
        new IncidentMoney($claim, MoneyDirectionEnum::Compensation)->setApproved(1_200_000);
        $fine = $this->anIncident($area, 'snaring', 'Snare line lifted', $now->modify('-1 day'), $ranger);
        new IncidentMoney($fine, MoneyDirectionEnum::Fine)->setAssessed(450_000);
        $this->em->flush();

        $kpis = $this->kpisFor($department, $now);
        self::assertSame(450_000.0, $kpis['incidents_fine']->value);
        self::assertSame(1_200_000.0, $kpis['incidents_compensation']->value);
        self::assertSame('TZS', $kpis['incidents_fine']->unit);
    }

    /**
     * A department that has never touched money of a kind gets NO plate for it —
     * absent, rather than a dashed slot. A dashed slot means "we could not
     * measure"; this means "that is not our work".
     */
    public function testADepartmentThatTouchesNoMoneyGetsNoMoneyPlates(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass', $now->modify('-1 day'), $ranger);

        $kpis = $this->kpisFor($department, $now);
        self::assertArrayHasKey('incidents', $kpis);
        self::assertArrayNotHasKey('incidents_fine', $kpis);
        self::assertArrayNotHasKey('incidents_compensation', $kpis);
    }

    /** Last month's figure travels with this month's, for the move the host prints. */
    public function testItCarriesLastMonthForTheMonthOverMonthMove(): void
    {
        $area = $this->anArea();
        $department = $this->aDepartment();
        $ranger = $this->aUser('ranger@example.test', 'Joseph', 'Mollel', $department);
        $now = new \DateTimeImmutable('2026-08-22 09:00:00');

        $this->anIncident($area, at: new \DateTimeImmutable('2026-07-10 09:00:00'), reportedBy: $ranger);
        $this->anIncident($area, 'snaring', 'Snare line lifted', new \DateTimeImmutable('2026-08-10 09:00:00'), $ranger);
        $this->anIncident($area, 'bushmeat', 'Bushmeat seized', new \DateTimeImmutable('2026-08-11 09:00:00'), $ranger);

        $kpi = $this->kpisFor($department, $now)['incidents'];

        self::assertSame(2.0, $kpi->value);
        self::assertSame(1.0, $kpi->previous);
        self::assertSame('+100%', $kpi->deltaLabel());
        self::assertSame('good', $kpi->direction());
    }
}
