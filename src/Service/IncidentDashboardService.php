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

namespace Uhifadhi\Incident\Service;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentDashboard;
use Uhifadhi\Incident\Model\IncidentFilter;
use Uhifadhi\Incident\Model\IncidentRail;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\ModuleContracts\Entity\UserInterface;

/**
 * BUILDS THE DASHBOARD, once per request.
 *
 * ONE QUERY, MANY READINGS. The month's incidents are loaded ONCE — with the
 * taxonomy and the money already joined — and every figure the surface shows is
 * computed from those same rows. That is not an optimisation, it is the design's
 * own rule made structural: the map, the register and the charts read the same
 * query, so the KPI strip, the matrix and the funnel CANNOT disagree about how
 * many incidents there were, even by a row, even under load.
 *
 * The counting is therefore plain PHP over a loaded collection rather than a
 * handful of GROUP BY queries. The set is one area's filings in one window —
 * the same rows the register already lists — so the arithmetic is trivial, and
 * in exchange every number on the screen is provably the same answer read a
 * different way. A deployment that one day wants a five-year window on this
 * screen is the point at which to revisit it; a month is not that.
 *
 * `$now` is handed IN rather than read from the clock: a dashboard is testable
 * and a period picker is a parameter, not a second code path.
 */
final readonly class IncidentDashboardService
{
    /** How many rows the register shows before "latest 14 of 47". */
    public const int REGISTER_ROWS = 14;

    /** How many tiles the evidence widget draws. */
    public const int EVIDENCE_TILES = 12;

    /** How many rows the ageing widget lists. */
    public const int AGEING_ROWS = 5;

    public function __construct(
        private IncidentRepository $incidents,
        private IncidentCategoryRepository $categories,
        private IncidentTransitionService $transitions,
        private string $currency,
    ) {
    }

    public function build(IncidentFilter $filter, \DateTimeImmutable $now, ?UserInterface $viewer = null): IncidentDashboard
    {
        $incidents = $this->incidents->findFiltered($filter);

        return new IncidentDashboard(
            filter: $filter,
            now: $now,
            categories: $this->categories->allInOrder(),
            filedCount: \count($incidents),
            categoryCounts: self::byCategory($incidents),
            statusCounts: self::byStatus($incidents),
            matrix: self::byCategoryAndStatus($incidents),
            zoneCounts: self::byZone($incidents),
            dailyCounts: self::byDay($incidents, $filter),
            money: self::money($incidents),
            recent: $incidents,
            recentTotal: \count($incidents),
            queue: null === $viewer ? [] : $this->incidents->queueFor($viewer, $filter->area),
            ageing: self::ageing($incidents, $now, self::AGEING_ROWS),
            board: self::board($incidents),
            evidence: $this->incidents->latestEvidence($filter, self::EVIDENCE_TILES),
            medianHoursToVerify: self::medianHoursToVerify($incidents),
            pastTermCount: self::pastTermCount($incidents, $now),
            rail: $this->rail($filter, $now, $viewer),
            currency: $this->currency,
        );
    }

    /**
     * THE ONE INCIDENT ON THE RAIL. The one this person last touched; failing
     * that, the head of their queue — and the rail says which, rather than
     * rendering an empty card. Null only when they have touched nothing and
     * nothing is waiting on them.
     */
    public function rail(IncidentFilter $filter, \DateTimeImmutable $now, ?UserInterface $viewer): ?IncidentRail
    {
        if (null === $viewer) {
            return null;
        }

        $because = IncidentRail::BECAUSE_TOUCHED;
        $incident = $this->incidents->lastTouchedBy($viewer, $filter->area);
        if (null === $incident) {
            $because = IncidentRail::BECAUSE_QUEUED;
            $incident = $this->incidents->queueFor($viewer, $filter->area)[0] ?? null;
        }

        return null === $incident ? null : $this->railFor($incident, $now, $because);
    }

    /**
     * The rail for one NAMED incident — what the case file draws under the
     * heading. Identical component, identical markup, same guards run.
     */
    public function railFor(Incident $incident, \DateTimeImmutable $now, string $because = IncidentRail::BECAUSE_TOUCHED): IncidentRail
    {
        return new IncidentRail(
            $incident,
            $this->transitions->available($incident, $now),
            $this->transitions->blocked($incident, $now),
            $because,
            $this->transitions->closesAt($incident),
        );
    }

    /**
     * @param list<Incident> $incidents
     *
     * @return array<string, int>
     */
    private static function byCategory(array $incidents): array
    {
        $counts = [];
        foreach ($incidents as $incident) {
            $slug = $incident->getCategory()->getSlug();
            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every place present, at zero where nothing is there. A chart that dropped
     * an empty column would redraw itself differently on a quiet month and the
     * reader would think the workflow had changed.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, int>
     */
    private static function byStatus(array $incidents): array
    {
        $counts = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $counts[$place->value] = \count(array_filter(
                $incidents,
                static fn (Incident $incident) => $incident->getStatus() === $place,
            ));
        }

        return $counts;
    }

    /**
     * @param list<Incident> $incidents
     *
     * @return array<string, array<string, int>>
     */
    private static function byCategoryAndStatus(array $incidents): array
    {
        $matrix = [];
        foreach ($incidents as $incident) {
            $slug = $incident->getCategory()->getSlug();
            $status = $incident->getStatus()->value;
            $matrix[$slug][$status] = ($matrix[$slug][$status] ?? 0) + 1;
        }

        return $matrix;
    }

    /**
     * An incident in none of the area's zones counts under the empty key.
     * "Unzoned" is a FIRST-CLASS answer — an org that has drawn no zones is the
     * normal state, and a widget that dropped those rows would report a month of
     * work as nothing happening.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, int>
     */
    private static function byZone(array $incidents): array
    {
        $counts = [];
        foreach ($incidents as $incident) {
            $zone = $incident->getZone()?->getName() ?? '';
            $counts[$zone] = ($counts[$zone] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * One count per calendar day of the window, INCLUDING the quiet ones: the
     * load bar draws one bar per day, and skipping the empty days would silently
     * compress the month.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, int>
     */
    private static function byDay(array $incidents, IncidentFilter $filter): array
    {
        $counts = [];
        if (null !== $filter->from && null !== $filter->to) {
            for ($day = $filter->from; $day < $filter->to; $day = $day->modify('+1 day')) {
                $counts[$day->format('Y-m-d')] = 0;
            }
        }
        foreach ($incidents as $incident) {
            $day = $incident->getReportedAt()->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * The money, both ways. The two directions are kept SEPARATE and are never
     * summed — a fine is owed to the authority and a claim is owed by it, and the
     * design refuses to add them anywhere, including in a helper.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, array{claimed: int, assessed: int, approved: int, settled: int, outstanding: int}>
     */
    private static function money(array $incidents): array
    {
        $totals = [];
        foreach (MoneyDirectionEnum::cases() as $direction) {
            $totals[$direction->value] = ['claimed' => 0, 'assessed' => 0, 'approved' => 0, 'settled' => 0, 'outstanding' => 0];
        }

        foreach ($incidents as $incident) {
            $money = $incident->getMoney();
            if (null === $money) {
                continue;
            }
            $key = $money->getDirection()->value;
            $totals[$key]['claimed'] += $money->getClaimed() ?? 0;
            $totals[$key]['assessed'] += $money->getAssessed() ?? 0;
            $totals[$key]['approved'] += $money->payable();
            $totals[$key]['settled'] += $money->getSettled();
            $totals[$key]['outstanding'] += $money->outstanding();
        }

        return $totals;
    }

    /**
     * The status board's columns: one per place, in workflow order, each holding
     * the incidents currently sitting there — currently, not ever, which is what
     * makes this different from the funnel.
     *
     * @param list<Incident> $incidents
     *
     * @return array<string, list<Incident>>
     */
    private static function board(array $incidents): array
    {
        $columns = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $columns[$place->value] = array_values(array_filter(
                $incidents,
                static fn (Incident $incident) => $incident->getStatus() === $place,
            ));
        }

        return $columns;
    }

    /**
     * OPEN WORK AGAINST ITS OWN CATEGORY'S TERM, worst first — the ageing
     * widget's rows, breaches at the top.
     *
     * @param list<Incident> $incidents
     *
     * @return list<Incident>
     */
    private static function ageing(array $incidents, \DateTimeImmutable $now, ?int $limit = null): array
    {
        $open = array_values(array_filter(
            $incidents,
            static fn (Incident $incident) => $incident->getStatus()->isOpen(),
        ));

        usort($open, static fn (Incident $a, Incident $b) => ($b->ageInHours($now) - $b->getSubcategory()->getTermHours())
            <=> ($a->ageInHours($now) - $a->getSubcategory()->getTermHours()));

        return null === $limit ? $open : \array_slice($open, 0, $limit);
    }

    /** @param list<Incident> $incidents */
    private static function pastTermCount(array $incidents, \DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($incidents as $incident) {
            if ($incident->getStatus()->isOpen() && $incident->isPastTerm($now)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The median hours from filing to verification, or null while nothing has
     * been verified.
     *
     * The MEDIAN and not the mean, because one incident nobody looked at for
     * three weeks would drag a mean past every honest reading of the month.
     *
     * @param list<Incident> $incidents
     */
    private static function medianHoursToVerify(array $incidents): ?float
    {
        $hours = [];
        foreach ($incidents as $incident) {
            $verified = $incident->getVerifiedAt();
            if (null !== $verified) {
                $hours[] = ($verified->getTimestamp() - $incident->getReportedAt()->getTimestamp()) / 3600;
            }
        }

        if ([] === $hours) {
            return null;
        }

        sort($hours);
        $count = \count($hours);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($hours[$middle - 1] + $hours[$middle]) / 2 : $hours[$middle];
    }
}
