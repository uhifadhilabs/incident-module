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
 * A BLOCK TOGGLE IS LIT BY ITS OWN CHECKBOX.
 *
 * The block picker's toggles are labels around a real checkbox, so the lit
 * border and the tick belong to `:has(input:checked)`: ticking one lights it and
 * unticking one puts it out, under the pointer and without a round trip. A lit
 * rule that only names the static `on` class is a picker whose boxes cannot be
 * seen to turn off — the state on screen then belongs to the last page load
 * rather than to the checkbox a person just clicked.
 *
 * The class stays in the selector list because the design workshop's mockups
 * carry no checkboxes and light their toggles with it.
 *
 * A TEXT CHECK, which is the limit of what it promises: it catches the lit state
 * being taken away from the checkbox, not a toggle that renders wrongly for some
 * other reason.
 */
final class BlockToggleLitByCheckboxTest extends TestCase
{
    public function testTheLitToggleNamesItsCheckbox(): void
    {
        self::assertStringContainsString(
            '.tx-tog.on,.tx-tog:has(input:checked){',
            self::stylesheet(),
            'The lit toggle is the one whose checkbox is ticked, as taxonomy.css draws it in the design workspace.',
        );
    }

    public function testTheLitTickNamesItsCheckbox(): void
    {
        self::assertStringContainsString(
            '.tx-tog.on .box,.tx-tog:has(input:checked) .box{',
            self::stylesheet(),
            'The tick inside the box follows the same checkbox the border does.',
        );
    }

    /** And neither lit rule is stated for the static class on its own. */
    public function testNeitherLitRuleIsStatedForTheStaticClassAlone(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\.tx-tog\.on(?: \.box)?\{/',
            self::stylesheet(),
            'A lit rule the checkbox is not part of keeps a toggle lit after it has been unticked.',
        );
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/public/taxonomy.css');
    }
}
