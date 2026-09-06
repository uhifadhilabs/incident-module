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
 * A COMPOSABLE BEHAVIOUR BLOCK — one of the coded things the platform already
 * knows how to record, that an area's sub-category can switch ON.
 *
 * RULED (taxonomy design): blocks are COMPOSED, not chosen from a menu of fixed
 * types and not gathered into named bundles. A sub-category switches on exactly
 * the blocks it needs; every one is independent, and there is no state a
 * combination cannot reach. There is no form-builder and there are no custom
 * fields — the set is closed here, in code, because each case is behaviour the
 * platform has to already know how to render on the filing form.
 *
 * NAMED BY WHAT THEY DO, never by an org's vocabulary, so the day an area renames
 * "snaring" every block name is still true. No area's words appear in any case.
 *
 * MONEY IS SPECIAL only in that its presence also carries a DIRECTION (a fine to
 * assess or a compensation claim to settle) — see
 * {@see \Uhifadhi\Incident\Entity\TaxonomySubcategory::$moneyDirection}. Absent
 * from the set means the money row is ABSENT from the form, not greyed out.
 */
enum BehaviorBlockEnum: string
{
    case Species = 'species';
    case Counts = 'counts';
    case Method = 'method';
    case Parties = 'parties';
    case Seizures = 'seizures';
    case Money = 'money';
    case Condition = 'condition';
    case Samples = 'samples';
    case Casualty = 'casualty';
    case Extent = 'extent';
    case NamedPlace = 'named-place';
    case Notice = 'notice';

    /** The block's name on a chip and in the picker — the design's own words. */
    public function label(): string
    {
        return match ($this) {
            self::Species => 'Species',
            self::Counts => 'Counts',
            self::Method => 'Method & means',
            self::Parties => 'Parties',
            self::Seizures => 'Seizures',
            self::Money => 'Money',
            self::Condition => 'Condition & disposition',
            self::Samples => 'Samples',
            self::Casualty => 'Casualty & treatment',
            self::Extent => 'Extent',
            self::NamedPlace => 'Named place',
            self::Notice => 'Notice & licence',
        };
    }

    /** What the block asks for — the picker's explanatory line, verbatim. */
    public function description(): string
    {
        return match ($this) {
            self::Species => "A species from this area's own species list, with sex and age class where they are known.",
            self::Counts => 'One or more counted quantities: animals, snares lifted, head of stock, structures, individuals affected.',
            self::Method => 'How it was done and with what: method, gear, vehicle, suspected agent.',
            self::Parties => 'The people on the record, each in a role — reporter, claimant, suspect, witness, responder.',
            self::Seizures => 'Items taken into custody, counted and described.',
            self::Money => 'A fine to assess, or a compensation claim to settle. Which way it runs is a setting on the sub-category; none means the block is absent from the form, not greyed out.',
            self::Condition => 'The state of the animal or carcass, and what became of it.',
            self::Samples => 'Samples taken, and where they went.',
            self::Casualty => 'Human injuries and the treatment given.',
            self::Extent => 'The measured size of the thing: area affected, footprint, length of boundary, how long it went on.',
            self::NamedPlace => 'The place beyond the pin, when the pin is not the answer: water body, road segment, boundary marker, household or boma.',
            self::Notice => 'Permit or licence status, and whether a notice was served.',
        };
    }

    /** Whether this block is the money block — the one that also carries a direction. */
    public function isMoney(): bool
    {
        return self::Money === $this;
    }

    /**
     * The blocks in the order the picker draws them.
     *
     * @return list<self>
     */
    public static function inPickerOrder(): array
    {
        return self::cases();
    }
}
