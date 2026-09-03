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

use Uhifadhi\Entity\User;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Workflow\IncidentGuardEnum;
use Uhifadhi\Incident\Workflow\IncidentWorkflow;

/**
 * THE ONLY SUPPORTED WAY AN INCIDENT MOVES.
 *
 * It runs the definition in {@see IncidentWorkflow} — it holds no list of its own
 * of what follows what, which is the property that lets a platform workflow
 * module take the definition over later without touching this file's callers.
 *
 * Three things it does that a plain status setter cannot:
 *
 *  1. **It refuses illegal moves**, so verification can never be skipped.
 *  2. **It runs the guards** and hands back the REASON, which the toolbar prints
 *     and the endpoint answers 422 with.
 *  3. **It appends to the timeline.** Every move leaves an event, because a
 *     status column that changed by itself proves nothing. The event is added to
 *     the object graph and the CALLER flushes — this service takes no entity
 *     manager, which is what keeps the whole state machine unit-testable without
 *     a database.
 *
 * The clock's own move has its own door: {@see closeIfDue()}. A person calling
 * {@see apply()} with `close` is refused, always.
 */
final readonly class IncidentTransitionService
{
    /**
     * The moves a PERSON may make right now, in workflow order. `close` is never
     * among them.
     *
     * @return list<IncidentTransitionEnum>
     */
    public function available(Incident $incident, \DateTimeImmutable $now): array
    {
        $available = [];
        foreach (IncidentWorkflow::transitionsFrom($incident->getStatus()) as $transition) {
            if ($transition->isOfferedToPeople() && null === $this->refusal($incident, $transition, $now)) {
                $available[] = $transition;
            }
        }

        return $available;
    }

    /**
     * WHY THE OTHER MOVES ARE NOT ON OFFER — every transition this incident cannot
     * make right now, against the sentence saying why.
     *
     * The design's toolbar prints these beside the buttons ("Close — only
     * reachable from resolved"), which is why a refusal is a sentence rather than
     * a boolean.
     *
     * @return array<string, string> transition value => reason
     */
    public function blocked(Incident $incident, \DateTimeImmutable $now): array
    {
        $blocked = [];
        foreach (IncidentWorkflow::transitions() as $transition) {
            $refusal = $this->refusal($incident, $transition, $now);
            if (null !== $refusal) {
                $blocked[$transition->value] = $refusal;
            }
        }

        return $blocked;
    }

    /**
     * The sentence saying why this move is refused, or NULL when it is allowed.
     *
     * @param bool $byClock whether the caller is the clock rather than a person —
     *                      see {@see closeIfDue()}, the only caller that passes true
     */
    public function refusal(Incident $incident, IncidentTransitionEnum $transition, \DateTimeImmutable $now, bool $byClock = false): ?string
    {
        if ($incident->getStatus() !== $transition->fromPlace()) {
            return \sprintf(
                '%s — only reachable from %s; this incident is %s.',
                $transition->label(),
                $transition->fromPlace()->label(),
                $incident->getStatus()->label(),
            );
        }

        $guard = IncidentWorkflow::guardFor($transition);
        if (null === $guard) {
            return null;
        }

        return match ($guard) {
            IncidentGuardEnum::MoneySettledOrWaived => $this->moneyRefusal($incident),
            IncidentGuardEnum::ClockOnly => $this->clockRefusal($incident, $now, $byClock),
        };
    }

    /**
     * MAKE THE MOVE. Appends the timeline event and stamps the place's own
     * timestamp; the caller flushes.
     *
     * @param string|null $actorName who to print the event under — kept beside the
     *                               user so the timeline still names them if the account later goes
     *
     * @throws IncidentTransitionException with the guard's own sentence as its message
     */
    public function apply(
        Incident $incident,
        IncidentTransitionEnum $transition,
        \DateTimeImmutable $at,
        ?User $actor = null,
        ?string $actorName = null,
        ?string $note = null,
    ): IncidentEvent {
        return $this->move($incident, $transition, $at, $actor, $actorName, $note, byClock: false);
    }

    /**
     * THE CLOCK'S MOVE. Closes an incident that has been resolved for
     * {@see IncidentWorkflow::CLOSE_AFTER_DAYS}, and answers null for one that is
     * not due — so a caller can sweep every resolved incident and let this decide.
     *
     * No actor, deliberately: a machine's action has no author, and stamping one
     * on would put a person's name on something no person did.
     */
    public function closeIfDue(Incident $incident, \DateTimeImmutable $now): ?IncidentEvent
    {
        if (null !== $this->refusal($incident, IncidentTransitionEnum::Close, $now, byClock: true)) {
            return null;
        }

        return $this->move(
            $incident,
            IncidentTransitionEnum::Close,
            $now,
            null,
            null,
            \sprintf('Closed by the clock, %d days after resolution.', IncidentWorkflow::CLOSE_AFTER_DAYS),
            byClock: true,
        );
    }

    /** When the clock will close this incident, or null while it is not resolved. */
    public function closesAt(Incident $incident): ?\DateTimeImmutable
    {
        $resolvedAt = $incident->getResolvedAt();

        return $resolvedAt?->modify(\sprintf('+%d days', IncidentWorkflow::CLOSE_AFTER_DAYS));
    }

    private function move(
        Incident $incident,
        IncidentTransitionEnum $transition,
        \DateTimeImmutable $at,
        ?User $actor,
        ?string $actorName,
        ?string $note,
        bool $byClock,
    ): IncidentEvent {
        $refusal = $this->refusal($incident, $transition, $at, $byClock);
        if (null !== $refusal) {
            throw new IncidentTransitionException($refusal);
        }

        $from = $incident->getStatus();
        $to = $transition->toPlace();
        $incident->setStatus($to);
        $this->stamp($incident, $to, $at);

        return new IncidentEvent(
            $incident,
            IncidentEventKindEnum::Transition,
            $at,
            $note ?? \sprintf('%s — %s.', $transition->label(), $to->label()),
        )
            ->withToStatus($to)
            ->withActor($actor, $actorName)
            ->withDetail(\sprintf('transition %s → %s', $from->label(), $to->label()));
    }

    /** Each place keeps its own moment, so the rail can print when each step happened. */
    private function stamp(Incident $incident, IncidentStatusEnum $place, \DateTimeImmutable $at): void
    {
        match ($place) {
            IncidentStatusEnum::Verified => $incident->setVerifiedAt($at),
            IncidentStatusEnum::InProgress => $incident->setRespondedAt($at),
            IncidentStatusEnum::Resolved => $incident->setResolvedAt($at),
            IncidentStatusEnum::Closed => $incident->setClosedAt($at),
            IncidentStatusEnum::Reported => $incident,
        };
    }

    /**
     * THE MONEY GUARD. Only asks where the category carries money at all — a
     * mortality incident has no money record and nothing to settle.
     */
    private function moneyRefusal(Incident $incident): ?string
    {
        $money = $incident->getMoney();
        if (null === $money) {
            // NOTHING TO SETTLE. A sub-category that carries money is one whose
            // form OFFERS the fields; it is not a promise that this incident
            // involves any. A roadkill where no driver was identified owes
            // nothing, and holding it open for a payment nobody is making would
            // be the workflow inventing a debt.
            return null;
        }

        if (!$money->isAssessed()) {
            // A money record that EXISTS with no figures on it is different:
            // somebody opened it, so somebody thinks there is money here, and the
            // assessment is the outstanding work.
            return 'Mark resolved — money was opened on this incident and none has been assessed yet. Assess it, or waive it with a reason.';
        }
        if ($money->isSettled()) {
            return null;
        }

        return \sprintf(
            'Mark resolved — %s %s of %s is still outstanding. Settle it, or waive it with a reason.',
            $money->getCurrency(),
            number_format($money->outstanding(), 0, '.', ','),
            $money->getDirection()->value,
        );
    }

    /**
     * THE CLOCK GUARD. Refuses every person without exception, and refuses the
     * clock itself until the term is up.
     */
    private function clockRefusal(Incident $incident, \DateTimeImmutable $now, bool $byClock): ?string
    {
        if (!$byClock) {
            return \sprintf(
                'Close — reached by time, not by a person: an incident closes itself %d days after it is resolved.',
                IncidentWorkflow::CLOSE_AFTER_DAYS,
            );
        }

        $closesAt = $this->closesAt($incident);
        if (null === $closesAt || $now < $closesAt) {
            return \sprintf(
                'Close — not due until %s.',
                $closesAt?->format('D j M Y') ?? 'this incident is resolved',
            );
        }

        return null;
    }
}
