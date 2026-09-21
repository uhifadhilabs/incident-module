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
use Uhifadhi\Bundle\TeamBundle\Access\Door;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Incident\Access\IncidentConcerns;
use Uhifadhi\Incident\Repository\IncidentEvidenceRepository;
use Uhifadhi\Incident\Service\IncidentEvidenceKey;
use Uhifadhi\Storage\Security\EvidenceAccessVoterInterface;

/**
 * Who may read an incident's evidence — the module half of storage-module's
 * permission contract.
 *
 * The storage bundle stores bytes and refuses to guess: a key that NO module
 * claims is denied. Until this class existed no voter claimed the `incident/…`
 * keys this module writes, so every incident photograph and signed
 * document — and every generated preview of one — was (correctly, by the contract's
 * deny-by-default rule) invisible on the Files hub, even where the case was
 * freely readable. It claims those keys and answers for them.
 *
 * THE RULE IS THE CASE-FILE CARD'S RULE, deliberately: the bytes answer exactly
 * the question the card that shows them answers, `case-files.read` ON THE AREA
 * THE INCIDENT LIES IN — resolved through the evidence → incident → area chain
 * the lookup below already reaches. Anything looser would put a photograph of a
 * suspect on the Files hub for somebody the case file itself withholds it from;
 * anything stricter would be a broken image on a page the reader is entitled
 * to. The OPEN-vs-resolved question is about REMOVING evidence, not reading it,
 * and lives in {@see \Uhifadhi\Incident\Storage\IncidentFileSource::guardFor}.
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
        /** Null where the installation runs no security; evidence is then refused. */
        private readonly ?Door $door = null,
    ) {
    }

    public function claimsKey(string $key): bool
    {
        return IncidentEvidenceKey::claims($key);
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

        $evidence = $this->evidence->findOneByPath($key);

        return null !== $evidence
            && (true === $this->door?->opensFor(IncidentConcerns::CASE_FILES, Verb::Read, $evidence->getIncident()->getArea()));
    }
}
