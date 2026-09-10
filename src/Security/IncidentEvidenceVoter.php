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

namespace Uhifadhi\Incident\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Storage\IncidentFileSource;
use Uhifadhi\Storage\Security\EvidenceAccessVoterInterface;

/**
 * Who may read an incident's evidence — the module half of storage-module's
 * permission contract.
 *
 * The storage bundle stores bytes and refuses to guess: a key that NO module
 * claims is denied. Until this class existed no voter claimed the `incident/…`
 * keys IncidentFileSource writes, so every incident photograph and signed
 * document — and every generated preview of one — was (correctly, by the contract's
 * deny-by-default rule) invisible on the Files hub, even where the case was
 * freely readable. It claims those keys and answers for them.
 *
 * THE RULE IS THE CASE-FILE PAGE'S RULE, deliberately: evidence is shown on the
 * incident detail screen, reached by any signed-in member of the authority (the
 * host's access_control puts every non-API path behind ROLE_USER). So the answer
 * is a signed-in user and a key this module actually holds; a stricter rule for
 * the bytes than for the page they appear on would only be a broken image on a
 * page the reader is entitled to. The OPEN-vs-resolved question is about removing
 * evidence, not reading it, and lives in {@see IncidentFileSource::guardFor}.
 *
 * REVISIT WHEN the host grows per-area permissions: this becomes "may the user
 * view the incident's area", resolved through the evidence → incident → area
 * chain the lookup below already reaches; the contract does not change, only the
 * question asked at the end of it.
 *
 * PREVIEWS need no handling here: the access decider resolves a `.thumb.jpg` to
 * the original it previews BEFORE polling voters, so this only ever sees a
 * stored evidence key. Keeping that in the one choke point (the decider) rather
 * than in every module's voter is why this class carries no thumbnail logic.
 */
final class IncidentEvidenceVoter implements EvidenceAccessVoterInterface
{
    public function __construct(
        private readonly IncidentEvidenceRepository $evidence,
    ) {
    }

    public function claimsKey(string $key): bool
    {
        return IncidentFileSource::claims($key);
    }

    public function mayRead(string $key, ?UserInterface $user): bool
    {
        // A visitor who is not signed in. The host's firewall would normally have
        // stopped them before this, but the voter is asked anyway and must answer
        // for itself: a deployment that ever exposes the serving route more
        // loosely must not thereby expose incident evidence.
        if (null === $user) {
            return false;
        }

        return null !== $this->evidence->findOneByPath($key);
    }
}
