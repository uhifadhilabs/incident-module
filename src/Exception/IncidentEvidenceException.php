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
 * A FILE THE DEPLOYMENT WILL NOT TAKE AS EVIDENCE — not a photograph, too large,
 * or an upload that never arrived.
 *
 * Carries storage's own sentence, in the voice {@see IncidentMoneyException} and
 * {@see IncidentTransitionException} use: the request was understood and is
 * simply not allowed, so a screen answers 422 and prints the message.
 *
 * ONLY THE REFUSAL IS TRANSLATED. A storage FAILURE — a full disk, a mount that
 * went away — passes through as the platform's own exception, because it is a
 * 500 and retrying it is worthwhile, and this module has nothing to add to it.
 * Flattening the two into one type would tell a caller to print "could not
 * attach" at a person for something that was never their doing.
 */
final class IncidentEvidenceException extends \RuntimeException
{
}
