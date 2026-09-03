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

namespace Uhifadhi\Incident\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Enum\IncidentStatusEnum;

/**
 * The five places, and only five. The design's IN·M1 card names them and the
 * gallery's status board draws one column per place, so a sixth would be visible
 * everywhere at once — including in the community-report ruling, which says an
 * untrusted reporter's incident enters at `reported` like any other.
 */
final class IncidentStatusEnumTest extends TestCase
{
    public function testTheWorkflowHasExactlyFivePlacesInOrder(): void
    {
        self::assertSame(
            ['reported', 'verified', 'in_progress', 'resolved', 'closed'],
            array_map(static fn (IncidentStatusEnum $s) => $s->value, IncidentStatusEnum::ordered()),
        );
    }

    public function testEachPlaceKnowsItsStepNumber(): void
    {
        self::assertSame(1, IncidentStatusEnum::Reported->step());
        self::assertSame(3, IncidentStatusEnum::InProgress->step());
        self::assertSame(5, IncidentStatusEnum::Closed->step());
    }

    /** The words the design prints, which are not the stored values. */
    public function testLabelsAreTheDesignsOwnWords(): void
    {
        self::assertSame('reported', IncidentStatusEnum::Reported->label());
        self::assertSame('in progress', IncidentStatusEnum::InProgress->label());
        self::assertSame('closed', IncidentStatusEnum::Closed->label());
    }

    /** incidents.css keys its status chips by these three-letter classes. */
    public function testChipClassesMatchTheStylesheet(): void
    {
        self::assertSame('rep', IncidentStatusEnum::Reported->cssClass());
        self::assertSame('ver', IncidentStatusEnum::Verified->cssClass());
        self::assertSame('wip', IncidentStatusEnum::InProgress->cssClass());
        self::assertSame('res', IncidentStatusEnum::Resolved->cssClass());
        self::assertSame('cls', IncidentStatusEnum::Closed->cssClass());
    }

    public function testAPlaceKnowsWhetherItIsStillOpen(): void
    {
        self::assertTrue(IncidentStatusEnum::Reported->isOpen());
        self::assertTrue(IncidentStatusEnum::InProgress->isOpen());
        // Resolved is done work; the design's "31 open" counts the first three.
        self::assertFalse(IncidentStatusEnum::Resolved->isOpen());
        self::assertFalse(IncidentStatusEnum::Closed->isOpen());
    }

    public function testAPlaceKnowsWhatItHasAlreadyPassed(): void
    {
        self::assertTrue(IncidentStatusEnum::InProgress->hasReached(IncidentStatusEnum::Verified));
        self::assertTrue(IncidentStatusEnum::InProgress->hasReached(IncidentStatusEnum::InProgress));
        self::assertFalse(IncidentStatusEnum::InProgress->hasReached(IncidentStatusEnum::Resolved));
    }
}
