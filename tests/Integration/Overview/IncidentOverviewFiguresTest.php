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

namespace Uhifadhi\Incident\Tests\Integration\Overview;

use Uhifadhi\Incident\Entity\Incident;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;
use Uhifadhi\Incident\Enum\MoneyDirectionEnum;
use Uhifadhi\Incident\Service\IncidentOverviewFigures;
use Uhifadhi\Incident\Tests\Integration\OverviewTestCase;

/**
 * WHAT THE FOUR OVERVIEW CARDS READ, against a real register in a real database.
 *
 * Every figure here is asserted against rows the module itself filed and moved,
 * because the whole point of these cards is that they are the module's own
 * answer about one area — not a mock's.
 */
final class IncidentOverviewFiguresTest extends OverviewTestCase
{
    public function testTheFlowBarCountsTheWholeRegisterByWhereEachIncidentIsNow(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertSame(8, $overview->total);
        self::assertSame(1, $overview->count(IncidentStatusEnum::Reported));
        self::assertSame(3, $overview->count(IncidentStatusEnum::Verified));
        self::assertSame(3, $overview->count(IncidentStatusEnum::InProgress));
        self::assertSame(1, $overview->count(IncidentStatusEnum::Resolved));
        self::assertSame(0, $overview->count(IncidentStatusEnum::Closed));
        // "Open" is the first three places and nothing else — resolved work is
        // finished work even before the clock closes it.
        self::assertSame(7, $overview->openCount());
    }

    /**
     * EVERY PLACE IS PRESENT, at zero where nothing is there. A bar that dropped
     * an empty segment would redraw itself on a quiet week and a reader would
     * think the workflow had changed.
     */
    public function testTheFlowBarKeepsAPlaceNothingIsSittingIn(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertCount(5, $overview->places());
        self::assertSame(0, $overview->count(IncidentStatusEnum::Closed));
    }

    public function testPastTermIsMeasuredAgainstEachIncidentsOwnCategoryTermWorstFirst(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        // The 72-hour snaring fine filed on the 5th is worse than the 72-hour
        // crop raid filed on the 10th; the 30-day depredation claim filed in July
        // is 27 days old and is NOT late, which one global SLA would have called.
        self::assertSame(
            ['INC-0008', 'INC-0006'],
            array_map(static fn (Incident $incident) => $incident->getReference(), $overview->pastTerm),
        );
    }

    public function testTodayIsMeasuredAgainstTheSameWeekdayAWeekAgo(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertCount(3, $overview->today);
        // Saturday 22 august against saturday 15 august — never against friday,
        // which is a different kind of day in an area with a market.
        self::assertSame('2026-08-15', $overview->lastWeekDay->format('Y-m-d'));
        self::assertCount(1, $overview->lastWeek);
    }

    public function testTodayIsBrokenDownByCategoryAndByZone(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        $byCategory = [];
        foreach ($overview->categories as $category) {
            $byCategory[$category->getSlug()] = \count($overview->todayFor($category));
        }
        self::assertSame(
            ['poaching' => 1, 'conflict' => 1, 'compliance' => 0, 'mortality' => 1],
            $byCategory,
        );

        self::assertSame(['North Gate' => 2, 'West Plains' => 1], $overview->todayZones());
    }

    public function testItCountsWhatWasClosedOutTodayRatherThanWhatWasFiled(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        // The crop raid filed last saturday was resolved this morning: filed on a
        // different day, finished on this one.
        self::assertSame(1, $overview->closedOutToday);
    }

    public function testTheTwoDirectionsOfMoneyAreCountedSeparatelyAndNeverSummed(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertSame(2_550_000, $overview->outstanding(MoneyDirectionEnum::Fine));
        self::assertSame(4_500_000, $overview->outstanding(MoneyDirectionEnum::Compensation));
    }

    public function testTheOldestUnpaidClaimIsTheCompensationNobodyHasPaid(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        $oldest = $overview->oldestUnpaid();
        self::assertNotNull($oldest);
        self::assertSame('INC-0007', $oldest->getReference());
        self::assertSame(1, $overview->openClaims());
        self::assertSame(1, $overview->claimZones());
    }

    /**
     * ABSENT IS NOT ZERO. Nothing has been verified in this area, so the median
     * is null — and the card draws an em dash rather than "0 h", which would say
     * that everything was verified the instant it was filed.
     */
    public function testTheMedianTimeToVerifyIsAbsentRatherThanZeroWhenNothingHasBeenVerified(): void
    {
        $area = $this->anArea();
        $this->installTaxonomy();
        $this->anIncident($area, 'snaring', 'Snare line at the forest edge', self::now()->modify('-2 hours'));

        $overview = $this->figures()->for($area, self::now());

        self::assertNull($overview->medianHoursToVerify);
    }

    /**
     * THE TERMS ARE THE TAXONOMY'S OWN. The card says what the shortest and the
     * longest promise are, by name, because one global SLA would be a lie about
     * every category at once — so the two are read from the register, never typed.
     */
    public function testItNamesTheShortestAndLongestTermTheRegisterActuallyCarries(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertNotNull($overview->shortestTerm);
        self::assertNotNull($overview->longestTerm);
        self::assertSame(72, $overview->shortestTerm->getTermHours());
        self::assertSame(720, $overview->longestTerm->getTermHours());
    }

    public function testTheLatestCardIsTheNewestHandfulNewestFirst(): void
    {
        $area = $this->aRegister();

        $overview = $this->figures()->for($area, self::now());

        self::assertCount(IncidentOverviewFigures::LATEST_ROWS, $overview->latest);
        self::assertSame('INC-0003', $overview->latest[0]->getReference());
    }

    /**
     * An area nobody has filed anything in has NOTHING TO SAY, and says so — the
     * cards render their absent state rather than a page of zeroes.
     */
    public function testAnAreaWithNoRegisterAtAllReportsItselfEmpty(): void
    {
        $area = $this->anArea('Quiet Area');
        $this->installTaxonomy();

        $overview = $this->figures()->for($area, self::now());

        self::assertSame(0, $overview->total);
        self::assertSame([], $overview->today);
        self::assertSame([], $overview->pastTerm);
        self::assertNull($overview->oldestUnpaid());
        self::assertSame(0, $overview->outstanding(MoneyDirectionEnum::Fine));
    }

    private function figures(): IncidentOverviewFigures
    {
        $figures = $this->service('incident.overview.figures');
        self::assertInstanceOf(IncidentOverviewFigures::class, $figures);

        return $figures;
    }
}
