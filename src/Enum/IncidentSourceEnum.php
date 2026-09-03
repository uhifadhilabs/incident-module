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
 * WHERE THE REPORT CAME FROM — a badge on the record, never a stage of work.
 *
 * THE RULING this enum exists to carry: a community or SMS report enters the
 * workflow at `reported` like every other incident, wearing this badge. There is
 * no sixth place before `reported` for an untrusted reporter, because a place is
 * a stage of WORK and "we have not met this reporter" is a property of the
 * REPORT. What the badge buys is honesty on the register and in the verification
 * queue: the person deciding where to drive today can see that one row is a
 * ranger's own note and another is a text message from a village.
 */
enum IncidentSourceEnum: string
{
    /** Taken by the authority itself — a walk-in at a station, or entered at a desk. */
    case Direct = 'direct';

    /** Filed from a patrol observation. Carries the provenance link the patrols module wrote. */
    case PatrolObservation = 'patrol_observation';

    /** Called or radioed in by a member of staff who is not the recorder. */
    case Radio = 'radio';

    /** A text message from the community. */
    case Sms = 'sms';

    /** Reported in person by a member of the community, outside a station. */
    case Community = 'community';

    /**
     * THE BADGE A WIRE TOKEN EARNS, and null where the token names nothing this
     * module has heard of.
     *
     * The seam's `source` names the SENDING MODULE, singular, as that module puts
     * it on the wire — patrol sends `patrol`. This enum names WHERE A REPORT CAME
     * FROM, which is a different vocabulary and a STORED one: `patrol_observation`
     * is on rows in the database and does not get renamed to tidy a query string.
     *
     * So the two are mapped here, in the one place, rather than left to a
     * fallback that happened to land on the right case. A token this module does
     * not know returns null and the caller decides — which is how a source that
     * arrives from a module written after this one still files a report.
     */
    public static function forToken(?string $token): ?self
    {
        if (null === $token || '' === trim($token)) {
            return null;
        }

        $token = strtolower(trim($token));

        return match ($token) {
            // What patrol puts on the wire, and its module slug as an alias.
            'patrol', 'patrols' => self::PatrolObservation,
            default => self::tryFrom($token),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'walk-in or desk report',
            self::PatrolObservation => 'filed from a patrol observation',
            self::Radio => 'radio or telephone',
            self::Sms => 'SMS from the community',
            self::Community => 'community report',
        };
    }

    /** The short word the register's provenance line prints under a row. */
    public function badge(): string
    {
        return match ($this) {
            self::Direct => 'direct report',
            self::PatrolObservation => 'patrol observation',
            self::Radio => 'radio',
            self::Sms => 'SMS',
            self::Community => 'community',
        };
    }

    /**
     * Whether the authority itself observed or took this report. It changes NO
     * permission and gates NO transition — it is what the badge means, and what a
     * verification queue sorts by when somebody has to choose what to check first.
     */
    public function isFirstParty(): bool
    {
        return match ($this) {
            self::Direct, self::PatrolObservation, self::Radio => true,
            self::Sms, self::Community => false,
        };
    }
}
