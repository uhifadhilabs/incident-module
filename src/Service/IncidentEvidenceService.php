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

namespace Uhifadhi\Incident\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Exception\IncidentEvidenceException;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Storage\Exception\EvidenceRejectedException;
use Uhifadhi\Storage\Service\EvidenceStorage;

/**
 * THE ONLY WAY A PHOTOGRAPH OR A DOCUMENT GETS ONTO AN INCIDENT.
 *
 * ONE WAY IN, whatever sent the bytes. An `UploadedFile` from a browser and a
 * plain `File` from an importer take the same path, so the private storage, the
 * detected type, the measured size and the generated preview are identical either
 * way — which is the property that lets demo content be seeded through the same
 * door a person uses.
 *
 * THE BYTES ARE THE PLATFORM'S, THE ROW IS THIS MODULE'S. `EvidenceStorage`
 * validates against what the deployment accepts, writes into a storage outside
 * the document root, reads the type from the BYTES rather than believing a
 * filename, and tries for a ~400px preview. What this module owns is which case
 * file a file belongs to, what kind of thing it is, and what the handset
 * recorded — knowing that is what makes a module a module, and the hub is
 * designed never to know it.
 *
 * THE KEY PREFIX IS THE CONTRACT, and it is {@see IncidentFileSource::PREFIX},
 * named there and nowhere else. Three collaborators read it: this writer,
 * {@see \Uhifadhi\Incident\Security\IncidentEvidenceVoter}, which claims those
 * keys so storage does not deny them by default, and the file source that lists
 * them on the hub. A key written under any other prefix is a photograph nobody
 * is allowed to look at, on a page they are entitled to read — which fails
 * silently, as a broken image.
 *
 * TIME AND PLACE ARE THE HANDSET'S. `capturedAt` is when the photograph was
 * taken, never when it was uploaded; uploading is bookkeeping and the row's own
 * timestamps record it. Evidence copied in from a source record carries its
 * original values across rather than today's.
 */
final readonly class IncidentEvidenceService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EvidenceStorage $storage,
    ) {
    }

    /**
     * ATTACH ONE FILE TO ONE CASE FILE.
     *
     * @param \SplFileInfo $file      an UploadedFile from a request, or a plain File for an
     *                                importer that never touched HTTP
     * @param string|null  $filename  what a person sees it called; the upload's own client
     *                                name when none is offered
     * @param string|null  $position  where it was taken, as GeoJSON Point text
     * @param string|null  $actorName who to print the timeline event under, kept beside the
     *                                account so the record still names them if the account goes
     *
     * @throws IncidentEvidenceException when the deployment does not accept the file — nothing
     *                                   is stored and no row is written
     */
    public function attach(
        Incident $incident,
        \SplFileInfo $file,
        ?string $filename = null,
        ?string $caption = null,
        ?\DateTimeImmutable $capturedAt = null,
        ?string $position = null,
        ?\DateTimeImmutable $at = null,
        ?UserInterface $actor = null,
        ?string $actorName = null,
    ): IncidentEvidence {
        try {
            // Validation happens FIRST, inside store(): a refused file leaves no
            // blob, and returning before the row is constructed is what leaves no
            // record of one either.
            $stored = $this->storage->store(
                $file,
                self::prefixFor($incident),
                self::clientKey(),
            );
        } catch (EvidenceRejectedException $refused) {
            throw new IncidentEvidenceException($refused->getMessage(), previous: $refused);
        }

        $evidence = new IncidentEvidence($incident, self::kindOf($stored->mimeType), self::nameOf($file, $filename))
            // The DETECTED type and the MEASURED size, so the hub weighs and
            // labels the bytes that are actually there.
            ->setPath($stored->key)
            ->setMimeType($stored->mimeType)
            ->setByteSize($stored->byteSize)
            // Null where nothing on this machine could decode the source. Stored
            // as null rather than as a key pointing at a file that is not there.
            ->setThumbKey($stored->thumbKey)
            ->setCapturedAt($capturedAt)
            ->setPosition($position)
            ->setCaption($caption);

        $this->entityManager->persist($evidence);

        new IncidentEvent(
            $incident,
            IncidentEventKindEnum::Evidence,
            $at ?? new \DateTimeImmutable(),
            \sprintf('%s attached: %s.', $evidence->getKind()->label(), $evidence->getFilename()),
        )->withActor($actor, $actorName);

        $this->entityManager->flush();

        return $evidence;
    }

    /**
     * The namespace this incident's files live under — the module's prefix and
     * the case file's uuid, so every key names the record it belongs to and the
     * voter's lookup is a lookup rather than a scan.
     */
    public static function prefixFor(Incident $incident): string
    {
        return IncidentFileSource::PREFIX.'/'.$incident->getUuid()->toRfc4122();
    }

    /**
     * A fresh segment per file. Unique within the prefix is all storage asks for,
     * and a uuid is the only thing that answers it without a round trip.
     */
    private static function clientKey(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * PHOTOGRAPH OR DOCUMENT, read from the DETECTED type. Never from the
     * filename: a filename is text somebody typed, and a case file that called a
     * spreadsheet a photograph would put it in the photograph strip.
     */
    private static function kindOf(string $mimeType): EvidenceKindEnum
    {
        return str_starts_with($mimeType, 'image/')
            ? EvidenceKindEnum::Photo
            : EvidenceKindEnum::Document;
    }

    /**
     * What it is called where a person can see it. Only a NAME is taken from the
     * upload — never a path, and never the extension that decides the stored key,
     * which storage derives from the bytes.
     */
    private static function nameOf(\SplFileInfo $file, ?string $filename): string
    {
        $offered = trim((string) $filename);
        if ('' !== $offered) {
            return mb_substr(basename($offered), 0, 160);
        }

        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();

        return mb_substr(basename($name), 0, 160);
    }
}
