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
use UhifadhiLabs\Incident\Repository\IncidentSubcategoryRepository;

/**
 * WHAT EXACTLY HAPPENED — the sixteen the design's reference card lists under
 * the four kinds: snaring, bushmeat, ivory & trophy, illegal fishing; livestock
 * depredation, crop raiding, human injury, property damage; unauthorized
 * construction, illegal grazing, boundary encroachment, unlicensed operation;
 * roadkill, natural mortality, disease die-off, poisoning.
 *
 * THREE THINGS A SUB-CATEGORY DECIDES, and they are why this is a row and not an
 * enum case:
 *
 *  1. **Whether money is even a field.** {@see $moneyDirection} — null means the
 *     money block is ABSENT from the form and the record, not greyed out. This is
 *     also where the ROADKILL RULING lives: roadkill is ONE entry that may carry
 *     a FINE (a driver was fined), not a pair of linked incidents. A category
 *     that carries money and an incident that happens to have none are different
 *     facts, and only the first is stored here.
 *  2. **What the clock promises.** {@see $termHours} — a human injury is 72
 *     hours, a construction notice is 14 days, a compensation claim is 30 days.
 *     One global SLA would be a lie about all three, which is why the ageing
 *     widget reads the term off the row rather than off a setting.
 *  3. **Which fields the form asks for.** {@see $fieldSet} — the design's "what
 *     this category asks for" section. A conflict asks for species, livestock
 *     lost, enclosure, household; a roadkill asks for species, sex, age class,
 *     road segment. One incident table, one form component, a field set per
 *     sub-category.
 *
 * Like {@see IncidentCategory} this is SEEDED, CONFIGURABLE DATA. Nothing in the
 * bundle switches on a sub-category slug.
 */
#[ORM\Entity(repositoryClass: IncidentSubcategoryRepository::class)]
#[ORM\Table(name: 'incident_subcategory')]
#[ORM\HasLifecycleCallbacks]
class IncidentSubcategory
{
    use TimestampableTrait;

    /** What a term reads as when a deployment has not promised one. */
    public const int DEFAULT_TERM_HOURS = 72;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 60, unique: true)]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: IncidentCategory::class, inversedBy: 'subcategories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IncidentCategory $category;

    #[ORM\Column(length: 80)]
    private string $label;

    /**
     * Which way money runs on this kind of incident, or NULL for one that carries
     * none. Null is the common case and it means ABSENT: the report flow does not
     * render the row, and the detail page has no money card at all.
     */
    #[ORM\Column(enumType: MoneyDirectionEnum::class, nullable: true)]
    private ?MoneyDirectionEnum $moneyDirection = null;

    /** The term THIS kind of incident promises, in hours. See the class docblock. */
    #[ORM\Column(options: ['default' => self::DEFAULT_TERM_HOURS])]
    private int $termHours = self::DEFAULT_TERM_HOURS;

    /**
     * The fields this kind of incident asks for, in the order the form draws them.
     *
     * @var list<array{key: string, label: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $fieldSet = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(IncidentCategory $category, string $slug, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->category = $category;
        $this->slug = $slug;
        $this->label = $label;
        $category->addSubcategory($this);
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCategory(): IncidentCategory
    {
        return $this->category;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getMoneyDirection(): ?MoneyDirectionEnum
    {
        return $this->moneyDirection;
    }

    public function setMoneyDirection(?MoneyDirectionEnum $moneyDirection): static
    {
        $this->moneyDirection = $moneyDirection;

        return $this;
    }

    /** Whether the money block exists on this kind of incident at all. */
    public function carriesMoney(): bool
    {
        return null !== $this->moneyDirection;
    }

    public function getTermHours(): int
    {
        return $this->termHours;
    }

    public function setTermHours(int $termHours): static
    {
        $this->termHours = max(1, $termHours);

        return $this;
    }

    /**
     * The term as the design writes it on a chip: "72 h" under four days, "14 d"
     * beyond — nobody reads 336 h as a fortnight.
     */
    public function termLabel(): string
    {
        return $this->termHours < 96
            ? \sprintf('%d h', $this->termHours)
            : \sprintf('%d d', intdiv($this->termHours, 24));
    }

    /** @return list<array{key: string, label: string}> */
    public function getFieldSet(): array
    {
        return $this->fieldSet;
    }

    /** @param list<array{key: string, label: string}> $fieldSet */
    public function setFieldSet(array $fieldSet): static
    {
        $this->fieldSet = $fieldSet;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** "conflict › livestock depredation" — the path chip on the detail page. */
    public function path(): string
    {
        return $this->category->getLabel().' › '.$this->label;
    }
}
