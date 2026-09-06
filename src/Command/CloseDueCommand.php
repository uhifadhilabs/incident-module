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

namespace Uhifadhi\Incident\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\Incident\Workflow\IncidentWorkflow;

/**
 * THE CLOCK'S HAND — the one process that actually closes resolved incidents.
 *
 * `closed` is reached BY TIME, never by a person: an incident closes itself
 * {@see IncidentWorkflow::CLOSE_AFTER_DAYS} days after it was resolved. That rule
 * has always lived in {@see IncidentTransitionService::closeIfDue()}, but nothing
 * in production ever TURNED the hand — so this command is the hand, and it is
 * registered in every environment (not dev tooling), because a workflow whose last
 * step never happens is a workflow that lies about being finished.
 *
 * NOT DEV TOOLING, and NOT A DAEMON. It sweeps once and exits, which is exactly
 * what a scheduler wants: run it on a daily cron —
 *
 *     0 2 * * *  bin/console incidents:close-due
 *
 * — or, on a host that installs symfony/scheduler later, attach it as a recurring
 * task. This deployment's host ships no scheduler (only messenger), so the cron IS
 * the mechanism; nothing here assumes one.
 *
 * IDEMPOTENT AND SAFE TO RUN REPEATEDLY. It asks the repository only for the rows
 * that are already due and then asks the SERVICE, incident by incident, to make
 * the move — the same guard a person's refused Close hits — so a second run in the
 * same minute closes nothing a first run did not, and an incident that is not yet
 * due is simply left resolved.
 */
#[AsCommand(
    name: 'incidents:close-due',
    description: 'Close every incident that has been resolved for 30 days — the clock\'s own move, run on a daily cron (idempotent).',
)]
final class CloseDueCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidents,
        private readonly IncidentTransitionService $transitions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Incidents — the clock\'s own move');

        $now = new \DateTimeImmutable();
        $due = $this->incidents->dueForClosure($now);

        $closed = 0;
        foreach ($due as $incident) {
            // The SERVICE decides, not this loop: closeIfDue() re-checks the term
            // and answers null for anything not actually due, so the query
            // narrowing the set can never make the command close something early.
            if (null !== $this->transitions->closeIfDue($incident, $now)) {
                ++$closed;
            }
        }

        if ($closed > 0) {
            $this->entityManager->flush();
        }

        $io->definitionList(
            ['resolved and due' => (string) \count($due)],
            ['closed by the clock' => (string) $closed],
            ['term' => \sprintf('%d days after resolution', IncidentWorkflow::CLOSE_AFTER_DAYS)],
        );

        $io->success(0 === $closed
            ? 'Nothing was due. Every resolved incident is still within its term.'
            : \sprintf('%d incident%s closed, %d days after resolution.', $closed, 1 === $closed ? '' : 's', IncidentWorkflow::CLOSE_AFTER_DAYS));

        return Command::SUCCESS;
    }
}
