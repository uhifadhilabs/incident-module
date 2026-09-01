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

namespace UhifadhiLabs\Incident\Overview;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Overview\PulseEvent;
use Uhifadhi\Overview\PulseProviderInterface;
use UhifadhiLabs\Incident\Model\IncidentHues;
use UhifadhiLabs\Incident\Model\IncidentOverviewWidgets;
use UhifadhiLabs\Incident\Repository\IncidentEventRepository;

/**
 * THIS MODULE'S MOVES IN THE AREA PULSE.
 *
 * The pulse is a log of MOVES, not of records: an incident verified, a note
 * written, evidence attached, a figure changed. This module has kept exactly
 * that log since it shipped — the append-only timeline that is the spine of a
 * case file — so the seam is answered by reading it, not by writing a second
 * one.
 *
 * WHEN THE PLATFORM'S WORKFLOW MODULE LANDS, THIS IS WHAT IT FILLS. The
 * roadmap's state machines and audit trail replace
 * {@see \UhifadhiLabs\Incident\Workflow\IncidentWorkflow} and the timeline
 * behind it; this class keeps answering the same interface with the same rows,
 * because nothing here knows where the events came from.
 *
 * THE HOST DOES NOT INTERPRET A MOVE. It prints the module's verb, sorts by
 * time and groups by day. The status chip is the module's own class, so the
 * host's neutral row can wear this module's colour for `verified` without ever
 * having heard of verification.
 */
final readonly class IncidentPulse implements PulseProviderInterface
{
    public function __construct(
        private IncidentEventRepository $events,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function moduleSlug(): string
    {
        return IncidentOverviewWidgets::GROUP;
    }

    public function pulseFor(AreaOfInterest $area, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $moves = [];
        foreach ($this->events->movesIn($area, $since, $now) as $event) {
            $incident = $event->getIncident();
            $landed = $event->getToStatus();

            $moves[] = new PulseEvent(
                at: $event->getOccurredAt(),
                moduleSlug: $this->moduleSlug(),
                moduleLabel: 'incidents',
                recordRef: $incident->getReference(),
                move: $event->getKind()->moveLabel(),
                // The event's own body, which is the sentence somebody wrote or
                // the one the transition wrote for them. Never re-composed here:
                // the timeline is the record and this is a view of it.
                summary: $event->getBody(),
                url: $this->router->generate('incident_show', [
                    'uuid' => $area->getUuidString(),
                    'reference' => $incident->getReference(),
                ]),
                // The kind of incident, as a colour — the same hue its pin wears
                // on the plate above and its chip wears on the card beside it.
                swatch: IncidentHues::of($incident->getCategory()->getColourKey()),
                state: $landed?->label(),
                stateClass: $landed?->cssClass(),
                meta: array_values(array_filter([
                    $incident->zoneLabel(),
                    // The clock's own move has no author, and stamping one on
                    // would put a person's name on something no person did.
                    $event->getActorName(),
                ])),
            );
        }

        return $moves;
    }
}
