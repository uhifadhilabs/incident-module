import { Controller } from '@hotwired/stimulus';

/*
 * A `datetime-local` FIELD, IN THE READER'S OWN ZONE — both ways.
 *
 * THE PROBLEM THE FRAME'S `localtime` DOES NOT SOLVE. A printed instant is a
 * `<time datetime>` and the shell rewrites its text to the reader's zone. A FORM
 * FIELD cannot work that way: `datetime-local` has no zone in it at all. Its value
 * is a wall clock, so the same string means a different instant to every reader,
 * and a server that parses it in its own zone stores a moment nobody typed.
 *
 * SO THE PAGE STATES THE INSTANT AND THE BROWSER STATES THE ZONE.
 *
 *   The server prints the field in UTC and puts the instant it was filled from on
 *   `data-instant` — the machine value, offset-qualified, the same contract the
 *   `<time datetime>` attribute keeps.
 *
 *   On connect this converts that instant to the reader's wall clock and writes it
 *   into the field, so somebody in East Africa reads 21:32 for an 18:32Z
 *   observation rather than 18:32.
 *
 *   And it fills a hidden field with the reader's IANA zone, so whatever they
 *   leave in the field is read back in the zone they typed it in.
 *
 * WITH NO JAVASCRIPT NOTHING IS WRONG, ONLY LESS CONVENIENT: the field keeps its
 * UTC wall clock, the zone field stays empty, and the server reads UTC — which is
 * exactly what the page showed. That is why the server's fallback is UTC and never
 * the zone the server happens to run in.
 *
 * IT IS WRITTEN TO MOVE. Nothing here knows what an incident is; the day a second
 * module needs a moment field, this belongs beside `localtime` in the shell, where
 * a module would name no controller for it either.
 */
export default class extends Controller {
    static targets = ['input', 'zone'];

    connect() {
        this.zoneTarget.value = this.readerZone();

        const instant = this.inputTarget.dataset.instant;
        if (instant) {
            const value = this.wallClockOf(instant);
            if (value !== null) {
                this.inputTarget.value = value;
            }
        }
    }

    /*
     * The reader's own IANA zone, which is the one thing a server cannot know.
     * An engine that cannot say returns nothing, and nothing means UTC — the zone
     * the field was printed in.
     */
    readerZone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            return '';
        }
    }

    /*
     * An offset-qualified instant as the local wall clock `datetime-local` wants
     * ("2026-08-22T21:32"). Built out of the local parts rather than off an ISO
     * string, because `toISOString()` is UTC and every shortcut through it lands
     * back on the bug this controller exists to fix.
     */
    wallClockOf(instant) {
        const at = new Date(instant);
        if (Number.isNaN(at.getTime())) {
            return null;
        }

        const pad = (n) => String(n).padStart(2, '0');

        return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`
            + `T${pad(at.getHours())}:${pad(at.getMinutes())}`;
    }
}
