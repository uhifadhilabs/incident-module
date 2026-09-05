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

namespace Uhifadhi\Incident\Overview;

use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Overview\NowTile;
use Uhifadhi\Area\Overview\NowTileProviderInterface;
use Uhifadhi\Incident\Model\IncidentOverview;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;

/**
 * THIS MODULE'S TWO TILES IN THE HOST'S RIGHT-NOW STRIP — where the open work
 * is, and what came in today.
 *
 * THE INDEX IS PROVENANCE. `IN·N1` and `IN·N2` are printed on the plates
 * because a person has to be able to tell which installed thing put a number
 * there, and provenance has to survive a screenshot.
 *
 * ABSENT IS NOT ZERO, and this is the clearest place it bites: an area with no
 * register gets NO tiles, not two tiles reading 0. An area WITH a register gets
 * "Filed today · 0" on a quiet morning, because there the module really did
 * measure the day and really did find nothing — which is a different statement
 * from having nothing to say.
 *
 * The same tiles are what the duty board draws at board density: one set of
 * numbers, two densities, so a count cannot read one way in the strip and
 * another on the wall.
 */
final readonly class IncidentNowTiles implements NowTileProviderInterface
{
    /**
     * Where the two tiles sit in the strip. The design's order is patrols, then
     * incidents, then the host's own summary at the end — the strip reads left to
     * right as who is out, what is open, and what needs somebody.
     */
    private const int PRIORITY = 300;

    public function __construct(
        private IncidentOverviewFigures $figures,
    ) {
    }

    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function nowTilesFor(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $overview = $this->figures->for($area, $now);
        if ($overview->isEmpty()) {
            return [];
        }

        $late = \count($overview->pastTerm);

        return [
            new NowTile(
                'IN·N1',
                $this->moduleSlug(),
                'Open incidents',
                (string) $overview->openCount(),
                self::openSubline($overview),
                // THE ONE ALARM THIS MODULE RAISES ON A STRIP. Work past its own
                // category's term is the only incidents figure that is WRONG
                // rather than merely large, so it is the only one that colours a
                // plate. A backlog is not an alarm; a broken promise is.
                alarm: $late > 0 ? \sprintf('%d past their term', $late) : null,
                tone: $late > 0 ? NowTile::TONE_BAD : NowTile::TONE_PLAIN,
                url: $overview->dashboardUrl,
                priority: self::PRIORITY,
            ),
            new NowTile(
                'IN·N2',
                $this->moduleSlug(),
                'Filed today',
                (string) \count($overview->today),
                self::todaySubline($overview),
                url: $overview->dashboardUrl,
                priority: self::PRIORITY + 10,
            ),
        ];
    }

    /** The three open places, in workflow order — never a total split some other way. */
    private static function openSubline(IncidentOverview $overview): string
    {
        $parts = [];
        foreach ($overview->places() as $place) {
            if ($place->isOpen()) {
                $parts[] = \sprintf('%d %s', $overview->count($place), $place->label());
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * Today's filings by kind, busiest first, in the taxonomy's OWN short word —
     * the category slug, which is what the design prints and what the chips on
     * the card beneath print. A kind nothing was filed under is left out: this is
     * one line under a number, not a breakdown.
     */
    private static function todaySubline(IncidentOverview $overview): string
    {
        $counts = [];
        foreach ($overview->categories as $category) {
            $count = \count($overview->todayFor($category));
            if ($count > 0) {
                $counts[$category->getSlug()] = $count;
            }
        }
        // Stable, so equal counts keep the taxonomy's own order rather than
        // reshuffling themselves between two renders of the same morning.
        arsort($counts, \SORT_NUMERIC);

        $parts = [];
        foreach ($counts as $slug => $count) {
            $parts[] = \sprintf('%d %s', $count, $slug);
        }

        return implode(' · ', $parts);
    }
}
