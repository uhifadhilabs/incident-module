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

namespace Uhifadhi\Incident\Service;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Storage\Service\EvidenceKey;

/**
 * Which evidence keys are INCIDENTS', in one place.
 *
 * Three collaborators need the same answer and must never disagree about it:
 * {@see IncidentEvidenceService} writes keys under this prefix,
 * {@see \Uhifadhi\Incident\Security\IncidentEvidenceVoter} claims them back on
 * the way out so storage-module's deny-by-default rule does not swallow them,
 * and {@see \Uhifadhi\Incident\Storage\IncidentFileSource} lists them on the
 * Files hub. A prefix remembered in three places is a prefix that eventually
 * differs in one, and the failure mode there is silent: a photograph nobody is
 * allowed to look at, on a page the reader is entitled to read.
 *
 * Static, because there is nothing to configure — these rules are the same in
 * every deployment.
 */
final class IncidentEvidenceKey
{
    /**
     * The owning prefix of every key this module writes. It is the first segment
     * of the key, which is what {@see EvidenceKey::rootSegment()} reads back and
     * what the voter claims.
     */
    public const string PREFIX = 'incident';

    private function __construct()
    {
    }

    /**
     * Where this incident's evidence is filed: under its CASE FILE, so a case's
     * photographs and documents can be found, archived or handed over as one
     * thing rather than hunted for across a flat directory, and so the voter's
     * lookup is a lookup rather than a scan.
     *
     * The case file is named by its uuid. Deliberately NOT its reference, which
     * is presentation minted from the register: evidence filed under a label
     * would be evidence that moved when the label did.
     */
    public static function prefixFor(Incident $incident): string
    {
        return self::PREFIX.'/'.$incident->getUuid()->toRfc4122();
    }

    /** Is this key one of ours? */
    public static function claims(string $key): bool
    {
        return self::PREFIX === EvidenceKey::rootSegment($key);
    }
}
