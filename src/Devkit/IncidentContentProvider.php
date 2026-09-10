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

namespace Uhifadhi\Incident\Devkit;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Service\IncidentMoneyService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;
use Uhifadhi\Incident\Service\IncidentTransitionService;

/**
 * A MONTH OF INCIDENTS TO LOOK AT — the sample month {@see DemoMonth} describes,
 * filed into the area the installation already has, so a developer's first
 * dashboard is a populated one.
 *
 * IT GOES THROUGH THE SAME SERVICES THE SCREENS DO, and that is the whole
 * discipline of it. Every incident is filed by {@see IncidentReportService},
 * every money figure recorded by {@see IncidentMoneyService}, and every state
 * reached one legal transition at a time through
 * {@see IncidentTransitionService} — the same guards a person's refused button
 * hits. Demo content written straight to the tables is demo content that can be
 * shaped in ways the product cannot produce, and every such row is a bug report
 * about a screen that is working correctly.
 *
 * WHAT THAT DISCIPLINE COSTS, SAID PLAINLY. The sample month describes three
 * things this module has no way to write:
 *
 *   PARTIES beyond the reporter — the claimant, the witness, the suspect. The
 *   filing service names the filer and nothing else names anybody.
 *   EVIDENCE — the photographs and the signed document each money case carries.
 *   Nothing in this module attaches one; the case file only ever reads them.
 *   THE ASSIGNEE — no service assigns an incident to anybody.
 *
 * None of the three is seeded, and the omission is the point: each is a screen
 * the product does not have yet, and a seeder that wrote them straight to the
 * entity manager would have hidden that for as long as it kept working.
 *
 * MONEY IS RECORDED ONLY WHERE THE PRODUCT ALLOWS IT — once response has
 * started. Sixteen rows of the sample month carry money at `reported` or
 * `verified`, which is a state no screen can produce, so their figures are left
 * out rather than written past the rule.
 *
 * THE REFERENCE IS THE REGISTER'S, not the month's. Filing mints the next one,
 * as it does for a person at the form, so the seeded references run with
 * whatever the installation already has rather than colliding with it.
 *
 * IT IS COLLECTED, NOT RUN. devkit installs through `require-dev`; in a
 * production build nothing collects this and it is an ordinary service nobody
 * ever asks anything of.
 *
 * THE ENTITY MANAGER IS HERE TO READ AND TO FLUSH, never to write a record.
 * Which area exists and who has an account are questions only the installation
 * can answer, and the transition service leaves its flush to its caller exactly
 * as it does for the controllers. Nothing below constructs or persists a row.
 *
 * @see ContentProviderInterface
 */
final readonly class IncidentContentProvider implements ContentProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentTaxonomyInstaller $taxonomy,
        private IncidentSubcategoryRepository $subcategories,
        private IncidentReportService $reports,
        private IncidentMoneyService $money,
        private IncidentTransitionService $transitions,
    ) {
    }

    public function key(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Incidents';
    }

    public function description(): string
    {
        return 'A month of incidents filed in the first area — poaching, conflict, compliance and mortality, walked to the states the register shows.';
    }

    /**
     * The people. Incidents are recorded BY somebody, and a register whose every
     * row was recorded by nobody says nothing about who is doing the work.
     *
     * Areas are not named here, deliberately: nothing installed ships area demo
     * content yet, and devkit refuses an edge to a key no provider declares. The
     * area is taken from whatever the installation has.
     *
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return ['team'];
    }

    public function load(): void
    {
        // The taxonomy first: there is nothing to file an incident against
        // without it, and a seeder that failed for that reason would send
        // somebody hunting for a bug that is really a missing install step.
        $this->taxonomy->install();

        $area = $this->firstArea();
        if (null === $area) {
            // An installation with no area is one with nowhere to file, and that
            // is a state rather than a failure.
            return;
        }

        $month = new \DateTimeImmutable(DemoMonth::MONTH.'-01 00:00:00');
        $recorders = $this->recorders();

        foreach (DemoMonth::incidents() as $index => $row) {
            $subcategory = $this->subcategories->findOneBySlug($row['subcategory']);
            if (null === $subcategory) {
                continue;
            }

            $reportedAt = $month
                ->setDate((int) $month->format('Y'), (int) $month->format('m'), $row['day'])
                ->setTime($row['hour'], $row['minute']);
            $recorder = [] === $recorders ? null : $recorders[$index % \count($recorders)];

            $incident = $this->reports->file(
                area: $area,
                subcategory: $subcategory,
                title: $row['title'],
                position: DemoMonth::positionFor($index),
                now: $reportedAt,
                severity: IncidentSeverityEnum::from($row['severity']),
                source: IncidentSourceEnum::from($row['source']),
                occurredAt: $reportedAt->modify('-2 hours'),
                narrative: $row['narrative'],
                reportedBy: $recorder,
                details: DemoMonth::detailsFor($subcategory, $index),
            );

            $this->walkTo($incident, IncidentStatusEnum::from($row['status']), $reportedAt, $recorder, $row['money']);

            // The transition service leaves the flush to its caller, the way the
            // controllers that move an incident do — one incident, one write,
            // which is also the boundary a request has.
            $this->entityManager->flush();
        }
    }

    /**
     * Move a freshly filed incident to where the sample month says it is — one
     * legal transition at a time, with the money recorded at the point the
     * product allows it: after response has started, and before the resolve that
     * reads it.
     *
     * @param array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null $money
     */
    private function walkTo(
        Incident $incident,
        IncidentStatusEnum $target,
        \DateTimeImmutable $reportedAt,
        ?UserInterface $actor,
        ?array $money,
    ): void {
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
            $this->transitions->apply($incident, $step, $at, $actor, self::nameOf($actor));

            if (IncidentTransitionEnum::Respond === $step && null !== $money && $incident->getSubcategory()->carriesMoney()) {
                $this->money->record(
                    $incident,
                    $money['claimed'],
                    $money['assessed'],
                    $money['approved'],
                    $money['settled'] ?? 0,
                    $at,
                    $actor,
                    self::nameOf($actor),
                );
            }
        }

        if (IncidentStatusEnum::Closed === $target) {
            // The clock's own move, and it is made by the clock: the term after
            // resolution, with no actor. Even here.
            $this->transitions->closeIfDue($incident, $at->modify('+31 days'));
        }
    }

    /**
     * The area to file into — the first the installation has. Read through the
     * platform's area contract rather than a bundle's class, because which class
     * answers it is the installation's business.
     */
    private function firstArea(): ?AreaOfInterest
    {
        $areas = $this->entityManager->getRepository(AreaOfInterest::class)->findBy([], ['id' => 'ASC'], 1);

        return $areas[0] ?? null;
    }

    /**
     * Whoever the installation already has accounts for, oldest first. This
     * creates no people: accounts belong to whoever owns them, and inventing some
     * here would put names on a performance page that nobody recognises.
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
