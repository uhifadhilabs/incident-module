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

namespace UhifadhiLabs\Incident\Model;

use UhifadhiLabs\Incident\Entity\Incident;
use UhifadhiLabs\Incident\Entity\IncidentCategory;
use UhifadhiLabs\Incident\Entity\IncidentEvidence;
use UhifadhiLabs\Incident\Enum\IncidentStatusEnum;
use UhifadhiLabs\Incident\Enum\MoneyDirectionEnum;

/**
 * EVERYTHING THE SIXTEEN WIDGETS DRAW, computed once.
 *
 * A dashboard renders whichever widgets a person has switched on, and several of
 * them ask the same questions — the KPI strip, the matrix and the funnel all want
 * the counts by status; the register, the map and the feed all want the same
 * rows. Computing per widget would run the same query four times and, worse,
 * would let two widgets on one screen disagree about how many incidents there
 * were, because each would have asked at a slightly different moment.
 *
 * So the surface is resolved ONCE ({@see \UhifadhiLabs\Incident\Service\IncidentDashboardService})
 * and every partial reads this. It is the same discipline as the design's own
 * rule that ONE filter drives the map, the register and the charts.
 *
 * A Twig partial receives this and reads it; it computes nothing, because a
 * template that does arithmetic is a template nobody can test.
 */
final readonly class IncidentDashboard
{
    /**
     * @param list<IncidentCategory>                                                                           $categories     the taxonomy, in its own order
     * @param array<string, int>                                                                               $categoryCounts category slug => count in the window
     * @param array<string, int>                                                                               $statusCounts   status value => count, every place present
     * @param array<string, array<string, int>>                                                                $matrix         category slug => status value => count
     * @param array<string, int>                                                                               $zoneCounts     zone name ('' = unzoned) => count
     * @param array<string, int>                                                                               $dailyCounts    Y-m-d => count, every day of the window present
     * @param array<string, array{claimed: int, assessed: int, approved: int, settled: int, outstanding: int}> $money          keyed by {@see MoneyDirectionEnum} value
     * @param list<Incident>                                                                                   $recent         newest first — the register, the feed and the map
     * @param list<Incident>                                                                                   $queue          what is waiting on the signed-in person, oldest first
     * @param list<Incident>                                                                                   $ageing         open work against its own term, worst first
     * @param array<string, list<Incident>>                                                                    $board          status value => the cards in that column
     * @param list<IncidentEvidence>                                                                           $evidence       newest capture first
     * @param IncidentRail|null                                                                                $rail           the one incident this person last touched, or null
     */
    public function __construct(
        public IncidentFilter $filter,
        public \DateTimeImmutable $now,
        public array $categories,
        public int $filedCount,
        public array $categoryCounts,
        public array $statusCounts,
        public array $matrix,
        public array $zoneCounts,
        public array $dailyCounts,
        public array $money,
        public array $recent,
        public int $recentTotal,
        public array $queue,
        public array $ageing,
        public array $board,
        public array $evidence,
        public ?float $medianHoursToVerify,
        public int $pastTermCount,
        public ?IncidentRail $rail,
        public string $currency,
    ) {
    }

    /**
     * The five places, in workflow order — so a template iterates the ENUM and
     * never a bare string key. A Twig file that had to name a status by its
     * stored value would be the one place in the module where the workflow's
     * vocabulary is retyped.
     *
     * @return list<IncidentStatusEnum>
     */
    public function places(): array
    {
        return IncidentStatusEnum::ordered();
    }

    /** How many are sitting in one place right now. */
    public function statusCount(IncidentStatusEnum $place): int
    {
        return $this->statusCounts[$place->value] ?? 0;
    }

    /**
     * The cards in one column of the status board.
     *
     * @return list<Incident>
     */
    public function column(IncidentStatusEnum $place): array
    {
        return $this->board[$place->value] ?? [];
    }

    /** How many of one kind reached one place — the matrix's cells. */
    public function matrixCount(IncidentCategory $category, IncidentStatusEnum $place): int
    {
        return $this->matrix[$category->getSlug()][$place->value] ?? 0;
    }

    /** How many are still somebody's work — the design's "31 open". */
    public function openCount(): int
    {
        $open = 0;
        foreach (IncidentStatusEnum::ordered() as $place) {
            if ($place->isOpen()) {
                $open += $this->statusCounts[$place->value] ?? 0;
            }
        }

        return $open;
    }

    /**
     * HOW MANY REACHED EACH PLACE — which is not how many are SITTING in it. An
     * incident that is resolved passed through verification, so the funnel's
     * "verified" row counts it; the status board's "verified" column does not.
     * Conflating the two would draw a funnel that widens.
     *
     * @return array<string, int> status value => how many got at least this far
     */
    public function reachedCounts(): array
    {
        $reached = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            $total = 0;
            foreach ($this->statusCounts as $value => $count) {
                $at = IncidentStatusEnum::tryFrom($value);
                if (null !== $at && $at->hasReached($place)) {
                    $total += $count;
                }
            }
            $reached[$place->value] = $total;
        }

        return $reached;
    }

    /** The money still owed in one direction — the KPI strip adds nothing across the two. */
    public function outstanding(MoneyDirectionEnum $direction): int
    {
        return $this->money[$direction->value]['outstanding'] ?? 0;
    }

    /** The busiest day of the window, for the load bar's caption. Null on an empty month. */
    public function peakDay(): ?string
    {
        if ([] === $this->dailyCounts) {
            return null;
        }

        $peak = array_keys($this->dailyCounts, max($this->dailyCounts), true);

        return $peak[0] ?? null;
    }

    public function peakCount(): int
    {
        return [] === $this->dailyCounts ? 0 : max($this->dailyCounts);
    }

    /**
     * The feed's days, newest first, each with its own count — the design groups
     * the feed by day and prints the day's total beside the heading.
     *
     * @return list<array{day: \DateTimeImmutable, incidents: list<Incident>}>
     */
    public function recentByDay(): array
    {
        $days = [];
        foreach ($this->recent as $incident) {
            $days[$incident->getReportedAt()->format('Y-m-d')][] = $incident;
        }

        $grouped = [];
        foreach ($days as $day => $incidents) {
            $grouped[] = ['day' => new \DateTimeImmutable($day), 'incidents' => $incidents];
        }

        return $grouped;
    }

    /**
     * WHAT THE MAP DRAWS. Delegated so the case file's single-incident map and the
     * dashboard's whole-month map are built by the SAME code — one marker, one
     * meaning, wherever it is drawn.
     *
     * @return array{type: string, features: list<array{type: string, geometry: array<string, mixed>, properties: array<string, mixed>}>}
     */
    public function mapPayload(): array
    {
        return IncidentMapPayload::of($this->recent);
    }

    /** The count against one category, zero where nothing of that kind was filed. */
    public function categoryCount(IncidentCategory $category): int
    {
        return $this->categoryCounts[$category->getSlug()] ?? 0;
    }
}
