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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;

/**
 * ONE KIND OF INCIDENT — the top level of the taxonomy the design's reference
 * card (IN·09) writes out in full: poaching & wildlife crime, human–wildlife
 * conflict, compliance & encroachment, wildlife mortality.
 *
 * DATA, NOT CODE. The four are what the module SEEDS, not what it hard-codes: a
 * deployment adds a fifth kind, renames one, or reorders them without a release,
 * exactly as it does with zones and departments. Nothing in this bundle switches
 * on a category slug.
 *
 * "LEADS" IS ORDERING, NOT ACCESS. {@see $leads} names the departments a lens
 * puts first — and that is the whole of its power. Every department can open
 * every category; a lens changes one query parameter and no permission. Any code
 * that turned this field into a filter on WHO MAY READ would be the one thing
 * this module's charter forbids.
 */
#[ORM\Entity(repositoryClass: IncidentCategoryRepository::class)]
#[ORM\Table(name: 'incident_category')]
#[ORM\HasLifecycleCallbacks]
class IncidentCategory
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** Stable across renames — it is what a saved filter and a seeded row name. */
    #[ORM\Column(length: 40, unique: true)]
    private string $slug;

    #[ORM\Column(length: 80)]
    private string $label;

    /**
     * The hue this kind of incident wears EVERYWHERE — map pin, register chip,
     * board card, taxonomy card. One of the four keys incidents.css declares
     * (`poach`, `hwc`, `comp`, `mort`); a deployment adding a fifth category
     * reuses one of them rather than inventing a colour the stylesheet has never
     * heard of, which would render as an invisible mark on a map.
     */
    #[ORM\Column(length: 16)]
    private string $colourKey;

    /**
     * The departments whose lens puts this category first, in the design's own
     * words ("Protection Service", "Ecology & Wildlife Mgmt"). Emphasis only —
     * see the class docblock.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $leads = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, IncidentSubcategory> */
    #[ORM\OneToMany(targetEntity: IncidentSubcategory::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $subcategories;

    public function __construct(string $slug, string $label, string $colourKey)
    {
        $this->uuid = Uuid::v7();
        $this->slug = $slug;
        $this->label = $label;
        $this->colourKey = $colourKey;
        $this->subcategories = new ArrayCollection();
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getColourKey(): string
    {
        return $this->colourKey;
    }

    public function setColourKey(string $colourKey): static
    {
        $this->colourKey = $colourKey;

        return $this;
    }

    /** @return list<string> */
    public function getLeads(): array
    {
        return $this->leads;
    }

    /** @param list<string> $leads */
    public function setLeads(array $leads): static
    {
        $this->leads = $leads;

        return $this;
    }

    /** The card's own line: "leads: Protection · Ecology". */
    public function leadsLine(): string
    {
        return implode(' · ', $this->leads);
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

    /** @return Collection<int, IncidentSubcategory> */
    public function getSubcategories(): Collection
    {
        return $this->subcategories;
    }

    public function addSubcategory(IncidentSubcategory $subcategory): static
    {
        if (!$this->subcategories->contains($subcategory)) {
            $this->subcategories->add($subcategory);
        }

        return $this;
    }

    /**
     * Whether ANY of this kind's sub-categories carries money. The taxonomy card
     * and the report flow ask it before drawing a money row at all — and the
     * answer is genuinely per sub-category, which is how roadkill can carry a
     * fine while natural mortality carries nothing.
     */
    public function carriesMoney(): bool
    {
        foreach ($this->subcategories as $subcategory) {
            if ($subcategory->carriesMoney()) {
                return true;
            }
        }

        return false;
    }
}
