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
 * It was not. The sheet stated `min(58vh, 560px)` and said in as many words that
 * it had been matched to PATROL's detail plate — a different screen in a
 * different module — while the incident case file the design draws states
 * `height:min(46vh,440px)` on its own map. Sizing a screen against a sibling's
 * screen instead of against its own design is how a port drifts while every test
 * stays green, so the number is asserted here against the value the design file
 * carries.
 *
 * A TEXT CHECK, and that is the limit of what it promises: it catches the plate
 * being sized to something other than the design, not a plate that renders
 * wrongly for some other reason. Rendered fidelity is a sweep, not a unit test.
 *
 * THE PLATE ALSO FILLS THE CARD, which is what `.plate-fill` has always been
 * called. The row is a stretch row — as it is in the design — so the map card is
 * as tall as whatever the column beside it needs, and a plate with a fixed
 * height left the difference as dead space under the legend chips. The design's
 * height becomes the FLOOR and the plate takes the slack; no drawn element
 * changes, only where spare space goes.
 */
final class CaseFilePlateSizingTest extends TestCase
{
    /** The height the design's own "Where" card states on its map. */
    private const string DESIGN_HEIGHT = 'min(46vh,440px)';

    public function testThePlateIsTheHeightTheDesignStates(): void
    {
        self::assertMatchesRegularExpression(
            '/\.plate-fill \.viewer\{[^}]*min-height:'.preg_quote(self::DESIGN_HEIGHT, '/').'/',
            self::stylesheet(),
            'The case file plate is sized by incidents/detail.html, not by another module\'s screen.',
        );
    }

    /** And the number the sheet drifted to is gone, not merely overridden further down. */
    public function testTheBorrowedPatrolPlateHeightIsGone(): void
    {
        self::assertStringNotContainsString('min(58vh,560px)', self::stylesheet());
    }

    /**
     * The plate takes the slack a stretch row hands the card, so there is no
     * dead region below the legend.
     */
    public function testThePlateFillsTheCardItIsNamedFor(): void
    {
        $sheet = self::stylesheet();

        self::assertMatchesRegularExpression('/\.c\.plate-fill\{[^}]*display:flex/', $sheet);
        self::assertMatchesRegularExpression('/\.c\.plate-fill\{[^}]*flex-direction:column/', $sheet);
        self::assertMatchesRegularExpression('/\.plate-fill \.viewer\{[^}]*flex:1/', $sheet);
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
