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

namespace Uhifadhi\Incident\Module;

use Uhifadhi\Area\Kpi\DepartmentKpi;
use Uhifadhi\Area\Kpi\DepartmentKpiProviderInterface;
use Uhifadhi\Area\Kpi\DepartmentRef;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * WHAT THIS DEPARTMENT'S PEOPLE DID WITH THE INCIDENTS MODULE, this month.
 *
 * THE SLICE. An incident is not a department's because of where it happened or
 * who may read it — the whole module refuses that idea. It is a department's
 * because THE PERSON WHO RECORDED IT holds a position filed under that
 * department: incident → reportedBy → position → department. Two departments
 * sharing this module therefore read the SAME ROWS and get DIFFERENT NUMBERS,
 * and neither is fenced out of the other's.
 *
 * An incident with no recorder, or whose recorder holds no position, or whose
 * position sits under no department, belongs to nobody's figures and is silently
 * absent from all of them rather than being shared out.
 *
 * ── WHICH FIGURES, AND WHY THESE ─────────────────────────────────────────────
 * The host's seam states a rule that decides this list: EVERY KPI IT CARRIES IS
 * BETTER WHEN LARGER, and a module with a less-is-better figure must invert it
 * before handing it over. "Open incidents" and "breaches" are both less-is-better
 * and neither inverts honestly — an area with more open incidents may simply be
 * an area where people are reporting, which is the behaviour this module exists
 * to encourage. Punishing a department's performance page for it would teach
 * exactly the wrong lesson.
 *
 * So the plates are the work, not the backlog:
 *
 *  - **Incidents recorded** — filings. More reporting is better reporting.
 *  - **Incidents resolved** — work finished.
 *  - **Resolved within term** — a SHARE, and the honest reading of "breaches":
 *    the same fact, pointing the right way, and null while nothing has been
 *    resolved rather than 0%.
 *  - **Fines assessed** and **Compensation approved** — money, in TWO plates,
 *    because the design refuses to add the two directions together anywhere and a
 *    performance page is not an exception.
 *
 * NULL IS UNKNOWN AND UNKNOWN IS NOT ZERO. A department that recorded no incident
 * carrying money gets a dashed slot for the money plates, not a zero: "they
 * assessed nothing" and "nothing they touched could carry a fine" are different
 * facts, and only one of them is about performance.
 */
final class IncidentDepartmentKpiProvider implements DepartmentKpiProviderInterface
{
    /** How many months of history the sparklines carry, including the current one. */
    private const int SPARK_MONTHS = 6;

