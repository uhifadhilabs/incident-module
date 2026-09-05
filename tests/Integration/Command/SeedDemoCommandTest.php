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

namespace Uhifadhi\Incident\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE DESIGN'S SAMPLE MONTH, SEEDED — and then read back through the DASHBOARD.
 *
 * {@see \Uhifadhi\Incident\Tests\Unit\Model\DemoMonthTest} adds the table up;
 * this proves the table survives being written to a real database and walked
 * through the real workflow, and that the widgets then print the numbers the
 * preset gallery states.
 *
 * That last part is the one that matters: every screenshot in the design app is a
 * claim about what the product shows, and this is where that claim is checked.
 */
final class SeedDemoCommandTest extends IntegrationTestCase
{
    /** @param array<string, string> $input */
    private function seed(array $input = []): CommandTester
    {
        $tester = $this->tester();
        $tester->execute($input);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /** The command, out of the booted kernel's own console application. */
    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel, 'IntegrationTestCase boots the kernel in setUp().');

        return new CommandTester(new Application($kernel)->find('incidents:seed:demo'));
    }

    public function testItSeedsTheFortySevenIncidentsTheGalleryTalksAbout(): void
    {
        $this->anArea('Sample Area');

        $this->seed();

        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
    }

    /** It installs the taxonomy itself: a seeder that failed for a missing install step sends people bug-hunting. */
    public function testItInstallsTheTaxonomyBeforeFilingAnything(): void
    {
        $this->anArea();

        $this->seed();

        self::assertSame('livestock depredation', $this->subcategory('livestock-depredation')->getLabel());
    }

    /**
     * IDEMPOTENT AND NON-DESTRUCTIVE. Running it twice is a no-op, and running it
     * after somebody has worked the demo data does not undo their work.
     */
    public function testASecondRunFilesNothing(): void
    {
        $this->anArea();
        $this->seed();
        $this->em->clear();

        $tester = $this->seed();

        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
        self::assertStringContainsString('already there', $tester->getDisplay());
    }

    /**
     * THE WORKFLOW WAS WALKED, NOT WRITTEN. Every seeded incident got where it is
     * one legal transition at a time, through the real service — so the seeder
     * cannot produce a state the product could not, and every one has a timeline.
     */
    public function testEverySeededIncidentWalkedTheRealWorkflow(): void
    {
        $this->anArea();
        $this->seed();
        $this->em->clear();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            // The filing, plus one event per transition it took to get here —
            // which is exactly the place's step number: `reported` is 1 event,
            // `closed` is 5.
            $expected = $incident->getStatus()->step();
            self::assertCount(
                $expected,
                $incident->getEvents(),
                \sprintf('%s is %s but its timeline has %d events.', $incident->getReference(), $incident->getStatus()->value, $incident->getEvents()->count()),
            );
        }
    }

    /**
     * THE NUMBERS THE GALLERY STATES, read back through the dashboard the product
     * actually renders.
     */
    public function testTheDashboardPrintsTheGallerysOwnNumbers(): void
    {
        $area = $this->anArea();
        $this->seed();
        $this->em->clear();

        /** @var IncidentDashboardService $service */
        $service = $this->service('incident.dashboard');
        $area = $this->em->getRepository($area::class)->find($area->getId());
        self::assertNotNull($area);

        $from = new \DateTimeImmutable(DemoMonth::MONTH.'-01 00:00:00');
        $dashboard = $service->build(
            new IncidentFilter($area, $from, $from->modify('+1 month')),
            $from->modify('+21 days'),
        );

        self::assertSame(47, $dashboard->filedCount, 'The gallery says 47 filed.');
        self::assertSame(31, $dashboard->openCount(), 'The gallery says 31 still open.');
        self::assertSame(7, $dashboard->statusCount(IncidentStatusEnum::Reported));
        self::assertSame(13, $dashboard->statusCount(IncidentStatusEnum::Verified));
        self::assertSame(11, $dashboard->statusCount(IncidentStatusEnum::InProgress));

        // 18 conflict · 12 poaching · 9 compliance · 8 mortality.
        self::assertSame(18, $dashboard->categoryCounts['conflict']);
        self::assertSame(12, $dashboard->categoryCounts['poaching']);
        self::assertSame(9, $dashboard->categoryCounts['compliance']);
        self::assertSame(8, $dashboard->categoryCounts['mortality']);

        // TZS 8.45M assessed in fines, 9.2M approved in compensation.
        self::assertSame(8_450_000, $dashboard->money[MoneyDirectionEnum::Fine->value]['approved']);
        self::assertSame(5_900_000, $dashboard->money[MoneyDirectionEnum::Fine->value]['settled']);
        self::assertSame(12_400_000, $dashboard->money[MoneyDirectionEnum::Compensation->value]['claimed']);
        self::assertSame(9_200_000, $dashboard->money[MoneyDirectionEnum::Compensation->value]['approved']);
        self::assertSame(4_700_000, $dashboard->money[MoneyDirectionEnum::Compensation->value]['settled']);

        // And the funnel: 16 reached resolved, 5 reached closed.
        $reached = $dashboard->reachedCounts();
        self::assertSame(47, $reached[IncidentStatusEnum::Reported->value]);
        self::assertSame(16, $reached[IncidentStatusEnum::Resolved->value]);
        self::assertSame(5, $reached[IncidentStatusEnum::Closed->value]);
    }

    /**
     * IT TAKES WHAT THE HOST ALREADY HAS. Zones are attached by name where the
     * area has one, and left null where it does not — unzoned is a first-class
     * answer, not a failure.
     */
    public function testZonesAreAttachedWhereTheHostHasDrawnThem(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate', 35.0, 35.5);
        $this->seed();
        $this->em->clear();

        $zoned = $this->em->getRepository(Incident::class)->findBy(['zone' => null]);
        self::assertLessThan(47, \count($zoned), 'At least the North Gate incidents should have found their zone.');
    }

    /** An area with nothing drawn on it still seeds perfectly well. */
    public function testAnAreaWithNoZonesSeedsAnyway(): void
    {
        $this->anArea();

        $tester = $this->seed();

        self::assertStringContainsString('zones matched', $tester->getDisplay());
        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
    }

    /** With no area at all it says so and fails, rather than inventing one. */
    public function testWithNoAreaItSaysSo(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No area to file incidents in', $tester->getDisplay());
    }
}
