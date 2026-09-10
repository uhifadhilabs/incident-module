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

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Exception\IncidentMoneyException;
use Uhifadhi\Incident\Service\IncidentMoneyService;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;

/**
 * THE MONEY WRITE SURFACE — the row born on the first amount, and never before.
 *
 * These are the entity/service rules the design states in prose, each one an
 * assertion against a real database: the lazy row, the four amounts, the waiver,
 * and the two things the surface refuses.
 */
final class IncidentMoneyServiceTest extends IntegrationTestCase
{
    private function money(): IncidentMoneyService
    {
        /** @var IncidentMoneyService $money */
        $money = $this->service('incident.money');

        return $money;
    }

    /** An incident walked to `in progress`, where money can be recorded. */
    private function inProgress(AreaOfInterest $area, string $subcategory = 'livestock-depredation'): Incident
    {
        $incident = $this->anIncident($area, $subcategory);

        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');
        $at = new \DateTimeImmutable();
        $transitions->apply($incident, IncidentTransitionEnum::Verify, $at);
        $transitions->apply($incident, IncidentTransitionEnum::Respond, $at->modify('+1 hour'));
        $this->em->flush();

        return $incident;
    }

    public function testNoRowExistsUntilAnAmountIsRecorded(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());

        self::assertNull($incident->getMoney(), 'Reaching in progress opens no money row on its own.');

        $money = $this->money()->record($incident, 1_600_000, 1_200_000, 1_200_000, 0, new \DateTimeImmutable());

        self::assertNotNull($incident->getMoney(), 'The first amount creates the row.');
        self::assertSame(1_200_000, $money->payable());
        self::assertSame(1_200_000, $money->outstanding());
        // The direction is the sub-category's, inherited — never chosen here.
        self::assertSame('compensation', $money->getDirection()->value);
    }

    public function testRecordingWithNothingOnItCreatesNoRow(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());

        $this->expectException(IncidentMoneyException::class);
        try {
            $this->money()->record($incident, null, null, null, null, new \DateTimeImmutable());
        } finally {
            self::assertNull($incident->getMoney(), 'An empty save must not open an unresolvable empty row.');
        }
    }

    public function testAmountsAdvanceAcrossSavesAndReachSettled(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());
        $service = $this->money();

        $service->record($incident, 1_600_000, 1_200_000, 1_200_000, 0, new \DateTimeImmutable());
        self::assertFalse($incident->getMoney()?->isSettled());

        // A later save pays it — the panel prefills and posts the whole truth.
        $money = $service->record($incident, 1_600_000, 1_200_000, 1_200_000, 1_200_000, new \DateTimeImmutable());
        self::assertSame(0, $money->outstanding());
        self::assertTrue($money->isSettled());
    }

    public function testWaivingSettlesTheMoneyWithAReason(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());

        $money = $this->money()->waive($incident, 'Household withdrew the claim in writing.', new \DateTimeImmutable());

        self::assertTrue($money->isWaived());
        self::assertTrue($money->isSettled());
        self::assertSame('Household withdrew the claim in writing.', $money->getWaivedReason());
    }

    public function testAWaiverWithNoReasonIsRefused(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());

        $this->expectException(IncidentMoneyException::class);
        $this->money()->waive($incident, '   ', new \DateTimeImmutable());
    }

    public function testEveryWriteLeavesATimelineEvent(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea());
        $before = $incident->getEvents()->count();

        $this->money()->record($incident, null, 1_200_000, null, null, new \DateTimeImmutable());

        $events = array_values($incident->getEvents()->toArray());
        self::assertCount($before + 1, $events);
        $moneyEvents = array_filter($events, static fn ($event) => IncidentEventKindEnum::Money === $event->getKind());
        self::assertCount(1, $moneyEvents, 'The write left exactly one money event on the timeline.');
    }

    /** A sub-category that carries no money grows none — the direction is absent, so the surface refuses. */
    public function testAnIncidentThatCarriesNoMoneyIsRefused(): void
    {
        $this->installTaxonomy();
        $incident = $this->inProgress($this->anArea(), 'natural-mortality');

        $this->expectException(IncidentMoneyException::class);
        $this->money()->record($incident, 1_000_000, null, null, null, new \DateTimeImmutable());
    }

    /** Not before response has started: the gate is a rule, not only a rendering. */
    public function testMoneyCannotBeRecordedBeforeResponse(): void
    {
        $this->installTaxonomy();
        $incident = $this->anIncident($this->anArea()); // still `reported`

        $this->expectException(IncidentMoneyException::class);
        $this->money()->record($incident, 1_000_000, null, null, null, new \DateTimeImmutable());
    }
}
