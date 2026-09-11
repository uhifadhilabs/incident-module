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

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Exception\IncidentEvidenceException;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;
use Uhifadhi\Incident\Service\IncidentEvidenceService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Storage\Service\EvidenceKey;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * ATTACHING A PHOTOGRAPH TO A CASE FILE — the thing this module described in
 * three docblocks and could not do.
 *
 * THE BYTES GO THROUGH THE PLATFORM, never through this module. `EvidenceStorage`
 * validates, writes into a private storage outside the document root, detects the
 * type from the bytes rather than believing a filename, and tries for a preview.
 * What this module owns is the row: which case file, what kind of thing it is,
 * what the handset recorded, and the key the voter and the Files hub will read
 * back. So these tests assert both halves — that the blob is really there, and
 * that the row describes it truthfully.
 */
final class IncidentEvidenceServiceTest extends IntegrationTestCase
{
    public function testAPhotographIsStoredAndTheRowDescribesIt(): void
    {
        $incident = $this->filedIncident();

        $evidence = $this->evidenceService()->store($incident, $this->aPhotograph(), 'IMG_1204.jpg');

        self::assertSame(EvidenceKindEnum::Photo, $evidence->getKind());
        self::assertSame('IMG_1204.jpg', $evidence->getFilename());
        self::assertSame('image/png', $evidence->getMimeType());
        self::assertGreaterThan(0, (int) $evidence->getByteSize());
        self::assertNotNull($evidence->getPath());
        self::assertTrue($this->storage()->exists((string) $evidence->getPath()));
    }

    /**
     * THE KEY IS THE MODULE'S OWN PREFIX, which is the whole of the contract
     * between the writer, {@see \Uhifadhi\Incident\Security\IncidentEvidenceVoter}
     * and {@see \Uhifadhi\Incident\Storage\IncidentFileSource}. A key under
     * somebody else's prefix is a key this module's voter never claims, and
     * storage denies by default: an invisible photograph on a page the reader
     * is entitled to.
     */
    public function testTheKeyIsClaimedByThisModule(): void
    {
        $incident = $this->filedIncident();

        $evidence = $this->evidenceService()->store($incident, $this->aPhotograph(), 'IMG_1204.jpg');

        $key = (string) $evidence->getPath();
        self::assertTrue(IncidentEvidenceKey::claims($key));
        self::assertSame(IncidentEvidenceKey::PREFIX, EvidenceKey::rootSegment($key));
        self::assertStringContainsString($incident->getUuid()->toRfc4122(), $key);
    }

    /** A photograph gets its ~400px preview, written beside the original by storage. */
    public function testAPhotographGetsItsPreview(): void
    {
        $incident = $this->filedIncident();

        $evidence = $this->evidenceService()->store($incident, $this->aPhotograph(), 'IMG_1204.jpg');

        self::assertNotNull($evidence->getThumbKey());
        self::assertTrue($this->storage()->exists((string) $evidence->getThumbKey()));
    }

    /**
     * THE HANDSET'S OWN TIME AND PLACE, not the upload's. The design is explicit
     * that the four photographs on a case file were taken at the boma and the map
     * places them there; uploading is bookkeeping.
     */
    public function testTheHandsetsTimeAndPlaceAreKept(): void
    {
        $incident = $this->filedIncident();
        $capturedAt = new \DateTimeImmutable('2026-08-20 11:02:00');

        $evidence = $this->evidenceService()->store(
            $incident,
            $this->aPhotograph(),
            'IMG_1204.jpg',
            caption: 'The broken fence line, looking north.',
            capturedAt: $capturedAt,
            position: '{"type":"Point","coordinates":[-29.75,-3.21]}',
        );

        $stored = $this->reread($incident);
        self::assertSame($capturedAt->format('c'), $stored->getCapturedAt()?->format('c'));
        self::assertSame('The broken fence line, looking north.', $stored->getCaption());
        self::assertNotNull($stored->getPosition());
    }

    /** Attaching evidence is an event on the case file, and it says what arrived. */
    public function testAttachingLeavesATimelineEvent(): void
    {
        $incident = $this->filedIncident();
        $before = $incident->getEvents()->count();

        $this->evidenceService()->store($incident, $this->aPhotograph(), 'IMG_1204.jpg');

        self::assertSame($before + 1, $incident->getEvents()->count());
    }

    /** With no filename offered, the one the upload arrived under is used. */
    public function testTheUploadsOwnNameIsUsedWhenNoneIsGiven(): void
    {
        $incident = $this->filedIncident();

        $evidence = $this->evidenceService()->store($incident, $this->anUpload('IMG_1207.png'));

        self::assertSame('IMG_1207.png', $evidence->getFilename());
    }

    /**
     * A FILE THE DEPLOYMENT DOES NOT ACCEPT IS REFUSED, AND NOTHING IS WRITTEN.
     * Storage validates before it builds a key, so a rejected upload leaves no
     * blob; this asserts it also leaves no row.
     */
    public function testAFileTheDeploymentRefusesLeavesNoRow(): void
    {
        $incident = $this->filedIncident();

        try {
            $this->evidenceService()->store($incident, $this->notAPhotograph(), 'notes.txt');
            self::fail('A file outside the deployment\'s accepted types must be refused.');
        } catch (IncidentEvidenceException $refused) {
            self::assertNotSame('', $refused->getMessage());
        }

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(IncidentEvidence::class)->findAll());
    }

    private function evidenceService(): IncidentEvidenceService
    {
        /** @var IncidentEvidenceService $evidence */
        $evidence = $this->service('incident.evidence');

        return $evidence;
    }

    private function storage(): EvidenceStorage
    {
        /** @var EvidenceStorage $storage */
        $storage = $this->service('storage.evidence_storage');

        return $storage;
    }

    private function filedIncident(): Incident
    {
        return $this->anIncident($this->anAreaWithKinds('Kifaru Sector'));
    }

    /** The one stored row, as the database holds it. */
    private function reread(Incident $incident): IncidentEvidence
    {
        $this->em->clear();

        $rows = $this->em->getRepository(IncidentEvidence::class)->findAll();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** A real PNG on disk — one pixel, but genuinely decodable, so the preview is genuinely made. */
    private function aPhotograph(): File
    {
        return new File($this->write('.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: ''));
    }

    /** The same bytes, arriving the way a browser sends them. */
    private function anUpload(string $clientName): UploadedFile
    {
        return new UploadedFile($this->aPhotograph()->getPathname(), $clientName, 'image/png', test: true);
    }

    private function notAPhotograph(): File
    {
        return new File($this->write('.txt', 'This is a note, not a photograph.'));
    }

    private function write(string $extension, string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'incident-evidence');
        self::assertIsString($path);
        $path .= $extension;
        file_put_contents($path, $bytes);

        return $path;
    }
}
