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

namespace Uhifadhi\Incident\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentCategory;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Exception\IncidentTransitionException;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Workflow\IncidentWorkflow;

/**
 * THE STATE MACHINE'S OWN TESTS. Every rule the design states in prose is one
 * assertion here, because these are the rules a screen is not allowed to have an
 * opinion about.
 */
final class IncidentTransitionServiceTest extends TestCase
{
    private const string NOW = '2026-08-22 09:00:00';

    private function service(): IncidentTransitionService
    {
        return new IncidentTransitionService();
    }

    private function now(string $at = self::NOW): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at);
    }

    /** A conflict incident: its sub-category carries compensation. */
    private function incident(bool $withMoney = false): Incident
    {
        $category = new IncidentCategory('conflict', 'Human–wildlife conflict', 'hwc');
        $subcategory = new IncidentSubcategory($category, 'livestock-depredation', 'livestock depredation');
        if ($withMoney) {
            $subcategory->setMoneyDirection(MoneyDirectionEnum::Compensation);
        }

        return new Incident(
            new AreaOfInterest()->setSource('test fixture'),
            $subcategory,
            'INC-0313',
            'Lion killed four goats at Riverside',
            '{"type":"Point","coordinates":[35.45,-3.21]}',
            $this->now('2026-08-20 05:41:00'),
        );
    }

    public function testAFreshIncidentStartsAtReportedAndCanOnlyBeVerified(): void
    {
        $incident = $this->incident();

        self::assertSame(IncidentWorkflow::initialPlace(), $incident->getStatus());
        self::assertSame(
            [IncidentTransitionEnum::Verify],
            $this->service()->available($incident, $this->now()),
        );
    }

    /**
     * THE ONE RULE NOTHING MAY BREAK: an incident can never skip verification.
     * It is not a check — there is simply no move from `reported` to anywhere
     * else — but the refusal has to be a refusal, not a silent no-op.
     */
    public function testAnIncidentCanNeverSkipVerification(): void
    {
        $incident = $this->incident();

        self::assertNotNull($this->service()->refusal($incident, IncidentTransitionEnum::Resolve, $this->now()));

        $this->expectException(IncidentTransitionException::class);
        $this->service()->apply($incident, IncidentTransitionEnum::Resolve, $this->now());
    }

    public function testVerifyingMovesTheIncidentOnAndStampsTheMoment(): void
    {
        $incident = $this->incident();
        $at = $this->now('2026-08-20 11:20:00');

        $event = $this->service()->apply($incident, IncidentTransitionEnum::Verify, $at, null, 'S. Laizer');

        self::assertSame(IncidentStatusEnum::Verified, $incident->getStatus());
        self::assertEquals($at, $incident->getVerifiedAt());
        self::assertSame(IncidentEventKindEnum::Transition, $event->getKind());
        self::assertSame(IncidentStatusEnum::Verified, $event->getToStatus());
        self::assertSame('S. Laizer', $event->getActorName());
        // The timeline is the record: a transition that left no trace would be a
        // status column changing by itself.
        self::assertCount(1, $incident->getEvents());
    }

    /** The design's "verified in 5 h 39 · term 72 h" is arithmetic on two stamps. */
    public function testTheTimeToVerifyIsReadableOffTheRecord(): void
    {
        $incident = $this->incident();
        $this->service()->apply($incident, IncidentTransitionEnum::Verify, $this->now('2026-08-20 11:20:00'));

        self::assertSame(5, $incident->hoursToVerify());
    }

    /**
     * An incident with no money on it resolves the moment the work is done —
     * whether its category carries money or not. A roadkill with no fine owes
     * nothing.
     */
    public function testAnIncidentWithoutMoneyResolvesFreely(): void
    {
        $incident = $this->incident(withMoney: true);
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now());

        self::assertNull($service->refusal($incident, IncidentTransitionEnum::Resolve, $this->now()));
    }

    /**
     * THE MONEY GUARD. A claim that has not been paid is not a resolved incident,
     * it is an unpaid one — and the refusal has to say which.
     */
    public function testAnIncidentWithMoneyOutstandingRefusesToResolve(): void
    {
        $incident = $this->incident(withMoney: true);
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now());

        $money = new IncidentMoney($incident, MoneyDirectionEnum::Compensation);
        $money->setClaimed(1_600_000)->setAssessed(1_200_000)->setApproved(1_200_000)->setSettled(0);

        $refusal = $service->refusal($incident, IncidentTransitionEnum::Resolve, $this->now());
        self::assertNotNull($refusal);
        self::assertStringContainsString('1,200,000', $refusal);

        self::assertNotContains(IncidentTransitionEnum::Resolve, $service->available($incident, $this->now()));
    }

    public function testPayingTheClaimUnblocksResolution(): void
    {
        $incident = $this->incident(withMoney: true);
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now());

        $money = new IncidentMoney($incident, MoneyDirectionEnum::Compensation);
        $money->setApproved(1_200_000)->setSettled(1_200_000);

        self::assertNull($service->refusal($incident, IncidentTransitionEnum::Resolve, $this->now()));
    }

    /** A waiver passes the guard — because somebody wrote down why. */
    public function testAWaivedClaimUnblocksResolution(): void
    {
        $incident = $this->incident(withMoney: true);
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now());

        $money = new IncidentMoney($incident, MoneyDirectionEnum::Compensation);
        $money->setApproved(1_200_000)->waive($this->now(), 'Household withdrew the claim in writing.');

        self::assertNull($service->refusal($incident, IncidentTransitionEnum::Resolve, $this->now()));
    }

    /**
     * A money record that EXISTS with no figures on it is an assessment somebody
     * started and has not finished — not "nothing is owed".
     *
     * (An incident with NO money record at all is a different fact and resolves
     * freely: see {@see testAnIncidentWithoutMoneyResolvesFreely()}. A roadkill
     * where no driver was identified owes nothing, and holding it open for a
     * payment nobody is making would be the workflow inventing a debt.)
     */
    public function testAnUnassessedClaimIsNotTreatedAsNothingToPay(): void
    {
        $incident = $this->incident(withMoney: true);
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now());
        new IncidentMoney($incident, MoneyDirectionEnum::Compensation);

        self::assertNotNull($service->refusal($incident, IncidentTransitionEnum::Resolve, $this->now()));
    }

    /**
     * CLOSED IS REACHED BY TIME, NEVER BY A PERSON. The design says it on the
     * rail, and the toolbar shows it as a blocked chip rather than a button.
     */
    public function testNobodyCanCloseAnIncidentByHand(): void
    {
        $incident = $this->resolvedIncident();

        self::assertNotContains(IncidentTransitionEnum::Close, $this->service()->available($incident, $this->now()));

        $this->expectException(IncidentTransitionException::class);
        $this->service()->apply($incident, IncidentTransitionEnum::Close, $this->now());
    }

    public function testTheClockClosesAnIncidentThirtyDaysAfterResolution(): void
    {
        $incident = $this->resolvedIncident();
        $service = $this->service();

        // Day 29: still resolved, and the clock says nothing.
        self::assertNull($service->closeIfDue($incident, $this->now('2026-09-18 09:00:00')));
        self::assertSame(IncidentStatusEnum::Resolved, $incident->getStatus());

        // Day 30, to the hour: resolved 21 aug 10:00, so it closes 20 sep 10:00.
        $event = $service->closeIfDue($incident, $this->now('2026-09-20 10:00:00'));

        self::assertNotNull($event);
        self::assertSame(IncidentStatusEnum::Closed, $incident->getStatus());
        // A machine's action has no author; inventing one would put a person's
        // name on something no person did.
        self::assertNull($event->getActor());
        self::assertNull($event->getActorName());
    }

    /**
     * The toolbar's other half: what is NOT available, and why. The design prints
     * the sentence beside the buttons, so a refusal that could only say "no" would
     * leave a person guessing.
     */
    public function testTheToolbarCanSayWhyEachRemainingMoveIsBlocked(): void
    {
        $incident = $this->incident();
        $blocked = $this->service()->blocked($incident, $this->now());

        self::assertArrayHasKey(IncidentTransitionEnum::Close->value, $blocked);
        self::assertStringContainsString('resolved', $blocked[IncidentTransitionEnum::Close->value]);
        // Verify is available, so it is not in the blocked list.
        self::assertArrayNotHasKey(IncidentTransitionEnum::Verify->value, $blocked);
    }

    /** Applying the same move twice is a refusal, not a second event. */
    public function testAMoveAlreadyMadeIsRefused(): void
    {
        $incident = $this->incident();
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());

        $this->expectException(IncidentTransitionException::class);
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now());
    }

    private function resolvedIncident(): Incident
    {
        $incident = $this->incident();
        $service = $this->service();
        $service->apply($incident, IncidentTransitionEnum::Verify, $this->now('2026-08-20 11:20:00'));
        $service->apply($incident, IncidentTransitionEnum::Respond, $this->now('2026-08-21 09:40:00'));
        $service->apply($incident, IncidentTransitionEnum::Resolve, $this->now('2026-08-21 10:00:00'));

        return $incident;
    }
}
