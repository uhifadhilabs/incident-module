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

namespace Uhifadhi\Incident\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Incident\Entity\TaxonomyKind;

/**
 * THE HUE IS A POSITION, AND NOBODY CHOOSES IT.
 *
 * RULED 2026-09-21: a module declares no colour. What this module owns is the
 * ORDER of an area's kinds; the house's categorical set does the rest. So a
 * kind answers WHICH of the nine it is — first kind, first hue — and the
 * number is read off its place in the area's list, never stored as a choice.
 *
 * Two properties this has to hold, and both are about an area that kept
 * editing:
 *
 *   IT WRAPS. The set has nine members; a tenth kind starts again at one
 *   rather than falling off the end into a colour nothing defines. A taxonomy
 *   past nine is a design problem, not a palette problem.
 *
 *   IT NEVER ANSWERS OUT OF RANGE. Position is an integer column an older
 *   release left zeroes and gaps in, so the answer is clamped into 1..9 for
 *   any value at all — including the negative one no code writes today and
 *   some import tomorrow will.
 */
final class TaxonomyKindCatIndexTest extends TestCase
{
    #[DataProvider('positions')]
    public function testThePositionInTheAreasListIsTheHueItWears(int $position, int $expected): void
    {
        $kind = new TaxonomyKind(new AreaOfInterest(), 'poaching', 'Poaching');
        $kind->setPosition($position);

        self::assertSame($expected, $kind->catIndex());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function positions(): iterable
    {
        yield 'the first kind takes the first hue' => [0, 1];
        yield 'the second takes the second' => [1, 2];
        yield 'the ninth takes the last of the set' => [8, 9];
        yield 'the tenth starts again' => [9, 1];
        yield 'the eighteenth is the ninth again' => [17, 9];
        yield 'a position an import left negative still draws' => [-3, 1];
    }
}
