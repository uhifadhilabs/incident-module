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
 * A write to a case file the record refuses — a link an incident makes to itself,
 * the same link claimed twice, a link reaching into another area.
 *
 * Its message is the sentence the panel prints and the endpoint answers 422 with,
 * in the same voice {@see IncidentTransitionException} and
 * {@see IncidentMoneyException} carry theirs: the request was understood
 * perfectly and is simply not allowed.
 */
final class IncidentCaseException extends \RuntimeException
{
}
