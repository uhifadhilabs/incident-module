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
 * THE LEGAL MOVES between the five places — four of them, and no others.
 *
 * A STATUS IS NEVER A DROPDOWN. The design draws the rail and offers only the
 * transitions that are legal from where the incident actually is; the values here
 * are what the transition route names, which is why they are stable strings and
 * not array indexes.
 *
 * There is deliberately no "reopen" and no "reject". Both were considered and
 * both are corrections, and a correction on this record type is an EVENT saying
 * what was corrected — not a rewind that erases the fact that a thing was once
 * resolved.
 */
enum IncidentTransitionEnum: string
{
    /** Somebody competent looked. The one step nothing may skip. */
    case Verify = 'verify';

    /** Work started: assigned, assessed, acted on. */
    case Respond = 'respond';

    /** The work is done — and the money, if this category carries any, is settled or waived. */
    case Resolve = 'resolve';

    /** The clock's own move. Never offered to a person. */
    case Close = 'close';

    public function fromPlace(): IncidentStatusEnum
    {
        return match ($this) {
            self::Verify => IncidentStatusEnum::Reported,
            self::Respond => IncidentStatusEnum::Verified,
            self::Resolve => IncidentStatusEnum::InProgress,
            self::Close => IncidentStatusEnum::Resolved,
        };
    }

    public function toPlace(): IncidentStatusEnum
    {
        return match ($this) {
            self::Verify => IncidentStatusEnum::Verified,
            self::Respond => IncidentStatusEnum::InProgress,
            self::Resolve => IncidentStatusEnum::Resolved,
            self::Close => IncidentStatusEnum::Closed,
        };
    }

    /** The words on the button, in the design's own voice. */
    public function label(): string
    {
        return match ($this) {
            self::Verify => 'Verify',
            self::Respond => 'Start response',
            self::Resolve => 'Mark resolved',
            self::Close => 'Close',
        };
    }

    /**
     * Whether a PERSON may ever make this move. `close` is reached by time, not by
     * a person — so it is false here, and the toolbar shows it as the blocked chip
     * the design draws rather than as a button nobody is allowed to press.
     */
    public function isOfferedToPeople(): bool
    {
        return self::Close !== $this;
    }
}
