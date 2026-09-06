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

namespace Uhifadhi\Incident\Exception;

/**
 * A money write the record refuses — recording money on an incident that carries
 * none, or before response has started, or a waiver with no reason.
 *
 * Its message is the sentence the money panel prints and the endpoint answers 422
 * with, in the same voice {@see IncidentTransitionException} carries the workflow's
 * refusals: the request was understood perfectly and is simply not allowed.
 */
final class IncidentMoneyException extends \RuntimeException
{
}
