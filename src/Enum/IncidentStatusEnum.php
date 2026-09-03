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
 * WHERE AN INCIDENT IS — the five places of the incident workflow, and the only
 * five there are.
 *
 * The design's model card (IN·M1) states the chain: reported → verified → in
 * progress → resolved → closed. Two rulings pin it shut:
 *
 *  - A community or SMS report is an ordinary `reported` incident wearing a
 *    SOURCE badge ({@see IncidentSourceEnum}). It is NOT a sixth place before
 *    `reported`: a place is a stage of work, and "we do not trust the reporter"
 *    is a property of the report, not a stage of working it.
 *  - `closed` is reached BY TIME, never by a person. It is a place all the same,
 *    because the record has to be able to say it is finished.
 *
 * The legal moves between these places live in {@see IncidentTransitionEnum} and
 * the guards on them in {@see \Uhifadhi\Incident\Workflow\IncidentWorkflow} —
 * this enum knows only the places and how they read.
 */
enum IncidentStatusEnum: string
{
    case Reported = 'reported';
    case Verified = 'verified';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * The places in workflow order — the order the rail draws them, the order the
     * status board columns sit in, and the order the funnel narrows through.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Reported, self::Verified, self::InProgress, self::Resolved, self::Closed];
    }

    /** 1…5 — the number the rail prints inside an unreached step's disc. */
    public function step(): int
    {
        return match ($this) {
            self::Reported => 1,
            self::Verified => 2,
            self::InProgress => 3,
            self::Resolved => 4,
            self::Closed => 5,
        };
    }

    /** The design's own words. "in progress" is two words on screen and one column in the database. */
    public function label(): string
    {
        return match ($this) {
            self::Reported => 'reported',
            self::Verified => 'verified',
            self::InProgress => 'in progress',
            self::Resolved => 'resolved',
            self::Closed => 'closed',
        };
    }

    /** The class the `.i-st` chip wears — the keys incidents.css already colours. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Reported => 'rep',
            self::Verified => 'ver',
            self::InProgress => 'wip',
            self::Resolved => 'res',
            self::Closed => 'cls',
        };
    }

    /**
     * Whether this is still somebody's work. The dashboard's "open incidents"
     * figure is exactly this set — the design spells it out as "7 reported · 13
     * verified · 11 in progress", so resolved work is finished work even before
     * the clock closes it.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Reported, self::Verified, self::InProgress => true,
            self::Resolved, self::Closed => false,
        };
    }

    /**
     * Whether an incident here has already been through `$place`. The rail draws a
     * tick for every passed step, and a gated panel renders only when its step has
     * been reached — this is the one question both of them ask.
     */
    public function hasReached(self $place): bool
    {
        return $this->step() >= $place->step();
    }
}
