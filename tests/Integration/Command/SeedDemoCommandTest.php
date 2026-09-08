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
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Service\IncidentDashboardService;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Enum\ThumbStateEnum;

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
    /** @param array<string, string|bool> $input */
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

    /**
     * SEEDED EVIDENCE APPEARS ON THE FILES HUB. Files are never standalone — each
     * comes from a record — so the seeder now gives every photograph AND a signed
     * document per money case a storage key rooted at "incident/…". The incidents
     * file source claims those keys and lists each under its own case file, so
     * /files shows a realistic spread of incident-linked evidence rather than the
     * module holding nothing.
     */
    public function testSeededIncidentsCarryEvidenceFilesForTheHub(): void
    {
        $this->anArea('Sample Area');
        $this->seed();
        $this->em->clear();

        $evidence = $this->em->getRepository(IncidentEvidence::class)->findAll();

        // Every seeded row now carries a key — none is left off the hub — and there
        // are more rows than incidents, because money cases add a document.
        self::assertNotEmpty($evidence);
        $keyed = array_filter($evidence, static fn (IncidentEvidence $e): bool => null !== $e->getPath());
        self::assertCount(\count($evidence), $keyed, 'Every seeded piece of evidence carries a storage key.');
        self::assertGreaterThan(47, \count($evidence), 'The 47 incidents bring photographs plus documents.');

        // BOTH kinds are present: photographs and signed documents.
        $documents = array_filter($evidence, static fn (IncidentEvidence $e): bool => EvidenceKindEnum::Document === $e->getKind());
        $photos = array_filter($evidence, static fn (IncidentEvidence $e): bool => EvidenceKindEnum::Photo === $e->getKind());
        self::assertNotEmpty($photos, 'Photographs are seeded.');
        self::assertNotEmpty($documents, 'Money incidents bring a signed document.');

        // Every key is one the incidents file source claims, and maps to a hub entry
        // that names its own case file — the seam that puts it on /files.
        foreach ($evidence as $e) {
            $key = (string) $e->getPath();
            self::assertStringStartsWith('incident/', $key);
            self::assertTrue(IncidentFileSource::claims($key), $key.' is not claimed by the incidents file source.');
            $entry = IncidentFileSource::entryFor($e, null);
            self::assertSame($key, $entry->key);
            self::assertSame($e->getIncident()->getReference(), $entry->ownerLabel);
        }
    }

    /**
     * THE BYTES ARE REAL, so the hub shows a size and a thumbnail rather than a
     * 0 B tile still "making the small one". The seeder writes each document
     * straight to the evidence storage and each photograph through the platform's
     * EvidenceStorage — this kernel wires a real (temporary) storage, so the blobs
     * genuinely land and the file source reads their size and preview back.
     */
    public function testSeededEvidenceCarriesRealBytesSizesAndPreviews(): void
    {
        $this->anArea('Sample Area');
        $this->seed();
        $this->em->clear();

        $documents = array_filter(
            $this->em->getRepository(IncidentEvidence::class)->findAll(),
            static fn (IncidentEvidence $e): bool => EvidenceKindEnum::Document === $e->getKind(),
        );
        self::assertNotEmpty($documents, 'Money incidents bring a signed document.');

        // A signed document is a real PDF with a real weight — no image support
        // needed, so this holds on every machine.
        foreach ($documents as $document) {
            self::assertSame('application/pdf', $document->getMimeType());
            self::assertGreaterThan(0, (int) $document->getByteSize(), $document->getFilename().' has no stored bytes.');
            self::assertNull($document->getThumbKey(), 'A document has nothing to shrink.');
            $entry = IncidentFileSource::entryFor($document, null);
            self::assertGreaterThan(0, $entry->byteSize);
            self::assertSame(ThumbStateEnum::Nothing, $entry->thumbState);
        }

        // Photographs need GD to be drawn and shrunk; where it is present the
        // seeder stores a real JPEG and a preview, and the hub reports both.
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('GD is unavailable — photographs are keyed without bytes on this machine.');
        }

        $photos = array_filter(
            $this->em->getRepository(IncidentEvidence::class)->findAll(),
            static fn (IncidentEvidence $e): bool => EvidenceKindEnum::Photo === $e->getKind(),
        );
        self::assertNotEmpty($photos, 'Photographs are seeded.');

        foreach ($photos as $photo) {
            self::assertSame('image/jpeg', $photo->getMimeType(), $photo->getFilename().' was not stored as a JPEG.');
            self::assertGreaterThan(0, (int) $photo->getByteSize(), $photo->getFilename().' has no stored bytes.');
            self::assertNotNull($photo->getThumbKey(), $photo->getFilename().' has no preview.');
            $entry = IncidentFileSource::entryFor($photo, null);
            self::assertGreaterThan(0, $entry->byteSize);
            self::assertSame(ThumbStateEnum::Made, $entry->thumbState, $photo->getFilename().' is not showing a made thumbnail.');
        }
    }

    /**
     * --fresh clears the area's incidents and reseeds them, so a park already
     * holding the sample month can be given real evidence bytes without a second
     * run leaving the old, blob-less rows in place. The count stays 47.
     */
    public function testFreshReseedsTheAreaFromScratch(): void
    {
        $this->anArea('Sample Area');
        $this->seed();
        $this->em->clear();

        $tester = $this->seed(['--fresh' => true]);

        self::assertSame(47, $this->em->getRepository(Incident::class)->count([]));
        self::assertStringContainsString('reseeded fresh', $tester->getDisplay());
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
