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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Repository\TaxonomyKindRepository;

/**
 * ONE KIND OF INCIDENT IN ONE AREA — the top level of the area-scoped taxonomy
 * the design rules out (option B): the taxonomy is keyed to the CURRENT area, and
 * every area owns its own. Northern Reserve's kinds are a different list from
 * Southern Reserve's; the two never merge, and nothing here is shared with another area.
 *
 * THIS IS THE ONLY TAXONOMY THE MODULE HAS. Incidents are filed against the
 * area's own words; there is no installation-wide vocabulary behind it, nothing
 * is seeded on install, and the kinds editor
 * ({@see \Uhifadhi\Incident\Controller\IncidentTaxonomyController}) is where every
 * one of them is written.
 *
 * "LEADS" IS ORDERING, NOT ACCESS. {@see $leads} names the departments a lens
 * puts first — and that is the whole of its power. Every department can open
 * every kind; a lens changes one query parameter and no permission. Code that
 * turned this field into a filter on WHO MAY READ would be the one thing this
 * module's charter forbids.
 *
 * EMPTY-START, DEACTIVATE-NEVER-DELETE. An area begins with NO kinds — the
 * platform ships none and suggests none. A kind is created, renamed and RETIRED
 * ({@see $active}) but never deleted, because incidents are filed against it and
 * a case file must never lose the words that describe it.
 *
 * THE WIRE-CODE NEVER CHANGES. {@see $code} is what a saved filter, an export
 * column and an offline handset actually hold; renaming the label leaves it
 * untouched. It is unique WITHIN THE AREA and independent of any other area's
 * codes.
 */
#[ORM\Entity(repositoryClass: TaxonomyKindRepository::class)]
#[ORM\Table(name: 'incident_taxonomy_kind')]
#[ORM\UniqueConstraint(name: 'uniq_tx_kind_area_code', columns: ['area_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class TaxonomyKind
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The area this kind belongs to. Mapped to the concrete AreaOfInterest, as
     * {@see Incident::$area} is: this bundle already requires AreaBundle
     * and its entity is the one identity ({@see AreaOfInterest::getId()}) an area
     * is told apart by. onDelete CASCADE, so removing an area takes its taxonomy.
     */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** Stable across renames — see the class docblock. Unique within the area. */
    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    /**
     * The hue this kind wears on the map pin, register chip and taxonomy row.
     * One of the keys incidents.css declares (`poach`, `hwc`, `comp`, `mort`);
     * an area choosing a fifth kind reuses one rather than inventing a colour the
     * stylesheet has never heard of.
     */
    #[ORM\Column(length: 16)]
    private string $colourKey;

    /**
     * The departments whose lens puts this kind first. Emphasis only — see the
     * class docblock.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $leads = [];

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** A retired kind is DIMMED, never hidden and never struck through. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, TaxonomySubcategory> */
    #[ORM\OneToMany(targetEntity: TaxonomySubcategory::class, mappedBy: 'kind', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $subcategories;

    public function __construct(AreaOfInterest $area, string $code, string $label, string $colourKey)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->code = $code;
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

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getCode(): string
    {
        return $this->code;
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): static
    {
        $this->active = false;

        return $this;
    }

    public function reactivate(): static
    {
        $this->active = true;

        return $this;
    }

    /** @return Collection<int, TaxonomySubcategory> */
    public function getSubcategories(): Collection
    {
        return $this->subcategories;
    }

    public function addSubcategory(TaxonomySubcategory $subcategory): static
    {
        if (!$this->subcategories->contains($subcategory)) {
            $this->subcategories->add($subcategory);
        }

        return $this;
    }

    /**
     * Whether ANY of this kind's sub-categories carries money. The taxonomy card
     * and the report flow ask it before drawing a money row at all — and the
     * answer is genuinely per sub-category, which is how roadkill can carry a fine
     * while natural mortality beside it carries nothing.
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

    /** How many sub-categories are still live under this kind. */
    public function activeSubcategoryCount(): int
    {
        $count = 0;
        foreach ($this->subcategories as $subcategory) {
            if ($subcategory->isActive()) {
                ++$count;
            }
        }

        return $count;
    }
}
