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

namespace Uhifadhi\Incident\Tests\Integration\Devkit;

use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Incident\Devkit\IncidentContentProvider;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Registry\FileRegistry;

/**
 * THE DESIGN'S SAMPLE MONTH, SEEDED THROUGH THE MODULE'S OWN SERVICES — and then
 * read back through the DASHBOARD.
 *
 * {@see \Uhifadhi\Incident\Tests\Unit\Model\DemoMonthTest} adds the table up;
 * this proves the table survives being written to a real database through the
 * doors a person uses, and that the widgets then print the numbers the preset
 * gallery states.
 *
 * That last part is the one that matters: every screenshot in the design app is a
 * claim about what the product shows, and this is where that claim is checked.
 */
final class IncidentContentProviderTest extends IntegrationTestCase
{
    private function provider(): ContentProviderInterface
    {
        /** @var ContentProviderInterface $provider */
        $provider = static::getContainer()->get('test_public.incident.devkit.content');

        return $provider;
    }

    /**
     * IT IS DECLARED, AND IT IS THE TAG DEVKIT READS. The tag is written as a
     * literal in this bundle's extension because devkit is absent in production;
     * a module that mistyped it would simply seed nothing, which looks exactly
     * like a module nobody installed.
     */
    public function testItIsRegisteredAsADevkitContentProvider(): void
    {
        $provider = $this->provider();

        self::assertInstanceOf(IncidentContentProvider::class, $provider);
        self::assertSame('incident', $provider->key());
        self::assertSame(['team'], $provider->dependsOn(), 'It is seeded after the people who record its incidents.');
        self::assertNotSame('', $provider->description());
    }

    public function testItFilesTheFortySevenIncidentsTheGalleryTalksAbout(): void
    {
        $this->anArea('Sample Area');

        $this->provider()->load();

        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
    }

    /** It installs the taxonomy itself: seeding that failed for a missing install step sends people bug-hunting. */
    public function testItInstallsTheTaxonomyBeforeFilingAnything(): void
    {
        $this->anArea();

        $this->provider()->load();

        self::assertSame('livestock depredation', $this->subcategory('livestock-depredation')->getLabel());
    }

    /**
     * THE PARTIES THE SAMPLE MONTH NAMES, seeded through the service that now
     * exists. Six of them across four case files — the claimant, the witness and
     * the animal the design's worked example draws.
     */
    public function testItSeedsThePartiesTheSampleMonthNames(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        self::assertSame(DemoMonth::partyCount(), $this->em->getRepository(IncidentParty::class)->count([]));
    }

    /** An animal is a party too, and the design's worked example is where it appears. */
    public function testTheWorkedExampleCarriesItsAnimal(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $roles = array_map(
            static fn (IncidentParty $party): string => $party->getRole()->value,
            $this->em->getRepository(IncidentParty::class)->findAll(),
        );

        self::assertContains(PartyRoleEnum::Animal->value, $roles);
    }

    /**
     * EVIDENCE WITH REAL BYTES BEHIND IT. The point of seeding it through the
     * service rather than writing rows is that the hub then reads a genuine size
     * and a genuine preview back — a row with a key and no blob would link at a
     * 404 and be listed nowhere.
     */
    public function testItSeedsEvidenceWithBytesBehindIt(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $evidence = $this->em->getRepository(IncidentEvidence::class)->findAll();
        self::assertSame(DemoMonth::evidenceCount(), \count($evidence));

        foreach ($evidence as $item) {
            self::assertNotNull($item->getPath(), 'Seeded evidence must be keyed, not merely recorded.');
            self::assertGreaterThan(0, (int) $item->getByteSize());
            self::assertNotNull($item->getThumbKey(), 'A photograph the machine can decode gets its preview.');
        }
    }

    /** Every seeded key is one this module's own voter claims, or nobody may look at it. */
    public function testEverySeededEvidenceKeyIsClaimedByThisModule(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        foreach ($this->em->getRepository(IncidentEvidence::class)->findAll() as $item) {
            self::assertTrue(IncidentFileSource::claims((string) $item->getPath()));
        }
    }

    /** So the module appears on the hub holding what it actually holds. */
    public function testTheSeededEvidenceReachesTheFilesHub(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        /** @var FileRegistry $registry */
        $registry = $this->service('storage.file_registry');

        self::assertCount(DemoMonth::evidenceCount(), $registry->all());
    }

