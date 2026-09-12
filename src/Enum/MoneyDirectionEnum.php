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
 * WHICH WAY THE MONEY RUNS. Two directions, and the design refuses to add them
 * together anywhere: a FINE is owed to the authority by somebody, a
 * COMPENSATION claim is owed by the authority to somebody. A single "amount"
 * column would lose the argument an incident is usually about.
 *
 * Which direction an incident carries — if any — is the SUB-CATEGORY's business
 * ({@see \Uhifadhi\Incident\Entity\TaxonomySubcategory::getMoneyDirection()}),
 * which is how roadkill can carry a fine while natural mortality carries nothing.
 */
enum MoneyDirectionEnum: string
{
    case Fine = 'fine';
    case Compensation = 'compensation';

    /**
     * HOW FAR AN INCIDENT MUST HAVE GOT before money can be recorded on it, and
     * the two directions do not answer the same.
     *
     * A CLAIM is somebody else's statement: a household asks for compensation the
     * moment the authority agrees the thing happened, and a product that refused
     * to write it down until a responder had been assigned would be losing a claim
     * it has already been handed. `verified` is when the authority agrees.
     *
     * A FINE is the authority's own act. Assessing a penalty is enforcement, which
     * is the work `in progress` names — fining somebody while the report is still
     * only a report would be fining them on the strength of an allegation.
     */
    public function recordableFrom(): IncidentStatusEnum
    {
        return match ($this) {
            self::Fine => IncidentStatusEnum::InProgress,
            self::Compensation => IncidentStatusEnum::Verified,
        };
    }

    /**
     * What the money panel prints when it refuses, in this direction's own words —
     * a claimant told "response has not started" would be told the wrong rule.
     */
    public function refusedTooEarly(): string
    {
        return match ($this) {
            self::Fine => 'A fine is assessed once response has started — this incident has not reached in progress yet.',
            self::Compensation => 'A compensation claim is recorded once an incident is verified — this incident has not reached verified yet.',
        };
    }

    /** The money block's heading, in the design's own words. */
    public function heading(): string
    {
        return match ($this) {
            self::Fine => 'Fines — owed TO the authority',
            self::Compensation => 'Compensation — owed BY the authority',
        };
    }

    /**
     * What the money that actually moved is CALLED in this direction. The same
     * stored figure reads "collected" against a fine and "paid" against a claim,
     * and printing the wrong word would describe the wrong transaction.
     */
    public function settledWord(): string
    {
        return match ($this) {
            self::Fine => 'collected',
            self::Compensation => 'paid',
        };
    }

    /**
     * WHO PAYS WHOM — the money block's summary line, in the design's own words.
     *
     * It is the caption rather than the heading because it is what a filer needs
     * while deciding whether the block is theirs to answer: "a fine to assess, or
     * a claim to settle" states both possibilities at a filer who is only ever
     * looking at one of them.
     */
    public function whoPaysWhom(): string
    {
        return match ($this) {
            self::Fine => 'the offender pays the authority',
            self::Compensation => 'the authority pays the claimant',
        };
    }

    /** What the largest figure is called before anything moves. */
    public function assessedWord(): string
    {
        return match ($this) {
            self::Fine => 'assessed',
            self::Compensation => 'approved',
        };
    }
}
