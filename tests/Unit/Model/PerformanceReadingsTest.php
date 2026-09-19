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

namespace Uhifadhi\Incident\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Model\IncidentReading;
use Uhifadhi\Incident\Model\IncidentTopicSlice;
use Uhifadhi\Incident\Model\PerformanceReadings;

/**
 * THE ARITHMETIC BEHIND THE INCIDENTS TOPIC, proved without a database.
 *
 * Every figure the performance page prints is a reading of a list of records:
 * how many arrived, how many are overdue, how long closing took, how many
 * claims came in and what got finished.
 *
 * NOBODY IS IN ANY OF IT. Figures follow scope, not people — what narrows a
 * list is the ground it was recorded on, which {@see IncidentTopicSlice}
 * resolves, and never who filed it.
 */
#[CoversClass(PerformanceReadings::class)]
#[CoversClass(IncidentReading::class)]
final class PerformanceReadingsTest extends TestCase
{
    private const string FILED = '2026-08-01 08:00:00';

    public function testItCountsWhatArrived(): void
    {
        self::assertSame(3, $this->readings(self::one(), self::one(), self::one())->filed());
        self::assertSame(0, PerformanceReadings::none()->filed());
        self::assertTrue(PerformanceReadings::none()->isEmpty());
    }

    /**
     * PAST TARGET MEANS STILL OPEN AND ALREADY OVER. Work that was finished
     * late is not part of the backlog, however late it was.
     */
    public function testOnlyOpenWorkThatHasRunOverCountsAsPastTarget(): void
    {
        $at = new \DateTimeImmutable('2026-08-20 08:00:00');

        $readings = $this->readings(
            self::one(termHours: 72),                                             // open, 19 days: over
            self::one(termHours: 720),                                            // open, 19 days: inside
            self::one(termHours: 24, resolvedAt: '2026-08-15 08:00:00'),          // finished late, but finished
        );

        self::assertSame(1, $readings->openPastTarget($at));
    }

    /** A median over no finished work is NULL — never nought days. */
    public function testTheMedianIsNullWhileNothingHasBeenFinished(): void
    {
        self::assertNull($this->readings(self::one(), self::one())->medianDaysToClose());
    }

    public function testTheMedianIsTheMiddleTimeToClose(): void
    {
        $readings = $this->readings(
            self::one(resolvedAt: '2026-08-03 08:00:00'),   // 2 days
            self::one(resolvedAt: '2026-08-05 08:00:00'),   // 4 days
            self::one(resolvedAt: '2026-08-11 08:00:00'),   // 10 days
        );

        self::assertSame(4.0, $readings->medianDaysToClose());
    }

    /** An even count takes the mean of the two middles. */
    public function testAnEvenCountAveragesTheTwoMiddleTimes(): void
    {
        $readings = $this->readings(
            self::one(resolvedAt: '2026-08-03 08:00:00'),   // 2
            self::one(resolvedAt: '2026-08-05 08:00:00'),   // 4
            self::one(resolvedAt: '2026-08-07 08:00:00'),   // 6
            self::one(resolvedAt: '2026-08-11 08:00:00'),   // 10
        );

        self::assertSame(5.0, $readings->medianDaysToClose());
    }

    /**
     * TWO CLAIM FIGURES AND THEY ARE DIFFERENT QUESTIONS: how many arrived,
     * and how many are still waiting on somebody.
     */
    public function testClaimsFiledCountsIntakeAndClaimsOpenCountsTheQueue(): void
    {
        $readings = $this->readings(
            self::one(claimed: true, claimOutstanding: true),
            self::one(claimed: true, claimOutstanding: false),
            self::one(),
        );

        self::assertSame(2, $readings->claimsFiled());
        self::assertSame(1, $readings->claimsOpen());
    }

    public function testItCountsWhatWasFinished(): void
    {
        self::assertSame(
            1,
            $this->readings(self::one(resolvedAt: '2026-08-04 08:00:00'), self::one())->resolved(),
        );
    }

    /** The distinct terms these records promised, in days, smallest first. */
    public function testItNamesTheTermsTheseRecordsWereJudgedAgainst(): void
    {
        $readings = $this->readings(
            self::one(termHours: 168),
            self::one(termHours: 240),
            self::one(termHours: 168),
        );

        self::assertSame([7.0, 10.0], $readings->termsInDays());
    }

    /**
     * FOUR BUCKETS, OPEN WORK ONLY: 0–7 days, 8–14, 15–21 and over three
     * weeks. Finished work has stopped ageing and is not in the backlog.
     */
    public function testTheAgeBucketsHoldTheOpenBacklogOnly(): void
    {
        $at = new \DateTimeImmutable('2026-09-01 08:00:00');

        $readings = new PerformanceReadings([
            new IncidentReading(new \DateTimeImmutable('2026-08-30 08:00:00'), null, 72, true),   // 2 d
            new IncidentReading(new \DateTimeImmutable('2026-08-22 08:00:00'), null, 72, true),   // 10 d
            new IncidentReading(new \DateTimeImmutable('2026-08-15 08:00:00'), null, 72, true),   // 17 d
            new IncidentReading(new \DateTimeImmutable('2026-07-01 08:00:00'), null, 72, true),   // 62 d
            new IncidentReading(new \DateTimeImmutable('2026-07-01 08:00:00'), new \DateTimeImmutable('2026-07-02 08:00:00'), 72, false),
        ]);

        self::assertSame([1, 1, 1, 1], $readings->openAgeBuckets($at));
    }

    private function readings(IncidentReading ...$readings): PerformanceReadings
    {
        return new PerformanceReadings(array_values($readings));
    }

    private static function one(
        ?string $resolvedAt = null,
        int $termHours = 168,
        bool $claimed = false,
        bool $claimOutstanding = false,
    ): IncidentReading {
        return new IncidentReading(
            reportedAt: new \DateTimeImmutable(self::FILED),
            resolvedAt: null === $resolvedAt ? null : new \DateTimeImmutable($resolvedAt),
            termHours: $termHours,
            open: null === $resolvedAt,
            claimed: $claimed,
            claimOutstanding: $claimOutstanding,
        );
    }
}
