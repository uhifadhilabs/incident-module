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

namespace UhifadhiLabs\Incident\Enum;

/**
 * HOW BAD — three steps, chosen at filing and revisable until verification.
 *
 * Severity is deliberately not a number: a scale of ten invites an argument
 * about the difference between a six and a seven, and the design's map legend
 * only ever draws one distinction (a dashed ring marks HIGH).
 */
enum IncidentSeverityEnum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [self::Low, self::Medium, self::High];
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
            self::Medium => 'md',
            self::High => 'hi',
        };
    }
}
