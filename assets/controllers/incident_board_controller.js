import { Controller } from '@hotwired/stimulus';

/*
 * IN·12 — the status board.
 *
 * DRAGGING A CARD BETWEEN COLUMNS *IS* THE TRANSITION. There is no separate
 * "change status" form in this direction: the drop posts the move and the server
 * decides. An ILLEGAL MOVE REFUSES — the workflow's guards answer 422 with the
 * reason, which is shown as it came, and the card goes back where it was. The UI
 * never decides what is allowed; it only asks.
 *
 * WITHOUT JAVASCRIPT the board is still whole: every card is a link to its case
 * file, where the same transitions are ordinary buttons. This controller is an
 * accelerator, never the only door.
 */
export default class extends Controller {
    static values = {
        // A transition URL with a placeholder reference and transition in it —
        // the routes are the server's to name, so the template renders one and
        // this substitutes into it rather than building URLs of its own.
        url: String,
        // The surface's CSRF token. A state-changing write carries one whichever
        // door it comes through, and a board that posted without it would 403 in
        // a browser while every server-side test passed — each of those builds
        // its own token, and only a person ever sees the difference.
        csrf: String,
    };

    connect() {
        this.dragged = null;
        // No token means no permission to move: the board stays a board of links.
        this.readOnly = '' === this.csrfValue;
        this.element.addEventListener('dragstart', this.onDragStart);
        this.element.addEventListener('dragover', this.onDragOver);
        this.element.addEventListener('drop', this.onDrop);
    }

    disconnect() {
        this.element.removeEventListener('dragstart', this.onDragStart);
        this.element.removeEventListener('dragover', this.onDragOver);
        this.element.removeEventListener('drop', this.onDrop);
    }

    onDragStart = (event) => {
        const card = event.target.closest('.i-card');
        if (!card || this.readOnly) {
            return;
        }
        this.dragged = card;
        event.dataTransfer.effectAllowed = 'move';
    };

    onDragOver = (event) => {
        if (this.dragged && event.target.closest('.i-col')) {
            event.preventDefault();
        }
    };

    onDrop = async (event) => {
        const column = event.target.closest('.i-col');
        if (!this.dragged || !column) {
            return;
        }
        event.preventDefault();

        const card = this.dragged;
        const from = card.closest('.i-col');
        this.dragged = null;
        if (from === column) {
            return;
        }

        // Optimistic: the card moves at once, and goes back if the workflow says
        // no. A board that waited for the round trip would feel broken.
        column.appendChild(card);

        const body = new FormData();
        body.append('_token', this.csrfValue);

        const response = await fetch(this.transitionUrl(card, column), {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body,
        });

        if (response.ok || response.redirected) {
            return;
        }

        from.appendChild(card);
        // The guard's own sentence — the same words the case file's toolbar
        // prints beside the moves that are allowed.
        window.alert(await response.text());
    };

    transitionUrl(card, column) {
        return this.urlValue
            .replace('INC-0000', encodeURIComponent(card.dataset.reference))
            .replace(/\/transition\/[a-z_]+$/, `/transition/${TRANSITIONS[column.dataset.place] ?? 'verify'}`);
    }
}

/**
 * Which move lands an incident in which column. The names are the workflow's own
 * ({@see Uhifadhi\Incident\Enum\IncidentTransitionEnum}); a column with no
 * move into it — "closed", which the clock reaches and no person does — has no
 * entry here, so a drop onto it asks for a move the server refuses and says why.
 */
const TRANSITIONS = {
    verified: 'verify',
    in_progress: 'respond',
    resolved: 'resolve',
};
