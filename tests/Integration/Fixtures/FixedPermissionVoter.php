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

namespace Uhifadhi\Incident\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Entity\User;
use Uhifadhi\Incident\Controller\IncidentDetailController;
use Uhifadhi\Incident\Controller\IncidentReportController;

/**
 * Test stand-in for the HOST's permission voter. The bundle only DECLARES
 * "incidents.record" and "incidents.manage"; deciding who holds them is the
 * host's job.
 *
 * Here that decision is fixed and DIFFERENT FOR THE TWO TIERS, on purpose: one
 * account may file and not move, another may do both. That is the split the
 * module's economics rest on — a report is cheap and a verification is expensive
 * — so the tests have to be able to exercise a person who has one and not the
 * other, which a single blanket "may do everything" stub could never show.
 *
 * @extends Voter<string, mixed>
 */
final class FixedPermissionVoter extends Voter
{
    /** May file an incident, and may not move one. */
    public const string REPORTER_EMAIL = 'reporter@example.test';

    /** May file AND move — the supervisor. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [
            IncidentReportController::RECORD_PERMISSION,
            IncidentDetailController::MANAGE_PERMISSION,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            IncidentReportController::RECORD_PERMISSION => \in_array($user->getEmail(), [self::REPORTER_EMAIL, self::MANAGER_EMAIL], true),
            IncidentDetailController::MANAGE_PERMISSION => self::MANAGER_EMAIL === $user->getEmail(),
            default => false,
        };
    }
}
