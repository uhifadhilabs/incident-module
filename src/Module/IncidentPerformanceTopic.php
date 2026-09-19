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

use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Contracts\Kpi\FigurePeriod;
use Uhifadhi\Contracts\Performance\ChartKind;
use Uhifadhi\Contracts\Performance\ChartSeries;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixCell;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Contracts\Performance\MatrixRow;
use Uhifadhi\Contracts\Performance\PerformanceScope;
use Uhifadhi\Contracts\Performance\PerformanceTopicProviderInterface;
use Uhifadhi\Contracts\Performance\TopicChart;
use Uhifadhi\Contracts\Performance\TopicKpi;
use Uhifadhi\Contracts\Performance\TopicMatrix;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Model\IncidentReading;
use Uhifadhi\Incident\Model\PerformanceReadings;
use Uhifadhi\Incident\Repository\IncidentDepartmentLens;
use Uhifadhi\Incident\Repository\IncidentRepository;
use Uhifadhi\Incident\Repository\TaxonomySubcategoryRepository;

/**
 * THE INCIDENTS TOPIC ON THE PERFORMANCE PAGE — five figures, two charts and
 * the departments that read this module.
 *
 * A TOPIC, NOT A COLUMN. The board this replaces put one module's columns
 * against every department, and half the cells were about departments that had
 * never attached the module. This publishes its own section instead: what was
 * filed, what is overdue, how long closing takes, what compensation is waiting
 * and what got finished — and a matrix whose ROWS ARE ONLY THE DEPARTMENTS
 * THAT ATTACH INCIDENTS. A department that does not read this module is not a
 * row of dashes here; it is not a row.
 *
 * ── WHAT A DEPARTMENT'S FIGURES ARE ──────────────────────────────────────────
 * DEPARTMENT-AS-A-LENS, exactly as the record states it: an incident belongs to
 * its AREA, and a department's figures are the incidents WHOSE RECORDING
 * POSITION SITS IN THAT DEPARTMENT. Nothing here restricts anybody's reading —
 * two departments in one area see the same incidents on every screen in the
 * product — this is a way of totting up who was working, and it is the only
 * slicing this topic does. {@see IncidentDepartmentLens} makes that walk.
 *
 * A row nobody seated filed — a seeded or imported one, or one filed by
 * somebody holding no position — is counted in the HEADLINE figures and in no
 * department's row. So the rows may add up to less than the headline, and
 * that difference is a real fact about the records rather than an arithmetic
 * error.
 *
 * ── WHERE THE HISTORY COMES FROM ─────────────────────────────────────────────
 * OUT OF THIS MODULE'S OWN RECORDS, period by period — never out of a
 * remembered figure. The host's own topics read what was written down in a
 * closed period because seats and goals cannot be recomputed; an incident can:
 * it carries when it was filed, when it was finished and what its sub-category
 * promised, so every one of the six periods behind a sparkline is recomputed
 * from the rows themselves. Nothing is stored for this page, and a figure is
 * never stale.
 *
 * ── THE THREE ABSENCES, KEPT APART ───────────────────────────────────────────
 *  - A PERIOD THE SCOPE HOLDS NO RECORD IN AT ALL is a HOLE: null in the
 *    history, and the chart draws a gap. Nobody was recording; nought would say
 *    they recorded nothing.
 *  - A FIGURE THAT CANNOT BE COMPUTED is NULL: a median time to close over a
 *    period where nothing was finished is not nought days.
 *  - A COLUMN A DEPARTMENT WAS NEVER ASKED is {@see MatrixCell::notMine()}:
 *    compensation claims, where the department's areas have written no
 *    compensation-bearing sub-category at all. Where they have and nobody
 *    claimed, the cell is a real nought.
 *
 * ── POLARITY ─────────────────────────────────────────────────────────────────
 * FILING IS NEITHER GOOD NOR BAD and says so ({@see ColumnPolarity::None}): an
 * area with more incidents filed may simply be an area where people are
 * reporting, which is the behaviour this module exists to encourage, and
 * tinting a department for it would teach exactly the wrong lesson. The same
 * goes for how many compensation claims arrived. Overdue work and a slower
 * close are {@see ColumnPolarity::Down}; finished work is
 * {@see ColumnPolarity::Up}.
 */
