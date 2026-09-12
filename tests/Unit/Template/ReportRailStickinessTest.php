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
 * THE RAIL BESIDE THE REPORT FORM STANDS STILL.
 *
 * The form is the long column and the rail is what is being read against it: what
 * the chosen kind asks, and the record the filing came from. A rail that travelled
 * with the document left the reader scrolling away from the questions to answer
 * them. So the rail is FIXED under the shell's sticky top bar and scrolls inside
 * itself, and the form scrolls past it.
 *
 * THE OFFSET IS THE SHELL'S, NOT A NUMBER THIS MODULE PICKED. `.topbar` is 56px
 * tall and sticks to the top of the viewport (the core's shell.css), which is the
 * same offset this sheet's sticky day heading already keeps. The bottom gutter is
 * the shell's page gutter, so the rail ends where a page ends.
 *
 * STICKY NEEDS THE FLEX CHILD TO STOP STRETCHING. A flex item's default
 * `align-self:stretch` makes the rail as tall as the row, and a box already as
 * tall as its container has nothing to stick within.
 *
 * AND BELOW THE STACKING BREAKPOINT IT IS A PLAIN BLOCK. Under 1160px the rail
 * sits beneath the form, where a fixed height would trap the checklist in a
 * scroller nobody expects.
 *
 * A TEXT CHECK, and that is the limit of what it promises: it catches the rail
 * being written as something other than a scroll region of its own. Rendered
 * behaviour is a sweep, not a unit test.
 */
final class ReportRailStickinessTest extends TestCase
{
    /** The height of the shell's sticky top bar, which the rail begins under. */
    private const string SHELL_STICKY_OFFSET = '56px';

    /** The shell's own page bottom gutter, so the rail ends where a page ends. */
    private const string PAGE_BOTTOM_GUTTER = '26px';

    /** The width below which the two columns cannot both stand. */
    private const string STACKING_BREAKPOINT = '1159px';

    public function testTheRailIsFixedAndScrollsInsideItself(): void
    {
        $rule = self::railRule();

        self::assertStringContainsString('position:sticky', $rule);
        self::assertStringContainsString('top:'.self::SHELL_STICKY_OFFSET, $rule, 'The rail begins under the shell\'s top bar.');
        self::assertStringContainsString(
            'max-height:calc(100vh - '.self::SHELL_STICKY_OFFSET.' - '.self::PAGE_BOTTOM_GUTTER.')',
            $rule,
            'What is left of the viewport under the top bar, less the gutter a page ends on.',
        );
        self::assertStringContainsString('overflow-y:auto', $rule, 'The rail is its own scroll region or it cannot be fixed.');
        // One axis on auto puts the other on auto too, and a card tab a pixel wide
        // of the box then grows a horizontal bar nobody asked for.
        self::assertStringContainsString('overflow-x:hidden', $rule);
        self::assertStringContainsString('overscroll-behavior:contain', $rule);
    }

    /**
     * AND ITS FIRST CARD'S TAB STAYS INSIDE THE BOX. A card tab sits at
     * `top:-9px`, so the box that clips carries 9px of padding above it — which is
     * the rail now, because the rail is the box that clips.
     */
    public function testTheRailPadsTheTopSoTheFirstCardsTabIsNotClipped(): void
    {
        self::assertStringContainsString('padding:9px 2px 2px 0', self::railRule());
    }

    /** A stretched flex item has nothing to stick within, so the rail stops stretching. */
    public function testTheRailStopsStretchingSoThatStickyHasSomewhereToWork(): void
    {
        self::assertStringContainsString('align-self:flex-start', self::railRule());
    }

    /** Stacked under the form, it is a plain block again. */
    public function testTheStackedRailIsNotFixed(): void
    {
        $stacked = self::stackedRailRule();

        self::assertStringContainsString('position:static', $stacked);
        self::assertStringContainsString('max-height:none', $stacked);
        self::assertStringContainsString('overflow:visible', $stacked);
    }

    /**
     * THE SCROLLBAR IS THIN AND TOKENED, AND IT IS NEVER HIDDEN — the same bar a
     * sticky rail wears elsewhere in the product. A scroll region nobody can see
     * the extent of is a scroll region nobody scrolls, and the colour comes from a
     * token so the bar reads in both themes.
     */
    public function testTheRailWearsTheThinTokenedScrollbarAndHidesNothing(): void
    {
        $rule = self::railRule();
        $sheet = self::stylesheet();

        self::assertStringContainsString('scrollbar-width:thin', $rule);
        self::assertStringContainsString('scrollbar-color:var(--ln2) transparent', $rule);
        self::assertMatchesRegularExpression('/\.i-rail::-webkit-scrollbar\{width:8px\}/', $sheet);
        self::assertMatchesRegularExpression('/\.i-rail::-webkit-scrollbar-thumb\{[^}]*background:var\(--ln2\)/', $sheet);
        self::assertStringNotContainsString('scrollbar-width:none', $sheet);
        self::assertStringNotContainsString('::-webkit-scrollbar{display:none', $sheet);
    }

    /**
     * AND THE RAIL CARRIES NO PHOTOGRAPHS. The record's own words and the rows
     * that say which patrol, whose eyes, when and where are what the form cannot
     * state for itself; the photographs are on the case file the filing becomes,
     * and a strip of them beside a form is a second gallery to maintain.
     */
    public function testNoPhotographStripRuleIsLeftInTheSheet(): void
    {
        $sheet = self::stylesheet();

        self::assertStringNotContainsString('.rr-shot', $sheet);
        self::assertStringNotContainsString('.rr-shots', $sheet);
    }

    /** The rail's own rule, outside any media query. */
    private static function railRule(): string
    {
        $matched = preg_match('/(?<![-\w.])\.i-rail\{[^}]*\}/', self::stylesheet(), $m);
        self::assertSame(1, $matched, 'The sheet states one .i-rail rule.');

        return $m[0];
    }

    /** And its rule below the stacking breakpoint. */
    private static function stackedRailRule(): string
    {
        $media = preg_match(
            '/@media \(max-width:'.preg_quote(self::STACKING_BREAKPOINT, '/').'\)\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/',
            self::stylesheet(),
            $m,
        );
        self::assertSame(1, $media, 'The sheet stacks the pair below the breakpoint.');

        $rail = preg_match('/(?<![-\w.])\.i-rail\{[^}]*\}/', $m[0], $inner);
        self::assertSame(1, $rail, 'The stacked pair restates the rail.');

        return $inner[0];
    }

    private static function stylesheet(): string
    {
        $sheet = file_get_contents(\dirname(__DIR__, 3).'/public/incidents.css');
        self::assertIsString($sheet);

        return $sheet;
    }
}
