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
use Uhifadhi\Contracts\Performance\GeoFigure;
use Uhifadhi\Contracts\Performance\GeoSeries;
use Uhifadhi\Incident\Module\IncidentPerformanceGeo;

/**
 * WHAT THE TWO PLATES CLAIM ABOUT THEMSELVES, read without a database —
 * which ground each is over, and which way is good.
 *
 * A PLATE HUES A PLACING, AND A HUE IS A CLAIM. Filing is neither an
 * achievement nor a failure: an area with more incidents filed may simply be
 * an area where people report, which is the behaviour this module exists to
 * encourage. A plate that painted it green or red would teach the opposite,
 * so both series say {@see ColumnPolarity::None} and neither is ever tinted.
 *
 * AND A SERIES SAYS WHAT IT IS OVER. Areas and the zones of one area are two
 * different plates; a zone series that did not name its area would leave the
 * page matching uuids against two tables to find out which it was handed.
 */
#[CoversClass(IncidentPerformanceGeo::class)]
final class IncidentPerformanceGeoSeriesTest extends TestCase
{
    private const string AREA = '0198f0a4-1b2c-7000-8000-000000000001';

    public function testTheAreaPlateIsOverAreasAndNamesNoArea(): void
    {
        $series = IncidentPerformanceGeo::areaSeries([new GeoFigure('uuid-north', 'North Sector', 4.0)]);

        self::assertSame(IncidentPerformanceGeo::BY_AREA, $series->key);
        self::assertSame(GeoSeries::OVER_AREAS, $series->over);
        // A series over every area is about no one area, and saying otherwise
        // would have the page drawing one area's zones from an area plate.
        self::assertNull($series->areaUuid);
        self::assertSame('Incidents filed, by area', $series->title);
    }

    public function testTheZonePlateIsOverTheZonesOfOneNamedArea(): void
    {
        $series = IncidentPerformanceGeo::zoneSeries(self::AREA, [new GeoFigure('uuid-ridge', 'Ridge', 2.0)]);

        self::assertSame(IncidentPerformanceGeo::BY_ZONE, $series->key);
        self::assertSame(GeoSeries::OVER_ZONES, $series->over);
        self::assertSame(self::AREA, $series->areaUuid);
        self::assertSame('Incidents filed, by zone', $series->title);
    }

    /** Filing is neither good nor bad, on either plate. */
    public function testNeitherPlateJudgesFiling(): void
    {
        foreach ([IncidentPerformanceGeo::areaSeries([]), IncidentPerformanceGeo::zoneSeries(self::AREA, [])] as $series) {
            self::assertSame(ColumnPolarity::None, $series->polarity);
            self::assertFalse($series->polarity->judges());
        }
    }

    /** A legend prints a number, so the number carries its word. */
    public function testBothPlatesCarryTheirUnit(): void
    {
        self::assertSame('incidents', IncidentPerformanceGeo::areaSeries([])->unit);
        self::assertSame('incidents', IncidentPerformanceGeo::zoneSeries(self::AREA, [])->unit);
    }

    /**
     * THE THREE ABSENCES, KEPT APART, as the plate sees them.
     *
     * A series with no figures at all is ground this module said nothing
     * about; a figure with a null value is ground it knows and could not
     * measure; and a nought is a reading. Only the first two are empty.
     */
    public function testAPlateOfNothingAndAPlateOfUnknownsAreBothEmptyWhileANoughtIsARead(): void
    {
        self::assertTrue(IncidentPerformanceGeo::areaSeries([])->isEmpty());
        self::assertTrue(IncidentPerformanceGeo::zoneSeries(self::AREA, [
            new GeoFigure('uuid-ridge', 'Ridge', null),
            new GeoFigure('uuid-plain', 'Plain', null),
        ])->isEmpty());

        self::assertFalse(IncidentPerformanceGeo::zoneSeries(self::AREA, [
            new GeoFigure('uuid-ridge', 'Ridge', 0.0),
        ])->isEmpty());
    }
}
