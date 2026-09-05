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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Area\Entity\AreaOfInterest;
use Uhifadhi\Area\Entity\Zone;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Entity\IncidentEvent;
use Uhifadhi\Incident\Entity\IncidentEvidence;
use Uhifadhi\Incident\Entity\IncidentMoney;
use Uhifadhi\Incident\Entity\IncidentParty;
use Uhifadhi\Incident\Entity\IncidentSubcategory;
use Uhifadhi\Incident\Enum\EvidenceKindEnum;
use Uhifadhi\Incident\Enum\IncidentEventKindEnum;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Repository\IncidentZoneLocator;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Service\IncidentTransitionService;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * SEEDS THE DESIGN'S SAMPLE MONTH — the forty-seven incidents the gallery talks
 * about, so a fresh host shows the module working and matches the spec.
 *
 * THE NUMBERS ARE THE DESIGN'S, TO THE SHILLING. The preset gallery states them
 * once and every widget repeats them: 47 filed, 31 still open, TZS 8.45M assessed
 * in fines and 9.2M approved in compensation, across four categories and seven
 * zones. {@see DemoMonth} is that month as data; this command writes it.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. Every incident is keyed by its reference, and
 * one that already exists is left exactly as it is — nothing is purged, renamed
 * or re-numbered, so running it twice is a no-op and running it after somebody
 * has worked the demo data does not undo their work.
 *
 * IT TAKES WHAT THE HOST ALREADY HAS. Zones are attached by NAME when the area
 * has one of that name and left null when it does not, because unzoned is a
 * first-class answer everywhere in this module. Recorders are drawn from the
 * accounts that exist; with none, the incidents are recorded by nobody and the
 * department KPI plates honestly report nothing.
 *
 * Dev-only: registered only where `incident.dev_tools` is on, so production never
 * gets a command that writes invented incidents.
 */
