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
 * WHAT KIND OF THING HAPPENED, on the append-only timeline that is the spine of
 * an incident record.
 *
 * Nothing on that timeline is ever edited or removed. A correction is a new NOTE
 * saying what was corrected — which is exactly what makes the record worth
 * anything in a hearing.
 */
enum IncidentEventKindEnum: string
{
    /** The incident moved from one place of the workflow to another. */
    case Transition = 'transition';

    /** Somebody wrote something down. Corrections are these. */
    case Note = 'note';

    /** Evidence was attached. */
    case Evidence = 'evidence';

    /** A figure on the money record changed. */
    case Money = 'money';

    /**
     * WHAT HAPPENED, as the area pulse prints it — the module's own words for
     * its own move, which the host never interprets.
     *
     * A transition says nothing here on purpose: the row wears the module's
     * status chip for the place it landed in, and repeating the place in the
     * verb would print it twice. See
     * {@see \Uhifadhi\Incident\Overview\IncidentPulse}.
     */
    public function moveLabel(): string
    {
        return match ($this) {
            self::Transition => 'moved to',
            self::Note => 'note added',
            self::Evidence => 'evidence attached',
            self::Money => 'money updated',
        };
    }

    /** The modifier the `.i-tl-item` wears; a note is the timeline's default. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Transition => 'tr',
            self::Evidence => 'ev',
            self::Money => 'mo',
            self::Note => '',
        };
    }
}
