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

namespace Uhifadhi\Incident\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;

/**
 * A PHOTOGRAPH OR A DOCUMENT attached to an incident.
 *
 * IT KEEPS ITS OWN TIME AND ITS OWN PLACE. {@see $capturedAt} is the moment the
 * HANDSET recorded, not the moment somebody uploaded — the design is explicit
 * that the four photographs on INC-0313 were taken at the boma and the map
 * places them there. Uploading is bookkeeping ({@see getCreatedAt()}); capture
 * is the fact.
 *
 * Evidence copied in from a source record (a patrol observation filed as an
 * incident) carries its ORIGINAL timestamps across, never today's.
 */
#[ORM\Entity(repositoryClass: IncidentEvidenceRepository::class)]
#[ORM\Table(name: 'incident_evidence')]
class IncidentEvidence
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Incident::class, inversedBy: 'evidence')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Column(enumType: EvidenceKindEnum::class)]
    private EvidenceKindEnum $kind;

    /** What it is called where a person can see it: IMG_1204.jpg, claim_form_signed.pdf. */
    #[ORM\Column(length: 160)]
    private string $filename;

    /** Where the bytes are, relative to whatever store the host configured. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $path = null;

    /**
     * The DETECTED type recorded when the blob was written — never what a
     * filename claimed. Null on a row that carries only a key and no stored
     * bytes (evidence keyed before the blob was written, or a host with no
     * storage), where the hub falls back to reading the type off the filename.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    /** Size of the stored original, in bytes. Null where no blob was written — the hub then weighs it nothing rather than an invented figure. */
    #[ORM\Column(nullable: true)]
    private ?int $byteSize = null;

    /** Key of the ~400px preview beside the original, or null where none was made (a document, or a photograph nothing here could decode). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $thumbKey = null;

    /** The handset's own moment — see the class docblock. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $capturedAt = null;

    /** Where it was taken, as GeoJSON Point text. Null for a document and for an untagged photograph. */
    #[ORM\Column(type: 'point', nullable: true)]
    private ?string $position = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $caption = null;

    public function __construct(Incident $incident, EvidenceKindEnum $kind, string $filename)
    {
        $this->uuid = Uuid::v7();
        $this->incident = $incident;
        $this->kind = $kind;
        $this->filename = $filename;
        $incident->addEvidence($this);
        $this->initTimestamps();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getIncident(): Incident
    {
        return $this->incident;
    }

    public function getKind(): EvidenceKindEnum
    {
        return $this->kind;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(?string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getByteSize(): ?int
    {
        return $this->byteSize;
    }

    public function setByteSize(?int $byteSize): static
    {
        $this->byteSize = $byteSize;

        return $this;
    }

    public function getThumbKey(): ?string
    {
        return $this->thumbKey;
    }

    public function setThumbKey(?string $thumbKey): static
    {
        $this->thumbKey = $thumbKey;

        return $this;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
    }

    public function setCapturedAt(?\DateTimeImmutable $capturedAt): static
    {
        $this->capturedAt = $capturedAt;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }
}
