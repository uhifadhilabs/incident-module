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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Trait\TimestampableTrait;
use Uhifadhi\Incident\Enum\AreaListEnum;
use Uhifadhi\Incident\Repository\AreaListEntryRepository;

/**
 * ONE WORD IN ONE OF ONE AREA'S FOUR LISTS — a species, a method, a land use, a
 * named place.
 *
 * IT IS A WORD AND NOT AN INCIDENT. Nothing on this row knows what an incident
 * is: it carries a key, a word, an optional note, the list it belongs to and the
 * area whose words these are, and that is the whole of it. The lists could
 * therefore be lifted to the area's own shared vocabulary later — a species list
 * a second module also reads — without any of this changing meaning, which is why
 * there is no severity, no block, no sub-category and no count stored here.
 * {@see AreaListEnum} is the one incident-shaped thing on
 * the row, and it names a LIST rather than a behaviour.
 *
 * THE KEY NEVER CHANGES, and the whole point of a list rests on it. {@see $key}
 * is what a filed record, an export column and an offline handset actually hold;
 * renaming the word leaves it untouched, so correcting a spelling never orphans a
 * case file and two records about the same animal stay countable as one animal.
 * It is unique within the AREA AND THE LIST, so a `water-body` named place and a
 * `water-body` land use are two different words.
 *
 * RETIRED LEAVES THE PICKER AND STAYS ON THE RECORD. {@see $retiredAt} is the day
 * a word left the report form and the handsets; every incident already filed
 * under it keeps it, it stays in the register filter, in every export and on
 * every case file, and one click brings it back. THERE IS NO DELETE — the screen
 * that edits these ships no such control, because nothing here may orphan a case
 * file.
 *
 * @see \Uhifadhi\Incident\Service\AreaListService  every write that reaches this row
 */
#[ORM\Entity(repositoryClass: AreaListEntryRepository::class)]
#[ORM\Table(name: 'incident_area_list_entry')]
#[ORM\UniqueConstraint(name: 'uniq_area_list_entry_key', columns: ['area_id', 'list', 'entry_key'])]
#[ORM\Index(name: 'idx_area_list_entry_area_list', columns: ['area_id', 'list'])]
#[ORM\HasLifecycleCallbacks]
class AreaListEntry
{
    use TimestampableTrait;

    /** What a word may be called, and what a key may be long. */
    public const int LABEL_LIMIT = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /**
     * The area whose words these are. Mapped to the concrete AreaOfInterest, as
     * {@see TaxonomyKind::$area} is, and onDelete CASCADE so removing an area
     * takes its vocabulary with it.
     */
    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** Which of the four lists this word is in. */
    #[ORM\Column(name: 'list', length: 24, enumType: AreaListEnum::class)]
    private AreaListEnum $list;

    /**
     * Stable across renames — see the class docblock. Unique within the area and
     * the list. `key` is a word Postgres would rather keep, so the column says
     * `entry_key` and the property says what it is.
     */
    #[ORM\Column(name: 'entry_key', length: 80)]
    private string $key;

    #[ORM\Column(length: self::LABEL_LIMIT)]
    private string $label;

    /**
     * What tells two similar words apart, in prose: the scientific name on a
     * species, where the place is on a named place, what a terse method means.
     * Optional, because most words need none.
     */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Null while the word is still offered. See the class docblock. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    public function __construct(AreaOfInterest $area, AreaListEnum $list, string $key, string $label)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->list = $list;
        $this->key = $key;
        $this->label = $label;
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

    public function getList(): AreaListEnum
    {
        return $this->list;
    }

    public function getKey(): string
    {
        return $this->key;
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

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

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

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    /** Whether the report form still offers this word. */
    public function isActive(): bool
    {
        return null === $this->retiredAt;
    }

    public function retire(\DateTimeImmutable $now): static
    {
        $this->retiredAt ??= $now;

        return $this;
    }

    public function reactivate(): static
    {
        $this->retiredAt = null;

        return $this;
    }
}
