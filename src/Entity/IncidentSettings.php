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
use Uhifadhi\Incident\Repository\IncidentSettingsRepository;

/**
 * WHAT ONE AREA RUNS INCIDENTS ON — what used to be one installation's opinion
 * and is now each area's own.
 *
 * ONE ROW PER AREA, AND THE ROW MAY BE ABSENT. An area that has never opened the
 * Settings section has no row, and the service answers out of the installation's
 * configuration instead; writing a row for every area at install time would make
 * an untouched default indistinguishable from a chosen one.
 *
 * COLUMNS, NOT A JSON BLOB. A currency decides what every fine and every claim in
 * this area is counted in, so it gets a column: the database can constrain it, a
 * migration can change it in the open, phpstan can check the type, and a reader
 * can see the whole of what an area may set by looking at the table. A JSON
 * document would hide all four behind a string.
 *
 * THIN, like every entity here: state and accessors, no behaviour.
 */
#[ORM\Entity(repositoryClass: IncidentSettingsRepository::class)]
#[ORM\Table(name: 'incident_settings')]
#[ORM\UniqueConstraint(name: 'uniq_incident_settings_area', columns: ['area_id'])]
#[ORM\HasLifecycleCallbacks]
class IncidentSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** The ISO code every fine and every compensation claim in this area is recorded and totalled in. */
    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(AreaOfInterest $area, string $currency)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->currency = $currency;
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }
}
