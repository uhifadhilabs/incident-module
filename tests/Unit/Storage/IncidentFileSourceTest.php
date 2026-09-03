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

namespace Uhifadhi\Incident\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Storage\Enum\FileKindEnum;
use Uhifadhi\Storage\Enum\GuardStateEnum;
use Uhifadhi\Storage\Enum\ThumbStateEnum;

/**
 * What the Files hub is told about an incident's evidence.
 *
 * Every field of a FileEntry is a CLAIM about the record, and the wrong claim
 * puts a photograph on somebody else's case file without anything throwing. The
 * mapping is therefore pinned here, against real entities.
 */
final class IncidentFileSourceTest extends TestCase
{
    public function testAPhotographIsHandedOverWithTheCaseFileItBelongsTo(): void
    {
        $evidence = $this->photo();
        $entry = IncidentFileSource::entryFor($evidence, '/areas/a/modules/incidents/INC-0313');

        self::assertSame('incident/0199a/e77c.jpg', $entry->key);
        self::assertSame('IMG_1204.jpg', $entry->name);
        self::assertSame('image/jpeg', $entry->mimeType);
        self::assertSame('incidents', $entry->moduleSlug);
        self::assertSame('Incidents', $entry->moduleLabel);
        self::assertSame('/areas/a/modules/incidents/INC-0313', $entry->ownerUrl);
        self::assertSame(FileKindEnum::Photo, $entry->kind);
    }

    /**
     * The owner label is the CASE FILE's reference alone. The hub's own template
     * prints "{moduleLabel} · {ownerLabel}", so a label that repeated the module
     * would read "Incidents · Incidents · INC-0313" on every tile.
     */
    public function testTheOwnerLabelIsTheIncidentsReferenceAndNotTheModulesName(): void
    {
        $entry = IncidentFileSource::entryFor($this->photo(), null);

        self::assertSame('INC-0313', $entry->ownerLabel);
        self::assertStringNotContainsString('Incidents', $entry->ownerLabel);
    }

    public function testTheAreaIsTheIncidentsArea(): void
    {
        $evidence = $this->photo();
        $entry = IncidentFileSource::entryFor($evidence, null);

        self::assertSame($evidence->getIncident()->getArea()->getUuidString(), $entry->areaSlug);
        self::assertSame('Kifaru Sector', $entry->areaLabel);
    }

    /**
     * THE KIND IS THE RECORD'S OWN ANSWER, not a guess at a mime type. Incidents
     * already classifies what a piece of evidence IS, and that classification is
     * the truth: a scanned claim form is a document even where its bytes are a
     * JPEG, because the record says what it is for.
     */
    public function testADocumentIsHandedOverAsADocument(): void
    {
        $entry = IncidentFileSource::entryFor($this->document(), null);

        self::assertSame(FileKindEnum::Document, $entry->kind);
        self::assertSame('application/pdf', $entry->mimeType);
        self::assertSame(ThumbStateEnum::Nothing, $entry->thumbState);
    }

    /** A document has no handset clock, so it sits under the day it was filed. */
    public function testADocumentHasNoMomentOfCaptureAndIsFiledUnderTheDayItArrived(): void
    {
        $entry = IncidentFileSource::entryFor($this->document(), null);

        self::assertNull($entry->takenAt);
        self::assertSame($entry->arrivedAt->format('Y-m-d'), $entry->day());
    }

    public function testTheHandsetsClockFilesAPhotographAndTheUploadClockIsWhenItArrived(): void
    {
        $evidence = $this->photo();
        $entry = IncidentFileSource::entryFor($evidence, null);

        self::assertEquals($evidence->getCapturedAt(), $entry->takenAt);
        self::assertEquals($evidence->getCreatedAt(), $entry->arrivedAt);
        self::assertSame('2026-08-19', $entry->day());
    }

    /** The caption belongs to the record; the hub shows it and never edits it. */
    public function testTheRecordsCaptionTravelsWithTheFile(): void
    {
        self::assertSame(
            'The broken fence line, looking north.',
            IncidentFileSource::entryFor($this->photo(), null)->caption,
        );
    }

    /**
     * NO SMALL PICTURE HAS EVER BEEN MADE FOR AN INCIDENT'S EVIDENCE, and the
     * hub is told exactly that. "Failed" would claim this machine tried and could
     * not decode the file, which is a cause nobody established — incidents has
     * simply never run evidence through the thumbnailer.
     */
    public function testAPhotographWithNoPreviewSaysThePictureHasNotBeenMadeYet(): void
    {
        $entry = IncidentFileSource::entryFor($this->photo(), null);

        self::assertNull($entry->thumbKey);
        self::assertSame(ThumbStateEnum::Waiting, $entry->thumbState);
    }

