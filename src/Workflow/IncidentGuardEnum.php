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

namespace UhifadhiLabs\Incident\Workflow;

/**
 * THE GUARDS, BY NAME. Part of the definition ({@see IncidentWorkflow::guardFor()})
 * rather than a private list inside the service that runs them, for the reason
 * stated on that class: when a platform workflow module takes the definition
 * over, these become its guard listeners and the case names are what its
 * configuration refers to.
 *
 * Each guard answers with a SENTENCE, never a boolean — the design's toolbar
 * prints the refusal beside the moves that are allowed, so a guard that could
 * only say "no" would leave a person staring at a button that does nothing.
 */
enum IncidentGuardEnum: string
{
    /**
     * `resolve` — an incident whose category carries money is not resolved while
     * that money is outstanding. It is unpaid, which is a different fact, and
     * closing the gap between the two is what a compensation queue exists for.
     * A WAIVER passes the guard, because somebody wrote down why.
     */
    case MoneySettledOrWaived = 'money_settled_or_waived';

    /**
     * `close` — reached by TIME, never by a person. The guard refuses every actor
     * without exception; the only caller that gets through is the clock, and it
     * gets through by not being an actor at all.
     */
    case ClockOnly = 'clock_only';
}
