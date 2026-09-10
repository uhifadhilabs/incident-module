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
use Symfony\Component\HttpFoundation\File\File;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentSeverityEnum;
use Uhifadhi\Incident\Enum\IncidentSourceEnum;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\IncidentTransitionEnum;
use Uhifadhi\Incident\Enum\PartyRoleEnum;
use Uhifadhi\Incident\Exception\IncidentEvidenceException;
use Uhifadhi\Incident\Model\DemoMonth;
use Uhifadhi\Incident\Repository\IncidentSubcategoryRepository;
use Uhifadhi\Incident\Service\IncidentCaseService;
use Uhifadhi\Incident\Service\IncidentEvidenceService;
use Uhifadhi\Incident\Service\IncidentMoneyService;
use Uhifadhi\Incident\Service\IncidentReportService;
use Uhifadhi\Incident\Service\IncidentTaxonomyInstaller;

/**
 * A MONTH OF INCIDENTS TO LOOK AT — the sample month {@see DemoMonth} describes,
 * filed into the area the installation already has, so a developer's first
 * dashboard is a populated one.
 *
 * IT GOES THROUGH THE SAME SERVICES THE SCREENS DO, and that is the whole
 * discipline of it. Every incident is filed by {@see IncidentReportService},
 * every state reached one legal transition at a time through
 * {@see IncidentCaseService}, every money figure recorded by
 * {@see IncidentMoneyService}, every party, assignment and photograph written
 * through the doors a person uses. Demo content written straight to the tables is
 * demo content that can be shaped in ways the product cannot produce, and every
 * such row is a bug report about a screen that is working correctly.
 *
 * THE SAMPLE MONTH ENDS TODAY. It is a shape, not a date — see
 * {@see DemoMonth::reportedAt()} for why a fixed month seeded a dashboard that
 * opened on nothing.
 *
 * WHAT IS STILL NOT SEEDED, SAID PLAINLY:
 *
 *   MONEY BELOW `in progress`. Sixteen rows of the sample month carry money at
 *   `reported` or `verified`, which is a state no screen can produce, so their
 *   figures are left out rather than written past the rule. The rows and the
 *   product's rule genuinely disagree, and which of them is wrong is a ruling
 *   nobody has made.
 *
 *   DOCUMENTS. The evidence a money case carries is a signed form, and the
 *   platform's default accepted types are images. Photographs are seeded with
 *   real bytes; the signed form waits on a deployment that accepts one.
 *
 * THE REFERENCE IS THE REGISTER'S, not the month's. Filing mints the next one,
 * as it does for a person at the form, so the seeded references run with
 * whatever the installation already has rather than colliding with it.
 *
 * IT IS COLLECTED, NOT RUN. devkit installs through `require-dev`; in a
 * production build nothing collects this and it is an ordinary service nobody
 * ever asks anything of.
 *
 * THE ENTITY MANAGER IS HERE TO READ, never to write a record. Which area exists
 * and who has an account are questions only the installation can answer, and
 * every service below writes and flushes for itself. Nothing here constructs or
 * persists a row.
 *
 * @see ContentProviderInterface
 */
