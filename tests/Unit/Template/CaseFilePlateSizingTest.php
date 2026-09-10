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
 * The incident case file the design draws states `height:min(46vh,440px)` on its
 * own map. Sizing a screen against a sibling module's screen instead of against
 * its own design is how a port drifts while every test stays green, so the
 * number is asserted here against the value the design file carries.
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
 * the column beside it needs, the design's height is the FLOOR, and the plate
 * takes the slack.
 */
final class CaseFilePlateSizingTest extends TestCase
{
    /** The height the design's own "Where" card states on its map. */
    private const string DESIGN_HEIGHT = 'min(46vh,440px)';

    public function testThePlateIsTheHeightTheDesignStates(): void
    {
        self::assertMatchesRegularExpression(
            '/\.plate-fill \.map-plate\{[^}]*min-height:'.preg_quote(self::DESIGN_HEIGHT, '/').'/',
            self::stylesheet(),
            'The case file plate is sized by incidents/detail.html, not by another module\'s screen.',
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
     * The plate takes the slack a stretch row hands the card, so there is no
     * dead region below it.
     */
    public function testThePlateFillsTheCardItIsNamedFor(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression('/\.c\.plate-fill\{[^}]*display:flex/', $sheet);
        self::assertMatchesRegularExpression('/\.c\.plate-fill\{[^}]*flex-direction:column/', $sheet);
        self::assertMatchesRegularExpression('/\.plate-fill \.map-plate\{[^}]*flex:1/', $sheet);
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
