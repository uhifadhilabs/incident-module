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

    /** An incident walked only as far as `verified`. */
    private function verified(AreaOfInterest $area, string $subcategory): Incident
    {
        $incident = $this->anIncident($area, $subcategory);

        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');
        $transitions->apply($incident, IncidentTransitionEnum::Verify, new \DateTimeImmutable());
        $this->em->flush();

        return $incident;
    }

    public function testNoRowExistsUntilAnAmountIsRecorded(): void
    {
        $incident = $this->inProgress($this->anAreaWithKinds());

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
        $incident = $this->inProgress($this->anAreaWithKinds());

        $this->expectException(IncidentMoneyException::class);
        try {
            $this->money()->record($incident, null, null, null, null, new \DateTimeImmutable());
        } finally {
            self::assertNull($incident->getMoney(), 'An empty save must not open an unresolvable empty row.');
        }
    }

    public function testAmountsAdvanceAcrossSavesAndReachSettled(): void
    {
        $incident = $this->inProgress($this->anAreaWithKinds());
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
        $incident = $this->inProgress($this->anAreaWithKinds());

        $money = $this->money()->waive($incident, 'Household withdrew the claim in writing.', new \DateTimeImmutable());

        self::assertTrue($money->isWaived());
        self::assertTrue($money->isSettled());
        self::assertSame('Household withdrew the claim in writing.', $money->getWaivedReason());
    }

    public function testAWaiverWithNoReasonIsRefused(): void
    {
        $incident = $this->inProgress($this->anAreaWithKinds());

        $this->expectException(IncidentMoneyException::class);
        $this->money()->waive($incident, '   ', new \DateTimeImmutable());
    }

    public function testEveryWriteLeavesATimelineEvent(): void
    {
        $incident = $this->inProgress($this->anAreaWithKinds());
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
        $incident = $this->inProgress($this->anAreaWithKinds(), 'natural-mortality');

        $this->expectException(IncidentMoneyException::class);
        $this->money()->record($incident, 1_000_000, null, null, null, new \DateTimeImmutable());
    }

    /**
     * A CLAIM IS RECORDED FROM `verified`. A household asks for compensation as
     * soon as the authority agrees the thing happened; making them wait until
     * somebody is assigned would be the product refusing to write down a claim it
     * has already been given.
     */
    public function testACompensationClaimIsRecordedFromVerified(): void
    {
        $incident = $this->verified($this->anAreaWithKinds(), 'livestock-depredation');

        $money = $this->money()->record($incident, 900_000, null, null, null, new \DateTimeImmutable());

        self::assertSame(900_000, $money->getClaimed());
        self::assertSame(900_000, $money->outstanding());
    }

    /**
     * A FINE IS NOT. Assessing a penalty is enforcement, which is the work that
     * starts at `in progress` — an authority that fined somebody before it had
     * opened the case would be fining them on the strength of a report.
     */
    public function testAFineIsRefusedUntilResponseHasStarted(): void
    {
        $incident = $this->verified($this->anAreaWithKinds(), 'illegal-grazing');

        $this->expectException(IncidentMoneyException::class);
        $this->expectExceptionMessage('A fine is assessed once response has started — this incident has not reached in progress yet.');
        $this->money()->record($incident, null, 750_000, null, null, new \DateTimeImmutable());
    }

    /** …and it is recorded the moment response does start. */
    public function testAFineIsRecordedFromInProgress(): void
    {
        $incident = $this->inProgress($this->anAreaWithKinds(), 'illegal-grazing');

        $money = $this->money()->record($incident, null, 750_000, 750_000, 750_000, new \DateTimeImmutable());

        self::assertSame(750_000, $money->getAssessed());
        self::assertSame(0, $money->outstanding());
    }

    /** Neither direction is recorded on a bare report, and each says so its own way. */
    public function testNeitherDirectionIsRecordedOnABareReport(): void
    {
        $incident = $this->anIncident($this->anAreaWithKinds()); // livestock-depredation, still `reported`

        $this->expectException(IncidentMoneyException::class);
        $this->expectExceptionMessage('A compensation claim is recorded once an incident is verified — this incident has not reached verified yet.');
        $this->money()->record($incident, 1_000_000, null, null, null, new \DateTimeImmutable());
    }
}
