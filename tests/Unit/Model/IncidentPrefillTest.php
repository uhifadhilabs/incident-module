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

namespace UhifadhiLabs\Incident\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use UhifadhiLabs\Incident\Model\IncidentPrefill;

/**
 * WHAT THE SOURCE CARD READS.
 *
 * A filing that arrives from an observation shows that observation: its label,
 * its own words, its time and — the part with a right answer — its POSITION, in
 * the same degrees-minutes-seconds notation the observation page prints. Two
 * records that describe the same place must not describe it two different ways,
 * so the notation is pinned here by test.
 */
final class IncidentPrefillTest extends TestCase
{
    /** The design's own example: the observation at 3°12'05"S 35°27'44"E. */
    public function testThePositionReadsAsDegreesMinutesSecondsLikeTheObservationPage(): void
    {
        $prefill = new IncidentPrefill(latitude: -3.2014, longitude: 35.4622);

        self::assertSame('3°12\'05"S 35°27\'44"E', $prefill->positionLabel());
    }

    /** North and east are the other half of the compass, and are not assumed. */
    public function testTheHemispheresAreReadFromTheSignOfEachCoordinate(): void
    {
        $prefill = new IncidentPrefill(latitude: 3.2014, longitude: -35.4622);

        self::assertSame('3°12\'05"N 35°27\'44"W', $prefill->positionLabel());
    }

    /**
     * Seconds that round to sixty carry into the minutes rather than printing
     * an impossible 60" — the whole point of the notation is that it can be read
     * aloud into a radio.
     */
    public function testSecondsThatRoundToSixtyCarryIntoTheMinutes(): void
    {
        // 0.0166666… ≈ 0°00'59.9994" — one ten-thousandth short of a minute.
        $prefill = new IncidentPrefill(latitude: 59.9999 / 3600, longitude: 0.0);

        self::assertSame('0°01\'00"N 0°00\'00"E', $prefill->positionLabel());
    }

    /** No coordinates means no position row on the card — never a half-written one. */
    public function testAPrefillWithoutCoordinatesHasNoPositionLabel(): void
    {
        self::assertNull(new IncidentPrefill(latitude: -3.2014)->positionLabel());
        self::assertNull(new IncidentPrefill()->positionLabel());
    }

    /**
     * THE SOURCE, NAMED IN WORDS. The seam is a query string and the module on
     * the other side of it chooses the token, so an unrecognised one is printed
     * as-is rather than swallowed: the card says where the report came from even
     * when this module has never heard of that kind of source.
     */
    public function testAKnownSourceIsNamedAndAnUnknownOneIsStillPrinted(): void
    {
        self::assertSame('patrol observation', new IncidentPrefill(source: 'patrol_observation')->sourceLabel());
        // What the patrols module actually sends today.
        self::assertSame('patrol', new IncidentPrefill(source: 'patrol')->sourceLabel());
        self::assertSame('camera trap', new IncidentPrefill(source: 'camera-trap')->sourceLabel());
        self::assertSame('linked record', new IncidentPrefill()->sourceLabel());
    }

    /** Provenance is the record plus its label — one without the other ties nothing. */
    public function testProvenanceNeedsBothTheRecordAndItsLabel(): void
    {
        $record = Uuid::v7();

        self::assertTrue(new IncidentPrefill(record: $record, label: 'OBS-02 · lion tracks')->hasProvenance());
        self::assertFalse(new IncidentPrefill(record: $record)->hasProvenance());
        self::assertFalse(new IncidentPrefill(label: 'OBS-02 · lion tracks')->hasProvenance());
    }
}
