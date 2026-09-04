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
use Uhifadhi\Incident\Repository\IncidentLinkRepository;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * "THESE TWO ARE RELATED" — and A LINK IS A CLAIM, so it carries who made it.
 *
 * The design's provenance card says it in as many words: "linked by S. Laizer · a
 * link is a claim, and it carries who made it". Two incidents being the same
 * predator is somebody's judgement, and a hearing is entitled to know whose.
 *
 * NOT the same thing as PROVENANCE. Provenance is where a record CAME FROM,
 * written once at filing and never edited ({@see Incident::recordProvenance()}).
 * A link is an opinion added later and can be withdrawn.
 */
#[ORM\Entity(repositoryClass: IncidentLinkRepository::class)]
#[ORM\Table(name: 'incident_link')]
// By FIELD, not by column — see Incident's own indexes.
#[ORM\UniqueConstraint(name: 'uniq_incident_link', fields: ['incident', 'related'])]
class IncidentLink
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Incident::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'incident_id', nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\ManyToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(name: 'related_id', nullable: false, onDelete: 'CASCADE')]
    private Incident $related;

    /** The claim itself, in words: "Same predator, almost certainly." */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $linkedBy = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $linkedByName = null;

    public function __construct(Incident $incident, Incident $related)
    {
        if ($incident === $related) {
            throw new \InvalidArgumentException('An incident cannot be related to itself.');
        }

        $this->uuid = Uuid::v7();
        $this->incident = $incident;
        $this->related = $related;
        $incident->addLink($this);
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

    public function getRelated(): Incident
    {
        return $this->related;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getLinkedBy(): ?UserInterface
    {
        return $this->linkedBy;
    }

    public function getLinkedByName(): ?string
    {
        return $this->linkedByName;
    }

    public function setLinkedBy(?UserInterface $linkedBy, ?string $name = null): static
    {
        $this->linkedBy = $linkedBy;
        $this->linkedByName = $name;

        return $this;
    }
}
