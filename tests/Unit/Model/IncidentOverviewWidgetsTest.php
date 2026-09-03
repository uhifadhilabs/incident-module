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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Incident\Model\IncidentOverviewWidgets;
use Uhifadhi\Model\Widget;

/**
 * The declaration this module puts into the HOST's area-overview library — a
 * transcription of the design's own surface declaration for the `incidents`
 * group, which is the spec.
 *
 * Every value here is asserted literally rather than derived, because the point
 * of the assertions is that the declaration MATCHES A DOCUMENT nobody can read
 * from inside the test run.
 */
final class IncidentOverviewWidgetsTest extends TestCase
{
    public function testTheHeadedSectionNamesTHECONTRIBUTORAndNotADesignDirection(): void
    {
        $group = IncidentOverviewWidgets::group();

        // On the module's own surface a group is one of the five directions. Here
        // it is PROVENANCE: the package name is in the label because a person has
        // to be able to tell which installed thing put these cards on the page.
        self::assertSame('incidents', $group->id);
        self::assertSame('Incidents · uhifadhi/incident-module', $group->label);
        self::assertStringContainsString('the five-state flow of what is still open', $group->description);
    }

    public function testItContributesTheFiveWidgetsTheDesignDeclares(): void
    {
        self::assertSame(
            ['in_flow', 'in_today', 'in_recent', 'in_money', 'in_column'],
            array_map(static fn (Widget $widget) => $widget->id, IncidentOverviewWidgets::widgets()),
        );
    }

    public function testEveryWidgetIsFiledUnderThisContributorsOwnGroup(): void
    {
        foreach (IncidentOverviewWidgets::widgets() as $widget) {
            self::assertSame(IncidentOverviewWidgets::GROUP, $widget->group, $widget->id);
        }
    }

    /**
     * ONE OF THEM IS ON. The area overview is not the incidents dashboard: the
     * module leads with where the open work is and offers the rest, because a
     * page composed of every module's everything is nobody's morning.
     */
    public function testOnlyTheFlowBarIsOnBeforeAnybodyOpensTheLibrary(): void
    {
        $on = array_values(array_map(
            static fn (Widget $widget) => $widget->id,
            array_filter(IncidentOverviewWidgets::widgets(), static fn (Widget $widget) => $widget->on),
        ));

        self::assertSame(['in_flow'], $on);
    }

    /**
     * @return iterable<string, array{string, string, int, list<int>, bool, string}>
     */
    public static function declaration(): iterable
    {
        yield 'in_flow' => ['in_flow', 'Open by state', 6, [12, 9, 6],
            true, 'The five-state flow as one bar — the same state machine the module’s own pages draw.'];
        yield 'in_today' => ['in_today', 'Incidents today', 6, [12, 9, 6, 4, 3],
            false, 'Filed today, by category, against yesterday. Today only — the month lives on the module.'];
        yield 'in_recent' => ['in_recent', 'Latest incidents', 6, [12, 9, 6],
            false, 'The last handful filed, newest first, each opening its case.'];
        yield 'in_money' => ['in_money', 'Money outstanding', 6, [12, 9, 6],
            false, 'Fines due and compensation unpaid — the one overview number that is not about today.'];
        yield 'in_column' => ['in_column', 'Incidents — the whole column', 6, [12, 9, 6, 4, 3],
            false, 'The module’s entire overview section as ONE widget: its heading and its cards, stacked.'];
    }

    /**
     * @param list<int> $spans
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('declaration')]
    public function testEachWidgetIsDeclaredExactlyAsTheDesignDeclaresIt(
        string $id,
        string $label,
        int $cols,
        array $spans,
        bool $on,
        string $note,
    ): void {
        $widgets = [];
        foreach (IncidentOverviewWidgets::widgets() as $widget) {
            $widgets[$widget->id] = $widget;
        }

        self::assertArrayHasKey($id, $widgets);
        self::assertSame($label, $widgets[$id]->label);
        self::assertSame($cols, $widgets[$id]->cols);
        self::assertSame($spans, $widgets[$id]->spans);
        self::assertSame($on, $widgets[$id]->on);
        self::assertSame($note, $widgets[$id]->note);
    }

    /**
     * A THIRD OF THE ROW, on the two widgets that are a COLUMN.
     *
     * The design gives `in_today` and `in_column` a quarter (3) as their
     * narrowest; the host's grid has since gained a third (4) for exactly the
     * module-columns case — three modules, three columns — so the two widgets
     * that can be a column offer it and the three that are single cards do not.
     */
    public function testTheTwoColumnWidgetsOfferAThirdOfTheRow(): void
    {
        $offering = [];
        foreach (IncidentOverviewWidgets::widgets() as $widget) {
            if (\in_array(4, $widget->spans, true)) {
                $offering[] = $widget->id;
            }
        }

        self::assertSame(['in_today', 'in_column'], $offering);
    }
}