final readonly class IncidentContentProvider implements ContentProviderInterface
{
    /**
     * THE PHOTOGRAPH EVERY SEEDED PIECE OF EVIDENCE IS. A small flat rectangle,
     * base64 of a real PNG — real enough that the platform detects its type,
     * measures it and makes a preview, which is the whole point of seeding
     * through the storage path rather than writing keys.
     *
     * Deliberately not a picture of anything. Demo evidence stands for the SHAPE
     * of a record; a stock photograph of a snare or a carcass would be a claim
     * about a place, and this seeder files into whatever area an installation has.
     */
    private const string PHOTOGRAPH = 'iVBORw0KGgoAAAANSUhEUgAAAGAAAABICAIAAACGBWc0AAAAcklEQVR42u3QMQ0AAAgDsPkXxUmQhQNujiZV0EwXhygQJEiQIEGC'
        .'BAlCkCBBggQJEiQIQYIECRIkSJAgBAkSJEiQIEGCBCFIkCBBggQJEoQgQYIECRIkSBCCBAkSJEiQIEGCECRIkCBBggQJQpAgQYK+WKVJB6MfJAAJAAAAAElFTkSuQmCC';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private IncidentTaxonomyInstaller $taxonomy,
        private IncidentSubcategoryRepository $subcategories,
        private IncidentReportService $reports,
        private IncidentMoneyService $money,
        private IncidentCaseService $cases,
        private IncidentEvidenceService $evidence,
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
        return 'A month of incidents filed in the first area — poaching, conflict, compliance and mortality, walked to the states the register shows, with their parties, responders and photographs.';
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

        $today = new \DateTimeImmutable();
        $recorders = $this->recorders();

        foreach (DemoMonth::incidents() as $index => $row) {
            $subcategory = $this->subcategories->findOneBySlug($row['subcategory']);
            if (null === $subcategory) {
                continue;
            }

            $reportedAt = DemoMonth::reportedAt($index, $today);
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

            $this->addParties($incident, $row['parties'], $reportedAt, $recorder);
            $this->attachEvidence($incident, $row['evidence'], $reportedAt, $recorder);
            $this->walkTo(
                $incident,
                IncidentStatusEnum::from($row['status']),
                $reportedAt,
                $recorder,
                // Whoever carries the response, once there is one to carry. A
                // different person from the one who filed it wherever the
                // installation has more than one account, because a register in
                // which everybody reports to themselves says nothing about a team.
                [] === $recorders ? null : $recorders[($index + 1) % \count($recorders)],
                $row['money'],
            );
        }
    }

    /**
     * Move a freshly filed incident to where the sample month says it is — one
     * legal transition at a time, with the assignee named and the money recorded
     * at the points the product allows: response is somebody's work, and money is
     * recorded after response has started and before the resolve that reads it.
     *
     * @param array{claimed: int|null, assessed: int|null, approved: int|null, settled: int|null}|null $money
     */
    private function walkTo(
        Incident $incident,
        IncidentStatusEnum $target,
        \DateTimeImmutable $reportedAt,
        ?UserInterface $actor,
        ?UserInterface $responder,
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
            $this->cases->move($incident, $step, $at, $actor, self::nameOf($actor));

            if (IncidentTransitionEnum::Respond !== $step) {
                continue;
            }

            $this->cases->assign($incident, $responder, $at, $actor, self::nameOf($actor));

            if (null !== $money && $incident->getSubcategory()->carriesMoney()) {
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
            $this->cases->closeIfDue($incident, $at->modify('+31 days'));
        }
    }

    /**
     * The people and the animals the sample month names on a case file, added the
     * way a person adds them — role, name and the line under it.
     *
     * @param list<array{role: string, name: string, described: string|null}> $parties
     */
    private function addParties(Incident $incident, array $parties, \DateTimeImmutable $at, ?UserInterface $actor): void
    {
        foreach ($parties as $party) {
            $role = PartyRoleEnum::tryFrom($party['role']);
            if (null === $role) {
                continue;
            }

            $this->cases->addParty(
                $incident,
                $role,
                $party['name'],
                $party['described'],
                at: $at->modify('+29 minutes'),
                actor: $actor,
                actorName: self::nameOf($actor),
            );
        }
    }

    /**
     * The photographs, with real bytes behind them — written through the same
     * evidence door a browser upload takes, so the Files hub reads a genuine size
     * and a genuine preview back rather than a key naming nothing.
     *
     * A file the deployment does not accept is skipped rather than fatal: a
     * deployment that narrowed its accepted types has made a decision, and a
     * seeder is not the place to argue with it.
     */
    private function attachEvidence(Incident $incident, int $count, \DateTimeImmutable $at, ?UserInterface $actor): void
    {
        for ($n = 1; $n <= $count; ++$n) {
            $capturedAt = $at->modify(\sprintf('+%d minutes', 90 + $n));

            try {
                $this->evidence->attach(
                    $incident,
                    $this->aPhotograph(),
                    \sprintf('IMG_%04d.png', ($incident->getId() ?? 0) * 10 + $n),
                    capturedAt: $capturedAt,
                    position: $incident->getPosition(),
                    at: $capturedAt,
                    actor: $actor,
                    actorName: self::nameOf($actor),
                );
            } catch (IncidentEvidenceException) {
                return;
            }
        }
    }

    /** The seeded photograph, on disk where the platform's storage can read it. */
    private function aPhotograph(): File
    {
        $path = tempnam(sys_get_temp_dir(), 'incident-demo-evidence');
        if (false === $path) {
            throw new \RuntimeException('The demo photograph could not be written to a temporary file.');
        }

        file_put_contents($path, base64_decode(self::PHOTOGRAPH, true) ?: '');

        return new File($path);
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
