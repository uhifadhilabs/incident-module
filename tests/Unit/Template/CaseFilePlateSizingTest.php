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
 * The incident case file the design draws gives the "Where" card a 400px map and
 * lets the card end where the map ends — `.recgrid>.c.plate-fill` with
 * `align-self:start`, and a viewer inside it at `flex:none;height:400px`. Sizing
 * a screen against a sibling module's screen instead of against its own design
 * is how a port drifts while every test stays green, so the rule is asserted
 * here against what the design file carries.
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
 * THE CARD IS THE PLATE'S SIZE, not the row's. The row is a stretch row — as it
 * is in the design — so the card says out loud that it will not stretch, and the
 * imagery keeps the size it was drawn at while the facts beside it run as long as
 * they need to.
 */
final class CaseFilePlateSizingTest extends TestCase
{
    /** The height the design's own "Where" card states for its map. */
    private const string DESIGN_HEIGHT = '400px';

    public function testThePlateIsTheHeightTheDesignStates(): void
    {
        self::assertMatchesRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*--map-plate-height:'.preg_quote(self::DESIGN_HEIGHT, '/').'/',
            self::stylesheet(),
            'The case file map is a fixed plate of imagery, as incidents/detail.html draws it.',
        );
    }

    public function testTheCardEndsWhereTheMapEnds(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*align-self:start/',
            $sheet,
            'A stretch row would otherwise run the card down past the imagery it holds.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\\.c\\.plate-fill\\{[^}]*min-height/',
            $sheet,
            'A floor under a fixed height is a floor that can only fight it.',
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
     * THE RULE IS STATED ON THE CARD, NEVER ON THE PLATE.
     *
     * A plate takes one custom property and invents no height of its own, and it
     * is the full width of whatever card it sits in. All this sheet states is
     * what that property is here, and that the card keeps to it.
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