final readonly class IncidentPerformanceTopic implements PerformanceTopicProviderInterface
{
    /** The topic's own address, in the URL and in the page's order. */
    public const string KEY = 'incidents';

    public const string FILED = 'incidents.filed';
    public const string OPEN_PAST_TARGET = 'incidents.open_past_target';
    public const string MEDIAN_DAYS_TO_CLOSE = 'incidents.median_days_to_close';
    public const string COMPENSATION_CLAIMS = 'incidents.compensation_claims';
    public const string CLAIMS_OPEN = 'incidents.claims_open';
    public const string RESOLVED = 'incidents.resolved';

    /** What a sparkline is drawn over, and the run the flow chart uses. */
    private const int PERIODS = 6;

    /** The upper edge of the first three age buckets, in days. */
    private const array AGE_LABELS = ['0–7 d', '8–14 d', '15–21 d', 'over 21 d'];

    public function __construct(
        private IncidentRepository $incidents,
        private IncidentDepartmentLens $lens,
        private DepartmentRepository $departments,
        private TaxonomySubcategoryRepository $subcategories,
        /** The slug this module is registered under in the host's catalogue. */
        private string $slug,
        private string $name = 'Incidents',
    ) {
    }

    public function moduleSlug(): string
    {
        return $this->slug;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return $this->name;
    }

    /**
     * FIVE, AND ALWAYS FIVE — filed, open past target, the median time to
     * close, compensation claims still open, and what was finished.
     *
     * @return list<TopicKpi>
     */
    public function kpis(PerformanceScope $scope, FigurePeriod $period): array
    {
        $run = $this->run($scope, $period);
        $latest = $run[self::PERIODS - 1];

        return [
            self::figure($run, self::FILED, 'Filed', ColumnPolarity::None,
                static fn (array $p): float => (float) $p['filed']->filed(),
                caption: $this->splitCaption($scope, $latest['filed']),
            ),
            self::figure($run, self::OPEN_PAST_TARGET, 'Open', ColumnPolarity::Down,
                static fn (array $p): float => (float) $p['filed']->openPastTarget($p['period']->until),
                caption: 'past their target',
            ),
            self::figure($run, self::MEDIAN_DAYS_TO_CLOSE, 'Median days to close', ColumnPolarity::Down,
                static fn (array $p): ?float => $p['resolved']->medianDaysToClose(),
                caption: self::targetsCaption($latest['resolved']),
                unit: 'd',
            ),
            self::figure($run, self::CLAIMS_OPEN, 'Claims open', ColumnPolarity::Down,
                static fn (array $p): float => (float) $p['filed']->claimsOpen(),
                caption: 'compensation neither settled nor waived',
            ),
            self::figure($run, self::RESOLVED, 'Resolved', ColumnPolarity::Up,
                static fn (array $p): float => (float) $p['resolved']->filed(),
                caption: 'this period',
            ),
        ];
    }

    /**
     * TWO CHARTS, both stated as shapes: the run of filing against closing,
     * and how long the work still open has been open.
     *
     * @return list<TopicChart>
     */
    public function charts(PerformanceScope $scope, FigurePeriod $period): array
    {
        $run = $this->run($scope, $period);

        $flow = new TopicChart(
            key: 'incidents.flow',
            title: 'Filed against closed, per period',
            kind: ChartKind::Line,
            labels: array_map(
                static fn (array $p): string => mb_strtolower($p['period']->from->format('M')),
                $run,
            ),
            series: [
                new ChartSeries('Filed', self::series($run, static fn (array $p): float => (float) $p['filed']->filed())),
                new ChartSeries('Closed', self::series($run, static fn (array $p): float => (float) $p['resolved']->filed())),
            ],
            caption: 'Recomputed from the records of each period — a period nobody filed in is a gap, not a nought.',
        );

        $open = $this->readingsOf($this->incidents->findOpenByScope($scope->areaUuid));
        $buckets = $open->openAgeBuckets($period->until);
        $age = new TopicChart(
            key: 'incidents.age',
            title: 'How long an open incident has been open',
            kind: ChartKind::Bar,
            labels: self::AGE_LABELS,
            // NOTHING OPEN IS NOT FOUR NOUGHTS. A backlog nobody has is an
            // absence of bars, and the chart drops itself rather than drawing
            // an empty floor that reads as a measured one.
            series: [new ChartSeries(
                'Open incidents',
                $open->isEmpty()
                    ? [null, null, null, null]
                    : array_map(static fn (int $n): float => (float) $n, $buckets),
            )],
            unit: 'incidents',
            caption: 'The backlog as it stands, whenever each one was filed.',
        );

        return [$flow, $age];
    }

    public function matrix(PerformanceScope $scope, FigurePeriod $period): TopicMatrix
    {
        $run = $this->run($scope, $period);
        $columns = self::columns();

        $rows = [];
        foreach ($this->departmentsIn($scope) as $department) {
            $id = $department->getId();
            if (null === $id) {
                continue;
            }

            $area = $department->getArea();
            $moneyScope = $area?->getUuidString() ?? $scope->areaUuid;
            $runsCompensation = $this->subcategories->scopeRunsMoney($moneyScope, MoneyDirectionEnum::Compensation);

            /** @var list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}> $mine */
            $mine = array_map(
                static fn (array $p): array => [
                    'period' => $p['period'],
                    'filed' => $p['filed']->forDepartment($id),
                    'resolved' => $p['resolved']->forDepartment($id),
                    // The SCOPE's silence, carried through unchanged.
                    'silent' => $p['silent'],
                ],
                $run,
            );
            $cells = [
                self::FILED => self::cell($mine, static fn (array $p): float => (float) $p['filed']->filed()),
                self::OPEN_PAST_TARGET => self::cell($mine, static fn (array $p): float => (float) $p['filed']->openPastTarget($p['period']->until)),
                self::MEDIAN_DAYS_TO_CLOSE => self::cell($mine, static fn (array $p): ?float => $p['resolved']->medianDaysToClose()),
                // THE COLUMN A DEPARTMENT WAS NEVER ASKED. Its areas run no
                // compensation-bearing sub-category, so there is no claim it
                // could have filed — a dash, never a nought.
                self::COMPENSATION_CLAIMS => $runsCompensation
                    ? self::cell($mine, static fn (array $p): float => (float) $p['filed']->claimsFiled())
                    : MatrixCell::notMine(),
            ];

            $rows[] = new MatrixRow(
                departmentUuid: (string) $department->getUuidString(),
                departmentName: (string) $department->getName(),
                cells: $cells,
                band: null === $area ? 'Org-wide' : (string) $area->getName(),
            );
        }

        return new TopicMatrix(
            $columns,
            $rows,
            'Only the departments that attach Incidents are rows, and a department’s figures are the incidents whose recording position sits in it.',
        );
    }

    /**
     * THE FOUR COLUMNS AND THEIR DIRECTIONS, stated once so a test can read
     * them without a database.
     *
     * @return list<MatrixColumn>
     */
    public static function columns(): array
    {
        return [
            new MatrixColumn(
                self::FILED,
                'Filed',
                polarity: ColumnPolarity::None,
                caption: 'Incidents recorded in the period. Filing is neither an achievement nor a failure, so this is never tinted.',
            ),
            new MatrixColumn(
                self::OPEN_PAST_TARGET,
                'Open past target',
                polarity: ColumnPolarity::Down,
                caption: 'Still open, and already past what their sub-category promised.',
            ),
            new MatrixColumn(
                self::MEDIAN_DAYS_TO_CLOSE,
                'Median days to close',
                unit: 'd',
                polarity: ColumnPolarity::Down,
                caption: 'The middle time from filing to resolution, over the work finished in the period.',
            ),
            new MatrixColumn(
                self::COMPENSATION_CLAIMS,
                'Compensation claims',
                polarity: ColumnPolarity::None,
                caption: 'Claims that arrived in the period. How many people asked is not a score, so this is never tinted.',
            ),
        ];
    }

    /**
     * SIX PERIODS OF THE SCOPE'S RECORDS, oldest first and ending with the one
     * asked about — each recomputed from the rows themselves.
     *
     * TWO SETS PER PERIOD, because what was FILED in a period and what was
     * FINISHED in it are different questions, and a page that derived one from
     * the other could only report the overlap.
     *
     * @return list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}>
     */
    private function run(PerformanceScope $scope, FigurePeriod $period): array
    {
        $periods = [$period];
        while (\count($periods) < self::PERIODS) {
            $periods[] = $periods[\count($periods) - 1]->previous();
        }
        $periods = array_reverse($periods);

        $run = [];
        foreach ($periods as $window) {
            $filed = $this->readingsOf($this->incidents->findByScopeBetween($scope->areaUuid, $window->from, $window->until));
            $resolved = $this->readingsOf($this->incidents->findResolvedByScopeBetween($scope->areaUuid, $window->from, $window->until));

            $run[] = [
                'period' => $window,
                'filed' => $filed,
                'resolved' => $resolved,
                // SILENCE IS THE SCOPE'S, NEVER A DEPARTMENT'S. A period the
                // whole scope holds no record in is a hole in every history
                // drawn over it; a department that filed nothing in a period
                // the scope WAS recording in scored a real nought, and the two
                // must not be drawn the same way.
                'silent' => $filed->isEmpty() && $resolved->isEmpty(),
            ];
        }

        return $run;
    }

    /**
     * THE ROWS, REDUCED TO WHAT A FIGURE IS MADE OF — and the recorder walked
     * to a department, once for the whole set rather than once per row.
     *
     * @param list<Incident> $incidents
     */
    private function readingsOf(array $incidents): PerformanceReadings
    {
        $recorders = [];
        foreach ($incidents as $incident) {
            $id = $incident->getReportedBy()?->getId();
            if (null !== $id) {
                $recorders[] = $id;
            }
        }
        $byUser = $this->lens->departmentByUser(array_values(array_unique($recorders)));

        $readings = [];
        foreach ($incidents as $incident) {
            $subcategory = $incident->getSubcategory();
            // A CLAIM IS FILED BY THE WORDS, NOT BY THE MONEY CARD. A
            // compensation-bearing sub-category means somebody has asked; the
            // money row is what the assessment writes afterwards, and a claim
            // nobody has costed yet is still a claim.
            $claimed = MoneyDirectionEnum::Compensation === $subcategory->getMoneyDirection();
            $money = $incident->getMoney();
            $recorder = $incident->getReportedBy()?->getId();

            $readings[] = new IncidentReading(
                reportedAt: $incident->getReportedAt(),
                resolvedAt: $incident->getResolvedAt(),
                termHours: $subcategory->getTermHours(),
                open: $incident->getStatus()->isOpen(),
                claimed: $claimed,
                claimOutstanding: $claimed && (null === $money || (!$money->isSettled() && !$money->isWaived())),
                departmentId: null === $recorder ? null : ($byUser[$recorder] ?? null),
            );
        }

        return new PerformanceReadings($readings);
    }

    /**
     * THE DEPARTMENTS THAT READ THIS MODULE, in the scope asked about: an
     * area's own departments and the organisation-wide ones, which is what
     * "reads this area" means everywhere else in the product.
     *
     * @return list<Department>
     */
    private function departmentsIn(PerformanceScope $scope): array
    {
        $reading = [];
        foreach ($this->departments->findAllActiveOrdered() as $department) {
            if (!$this->attachesThisModule($department)) {
                continue;
            }

            $area = $department->getArea();
            if (!$scope->isOrganisation() && null !== $area && $scope->areaUuid !== $area->getUuidString()) {
                continue;
            }

            $reading[] = $department;
        }

        return $reading;
    }

    private function attachesThisModule(Department $department): bool
    {
        foreach ($department->getModules() as $module) {
            if ($this->slug === (string) $module->getSlug()) {
                return true;
            }
        }

        return false;
    }

    /**
     * ONE HEADLINE FIGURE, with its movement and its run — the value read out
     * of the history's last point rather than computed a second time, so a
     * period the scope was silent in reads "no figure" on the card and as a
     * hole in the sparkline instead of disagreeing with itself.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}): ?float $reading
     */
    private static function figure(
        array $run,
        string $key,
        string $label,
        ColumnPolarity $polarity,
        callable $reading,
        string $caption = '',
        string $unit = '',
    ): TopicKpi {
        $history = self::series($run, $reading);

        return new TopicKpi(
            key: $key,
            label: $label,
            value: $history[self::PERIODS - 1],
            unit: $unit,
            delta: self::delta($run, $reading),
            history: $history,
            caption: $caption,
            polarity: $polarity,
        );
    }

    /**
     * ONE CELL, with its movement and its run — and a hole wherever the scope
     * holds no record at all for that period.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}): ?float $reading
     */
    private static function cell(array $run, callable $reading): MatrixCell
    {
        $history = self::series($run, $reading);

        return new MatrixCell(
            value: $history[self::PERIODS - 1],
            delta: self::delta($run, $reading),
            history: $history,
        );
    }

    /**
     * SIX POINTS, OLDEST FIRST, HOLES KEPT. A period the scope holds nothing
     * in at all is null — nobody was recording, which is not the same fact as
     * recording nothing.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}): ?float $reading
     *
     * @return list<float|null>
     */
    private static function series(array $run, callable $reading): array
    {
        $points = [];
        foreach ($run as $snapshot) {
            $points[] = $snapshot['silent'] ? null : $reading($snapshot);
        }

        return $points;
    }

    /**
     * THE MOVEMENT ON THE PERIOD BEFORE, and null where either end of the
     * comparison has no figure — "it moved" is a claim, and a claim needs two
     * readings.
     *
     * @param list<array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}>             $run
     * @param callable(array{period: FigurePeriod, filed: PerformanceReadings, resolved: PerformanceReadings, silent: bool}): ?float $reading
     */
    private static function delta(array $run, callable $reading): ?float
    {
        $history = self::series($run, $reading);
        $now = $history[self::PERIODS - 1];
        $was = $history[self::PERIODS - 2];

        return null === $now || null === $was ? null : $now - $was;
    }

    /**
     * WHO THE HEADLINE IS MADE OF — "24 Protection Service · 37 Community
     * Development", the split the design prints under the filed figure. A
     * department that recorded nothing is left out; so is every row nobody
     * seated filed, which is why the parts can add up to less than the whole.
     */
    private function splitCaption(PerformanceScope $scope, PerformanceReadings $filed): string
    {
        $counts = $filed->filedByDepartment();
        if ([] === $counts) {
            return 'nobody seated recorded any of these';
        }

        $parts = [];
        foreach ($this->departmentsIn($scope) as $department) {
            $id = $department->getId();
            if (null !== $id && ($counts[$id] ?? 0) > 0) {
                $parts[] = \sprintf('%d %s', $counts[$id], (string) $department->getName());
            }
        }

        return [] === $parts ? 'nobody seated recorded any of these' : implode(' · ', $parts);
    }

    /**
     * WHAT THE MEDIAN WAS PROMISED AGAINST. A duration printed without its
     * term is unreadable, and the term is the sub-category's rather than a
     * setting anybody could name here.
     */
    private static function targetsCaption(PerformanceReadings $resolved): string
    {
        $terms = $resolved->termsInDays();
        if ([] === $terms) {
            return 'nothing finished in this period';
        }

        return \sprintf(
            'targets %s',
            implode(' and ', array_map(static fn (float $days): string => rtrim(rtrim(number_format($days, 1, '.', ''), '0'), '.'), $terms)),
        );
    }
}
