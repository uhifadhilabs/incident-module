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

namespace UhifadhiLabs\Incident\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\User;
use UhifadhiLabs\Incident\Enum\IncidentEventKindEnum;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Repository\IncidentEventRepository;

/**
 * ONE THING THAT HAPPENED TO AN INCIDENT — the spine of the record.
 *
 * THE TIMELINE IS APPEND-ONLY. There is no setter on this class beyond the
 * constructor, and that is the design, not an oversight: nothing here is ever
 * edited or removed, and a correction is a NEW event that says what was
 * corrected. It is exactly that property that makes the record worth anything in
 * a hearing — a row somebody could quietly rewrite proves nothing.
 *
 * Deliberately WITHOUT the timestampable trait: an immutable row has no honest
 * updatedAt, and {@see $occurredAt} is the time that matters — the moment the
 * thing happened, which for a transition is when it was made and for a note is
 * when it was written.
 */
#[ORM\Entity(repositoryClass: IncidentEventRepository::class)]
#[ORM\Table(name: 'incident_event')]
// By FIELD, not by column — see Incident's own indexes.
#[ORM\Index(name: 'idx_incident_event_incident', fields: ['incident', 'occurredAt'])]
class IncidentEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Incident::class, inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Column(enumType: IncidentEventKindEnum::class)]
    private IncidentEventKindEnum $kind;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** What happened, in words — the line the timeline prints. */
    #[ORM\Column(type: 'text')]
    private string $body;

    /**
     * The small print under the line: "transition reported → verified · 5 h 39
     * after filing · term is 72 h". Provenance for the entry itself, kept apart
     * from the entry so a timeline can be read without it.
     */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $detail = null;

    /**
     * Who did it. Null is honest for the clock's own events — an auto-close has no
     * author, and inventing one would put a person's name on a machine's action.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    /** The name the author is printed under, kept even if the user row later goes. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $actorName = null;

    /** For a transition event: where it went. Null for every other kind. */
    #[ORM\Column(enumType: IncidentStatusEnum::class, nullable: true)]
    private ?IncidentStatusEnum $toStatus = null;

    public function __construct(
        Incident $incident,
        IncidentEventKindEnum $kind,
        \DateTimeImmutable $occurredAt,
        string $body,
    ) {
        $this->uuid = Uuid::v7();
        $this->incident = $incident;
        $this->kind = $kind;
        $this->occurredAt = $occurredAt;
        $this->body = $body;
        $incident->addEvent($this);
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

    public function getKind(): IncidentEventKindEnum
    {
        return $this->kind;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * Set ONCE, at construction time, by whoever is building the event. Not a
     * mutation of history: an event is assembled and then appended, and nothing
     * calls this after the flush that stored it.
     */
    public function withDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorName(): ?string
    {
        return $this->actorName;
    }

    /** Set once, at construction time — see {@see withDetail()}. */
    public function withActor(?User $actor, ?string $actorName = null): static
    {
        $this->actor = $actor;
        $this->actorName = $actorName;

        return $this;
    }

    public function getToStatus(): ?IncidentStatusEnum
    {
        return $this->toStatus;
    }

    /** Set once, at construction time — see {@see withDetail()}. */
    public function withToStatus(?IncidentStatusEnum $toStatus): static
    {
        $this->toStatus = $toStatus;

        return $this;
    }
}
