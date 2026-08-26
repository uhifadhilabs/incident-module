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
use UhifadhiLabs\Incident\Model\DemoMonth;
use UhifadhiLabs\Incident\Model\IncidentTaxonomy;

/**
 * THE SAMPLE MONTH ADDS UP TO WHAT THE GALLERY SAYS IT DOES.
 *
 * The preset gallery states the numbers once and every widget repeats them, so a
 * demo that quietly stopped matching would make every screenshot in the design
 * app a claim the product no longer supports. These assertions are that check,
 * and they are deliberately literal.
 */
final class DemoMonthTest extends TestCase
{
    public function testFortySevenIncidentsWereFiled(): void
    {
        self::assertCount(47, DemoMonth::incidents());
    }

    /** 31 still open: 7 reported · 13 verified · 11 in progress. */
    public function testThirtyOneAreStillOpenInTheDesignsOwnBreakdown(): void
    {
        $byStatus = self::tally('status');

        self::assertSame(7, $byStatus['reported']);
        self::assertSame(13, $byStatus['verified']);
        self::assertSame(11, $byStatus['in_progress']);
        self::assertSame(11, $byStatus['resolved']);
        self::assertSame(5, $byStatus['closed']);
        self::assertSame(31, $byStatus['reported'] + $byStatus['verified'] + $byStatus['in_progress']);
    }

    /** The funnel: 16 of the 47 reached resolved, and 5 reached closed. */
    public function testTheFunnelNarrowsTheWayTheDesignDraws(): void
    {
        $byStatus = self::tally('status');

        self::assertSame(16, $byStatus['resolved'] + $byStatus['closed']);
        self::assertSame(5, $byStatus['closed']);
    }

    /** 18 conflict · 12 poaching · 9 compliance · 8 mortality. */
    public function testTheFourKindsSplitAsTheKpiStripStates(): void
    {
        $ofKind = [];
        foreach (DemoMonth::incidents() as $row) {
            $ofKind[self::kindOf($row['subcategory'])] = (($ofKind[self::kindOf($row['subcategory'])] ?? 0) + 1);
        }

        self::assertSame(12, $ofKind['poaching']);
        self::assertSame(18, $ofKind['conflict']);
        self::assertSame(9, $ofKind['compliance']);
        self::assertSame(8, $ofKind['mortality']);
    }

    /** TZS 8,450,000 assessed in fines and 5,900,000 collected — to the shilling. */
    public function testTheFinesAreTheGallerysFinesExactly(): void
    {
        [$assessed, $settled] = self::money('fine');

        self::assertSame(8_450_000, $assessed);
        self::assertSame(5_900_000, $settled);
        self::assertSame(2_550_000, $assessed - $settled, 'The KPI strip prints 2.55M outstanding.');
    }

    /** TZS 12.4M claimed, 9.2M approved, 4.7M paid. */
    public function testTheCompensationIsTheGallerysCompensationExactly(): void
    {
        $claimed = 0;
        $approved = 0;
        $paid = 0;
        foreach (DemoMonth::incidents() as $row) {
            if (null === $row['money'] || 'compensation' !== self::directionOf($row['subcategory'])) {
                continue;
            }
            $claimed += $row['money']['claimed'] ?? 0;
            $approved += $row['money']['approved'] ?? 0;
            $paid += $row['money']['settled'] ?? 0;
        }

        self::assertSame(12_400_000, $claimed);
        self::assertSame(9_200_000, $approved);
        self::assertSame(4_700_000, $paid);
        self::assertSame(4_500_000, $approved - $paid, 'The KPI strip prints 4.5M compensation unpaid.');
    }

    /** Seven zones, and every row names one of them. */
    public function testEveryRowNamesOneOfTheSevenZones(): void
    {
        self::assertCount(7, DemoMonth::ZONES);

        foreach (DemoMonth::incidents() as $row) {
            self::assertContains($row['zone'], DemoMonth::ZONES, $row['reference'].' is filed in an unknown zone.');
        }
    }

