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

namespace Uhifadhi\Incident\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Performance\ColumnPolarity;
use Uhifadhi\Contracts\Performance\MatrixColumn;
use Uhifadhi\Incident\Module\IncidentPerformanceTopic;

/**
 * WHICH WAY IS GOOD, COLUMN BY COLUMN — the one thing a matrix cannot work
 * out for itself, and the one this module is most likely to get wrong.
 *
 * FILING IS NEITHER AN ACHIEVEMENT NOR A FAILURE. An area with more incidents
 * filed may simply be an area where people are reporting, which is the
 * behaviour this module exists to encourage; tinting a department for it, or
 * colouring the movement green or red, would teach exactly the wrong lesson.
 * The same goes for how many compensation claims arrived — that is how many
 * people asked, not how well anybody worked.
 */
#[CoversClass(IncidentPerformanceTopic::class)]
final class IncidentPerformanceTopicColumnsTest extends TestCase
{
    public function testTheFourColumnsTheDesignDraws(): void
    {
        self::assertSame(
            [
                IncidentPerformanceTopic::FILED,
                IncidentPerformanceTopic::OPEN_PAST_TARGET,
                IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE,
                IncidentPerformanceTopic::COMPENSATION_CLAIMS,
            ],
            array_map(static fn (MatrixColumn $c): string => $c->key, IncidentPerformanceTopic::columns()),
        );

        self::assertSame(
            ['Filed', 'Open past target', 'Median days to close', 'Compensation claims'],
            array_map(static fn (MatrixColumn $c): string => $c->label, IncidentPerformanceTopic::columns()),
        );
    }

    public function testEachColumnDeclaresItsOwnDirection(): void
    {
        self::assertSame(
            [
                IncidentPerformanceTopic::FILED => ColumnPolarity::None,
                IncidentPerformanceTopic::OPEN_PAST_TARGET => ColumnPolarity::Down,
                IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE => ColumnPolarity::Down,
                IncidentPerformanceTopic::COMPENSATION_CLAIMS => ColumnPolarity::None,
            ],
            self::polarities(),
        );
    }

    /**
     * A COLUMN WITH NO POLARITY IS NEVER TINTED AND ITS MOVEMENT IS NEVER
     * COLOURED. The host reads that off the column, so this asserts the two
     * questions it asks give the honest answer for both untinted columns —
     * whichever way the figure moved.
     */
    public function testTheColumnsWithNoDirectionMakeNoClaimAboutAnyMovement(): void
    {
        foreach ([IncidentPerformanceTopic::FILED, IncidentPerformanceTopic::COMPENSATION_CLAIMS] as $key) {
            $polarity = self::polarities()[$key];

            self::assertFalse($polarity->judges(), $key.' must never be tinted.');
            self::assertNull($polarity->isGood(7.0), $key.' must make no claim about a rise.');
            self::assertNull($polarity->isGood(-7.0), $key.' must make no claim about a fall.');
            self::assertNull($polarity->isGood(0.0), $key.' must make no claim about standing still.');
        }
    }

    /** And the judged columns do judge, the way the design's chips read. */
    public function testMoreOverdueWorkAndASlowerCloseAreBothBad(): void
    {
        foreach ([IncidentPerformanceTopic::OPEN_PAST_TARGET, IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE] as $key) {
            $polarity = self::polarities()[$key];

            self::assertTrue($polarity->judges(), $key.' is a column with a direction.');
            self::assertFalse((bool) $polarity->isGood(1.0), $key.' rising is bad news.');
            self::assertTrue((bool) $polarity->isGood(-1.0), $key.' falling is good news.');
        }
    }

    /** The time column carries its unit, or "8.8" is a number nobody can read. */
    public function testTheTimeColumnCarriesItsUnit(): void
    {
        $units = [];
        foreach (IncidentPerformanceTopic::columns() as $column) {
            $units[$column->key] = $column->unit;
        }

        self::assertSame('d', $units[IncidentPerformanceTopic::MEDIAN_DAYS_TO_CLOSE]);
        self::assertSame('', $units[IncidentPerformanceTopic::FILED]);
    }

    /** Every column says what it means, for the header's own title. */
    public function testEveryColumnSaysWhatItMeans(): void
    {
        foreach (IncidentPerformanceTopic::columns() as $column) {
            self::assertNotSame('', $column->caption, $column->key.' must say what it counts.');
        }
    }

    /** @return array<string, ColumnPolarity> */
    private static function polarities(): array
    {
        $polarities = [];
        foreach (IncidentPerformanceTopic::columns() as $column) {
            $polarities[$column->key] = $column->polarity;
        }

        return $polarities;
    }
}