    /**
     * THE ASSIGNEE, WHICH NOTHING USED TO WRITE. Response is somebody's work, so
     * an incident that has reached `in progress` is carrying somebody's name —
     * and one that has not is carrying nobody's, which is the honest drawing of
     * "not started".
     */
    public function testResponseIsAssignedAndNothingElseIs(): void
    {
        $this->anArea('Sample Area');
        $this->aUser('j.mollel@example.test', 'Joseph', 'Mollel');
        $this->provider()->load();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            $reached = $incident->getStatus()->hasReached(IncidentStatusEnum::InProgress);
            self::assertSame(
                $reached,
                null !== $incident->getAssignedTo(),
                \sprintf('%s is %s and %s assigned.', (string) $incident->getReference(), $incident->getStatus()->value, null === $incident->getAssignedTo() ? 'not' : ''),
            );
        }
    }

    /**
     * THE DEMO OPENS ON A POPULATED DASHBOARD, which is the only reason to seed
     * one. The dashboard's default window is the CURRENT month; a sample month
     * pinned to a date in the past put all forty-seven incidents just out of view
     * and a freshly seeded installation opened on "0 filed" and an empty register.
     */
    public function testMostOfTheSampleMonthLandsInsideTheCurrentMonth(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $monthStart = new \DateTimeImmutable()->modify('first day of this month')->setTime(0, 0);
        $inThisMonth = 0;
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            if ($incident->getReportedAt() >= $monthStart) {
                ++$inThisMonth;
            }
        }

        self::assertSame(DemoMonth::RECENT_COUNT, $inThisMonth);
    }

    /** And nothing is filed in the future, whatever day of the month it is run on. */
    public function testNothingIsReportedAfterToday(): void
    {
        $this->anArea('Sample Area');
        $this->provider()->load();

        $endOfToday = new \DateTimeImmutable()->setTime(23, 59, 59);
        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            self::assertLessThanOrEqual($endOfToday, $incident->getReportedAt(), (string) $incident->getReference());
        }
    }

    /**
     * AN INSTALLATION WITH NO AREA HAS NOWHERE TO FILE, and that is a state
     * rather than a failure — devkit seeds every module in one run, and one with
     * nothing to hang its records on must not stop the others.
     */
    public function testWithNoAreaItFilesNothingAndDoesNotThrow(): void
    {
        $this->provider()->load();

        self::assertSame(0, $this->em->getRepository(Incident::class)->count([]));
    }

    /**
     * THE WORKFLOW WAS WALKED, NOT WRITTEN. Every seeded incident got where it is
     * one legal transition at a time, through the real service — so the seeding
     * cannot produce a state the product could not, and every one has a timeline.
     */
    public function testEverySeededIncidentWalkedTheRealWorkflow(): void
    {
        $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            // The filing, plus one event per transition it took to get here —
            // plus a money event where an amount was recorded on the way.
            self::assertGreaterThanOrEqual(
                $incident->getStatus()->step(),
                $incident->getEvents()->count(),
                \sprintf('%s is %s but its timeline is shorter than the walk that got it there.', $incident->getReference(), $incident->getStatus()->value),
            );
        }
    }

    /**
     * THE REFERENCE IS THE REGISTER'S. Filing mints the next one, exactly as it
     * does for a person at the form, rather than carrying the sample month's own
     * strings past the register that hands them out.
     */
    public function testEveryReferenceWasMintedByTheRegister(): void
    {
        $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        foreach ($this->em->getRepository(Incident::class)->findAll() as $incident) {
            self::assertStringStartsWith('INC-', (string) $incident->getReference());
        }
    }

    /**
     * THE GALLERY'S OWN NUMBERS, printed by the dashboard that reads the seeded
     * rows.
     *
     * MONEY IS THE ONE FIGURE THAT DIFFERS FROM THE DESIGN, and deliberately: the
     * product records money once response has started, and sixteen rows of the
     * sample month carry money at `reported` or `verified`. Those figures are not
     * written, because writing them would mean seeding a state no screen can
     * produce. What is asserted here is therefore the money the PRODUCT can hold.
     */
    public function testTheDashboardPrintsTheGallerysOwnNumbers(): void
    {
        $area = $this->anArea();
        $this->provider()->load();
        $this->em->clear();

        /** @var IncidentDashboardService $service */
        $service = $this->service('incident.dashboard');
        $area = $this->em->getRepository($area::class)->find($area->getId());
        self::assertNotNull($area);

        // THE WHOLE SPAN, because the sample month is now measured back from
        // today rather than pinned to a date — see DemoMonth::reportedAt().
        $today = new \DateTimeImmutable()->setTime(23, 59, 59);
        $dashboard = $service->build(
            new IncidentFilter($area, $today->modify(\sprintf('-%d days', DemoMonth::SPAN_DAYS + 1)), $today),
            $today,
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

        // And the funnel: 16 reached resolved, 5 reached closed.
        $reached = $dashboard->reachedCounts();
        self::assertSame(47, $reached[IncidentStatusEnum::Reported->value]);
        self::assertSame(16, $reached[IncidentStatusEnum::Resolved->value]);
        self::assertSame(5, $reached[IncidentStatusEnum::Closed->value]);

        // Money, from the rows that had reached response by the time it was
        // recorded — never a claim on an incident nobody has responded to.
        self::assertGreaterThan(0, $dashboard->money[MoneyDirectionEnum::Fine->value]['approved']);
        self::assertGreaterThan(0, $dashboard->money[MoneyDirectionEnum::Compensation->value]['approved']);
    }

    /**
     * ZONES ARE THE MAP'S ANSWER, NOT A NAME MATCH. Filing asks PostGIS which
     * zone the point falls in, so an area with a zone drawn over the sample
     * month's positions gets its incidents zoned and one without gets none —
     * unzoned being a first-class answer rather than a failure.
     */
    public function testZonesAreAttachedWhereTheAreaHasThemDrawn(): void
    {
        $area = $this->anArea();
        $this->aZone($area, 'North Gate', -30.0, -29.5);
        $this->provider()->load();
        $this->em->clear();

        $unzoned = $this->em->getRepository(Incident::class)->findBy(['zone' => null]);
        self::assertLessThan(47, \count($unzoned), 'The incidents inside the drawn zone should have found it.');
    }
}
