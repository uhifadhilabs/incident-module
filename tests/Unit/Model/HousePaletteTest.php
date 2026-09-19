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
use Uhifadhi\Incident\Model\HousePalette;

/**
 * WHAT THIS MODULE HANDS A HOST THAT STILL ASKS FOR A COLOUR.
 *
 * RULED 2026-09-21: a module declares no colour. Where a contract takes a
 * category as an index it gets one; where a contract still takes a colour
 * STRING — the atlas layer's swatch, the pulse event's — it gets the house
 * TOKEN by name, never a value. The host resolves it, so the same position is
 * the same hue on the plate as in the register, and this module never learns
 * what that hue is.
 *
 * The exact spelling matters twice over: it is what the shell's `[data-cat]`
 * palette defines, and it is what the shell's plate rules match on
 * (`.viewer [fill="var(--cat-3)"]`) to repaint a marker for imagery. A
 * different spelling of the same idea would draw black on a map.
 */
final class HousePaletteTest extends TestCase
{
    public function testAPositionIsPublishedAsTheHouseTokenThatNamesIt(): void
    {
        self::assertSame('var(--cat-1)', HousePalette::token(1));
        self::assertSame('var(--cat-9)', HousePalette::token(9));
    }

    public function testAPositionOutsideTheSetIsNotInventedButFallsBackToTheMutedMark(): void
    {
        // Nothing should ask this — `catIndex()` already wraps and clamps — so
        // the answer is the honest grey rather than a tenth hue.
        self::assertSame('var(--fog)', HousePalette::token(0));
        self::assertSame('var(--fog)', HousePalette::token(10));
    }
}
