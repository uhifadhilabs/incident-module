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

namespace Uhifadhi\Incident\Enum;

/**
 * HOW BAD — four steps, chosen at filing and revisable until verification.
 *
 * Severity is deliberately not a number: a scale of ten invites an argument
 * about the difference between a six and a seven. The design's map legend draws
 * one distinction — the ring marks the serious end: HIGH or CRITICAL.
 */
enum IncidentSeverityEnum: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Gentlest first — the order the bars are built in before being reversed to
     * read worst-first ({@see IncidentDashboard::severitiesWorstFirst}).
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Low, self::Moderate, self::High, self::Critical];
    }

    public function label(): string
    {
        return $this->value;
    }

    /** The class the `.i-sev` chip wears. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Low => 'lo',
            self::Moderate => 'mod',
            self::High => 'hi',
            self::Critical => 'crit',
        };
    }
}
