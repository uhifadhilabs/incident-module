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

namespace Uhifadhi\Incident\Tests\Integration\Service;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE CASE FILE'S WRITE SURFACE, asserted against the database rather than
 * against the object graph.
 *
 * The distinction is the whole point of this file. {@see IncidentTransitionService}
 * decides the move and leaves the change in memory — that is what keeps the state
 * machine unit-testable with no database at all — and something has to make the
 * decision durable. Until now that something was the controller, holding an entity
 * manager for one line. It is this service, and these tests never flush: if a write
 * here is not durable on its own, the re-read finds the old value.
 */
final class IncidentCaseServiceTest extends IntegrationTestCase
{
    public function testMovingAnIncidentOnIsDurableWithoutTheCallerFlushing(): void
    {
        $incident = $this->filedIncident();

        $this->cases()->move($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable('2026-08-20 11:20:00'));

        self::assertSame(IncidentStatusEnum::Verified, $this->reread($incident)->getStatus());
    }

    /** The move leaves an event, and the event is stored with it rather than after it. */
    public function testTheTimelineEventIsStoredWithTheMove(): void
    {
        $incident = $this->filedIncident();
        $before = $incident->getEvents()->count();

        $this->cases()->move($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable('2026-08-20 11:20:00'));

        self::assertSame($before + 1, $this->reread($incident)->getEvents()->count());
    }

    /**
     * A REFUSAL WRITES NOTHING. The workflow's exception comes through untouched —
     * the endpoint answers 422 with the guard's own sentence — and the row is
     * exactly as it was.
     */
    public function testARefusedMoveLeavesTheRecordAlone(): void
    {
        $incident = $this->filedIncident();

        $this->expectException(IncidentTransitionException::class);

        try {
            $this->cases()->move($incident, IncidentTransitionEnum::Resolve, new \DateTimeImmutable('2026-08-20 11:20:00'));
        } finally {
            self::assertSame(IncidentStatusEnum::Reported, $this->reread($incident)->getStatus());
        }
    }

    /** The clock's own move is durable on the same terms. */
    public function testTheClockCloseIsDurableToo(): void
    {
        $incident = $this->filedIncident();
        $cases = $this->cases();
        $at = new \DateTimeImmutable('2026-08-20 11:20:00');
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $cases->move($incident, $step, $at = $at->modify('+1 hour'));
        }

        $cases->closeIfDue($incident, $at->modify('+31 days'));

        self::assertSame(IncidentStatusEnum::Closed, $this->reread($incident)->getStatus());
    }

    /** An incident that is not yet due stays where it is, and says so with a null. */
    public function testAnIncidentThatIsNotDueIsNotClosed(): void
    {
        $incident = $this->filedIncident();

        self::assertNull($this->cases()->closeIfDue($incident, new \DateTimeImmutable('2026-08-21 09:00:00')));
        self::assertSame(IncidentStatusEnum::Reported, $this->reread($incident)->getStatus());
    }

    private function cases(): IncidentCaseService
    {
        /** @var IncidentCaseService $cases */
        $cases = $this->service('incident.case');

        return $cases;
    }

    private function filedIncident(): Incident
    {
        $this->installTaxonomy();

        return $this->anIncident($this->anArea('Kifaru Sector'));
    }

    /** The row as the database holds it, with nothing of this request's in the way. */
    private function reread(Incident $incident): Incident
    {
        $reference = $incident->getReference();
        $this->em->clear();

        $found = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($found);

        return $found;
    }
}
