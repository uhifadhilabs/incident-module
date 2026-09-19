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

namespace Uhifadhi\Incident\Model;

/**
 * THE HOUSE TOKEN, NAMED — for the contracts that still take a colour.
 *
 * RULED 2026-09-21: a module declares no colour. A category is a POSITION in a
 * declared order, and the house owns the nine hues those positions resolve to.
 * Where a contract takes the position itself — `AreaNavChild::$cat`,
 * `ChartSeries::$cat` — this module hands over the integer and nothing else.
 *
 * WHERE A CONTRACT STILL TAKES A STRING — the atlas layer's `swatch`, the
 * overview's `MapLayer` and `PulseEvent` — this is what goes in it: the house
 * token BY NAME, `var(--cat-3)`, resolved wherever it is drawn. The module
 * still states no value, and the string is written in one place so it cannot
 * drift from the one the shell defines.
 *
 * AND NOT EVERY MARK IS A CATEGORY. A thing told apart from its siblings takes
 * a position; a thing that MEANS something takes the semantic token that means
 * it, down the same path and as the same kind of string — which is what the
 * constants below are for.
 *
 * The spelling is load-bearing twice. It is the token the shell's palette
 * declares, and it is what the shell's plate rules match on —
 * `.viewer [fill="var(--cat-3)"]` repaints a marker with the imagery reading
 * of the same position — so a map can be drawn with the ordinary token and
 * still obey the plate. Spell it differently and the pin draws black.
 *
 * It goes away when those three contracts take an index like the other two do.
 */
final class HousePalette
{
    /** How many hues the house's categorical set has. */
    public const int CATEGORIES = 9;

    /** What an unknown position wears: the muted mark, which is the shell's own fallback. */
    public const string UNKNOWN = 'var(--fog)';

    /** Work that still needs doing. A state, so a meaning and not a position. */
    public const string OPEN = 'var(--warn)';

    /** Work that went well and is finished with. */
    public const string DONE = 'var(--ok)';

    /**
     * @param int $catIndex a position in the house's set, 1 to 9
     */
    public static function token(int $catIndex): string
    {
        if ($catIndex < 1 || $catIndex > self::CATEGORIES) {
            return self::UNKNOWN;
        }

        return \sprintf('var(--cat-%d)', $catIndex);
    }
}