    /**
     * The size is not recorded on the row, so the honest figure is zero rather
     * than an invented one: the hub's space bars must not add up bytes nobody
     * measured.
     */
    public function testAnUnmeasuredFileWeighsNothingRatherThanSomethingInvented(): void
    {
        self::assertSame(0, IncidentFileSource::entryFor($this->photo(), null)->byteSize);
    }

    /**
     * AN INCIDENT STILL IN PROGRESS WILL NOT LET GO OF EVIDENCE A CLAIM RESTS ON.
     */
    #[DataProvider('openStatuses')]
    public function testEvidenceOnAnOpenIncidentIsLocked(IncidentStatusEnum $status): void
    {
        $guard = IncidentFileSource::guardFor($this->incident($status));

        self::assertSame(GuardStateEnum::Locked, $guard->state);
        self::assertFalse($guard->offersRemoval());
    }

    /** @return iterable<string, array{IncidentStatusEnum}> */
    public static function openStatuses(): iterable
    {
        yield 'reported' => [IncidentStatusEnum::Reported];
        yield 'verified' => [IncidentStatusEnum::Verified];
        yield 'in progress' => [IncidentStatusEnum::InProgress];
    }

    /** A resolved and filed case may let go of an attachment. */
    #[DataProvider('settledStatuses')]
    public function testEvidenceOnASettledIncidentMayBeRemoved(IncidentStatusEnum $status): void
    {
        $guard = IncidentFileSource::guardFor($this->incident($status));

        self::assertSame(GuardStateEnum::Allowed, $guard->state);
        self::assertTrue($guard->offersRemoval());
    }

    /** @return iterable<string, array{IncidentStatusEnum}> */
    public static function settledStatuses(): iterable
    {
        yield 'resolved' => [IncidentStatusEnum::Resolved];
        yield 'closed' => [IncidentStatusEnum::Closed];
    }

    /**
     * A key under this module's prefix that no row holds is not this module's
     * file. Locked rather than denied: nothing here can authorise removing bytes
     * nothing here admits to owning.
     */
    public function testAKeyNoRowHoldsIsLockedRatherThanBlamedOnTheReader(): void
    {
        $guard = IncidentFileSource::guardFor(null);

        self::assertSame(GuardStateEnum::Locked, $guard->state);
        self::assertFalse($guard->offersRemoval());
    }

    #[DataProvider('keys')]
    public function testWhichKeysAreIncidents(string $key, bool $claimed): void
    {
        self::assertSame($claimed, IncidentFileSource::claims($key));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function keys(): iterable
    {
        yield 'ours' => ['incident/0199a/e77c.jpg', true];
        yield 'a preview of ours' => ['incident/0199a/e77c.jpg.thumb.jpg', true];
        yield 'patrol' => ['patrol/0199a/e77c.jpg', false];
        yield 'a lookalike' => ['incidents/0199a/e77c.jpg', false];
        yield 'nothing at all' => ['', false];
    }

    private function incident(IncidentStatusEnum $status = IncidentStatusEnum::Reported): Incident
    {
        $area = new AreaOfInterest();
        $area->setName('Kifaru Sector');

        $category = new IncidentCategory('conflict', 'Human–wildlife conflict', 'hwc');
        $subcategory = new IncidentSubcategory($category, 'livestock-depredation', 'livestock depredation');

        $incident = new Incident(
            $area,
            $subcategory,
            'INC-0313',
            'Lion killed four goats at Osinoni',
            '{"type":"Point","coordinates":[35.45,-3.21]}',
            new \DateTimeImmutable('2026-08-19 07:10:00'),
        );

        return $incident->setStatus($status);
    }

    private function photo(): IncidentEvidence
    {
        $evidence = new IncidentEvidence($this->incident(), EvidenceKindEnum::Photo, 'IMG_1204.jpg');

        return $evidence
            ->setPath('incident/0199a/e77c.jpg')
            ->setCapturedAt(new \DateTimeImmutable('2026-08-19 12:10:00'))
            ->setCaption('The broken fence line, looking north.');
    }

    private function document(): IncidentEvidence
    {
        $evidence = new IncidentEvidence($this->incident(), EvidenceKindEnum::Document, 'claim_form_signed.pdf');

        return $evidence->setPath('incident/0199a/claim.pdf');
    }
}
