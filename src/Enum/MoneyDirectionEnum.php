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

namespace UhifadhiLabs\Incident\Enum;

/**
 * WHICH WAY THE MONEY RUNS. Two directions, and the design refuses to add them
 * together anywhere: a FINE is owed to the authority by somebody, a
 * COMPENSATION claim is owed by the authority to somebody. A single "amount"
 * column would lose the argument an incident is usually about.
 *
 * Which direction an incident carries — if any — is the SUB-CATEGORY's business
 * ({@see \UhifadhiLabs\Incident\Entity\IncidentSubcategory::getMoneyDirection()}),
 * which is how roadkill can carry a fine while natural mortality carries nothing.
 */
enum MoneyDirectionEnum: string
{
    case Fine = 'fine';
    case Compensation = 'compensation';

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

    /** What the largest figure is called before anything moves. */
    public function assessedWord(): string
    {
        return match ($this) {
            self::Fine => 'assessed',
            self::Compensation => 'approved',
        };
    }
}
