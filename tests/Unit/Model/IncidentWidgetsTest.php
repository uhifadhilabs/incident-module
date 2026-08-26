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

    /** Sixteen widgets, including the rail the design added to the status board. */
    public function testItShipsTheSixteenWidgetsTheDesignDeclares(): void
    {
        self::assertSame([
            'kpis', 'register', 'queue',
            'maplist', 'map', 'zones',
            'spark', 'feed', 'evidence',
            'categories', 'matrix', 'money',
            'board', 'sla', 'funnel', 'rail',
        ], IncidentWidgets::catalog()->ids());
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
