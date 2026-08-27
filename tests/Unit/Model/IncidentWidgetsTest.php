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
use Uhifadhi\Model\WidgetCatalog;
use UhifadhiLabs\Incident\Model\IncidentWidgets;

/**
 * THE CATALOGUE IS A TRANSCRIPTION of the design's surface declaration
 * (incidents.widgets.js). Every assertion here quotes that file, because a
 * catalogue that drifted from it would put a widget on the dashboard that the
 * design app has never drawn.
 */
final class IncidentWidgetsTest extends TestCase
{
    public function testItIsTheIncidentsSurface(): void
    {
        self::assertSame('incidents', IncidentWidgets::catalog()->surface);
    }

    /** The five headed sections ARE the five directions incidents was drawn in. */
    public function testTheLibrarysSectionsAreTheFiveDirections(): void
    {
        $groups = IncidentWidgets::catalog()->groups();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($g) => $g->id, $groups));
        self::assertSame(
            ['Case files', 'Map first', 'Live feed', 'Category board', 'Status board'],
            array_map(static fn ($g) => $g->label, $groups),
        );
    }

    /**
     * Seventeen widgets, including the rail the design added to the status board
     * and the report entry card it added to case files.
     */
    public function testItShipsTheSeventeenWidgetsTheDesignDeclares(): void
    {
        self::assertSame([
            'kpis', 'register', 'queue', 'report',
            'maplist', 'map', 'zones',
            'spark', 'feed', 'evidence',
            'categories', 'matrix', 'money',
            'board', 'sla', 'funnel', 'rail',
        ], IncidentWidgets::catalog()->ids());
    }

    /**
     * THE REPORT ENTRY CARD is filed under Case files — the direction of whoever
     * keeps the register, and a new file is the file drawer's own verb — and it
     * is OFF, because the dashboard header already carries a Report control.
     */
    public function testTheReportEntryCardShipsOffAndUnderCaseFiles(): void
    {
        $card = IncidentWidgets::catalog()->get('report');

        self::assertSame('File an incident', $card->label);
        self::assertSame('a', $card->group);
        self::assertFalse($card->on, 'The entry card is a second door; nobody gets it unasked.');
        self::assertSame(6, $card->cols);
        self::assertSame([12, 9, 6], IncidentWidgets::catalog()->spans('report'));
    }

    /**
     * ADDING IT CHANGED NO DESIGN. The five directions and the shipped
     * composition are exactly what they were, so a person who adopted one sees
     * nothing new appear on their dashboard.
     */
    public function testNoDesignTheSurfaceShipsComposesTheEntryCard(): void
    {
        $catalog = IncidentWidgets::catalog();

        self::assertArrayNotHasKey('report', $catalog->defaultLayout());
        foreach ($catalog->presets() as $preset) {
            self::assertArrayNotHasKey('report', $preset->layout, \sprintf('Design "%s" gained a widget.', $preset->id));
        }
    }

    /**
     * THE SHIPPED COMPOSITION IS NOT ONE OF THE FIVE. It takes the numbers from
     * A, the map from B, the register from A and the money board from D — so it
     * leads the strip as a built-in preset in its own right, under the name the
     * design gives it.
     */
    public function testTheShippedCompositionIsItsOwnNamedDesign(): void
    {
        $catalog = IncidentWidgets::catalog();

        self::assertSame(['kpis' => 12, 'register' => 12, 'map' => 12, 'money' => 12], $catalog->defaultLayout());
        self::assertSame(WidgetCatalog::DEFAULT_PRESET_ID, $catalog->defaultPresetId());

        $shipped = $catalog->preset(WidgetCatalog::DEFAULT_PRESET_ID);
        self::assertNotNull($shipped);
        self::assertSame('The incidents dashboard', $shipped->label);
    }

    /** All five directions ship as presets, each described in the gallery's own words. */
    public function testEveryDirectionShipsAsAPresetAnyoneCanAdopt(): void
    {
        $presets = IncidentWidgets::catalog()->presets();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($p) => $p->id, $presets));
        self::assertSame(['kpis' => 12, 'register' => 12, 'queue' => 12], $presets[0]->layout);
        // E is the one that gained the rail — "where things stand" sits between
        // the numbers and the board.
        self::assertSame(
            ['kpis' => 12, 'rail' => 12, 'board' => 12, 'sla' => 6, 'funnel' => 6],
            $presets[4]->layout,
        );
    }

    /**
     * A headed section and the preset that adopts it say the SAME thing about the
     * direction — the trade-off line is written once.
     */
    public function testASectionAndItsPresetNeverSayDifferentThings(): void
    {
        $catalog = IncidentWidgets::catalog();

        foreach ($catalog->groups() as $group) {
            $preset = $catalog->preset($group->id);
            self::assertNotNull($preset, \sprintf('Direction "%s" has no preset.', $group->id));
            self::assertSame($group->label, $preset->label);
            self::assertSame($group->description, $preset->description);
        }
    }

    /** Every widget carries the one line the add-widget picker prints. */
    public function testEveryWidgetSaysWhatItShows(): void
    {
        $catalog = IncidentWidgets::catalog();

        foreach ($catalog->ids() as $id) {
            self::assertNotNull($catalog->get($id)->note, \sprintf('Widget "%s" has no picker line.', $id));
        }
    }

    /** The board is a five-column drag surface and is never offered at a narrower width. */
    public function testTheStatusBoardIsOnlyEverFullWidth(): void
    {
        self::assertSame([12], IncidentWidgets::catalog()->spans('board'));
    }
}