    /**
     * THE RESOLVE GUARD IS NOT BYPASSED BY THE SEEDER. An incident the sample
     * month says is resolved or closed must have its money settled, or the real
     * transition service would refuse to move it there — and the seeder walks
     * every incident through the real service on purpose.
     */
    public function testNothingIsResolvedWithMoneyStillOutstanding(): void
    {
        foreach (DemoMonth::incidents() as $row) {
            if (!\in_array($row['status'], ['resolved', 'closed'], true) || null === $row['money']) {
                continue;
            }

            self::assertSame(
                $row['money']['approved'] ?? 0,
                $row['money']['settled'] ?? 0,
                $row['reference'].' is resolved with money still outstanding; the workflow would refuse it.',
            );
        }
    }

    /** Every reference is unique, and they run consecutively from INC-0272. */
    public function testTheCaseNumbersAreUniqueAndConsecutive(): void
    {
        $references = array_column(DemoMonth::incidents(), 'reference');

        self::assertSame($references, array_unique($references));
        self::assertSame('INC-0272', $references[0]);
        self::assertSame('INC-0318', $references[46]);
    }

    /** Every row names a sub-category the shipped taxonomy actually has. */
    public function testEveryRowFilesAgainstASubcategoryTheModuleShips(): void
    {
        $known = [];
        foreach (IncidentTaxonomy::shipped() as $category) {
            $known = [...$known, ...array_keys($category['subcategories'])];
        }

        foreach (DemoMonth::incidents() as $row) {
            self::assertContains($row['subcategory'], $known, $row['reference'].' names a sub-category nothing ships.');
        }
    }

    /** The worked example the whole design app opens: INC-0313. */
    public function testTheWorkedExampleIsTheDesignsWorkedExample(): void
    {
        $incident = null;
        foreach (DemoMonth::incidents() as $row) {
            if ('INC-0313' === $row['reference']) {
                $incident = $row;
            }
        }

        self::assertNotNull($incident, 'The design app opens on INC-0313; the sample month has to hold it.');
        self::assertSame('Lion killed four goats at Osinoni — compensation claim opened', $incident['title']);
        self::assertSame('livestock-depredation', $incident['subcategory']);
        self::assertSame('in_progress', $incident['status']);
        self::assertSame('Endulen', $incident['zone']);
        self::assertNotNull($incident['money']);
        self::assertSame(1_600_000, $incident['money']['claimed']);
        self::assertSame(1_200_000, $incident['money']['approved']);
        self::assertSame(0, $incident['money']['settled']);
        self::assertNotNull($incident['narrative'], 'The narrative is quoted verbatim on the detail page.');
    }

    /** @return array<string, int> */
    private static function tally(string $column): array
    {
        $counts = [];
        foreach (DemoMonth::incidents() as $row) {
            $value = 'status' === $column ? $row['status'] : $row['severity'];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array{int, int} assessed, settled */
    private static function money(string $direction): array
    {
        $assessed = 0;
        $settled = 0;
        foreach (DemoMonth::incidents() as $row) {
            if (null === $row['money'] || $direction !== self::directionOf($row['subcategory'])) {
                continue;
            }
            $assessed += $row['money']['assessed'] ?? 0;
            $settled += $row['money']['settled'] ?? 0;
        }

        return [$assessed, $settled];
    }

    private static function kindOf(string $subcategory): string
    {
        foreach (IncidentTaxonomy::shipped() as $slug => $category) {
            if (isset($category['subcategories'][$subcategory])) {
                return $slug;
            }
        }

        self::fail(\sprintf('No shipped category holds "%s".', $subcategory));
    }

    private static function directionOf(string $subcategory): ?string
    {
        foreach (IncidentTaxonomy::shipped() as $category) {
            if (isset($category['subcategories'][$subcategory])) {
                return $category['subcategories'][$subcategory]['money'];
            }
        }

        return null;
    }
}
