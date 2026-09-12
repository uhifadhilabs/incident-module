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
 * WHERE THE WORDS COME FROM: the Lists section of this module's configure page
 * ({@see \Uhifadhi\Incident\Controller\IncidentAreaListController}), per area. A
 * list with nothing in it is a legitimate state — the form draws the select
 * empty, beside the typed `other`, and a filer is never shown an invented choice.
 *
 * THERE ARE EXACTLY FOUR AND AN AREA CANNOT ADD A FIFTH. Each case is the list
 * one block question reads, so a fifth list arrives only with a fifth question,
 * in code. What an area owns is the words in them.
 *
 * THE COPY THE SCREEN SAYS ABOUT A LIST LIVES HERE, beside the placeholder the
 * form already drew from this enum: a list's own name, what its "used by" line
 * adds after the gate, and the words of its add panel. Adding a case therefore
 * brings its whole vocabulary with it and no screen grows a case of its own.
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

    /** What the fold's summary line calls this list. */
    public function label(): string
    {
        return match ($this) {
            self::Species => 'Species',
            self::Method => 'Method',
            self::LandUse => 'Land use',
            self::NamedPlace => 'Named places',
        };
    }

    /**
     * What the "used by" line adds once it has said whether the answer gates a
     * filing — the clause that is true of THIS list and of no other.
     */
    public function gateNote(): string
    {
        return match ($this) {
            self::Species => 'a sub-category with the Species block on cannot be filed without one',
            self::Method => '',
            self::LandUse => 'the measures beside it are what the block needs, so an incident files with the land use left blank',
            self::NamedPlace => 'beside the typed name a filer can always give',
        };
    }

    /**
     * The rest of what is true of this list and nothing else, one sentence per
     * entry, said once for the whole list rather than on every row.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return match ($this) {
            self::NamedPlace => [
                'The <b>kind of place</b> asked next to it — water body, road segment, boundary marker, household or boma, other — is fixed in code and is not edited here.',
                '<b>A point comes later:</b> these entries are places and each one will hold coordinates, but nothing on this screen can set one and the map has nothing to draw from them yet — said here once, for the whole list, rather than on every row.',
            ],
            default => [],
        };
    }

    /** The add panel's heading. */
    public function addHeading(): string
    {
        return match ($this) {
            self::Species => 'Add a species',
            self::Method => 'Add a method',
            self::LandUse => 'Add a land use',
            self::NamedPlace => 'Add a named place',
        };
    }

    /** The add panel's act. */
    public function addAction(): string
    {
        return match ($this) {
            self::Species => '+ Add species',
            self::Method => '+ Add method',
            self::LandUse => '+ Add land use',
            self::NamedPlace => '+ Add place',
        };
    }

    /** What the add panel's word field asks for. */
    public function wordPlaceholder(): string
    {
        return match ($this) {
            self::Species => 'Common name, as this area says it',
            self::Method => "How it was done, in this area's words",
            self::LandUse => 'What the affected ground is used for',
            self::NamedPlace => 'Name of the place, as this area says it',
        };
    }

    /** What the add panel's note field asks for, where it asks for something specific. */
    public function notePlaceholder(): string
    {
        return match ($this) {
            self::Species => 'Scientific name (optional)',
            self::NamedPlace => 'Where it is, in words (optional)',
            default => 'Short note (optional)',
        };
    }
}
