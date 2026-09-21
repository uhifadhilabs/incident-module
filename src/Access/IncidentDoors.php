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

namespace Uhifadhi\Incident\Access;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * WHETHER A FIGURE MAY BE DRAWN — the door, asked from the places a template
 * cannot ask it from.
 *
 * A SCREEN OF THIS MODULE'S OWN calls `door('case-money.read', area)` in its
 * own markup, because it has the area in front of it. A CONTRIBUTED CELL does
 * not: the area overview, the organization dashboard, a department's KPI strip
 * and a performance topic are all rendered by somebody else, from a context
 * this module hands over, and the question has to be answered before the
 * handover. This is where.
 *
 * IT ANSWERS FOR A WHOLE SCOPE, WHICH IS THE POINT. An organization-wide
 * reading is the areas' readings added up, so a figure across every area is
 * only honest where the reader may read EVERY area in it: one refused area
 * would otherwise be smuggled into a total. So a scope with no area named
 * means "all of them", and one refusal withholds the figure.
 *
 * IT FAILS CLOSED, THREE WAYS. No door (an installation with no
 * SecurityBundle), no token (a warm-up, a console command, a cell assembled
 * off a request) and no answer all read the same: withheld. A figure about
 * money or about the people on a case is not a thing to draw while unsure.
 *
 * WITHHELD IS NOT ZERO, and every caller says so in its own words: the cell
 * prints "withheld", the plate is left out of the strip. A nought would be a
 * measurement, and this is the absence of permission to take one.
 */
final readonly class IncidentDoors
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        /** Null where the installation runs no security; everything is then withheld. */
        private ?Door $door = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /** One area, or none in context. */
    public function opens(string $concern, Verb $verb, ?AreaInterface $area): bool
    {
        if (null === $this->door || null === $this->tokens?->getToken()) {
            return false;
        }

        return $this->door->opensFor($concern, $verb, $area);
    }

    /**
     * THE SCOPE A CONTRIBUTED FIGURE IS MEASURED OVER, as every contract in
     * the platform spells one: an area's uuid, or null for the whole
     * organization. Null asks every area, and one refusal is a refusal.
     */
    public function opensAcross(string $concern, Verb $verb, ?string $areaUuid): bool
    {
        if (null === $this->door || null === $this->tokens?->getToken()) {
            return false;
        }

        if (null !== $areaUuid) {
            $area = $this->areas->findOneByUuid($areaUuid);

            // A uuid nothing answers to is not ground this reader was
            // refused; it is ground that does not exist, and there is
            // nothing to withhold.
            return null === $area || $this->door->opensFor($concern, $verb, $area);
        }

        foreach ($this->areas->findAllOrdered() as $area) {
            if (!$this->door->opensFor($concern, $verb, $area)) {
                return false;
            }
        }

        return true;
    }
}
