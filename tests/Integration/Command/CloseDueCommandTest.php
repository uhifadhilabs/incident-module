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

namespace Uhifadhi\Incident\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Incident\Workflow\IncidentWorkflow;

/**
 * THE CLOCK'S HAND, RUN — proof that `incidents:close-due` actually advances the
 * one move no person may make.
 *
 * The rule has always lived in {@see IncidentTransitionService::closeIfDue()};
 * until this command nothing in production ever called it, so a resolved incident
 * stayed resolved forever. These tests prove the sweep closes exactly the due ones
 * and moves nothing else — across areas, and idempotently.
 */
final class CloseDueCommandTest extends IntegrationTestCase
{
    private function sweep(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel, 'IntegrationTestCase boots the kernel in setUp().');

        $tester = new CommandTester(new Application($kernel)->find('incidents:close-due'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /** A resolved incident, resolved $daysAgo days before now, in the given area. */
    private function resolvedDaysAgo(AreaOfInterest $area, int $daysAgo): Incident
    {
        $incident = $this->anIncident($area, 'natural-mortality', 'Wildebeest carcass, no injury pattern');

        /** @var IncidentTransitionService $transitions */
        $transitions = $this->service('incident.transitions');
        $at = new \DateTimeImmutable(\sprintf('-%d days', $daysAgo));
        foreach ([IncidentTransitionEnum::Verify, IncidentTransitionEnum::Respond, IncidentTransitionEnum::Resolve] as $step) {
            $transitions->apply($incident, $step, $at = $at->modify('+1 minute'));
        }
        $this->em->flush();

        return $incident;
    }

    public function testItClosesOnlyTheIncidentsThatAreActuallyDue(): void
    {
        $this->installTaxonomy();
        $area = $this->anArea();

        $due = $this->resolvedDaysAgo($area, IncidentWorkflow::CLOSE_AFTER_DAYS + 1);
        $notYet = $this->resolvedDaysAgo($area, IncidentWorkflow::CLOSE_AFTER_DAYS - 5);

        $tester = $this->sweep();
        $this->em->clear();

        $repository = $this->em->getRepository(Incident::class);
        self::assertSame(
            IncidentStatusEnum::Closed,
            $repository->findOneBy(['reference' => $due->getReference()])?->getStatus(),
            'An incident resolved longer than the term ago must be closed by the clock.',
        );
        self::assertSame(
            IncidentStatusEnum::Resolved,
            $repository->findOneBy(['reference' => $notYet->getReference()])?->getStatus(),
            'An incident still within its term must be left resolved.',
        );
        self::assertStringContainsString('closed by the clock', $tester->getDisplay());
    }

    /** It sweeps every area — the clock keeps nobody's hours, and a boundary is not a thing time respects. */
    public function testItClosesDueIncidentsAcrossEveryArea(): void
    {
        $this->installTaxonomy();
        $here = $this->resolvedDaysAgo($this->anArea('Here'), IncidentWorkflow::CLOSE_AFTER_DAYS + 2);
        $there = $this->resolvedDaysAgo($this->anArea('There'), IncidentWorkflow::CLOSE_AFTER_DAYS + 2);

        $this->sweep();
        $this->em->clear();

        $repository = $this->em->getRepository(Incident::class);
        self::assertSame(IncidentStatusEnum::Closed, $repository->findOneBy(['reference' => $here->getReference()])?->getStatus());
        self::assertSame(IncidentStatusEnum::Closed, $repository->findOneBy(['reference' => $there->getReference()])?->getStatus());
    }

    /** Idempotent: a second run closes nothing and does not fail. */
    public function testASecondRunClosesNothingMore(): void
    {
        $this->installTaxonomy();
        $area = $this->anArea();
        $this->resolvedDaysAgo($area, IncidentWorkflow::CLOSE_AFTER_DAYS + 1);

        $this->sweep();
        $this->em->clear();

        $second = $this->sweep();
        self::assertStringContainsString('resolved and due', $second->getDisplay());
        self::assertStringContainsString('Nothing was due', $second->getDisplay());
    }

    /** With nothing resolved at all it succeeds and says so, rather than doing anything. */
    public function testWithNothingResolvedItSaysSo(): void
    {
        $this->installTaxonomy();
        $area = $this->anArea();
        $this->anIncident($area); // filed, never moved

        $tester = $this->sweep();

        self::assertStringContainsString('Nothing was due', $tester->getDisplay());
        self::assertSame(
            IncidentStatusEnum::Reported,
            $this->em->getRepository(Incident::class)->findOneBy([])?->getStatus(),
        );
    }
}
