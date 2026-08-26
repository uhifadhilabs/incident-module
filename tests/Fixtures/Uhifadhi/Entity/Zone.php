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

namespace Uhifadhi\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * DEV/TEST-ONLY stub of uhifadhi's Uhifadhi\Entity\Zone — carried in the bundle's
 * autoload-dev so the Incident→Zone mapping resolves when the bundle is tested and
 * phpstan'd in isolation. NOT shipped (autoload-dev is dropped on install), so inside
 * uhifadhi the REAL Zone is loaded instead.
 *
 * It mirrors the real class for the fields this module reads — id, uuid, name, area and
 * the polygon a point is tested against. Zones are the host's SPATIAL lens; a module
 * consumes them generically ("which zone is this point in?") and must never name one,
 * which is why "unzoned" is a first-class answer everywhere in this bundle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'zone')] // matches uhifadhi's real table
class Zone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /** MultiPolygon in WGS84, as GeoJSON text — the same shape the real column holds. */
    #[ORM\Column(type: 'multipolygon', nullable: true)]
    private ?string $geom = null;

    public function __construct(AreaOfInterest $area, string $name)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getGeom(): ?string
    {
        return $this->geom;
    }

    public function setGeom(?string $geom): static
    {
        $this->geom = $geom;

        return $this;
    }
}
