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
 * HOW LONG, SAID THE WAY THIS MODULE SAYS IT.
 *
 * The design writes an age in hours up to two days and in days after that,
 * because "62 h" is a number a person converts and "3 d" is one they read. The
 * module's own `_ui.html.twig` ageing chip already draws it that way; this is
 * the same rule in PHP, for the places a host prints the module's words without
 * rendering its templates — an attention item's age label, a tile's alarm.
 *
 * ONE PLACE, because an incident that reads "17 d" on the area overview and
 * "408 h" on its own case file is two records as far as anybody reading is
 * concerned.
 */
final class IncidentAge
{
    /** The hour/day boundary the design draws at — two days. */
    public const int HOURS_BEFORE_DAYS = 48;

    public static function label(int $hours): string
    {
        return $hours < self::HOURS_BEFORE_DAYS
            ? \sprintf('%d h', $hours)
            : \sprintf('%d d', intdiv($hours, 24));
    }

    /** The same age in words, for a sentence rather than a chip: "17 days", "6 hours". */
    public static function words(int $hours): string
    {
        if ($hours < self::HOURS_BEFORE_DAYS) {
            return \sprintf('%d %s', $hours, 1 === $hours ? 'hour' : 'hours');
        }

        $days = intdiv($hours, 24);

        return \sprintf('%d %s', $days, 1 === $days ? 'day' : 'days');
    }
}
