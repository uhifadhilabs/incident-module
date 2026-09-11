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

namespace Uhifadhi\Incident\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * THE CASE FILE'S MAP PLATE IS SIZED BY THIS SCREEN'S OWN DESIGN.
 *
 * The incident case file the design draws gives the "Where" card the height of
 * the column beside it, with 360px as the floor — `.recgrid>.c.plate-fill` and
 * the plate inside it filling what the row gives the card. Sizing a screen
 * against a sibling module's screen instead of against its own design is how a
 * port drifts while every test stays green, so the rule is asserted here against
 * what the design file carries.
 *
 * A TEXT CHECK, and that is the limit of what it promises: it catches the plate
 * being sized to something other than the design, not a plate that renders
 * wrongly for some other reason. Rendered fidelity is a sweep, not a unit test.
 *
 * THE HEIGHT IS STATED ON THE PLATE ROOT, which is the only thing about a map
 * this module says. The plate's column, its imagery frame, its floating legend
 * and its fullscreen are the atlas's; a module that restated any of them would
 * be the one map in the product that reads differently.
 *
 * THE PLATE FILLS THE CARD, which is what `.plate-fill` is named for. The row is
 * a stretch row — as it is in the design — so the card is as tall as whatever
 * the column beside it needs, 360px is the FLOOR under both, and the plate takes
 * the whole of it.
 */
final class CaseFilePlateSizingTest extends TestCase
{
    /** The floor the design's own "Where" card states under its map. */
    private const string DESIGN_FLOOR = '360px';

    public function testThePlateTakesTheHeightTheRowGivesTheCard(): void
    {
        self::assertMatchesRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*--map-plate-height:100%/',
            self::stylesheet(),
            'The case file plate is the height of the column beside it, as incidents/detail.html draws it.',
        );
    }

    public function testTheCardCarriesTheDesignsFloor(): void
    {
        self::assertMatchesRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*min-height:'.preg_quote(self::DESIGN_FLOOR, '/').'/',
            self::stylesheet(),
            'A short column must not shrink the map below the floor the design states.',
        );
    }

    /** And a sibling module's plate height appears nowhere in this sheet. */
    public function testNoSiblingModulesPlateHeightIsStatedHere(): void
    {
        self::assertStringNotContainsString('min(58vh,560px)', self::stylesheet());
    }

    /**
     * THE PLATE'S OWN INTERNALS ARE THE ATLAS'S. A module that sizes, frames or
     * dresses the map itself is a module whose map reads differently from every
     * other one in the product.
     */
    public function testThisSheetStatesNothingAboutThePlatesInternals(): void
    {
        $sheet = self::stylesheet();

        self::assertStringNotContainsString('.viewer', $sheet);
        self::assertStringNotContainsString('leaflet', $sheet);
        self::assertStringNotContainsString('map-chrome', $sheet);
        self::assertStringNotContainsString('map-legend', $sheet);
    }

    /**
     * THE FLOOR IS THE CARD'S, THE HEIGHT IS THE PLATE'S.
     *
     * `min-height` plus `flex: 1` on the plate is what this card used to say,
     * and the atlas owns that rule now: the plate takes one custom property and
     * refuses to invent a height of its own. All this sheet states is that the
     * property is the card's own height, and how short the card may get.
     */
    public function testTheSheetStatesTheRuleOnTheCardAndNotOnThePlate(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression('/\\.c\\.plate-fill\\{[^}]*display:flex/', $sheet);
        self::assertMatchesRegularExpression('/\\.c\\.plate-fill\\{[^}]*flex-direction:column/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\\.plate-fill \\.map-plate\\{[^}]*min-height/', $sheet);
        self::assertDoesNotMatchRegularExpression('/\\.plate-fill \\.map-plate\\{[^}]*flex:1/', $sheet);
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
