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

namespace UhifadhiLabs\Incident\Exception;

/**
 * A move the workflow refuses — because it is not legal from where the incident
 * is, or because a guard said no.
 *
 * It carries the REASON as its message, and the reason is the sentence a screen
 * shows: the transition endpoint answers 422 with it, and the detail page's
 * toolbar prints the same words beside the moves that ARE allowed. One sentence,
 * written once, by the guard that knows why.
 */
final class IncidentTransitionException extends \RuntimeException
{
}
