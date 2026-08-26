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
use UhifadhiLabs\Incident\Entity\Trait\TimestampableTrait;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;
use UhifadhiLabs\Incident\Repository\IncidentMoneyRepository;

/**
 * THE MONEY ON ONE INCIDENT — and there are FOUR amounts, not one, because they
 * are four different facts: what was asked, what was judged, what was signed off,
 * and what actually left (or reached) the account. A single "amount" column would
 * lose the argument the incident is usually about.
 *
 * The DIRECTION is the sub-category's, never a choice made here: a fine is owed
 * TO the authority, a compensation claim is owed BY it, and the design refuses to
 * add the two together anywhere. A row exists only where
 * {@see IncidentSubcategory::carriesMoney()} — no row is how "this category
 * carries no money" is stored, which is why the card is ABSENT rather than empty.
 *
 * AMOUNTS ARE WHOLE UNITS OF THE CURRENCY (shillings), as integers. TZS has no
 * circulating subdivision, and a float would eventually print a claim as
 * 1,199,999.99.
 *
 * WAIVING is explicit and carries a reason: it is the other way an incident's
 * money can be "settled", and the resolve guard accepts it precisely because
 * somebody wrote down why.
 */
#[ORM\Entity(repositoryClass: IncidentMoneyRepository::class)]
#[ORM\Table(name: 'incident_money')]
class IncidentMoney
{
    use TimestampableTrait;

    public const string DEFAULT_CURRENCY = 'TZS';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\OneToOne(targetEntity: Incident::class, inversedBy: 'money')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Incident $incident;

    #[ORM\Column(enumType: MoneyDirectionEnum::class)]
    private MoneyDirectionEnum $direction;

    #[ORM\Column(length: 3, options: ['default' => self::DEFAULT_CURRENCY])]
    private string $currency = self::DEFAULT_CURRENCY;

    /** What was asked for. Null where nobody has claimed anything yet. */
    #[ORM\Column(nullable: true)]
    private ?int $claimed = null;

    /** What was judged to be owed. */
    #[ORM\Column(nullable: true)]
    private ?int $assessed = null;

    /** What was signed off for payment. For a fine this is the notice served. */
    #[ORM\Column(nullable: true)]
    private ?int $approved = null;

    /** What actually moved — "paid" on a claim, "collected" on a fine. */
    #[ORM\Column(options: ['default' => 0])]
    private int $settled = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $waivedAt = null;

    /** Why. A waiver without one is not a waiver, it is a deletion. */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $waivedReason = null;

    public function __construct(Incident $incident, MoneyDirectionEnum $direction)
    {
        $this->uuid = Uuid::v7();
        $this->incident = $incident;
        $this->direction = $direction;
        $incident->setMoney($this);
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

    public function getDirection(): MoneyDirectionEnum
    {
        return $this->direction;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getClaimed(): ?int
    {
        return $this->claimed;
    }

    public function setClaimed(?int $claimed): static
    {
        $this->claimed = $claimed;

        return $this;
    }

    public function getAssessed(): ?int
    {
        return $this->assessed;
    }

    public function setAssessed(?int $assessed): static
    {
        $this->assessed = $assessed;

        return $this;
    }

    public function getApproved(): ?int
    {
        return $this->approved;
    }

    public function setApproved(?int $approved): static
    {
        $this->approved = $approved;

        return $this;
    }

    public function getSettled(): int
    {
        return $this->settled;
    }

    public function setSettled(int $settled): static
    {
        $this->settled = max(0, $settled);

        return $this;
    }

    /**
     * The figure the outstanding balance is measured against: what was signed off,
     * falling back to what was judged and then to what was asked. Zero where
     * nobody has put a number on it at all — an empty money card owes nothing.
     */
    public function payable(): int
    {
        return $this->approved ?? $this->assessed ?? $this->claimed ?? 0;
    }

    /** What is still owed, never negative — an overpayment is a different problem. */
    public function outstanding(): int
    {
        return max(0, $this->payable() - $this->settled);
    }

    /** 0…100, for the design's little progress bar. A payable of nothing is fully settled. */
    public function settledPercent(): int
    {
        $payable = $this->payable();
        if (0 === $payable) {
            return 100;
        }

        return (int) min(100, round($this->settled / $payable * 100));
    }

    public function getWaivedAt(): ?\DateTimeImmutable
    {
        return $this->waivedAt;
    }

    public function getWaivedReason(): ?string
    {
        return $this->waivedReason;
    }

    public function isWaived(): bool
    {
        return null !== $this->waivedAt;
    }

    /**
     * Give up on the money, on the record, with a reason.
     *
     * @throws \InvalidArgumentException on a waiver with no reason — see the class docblock
     */
    public function waive(\DateTimeImmutable $at, string $reason): static
    {
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException('A waiver must say why; a waiver without a reason is a deletion.');
        }

        $this->waivedAt = $at;
        $this->waivedReason = trim($reason);

        return $this;
    }

    /** Whether anybody has yet put a number on this at all. */
    public function isAssessed(): bool
    {
        return null !== ($this->approved ?? $this->assessed ?? $this->claimed);
    }

    /**
     * THE RESOLVE GUARD'S QUESTION: is this money finished with? Either it has
     * been assessed AND everything owed has moved, or somebody waived it and said
     * why. Nothing else counts — least of all "every amount is null", which means
     * the assessment has not been DONE rather than that there is nothing to pay,
     * and would otherwise let an untouched claim be resolved away.
     */
    public function isSettled(): bool
    {
        return $this->isWaived() || ($this->isAssessed() && 0 === $this->outstanding());
    }
}
