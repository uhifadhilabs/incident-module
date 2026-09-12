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

namespace Uhifadhi\Incident\Enum;

/**
 * THE PER-AREA LISTS A BLOCK QUESTION READS FROM.
 *
 * Four questions do not hold their own words: which animal, by what method, what
 * the ground is used for and which named place. Those are ONE AREA'S OWN
 * vocabulary — the point of a list is that two records about the same water body
 * are countable as one place and two records about the same animal are countable
 * as one species, which a typed name can never promise.
 *
 * THE LIST IS DATA, NEVER A CASE IN CODE. This enum names the list a question
 * reads; it never holds its members. A module that hardcoded "Lion" would be
 * writing one area's animals into every area on earth.
 *
 * WHAT IS HELD: no screen edits these lists yet. Until one does, the report form
 * draws the list it is given — empty — beside the typed `other` the design's
 * named place already carries, and a filer is never shown an invented choice.
 */
enum AreaListEnum: string
{
    case Species = 'species';
    case Method = 'method';
    case LandUse = 'land-use';
    case NamedPlace = 'named-place';

    /** The empty select's own line, in the design's words. */
    public function placeholder(): string
    {
        return match ($this) {
            self::Species => "— pick from the area's species list —",
            self::Method => "— pick from the area's method list —",
            self::LandUse => "— pick from the area's land-use list —",
            self::NamedPlace => "— pick from the area's named places —",
        };
    }
}
