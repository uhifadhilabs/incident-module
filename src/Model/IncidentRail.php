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

namespace UhifadhiLabs\Incident\Model;

use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Enum\IncidentTransitionEnum;

/**
 * THE STATE MACHINE, MADE VISIBLE, for ONE incident — the component the design
 * draws identically in three places: on the detail page under the heading, on the
 * gallery pages, and as the "where things stand" dashboard widget.
 *
 * IDENTICAL COMPONENT, IDENTICAL MARKUP. That is the design's own instruction, so
 * there is one model and one partial and both are used by all three. A rail that
 * rendered differently on the dashboard than on the case file would be two
 * different claims about the same incident.
 *
 * The dashboard widget is PERSON-SCOPED: it shows the incident YOU last touched,
 * falling back to the head of your queue, and says which in its own header. Two
 * people looking at the same dashboard see two different incidents here — which
 * is why the header always names the incident and why this is never a summary.
 */
final readonly class IncidentRail
{
    /** Why this incident is the one on the rail. */
    public const string BECAUSE_TOUCHED = 'touched';
    public const string BECAUSE_QUEUED = 'queued';

    /**
     * @param list<IncidentTransitionEnum> $available the moves offered right now, guards already run
     * @param array<string, string>        $blocked   transition value => the sentence saying why not
     * @param string                       $because   {@see BECAUSE_TOUCHED} or {@see BECAUSE_QUEUED}
     */
    public function __construct(
        public Incident $incident,
        public array $available,
        public array $blocked,
        public string $because = self::BECAUSE_TOUCHED,
        public ?\DateTimeImmutable $closesAt = null,
    ) {
    }

    /**
     * THE REFUSALS WORTH PRINTING beside the buttons — which is not all of them.
     *
     * {@see $blocked} is complete and honest: every move this incident cannot
     * make, with the reason. But most of those reasons are only "it is not that
     * step's turn yet", and the rail above already says so by drawing those steps
     * as unreached. Printing them again would bury the two refusals that a person
     * genuinely needs:
     *
     *  - a move that IS this step's turn and a GUARD is refusing it (the money is
     *    outstanding) — the thing standing between them and finishing;
     *  - a move NO PERSON may ever make (`close`, which the clock reaches) — the
     *    design prints exactly this one on a case file that is nowhere near it,
     *    because otherwise people go looking for the button.
     *
     * @return array<string, string> transition value => reason
     */
    public function notableBlocked(): array
    {
        $notable = [];
        foreach ($this->blocked as $value => $reason) {
            $transition = IncidentTransitionEnum::tryFrom($value);
            if (null === $transition) {
                continue;
            }
            if (!$transition->isOfferedToPeople() || $transition->fromPlace() === $this->incident->getStatus()) {
                $notable[$value] = $reason;
            }
        }

        return $notable;
    }

    /** The header's own line: why you are looking at this one. */
    public function reason(): string
    {
        return self::BECAUSE_TOUCHED === $this->because
            ? 'the incident you last touched'
            : 'the oldest thing waiting on you';
    }

    /**
     * The five steps, each knowing whether it is passed, current or still ahead,
     * and when it happened if it did.
     *
     * @return list<array{place: IncidentStatusEnum, state: string, at: \DateTimeImmutable|null}>
     */
    public function steps(): array
    {
        $status = $this->incident->getStatus();

        $steps = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $steps[] = [
                'place' => $place,
                'state' => match (true) {
                    $place === $status => 'now',
                    $status->hasReached($place) => 'done',
                    default => 'todo',
                },
                'at' => $this->reachedAt($place),
            ];
        }

        return $steps;
    }

    /**
     * WHETHER A STEP'S PANEL EXISTS AT ALL. The design's contract, in one method:
     * a panel is rendered only once its step has been reached — never rendered and
     * disabled, because an empty resolution form on a freshly reported incident is
     * an invitation to fill it in early.
     */
    public function hasReached(IncidentStatusEnum $place): bool
    {
        return $this->incident->getStatus()->hasReached($place);
    }

    private function reachedAt(IncidentStatusEnum $place): ?\DateTimeImmutable
    {
        return match ($place) {
            IncidentStatusEnum::Reported => $this->incident->getReportedAt(),
            IncidentStatusEnum::Verified => $this->incident->getVerifiedAt(),
            IncidentStatusEnum::InProgress => $this->incident->getRespondedAt(),
            IncidentStatusEnum::Resolved => $this->incident->getResolvedAt(),
            IncidentStatusEnum::Closed => $this->incident->getClosedAt(),
        };
    }
}
