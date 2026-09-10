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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Exception\IncidentTransitionException;

/**
 * THE CASE FILE'S WRITE SURFACE — everything that happens to one incident after
 * it is filed, and the place the change becomes durable.
 *
 * WHY THIS SITS BETWEEN A CONTROLLER AND {@see IncidentTransitionService}. The
 * transition service is the state machine and takes no entity manager on purpose:
 * that is what lets every rule the workflow has be asserted in a plain unit test
 * with no database. Somebody still has to write the decision down, and until this
 * class existed that somebody was the controller, holding an entity manager for a
 * single `flush()`. A screen deciding when a domain change is persisted is a
 * screen with an opinion about the domain.
 *
 * SO THE DIVISION IS: the transition service decides and stamps, this service
 * persists, and the controller authorizes and responds. A refusal never reaches
 * the flush — {@see IncidentTransitionException} is thrown before anything is
 * written, and the endpoint answers 422 with the guard's own sentence.
 */
final readonly class IncidentCaseService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentTransitionService $transitions,
    ) {
    }

    /**
     * MOVE THE INCIDENT ON, durably.
     *
     * @param string|null $actorName who to print the event under — kept beside the
     *                               account so the timeline still names them if the account later goes
     *
     * @throws IncidentTransitionException with the guard's own sentence as its message
     */
    public function move(
        Incident $incident,
        IncidentTransitionEnum $transition,
        \DateTimeImmutable $at,
        ?UserInterface $actor = null,
        ?string $actorName = null,
        ?string $note = null,
    ): IncidentEvent {
        $event = $this->transitions->apply($incident, $transition, $at, $actor, $actorName, $note);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * THE CLOCK'S MOVE, durably — and nothing at all for an incident that is not
     * due, so a sweep can offer every resolved incident and let the workflow
     * decide.
     */
    public function closeIfDue(Incident $incident, \DateTimeImmutable $now): ?IncidentEvent
    {
        $event = $this->transitions->closeIfDue($incident, $now);
        if (null === $event) {
            return null;
        }

        $this->entityManager->flush();

        return $event;
    }
}