    public function __construct(
        private readonly IncidentRepository $incidents,
        /** The slug this module is registered under in the host's catalogue. */
        private readonly string $slug,
        private readonly string $name = 'Incidents',
        private readonly string $currency = 'TZS',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    /**
     * THE DEPARTMENT ARRIVES AS A REF, NOT AS AN ENTITY. Nothing publishes a
     * contract for a department — there is no `DepartmentInterface` in
     * uhifadhi/module-contracts and none in uhifadhi/team-module — so a seam
     * typed against team's class would make every module that reports a figure
     * hard-require team. {@see DepartmentRef} carries the whole of what a figure
     * needs: the id rows are filed under, the name a plate prints.
     *
     * @return list<DepartmentKpi>
     */
    public function kpisFor(DepartmentRef $department, \DateTimeImmutable $now): array
    {
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $monthStart->modify('+1 month');
        $previousStart = $monthStart->modify('-1 month');

        $month = $this->incidents->findForDepartment($department->id, $monthStart, $nextMonth);
        $previous = $this->incidents->findForDepartment($department->id, $previousStart, $monthStart);

        // Nothing recorded by this department's people in either month: report
        // NOTHING rather than a row of zeros. The host draws a dashed labelled
        // slot, which is the truthful rendering of "we have no reading".
        if ([] === $month && [] === $previous) {
            return [];
        }

        $caption = \sprintf('%s module · recorded by this department', $this->name);
        $kpis = [
            new DepartmentKpi(
                'incidents',
                'Incidents recorded',
                $this->slug,
                $this->name,
                (float) \count($month),
                '',
                (float) \count($previous),
                $this->spark($department->id, $monthStart, static fn (array $rows): float => (float) \count($rows)),
                $caption,
            ),
            new DepartmentKpi(
                'incidents_resolved',
                'Incidents resolved',
                $this->slug,
                $this->name,
                (float) self::resolvedCount($month),
                '',
                (float) self::resolvedCount($previous),
                $this->spark($department->id, $monthStart, static fn (array $rows): float => (float) self::resolvedCount($rows)),
                $caption,
            ),
            new DepartmentKpi(
                'incidents_in_term',
                'Resolved within term',
                $this->slug,
                $this->name,
                self::withinTermShare($month),
                DepartmentKpi::SHARE,
                self::withinTermShare($previous),
                $this->spark($department->id, $monthStart, static fn (array $rows): ?float => self::withinTermShare($rows)),
                // Its own provenance line: the term is the CATEGORY's, not a
                // global setting, and a share printed without saying what it was
                // measured against is unreadable.
                $caption.' · against each category’s own term',
            ),
        ];

        // A list of pairs, not a map: an enum case cannot be an array key, and
        // the two directions must stay two plates — the design refuses to add a
        // fine and a claim together anywhere, and a performance page is not an
        // exception.
        foreach ([
            [MoneyDirectionEnum::Fine, 'Fines assessed'],
            [MoneyDirectionEnum::Compensation, 'Compensation approved'],
        ] as [$direction, $label]) {
            $value = self::money($month, $direction);
            $was = self::money($previous, $direction);
            // Absent entirely rather than dashed, when this department has never
            // touched money of this kind: a plate for a fact that does not apply
            // is clutter, and the host's dashed slot means "we could not measure",
            // not "this is not our work".
            if (null === $value && null === $was) {
                continue;
            }

            $kpis[] = new DepartmentKpi(
                'incidents_'.$direction->value,
                $label,
                $this->slug,
                $this->name,
                null === $value ? null : (float) $value,
                $this->currency,
                null === $was ? null : (float) $was,
                $this->spark($department->id, $monthStart, static fn (array $rows): ?float => null === ($m = self::money($rows, $direction)) ? null : (float) $m),
                $caption,
            );
        }

        return $kpis;
    }

    /**
     * The last {@see SPARK_MONTHS} months of one figure, oldest first. A month
     * this department could not be measured in contributes nothing rather than a
     * zero, so a sparkline never dips to the floor because a reading is missing.
     *
     * @param callable(list<Incident>): ?float $reading
     *
     * @return list<float>
     */
    private function spark(int $departmentId, \DateTimeImmutable $monthStart, callable $reading): array
    {
        $series = [];
        for ($back = self::SPARK_MONTHS - 1; $back >= 0; --$back) {
            $from = $monthStart->modify(\sprintf('-%d months', $back));
            $value = $reading($this->incidents->findForDepartment($departmentId, $from, $from->modify('+1 month')));
            if (null !== $value) {
                $series[] = $value;
            }
        }

        return $series;
    }

    /** @param list<Incident> $incidents */
    private static function resolvedCount(array $incidents): int
    {
        $resolved = 0;
        foreach ($incidents as $incident) {
            if (null !== $incident->getResolvedAt()) {
                ++$resolved;
            }
        }

        return $resolved;
    }

    /**
     * The share of this month's RESOLVED work that finished inside its own
     * category's term. Null while nothing has been resolved — a month with no
     * finished work has no compliance rate, and printing 0% would read as total
     * failure rather than as no data.
     *
     * @param list<Incident> $incidents
     */
    private static function withinTermShare(array $incidents): ?float
    {
        $resolved = 0;
        $inTerm = 0;
        foreach ($incidents as $incident) {
            $resolvedAt = $incident->getResolvedAt();
            if (null === $resolvedAt) {
                continue;
            }
            ++$resolved;
            if (!$incident->isPastTerm($resolvedAt)) {
                ++$inTerm;
            }
        }

        return 0 === $resolved ? null : $inTerm / $resolved * 100.0;
    }

    /**
     * The money this department's people put on the record, in one direction, or
     * NULL where they recorded nothing that carries it.
     *
     * @param list<Incident> $incidents
     */
    private static function money(array $incidents, MoneyDirectionEnum $direction): ?int
    {
        $total = null;
        foreach ($incidents as $incident) {
            $money = $incident->getMoney();
            if (null === $money || $money->getDirection() !== $direction) {
                continue;
            }
            $total = ($total ?? 0) + $money->payable();
        }

        return $total;
    }
}