#[AsCommand(
    name: 'incidents:seed:demo',
    description: 'Seed the design’s sample month of incidents into one area (idempotent).',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IncidentRepository $incidents,
        private readonly IncidentSubcategoryRepository $subcategories,
        private readonly IncidentTaxonomyInstaller $taxonomy,
        private readonly IncidentTransitionService $transitions,
        private readonly IncidentZoneLocator $zones,
        private readonly string $currency,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('area', InputArgument::OPTIONAL, 'The area’s name or UUID. Defaults to the only area, where there is only one.')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'The month to file them in, as YYYY-MM.', DemoMonth::MONTH)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Incidents — the design’s sample month');

        // The taxonomy first: there is nothing to file an incident against
        // without it, and a seeder that failed for that reason would send
        // somebody hunting for a bug that is really a missing install step.
        $this->taxonomy->install();

        $area = $this->area($input->getArgument('area'));
        if (null === $area) {
            $io->error('No area to file incidents in. Seed an area first, or name one.');

            return Command::FAILURE;
        }

        $month = $this->month($io, $input->getOption('month'));
        if (null === $month) {
            return Command::FAILURE;
        }

        $zones = $this->zonesOf($area);
        $recorders = $this->recorders();
        $filed = 0;
        $skipped = 0;

        foreach (DemoMonth::incidents() as $index => $row) {
            if (null !== $this->incidents->findOneByReference($row['reference'])) {
                ++$skipped;
                continue;
            }

            $subcategory = $this->subcategories->findOneBySlug($row['subcategory']);
            if (null === $subcategory) {
                $io->warning(\sprintf('No sub-category "%s" in this deployment — %s skipped.', $row['subcategory'], $row['reference']));
                continue;
            }

            $this->file($area, $subcategory, $row, $month, $zones, $recorders, $index);
            ++$filed;
        }

        $this->entityManager->flush();

        $io->definitionList(
            ['area' => (string) $area->getName()],
            ['month' => $month->format('F Y')],
            ['filed' => (string) $filed],
            ['already there' => (string) $skipped],
            ['zones matched' => \sprintf('%d of %d', \count($zones), \count(DemoMonth::ZONES))],
            ['recorders' => 0 === \count($recorders) ? 'none — the incidents are recorded by nobody' : (string) \count($recorders)],
        );

        $io->success('The sample month is in. Nothing existing was touched.');

        return Command::SUCCESS;
    }

    /**
     * @param array{reference: string, day: int, hour: int, minute: int, subcategory: string, status: string, severity: string, zone: string, title: string, narrative: string|null, source: string, money: array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null, evidence: int, parties: list<array{role: string, name: string, described: string|null}>} $row
     * @param array<string, Zone>                                                                                                                                                                                                                                                                                                                                                              $zones
     * @param list<UserInterface>                                                                                                                                                                                                                                                                                                                                                              $recorders
     */
    private function file(
        AreaOfInterest $area,
        IncidentSubcategory $subcategory,
        array $row,
        \DateTimeImmutable $month,
        array $zones,
        array $recorders,
        int $index,
    ): void {
        $reportedAt = $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $row['day'])->setTime($row['hour'], $row['minute']);
        $position = DemoMonth::positionFor($index);

        $incident = new Incident($area, $subcategory, $row['reference'], $row['title'], $position, $reportedAt);
        $recorder = [] === $recorders ? null : $recorders[$index % \count($recorders)];
        $recorderName = self::nameOf($recorder);

        $incident
            ->setSeverity(IncidentSeverityEnum::from($row['severity']))
            ->setSource(IncidentSourceEnum::from($row['source']))
            ->setOccurredAt($reportedAt->modify('-2 hours'))
            ->setNarrative($row['narrative'])
            ->setReportedBy($recorder)
            ->setAssignedTo($recorder)
            // The zone by NAME where the host has one, and by geometry otherwise —
            // a demo area with real zones drawn on it gets the real answer.
            ->setZone($zones[$row['zone']] ?? $this->zones->locate($area, $position))
            ->setDetails(DemoMonth::detailsFor($subcategory, $index));

        new IncidentEvent($incident, IncidentEventKindEnum::Note, $reportedAt, \sprintf('Filed as %s.', $subcategory->path()))
            ->withActor($recorder, $recorderName)
            ->withDetail('source: '.$incident->getSource()->badge());

        foreach ($row['parties'] as $party) {
            new IncidentParty($incident, PartyRoleEnum::from($party['role']), $party['name'])
                ->setDescribedAs($party['described']);
        }

        for ($photo = 1; $photo <= $row['evidence']; ++$photo) {
            // Evidence keeps the moment the handset recorded, never the moment it
            // was uploaded — which here means the site visit, not the seed run.
            new IncidentEvidence($incident, EvidenceKindEnum::Photo, \sprintf('IMG_%04d.jpg', 1200 + $index * 4 + $photo))
                ->setCapturedAt($reportedAt->modify(\sprintf('+%d hours', 5 + $photo)))
                ->setPosition($position);
        }

        // The money BEFORE the transitions, because the resolve guard reads it:
        // an incident cannot be resolved while its claim is outstanding, and the
        // seeder is not allowed a shortcut past its own rules.
        if (null !== $row['money'] && $subcategory->carriesMoney()) {
            $direction = $subcategory->getMoneyDirection() ?? MoneyDirectionEnum::Fine;
            new IncidentMoney($incident, $direction)
                ->setCurrency($this->currency)
                ->setClaimed($row['money']['claimed'])
                ->setAssessed($row['money']['assessed'])
                ->setApproved($row['money']['approved'])
                ->setSettled($row['money']['settled'] ?? 0);
        }

        $this->entityManager->persist($incident);
        $this->walkTo($incident, IncidentStatusEnum::from($row['status']), $reportedAt, $recorder, $recorderName);
    }

    /**
     * Move a freshly filed incident to where the sample month says it is — one
     * legal transition at a time, through the real service. A seeder that wrote
     * the status column directly would be able to produce states the product
     * cannot, and the first bug report would be about the seeded data.
     */
    private function walkTo(Incident $incident, IncidentStatusEnum $target, \DateTimeImmutable $reportedAt, ?UserInterface $actor, ?string $actorName): void
    {
        $at = $reportedAt;
        foreach ([
            IncidentTransitionEnum::Verify,
            IncidentTransitionEnum::Respond,
            IncidentTransitionEnum::Resolve,
        ] as $step) {
            if (!$target->hasReached($step->toPlace())) {
                return;
            }
            $at = $at->modify('+7 hours');
            $this->transitions->apply($incident, $step, $at, $actor, $actorName);
        }

        if (IncidentStatusEnum::Closed === $target) {
            // The clock's own move, and it is made by the clock: thirty days after
            // resolution, with no actor. Even in a seeder.
            $this->transitions->closeIfDue($incident, $at->modify('+31 days'));
        }
    }

    private function area(mixed $named): ?AreaOfInterest
    {
        $repository = $this->entityManager->getRepository(AreaOfInterest::class);
        if (\is_string($named) && '' !== $named) {
            return $repository->findOneBy(['name' => $named]) ?? $repository->findOneBy(['uuid' => $named]);
        }

        $areas = $repository->findBy([], ['id' => 'ASC'], 1);

        return $areas[0] ?? null;
    }

    private function month(SymfonyStyle $io, mixed $raw): ?\DateTimeImmutable
    {
        $value = \is_string($raw) && '' !== $raw ? $raw : DemoMonth::MONTH;
        $month = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value.'-01 00:00:00');
        if (false === $month) {
            $io->error(\sprintf('"%s" is not a month. Write it as YYYY-MM.', $value));

            return null;
        }

        return $month;
    }

    /**
     * The area's zones, by name — only the ones the host actually has. A demo
     * area with no zones drawn gets an empty map, and every incident is unzoned,
     * which is a first-class answer rather than a failure.
     *
     * @return array<string, Zone>
     */
    private function zonesOf(AreaOfInterest $area): array
    {
        $found = [];
        foreach ($this->entityManager->getRepository(Zone::class)->findBy(['area' => $area]) as $zone) {
            if (\in_array($zone->getName(), DemoMonth::ZONES, true)) {
                $found[(string) $zone->getName()] = $zone;
            }
        }

        return $found;
    }

    /**
     * Whoever the host already has accounts for, oldest first. The seeder creates
     * no people: accounts are the host's business (seeder:accounts), and inventing
     * users here would put names on a performance page that nobody recognises.
     *
     * @return list<UserInterface>
     */
    private function recorders(): array
    {
        /** @var list<UserInterface> $users */
        $users = $this->entityManager->getRepository(UserInterface::class)->findBy([], ['id' => 'ASC'], 6);

        return $users;
    }

    private static function nameOf(?UserInterface $user): ?string
    {
        if (null === $user) {
            return null;
        }

        $first = (string) $user->getFirstName();
        $last = (string) $user->getLastName();
        $name = trim(('' !== $first ? mb_substr($first, 0, 1).'. ' : '').$last);

        return '' !== $name ? $name : null;
    }
}
