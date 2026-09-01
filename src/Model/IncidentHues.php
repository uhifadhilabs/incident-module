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

namespace UhifadhiLabs\Incident\Model;

/**
 * THE FOUR CATEGORY HUES, AS COLOURS RATHER THAN AS CLASS NAMES.
 *
 * Everywhere this module renders its own markup it names a hue by its KEY —
 * `.i-cat.poach`, `.i-hue-mort` — and incidents.css turns the key into a colour.
 * That is right on this module's own pages and impossible off them: a map layer
 * hands the HOST a swatch, and the host draws a legend for modules whose
 * stylesheets it has never read.
 *
 * A LAYER'S COLOUR IS DATA. The host's own contract says so, and it follows that
 * the colour is the same in light and dark and is stated once. These are the
 * dark-theme values from incidents.css, which are the values the design's map
 * plate draws on — a map has one ground and it is not the page's.
 *
 * If a hue changes it changes in BOTH places, which is the price of a colour
 * that has to exist in CSS and in PHP. There are four of them and they have not
 * moved since the taxonomy was written.
 */
final class IncidentHues
{
    /** The key a colour the stylesheet has never heard of falls back to. */
    public const string FALLBACK = '#B9C8BD';

    /** @var array<string, string> colour key => the CSS colour incidents.css gives it */
    private const array HUES = [
        'poach' => '#E05B41',
        'hwc' => '#DBA33F',
        'comp' => '#8E7BE0',
        'mort' => '#5FA8E0',
    ];

    public static function of(string $colourKey): string
    {
        return self::HUES[$colourKey] ?? self::FALLBACK;
    }
}
