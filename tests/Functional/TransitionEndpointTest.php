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

namespace UhifadhiLabs\Incident\Tests\Functional;

use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Entity\IncidentEvent;
use UhifadhiLabs\Incident\Entity\IncidentMoney;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Enum\IncidentTransitionEnum;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;
use UhifadhiLabs\Incident\Service\IncidentTransitionService;

/**
 * MOVING AN INCIDENT ON, over HTTP — the endpoint the case file's buttons and the
 * status board's drag-and-drop both post to, because a transition IS the whole
 * instruction and there is only one way to make it.
 *
 * The workflow decides, never the endpoint: an illegal move and a guard's refusal
 * come back as 422 with the GUARD'S OWN SENTENCE, which is the same text the
 * toolbar prints beside the moves that are allowed.
 */
final class TransitionEndpointTest extends FunctionalTestCase
{
    private function transitions(): IncidentTransitionService
    {
        /** @var IncidentTransitionService $transitions */
        $transitions = static::getContainer()->get('test_public.incident.transitions');

        return $transitions;
    }

    private function url(string $areaUuid, Incident $incident, IncidentTransitionEnum $transition): string
    {
        return \sprintf(
            '/areas/%s/modules/incidents/%s/transition/%s',
            $areaUuid,
            $incident->getReference(),
            $transition->value,
        );
    }

    public function testVerifyingMovesTheIncidentAndLeavesATrace(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $manager = $this->aManager();
        $this->client->loginUser($manager);

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Verify), [
            '_token' => $this->csrfFor($area),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $moved = $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()]);
        self::assertNotNull($moved);
        self::assertSame(IncidentStatusEnum::Verified, $moved->getStatus());
        self::assertNotNull($moved->getVerifiedAt());
        // The timeline is the record: the filing, then the transition.
        $events = array_values($moved->getEvents()->toArray());
        self::assertCount(2, $events);
        $transition = $events[1] ?? null;
        if (!$transition instanceof IncidentEvent) {
            self::fail('The transition left nothing on the timeline; a status column that changes by itself proves nothing.');
        }
        self::assertSame('S. Laizer', $transition->getActorName());
    }

    /**
     * NOTHING SKIPS VERIFICATION. Not through the UI, not by posting the URL
     * directly — the endpoint has no opinion, and the workflow refuses.
     */
    public function testAMoveThatWouldSkipVerificationIsRefused(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Resolve), [
            '_token' => $this->csrfFor($area),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('only reachable from in progress', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame(
            IncidentStatusEnum::Reported,
            $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()])?->getStatus(),
        );
    }

    /**
     * THE MONEY GUARD, over HTTP: an incident whose claim is outstanding is not
     * resolved, it is UNPAID — and the refusal says which, in the words the
     * toolbar prints.
     */
    public function testResolvingIsRefusedWhileTheClaimIsOutstanding(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $manager = $this->aManager();
        $this->client->loginUser($manager);

        $at = new \DateTimeImmutable();
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, $at);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Respond, $at->modify('+1 hour'));
        // Somebody opened a claim on it — which is when the money record appears.
        new IncidentMoney($incident, MoneyDirectionEnum::Compensation)
            ->setClaimed(1_600_000)->setAssessed(1_200_000)->setApproved(1_200_000)->setSettled(0);
        $this->em->flush();

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Resolve), [
            '_token' => $this->csrfFor($area),
        ]);

        self::assertResponseStatusCodeSame(422);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1,200,000', $body);
        self::assertStringContainsString('outstanding', $body);
    }

    /** …and settling it lets the same move through, unchanged. */
    public function testPayingTheClaimUnblocksTheSameMove(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $at = new \DateTimeImmutable();
        $this->transitions()->apply($incident, IncidentTransitionEnum::Verify, $at);
        $this->transitions()->apply($incident, IncidentTransitionEnum::Respond, $at->modify('+1 hour'));
        new IncidentMoney($incident, MoneyDirectionEnum::Compensation)
            ->setApproved(1_200_000)->setSettled(1_200_000);
        $this->em->flush();

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Resolve), [
            '_token' => $this->csrfFor($area),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(
            IncidentStatusEnum::Resolved,
            $this->em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()])?->getStatus(),
        );
    }

    /**
     * NOBODY CLOSES AN INCIDENT BY HAND — not even a supervisor, not even by
     * posting the URL. The clock reaches `closed`, and no person does.
     */
    public function testNobodyCanCloseAnIncidentByPostingTheUrl(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');
        $this->client->loginUser($this->aManager());

        $at = new \DateTimeImmutable();
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $this->transitions()->apply($incident, $step, $at = $at->modify('+1 hour'));
        }
        $this->em->flush();

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Close), [
            '_token' => $this->csrfFor($area),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'reached by time, not by a person',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** Moving an incident needs "incidents.manage". Filing one is not enough. */
    public function testAReporterMayFileAndMayNotMove(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $manager = $this->aManager();
        $this->client->loginUser($manager);
        $token = $this->csrfFor($area);

        $this->client->loginUser($this->aReporter());
        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Verify), [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** A state-changing write with no token is refused, whoever is signed in. */
    public function testAMoveWithoutACsrfTokenIsRefused(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', $this->url($this->uuidOf($area), $incident, IncidentTransitionEnum::Verify));

        self::assertResponseStatusCodeSame(403);
    }

    /** A transition the workflow has never heard of is a 404, not a 500. */
    public function testAnInventedTransitionIsNotFound(): void
    {
        $area = $this->anArea();
        $incident = $this->anIncident($area);
        $this->client->loginUser($this->aManager());

        $this->client->request('POST', \sprintf(
            '/areas/%s/modules/incidents/%s/transition/teleport',
            $this->uuidOf($area),
            $incident->getReference(),
        ), ['_token' => $this->csrfFor($area)]);

        self::assertResponseStatusCodeSame(404);
    }
}
