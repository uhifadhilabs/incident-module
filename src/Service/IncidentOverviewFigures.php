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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Uhifadhi\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Model\IncidentOverview;
use Uhifadhi\Incident\Repository\IncidentCategoryRepository;
use Uhifadhi\Incident\Repository\IncidentRepository;

/**
 * BUILDS THIS MODULE'S READING OF ONE AREA'S MORNING, once per render.
 *
 * FIVE THINGS ASK FOR IT and they must agree: the four overview cards, the two
 * right-now tiles, the attention items, the open-incidents map layer and the
 * duty board all state the same numbers. So it is built once and MEMOISED per
 * (area, instant) — a page that drew the flow bar, the tile and the board would
 * otherwise measure the same register three times, seconds apart, and print
 * three answers.
 *
 * `$now` is handed IN rather than read from the clock, exactly as
 * {@see IncidentDashboardService} takes it: every card on the page is stated
 * relative to the SAME instant, and the whole thing is testable at a fixed
 * moment. It is also the memo key, which is why an hour later is a new answer
 * and a second later is not a new query.
 *
 * ── THE WINDOWS, AND WHY THEY DO NOT RECONCILE ─────────────────────────────
 * TODAY IS COMPARED WITH THE SAME WEEKDAY A WEEK AGO, never with yesterday: a
 * saturday in an area with a livestock market is a different kind of day from a
 * friday, and comparing the two would report the week's rhythm as a change in
 * the work. THE MONTH LIVES ON THE MODULE'S OWN DASHBOARD — the only monthly
 * figure here is the money signed off, and its own line says "this month".
 */
final class IncidentOverviewFigures
{
    /** How many rows the "latest incidents" card lists. The design's handful. */
    public const int LATEST_ROWS = 6;

    /** @var array<string, IncidentOverview> */
    private array $memo = [];

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentCategoryRepository $categories,
        private readonly UrlGeneratorInterface $router,
        private readonly string $currency,
    ) {
    }

    public function for(AreaOfInterest $area, \DateTimeImmutable $now): IncidentOverview
    {
        $key = $area->getUuidString().'@'.$now->format(\DateTimeInterface::ATOM);

        return $this->memo[$key] ??= $this->build($area, $now);
    }

    private function build(AreaOfInterest $area, \DateTimeImmutable $now): IncidentOverview
    {
        $day = $now->setTime(0, 0);
        $lastWeekDay = $day->modify('-7 days');
        $monthFrom = $day->modify('first day of this month');

        $open = $this->incidents->openFor($area);
        $unpaid = $this->incidents->outstandingMoneyFor($area);
        $terms = $this->incidents->termsInUseFor($area);

        $uuid = ['uuid' => $area->getUuidString()];
        $dashboardUrl = $this->router->generate('incident_dashboard', $uuid);
        $statusUrls = [];
        foreach (IncidentStatusEnum::ordered() as $place) {
            // The same query parameter IncidentFilter reads, so a segment of the
            // bar lands on the module's own list already narrowed to that place.
            $statusUrls[$place->value] = $this->router->generate('incident_dashboard', [...$uuid, 'status' => $place->value]);
        }

        $outstanding = [];
        foreach ($unpaid as $incident) {
            $money = $incident->getMoney();
            if (null === $money) {
                continue;
            }
            $outstanding[$money->getDirection()->value] = ($outstanding[$money->getDirection()->value] ?? 0) + $money->outstanding();
        }

        return new IncidentOverview(
            now: $now,
            day: $day,
            lastWeekDay: $lastWeekDay,
            currency: $this->currency,
            dashboardUrl: $dashboardUrl,
            total: $this->incidents->countFor($area),
            categories: $this->categories->allInOrder(),
            statusTally: $this->incidents->statusTallyFor($area),
            pastTerm: self::pastTerm($open, $now),
            medianHoursToVerify: self::median($this->incidents->hoursToVerifyFor($area)),
            shortestTerm: $terms[0] ?? null,
            longestTerm: [] === $terms ? null : $terms[\count($terms) - 1],
            today: $this->incidents->filedBetween($area, $day, $day->modify('+1 day')),
            lastWeek: $this->incidents->filedBetween($area, $lastWeekDay, $lastWeekDay->modify('+1 day')),
            closedOutToday: $this->incidents->countClosedOutBetween($area, $day, $day->modify('+1 day')),
            latest: $this->incidents->recentForArea($area, self::LATEST_ROWS),
            unpaid: $unpaid,
            outstanding: $outstanding,
            assessedMonth: $this->incidents->moneyAssessedBetween($area, $monthFrom, $monthFrom->modify('+1 month')),
            statusUrls: $statusUrls,
        );
    }

    /**
     * OPEN WORK PAST ITS OWN CATEGORY'S TERM, worst first — measured by how far
     * past, not by how old. A nine-day-old claim against a thirty-day term is
     * not late; a four-day-old injury against a 72-hour one is. One global SLA
     * would be a lie about both.
     *
     * @param list<Incident> $open
     *
     * @return list<Incident>
     */
    private static function pastTerm(array $open, \DateTimeImmutable $now): array
    {
        $late = array_values(array_filter($open, static fn (Incident $incident) => $incident->isPastTerm($now)));

        usort($late, static fn (Incident $a, Incident $b) => ($b->ageInHours($now) - $b->getSubcategory()->getTermHours())
            <=> ($a->ageInHours($now) - $a->getSubcategory()->getTermHours()));

        return $late;
    }

    /**
     * The MEDIAN and not the mean, exactly as the module's own dashboard takes
     * it: one incident nobody looked at for three weeks would drag a mean past
     * every honest reading of the area. Null where nothing has been verified —
     * ABSENT IS NOT ZERO, and "0 h" would claim everything was verified the
     * instant it was filed.
     *
     * @param list<float> $hours
     */
    private static function median(array $hours): ?float
    {
        if ([] === $hours) {
            return null;
        }

        sort($hours);
        $count = \count($hours);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($hours[$middle - 1] + $hours[$middle]) / 2 : $hours[$middle];
    }
}
