import { Controller } from '@hotwired/stimulus';

/*
 * THE REPORT SHEET — one component, mounted over whichever surface you were on.
 * Filing an incident must not feel like five different products just because
 * five dashboards can lead to it.
 *
 * STEP 2 IS THE CATEGORY'S OWN. Choosing a kind of incident swaps in that kind's
 * field set, and the money row is ABSENT — not disabled — for a category that
 * carries no money. That is the design's contract and it is enforced here by
 * removing the fieldset from the document, never by greying it.
 *
 * The whole page works without this controller: /areas/{uuid}/modules/incidents/new
 * is the same sheet as its own page, and the form posts normally. What this adds
 * is opening it over a dashboard and switching the field set without a round
 * trip.
 */
export default class extends Controller {
    static targets = ['sheet', 'category', 'fieldset'];

    connect() {
        this.lastTrigger = null;
        this.onKey = (event) => {
            if ('Escape' === event.key) {
                this.close();
            }
        };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    open(event) {
        event?.preventDefault();
        this.lastTrigger = event?.currentTarget ?? null;
        this.sheetTarget.classList.add('open');
        this.sheetTarget.querySelector('.i-catopt')?.focus();
    }

    close() {
        this.sheetTarget.classList.remove('open');
        this.lastTrigger?.focus();
    }

    /** The backdrop closes it; a click inside the sheet does not. */
    closeOnBackdrop(event) {
        if (event.target === this.sheetTarget) {
            this.close();
        }
    }

    /**
     * Choose a kind of incident, or a sibling sub-category within it: that
     * sub-category's own field set replaces whatever was there.
     *
     * Both doors call this. A step-1 card carries its whole kind's slugs, so the
     * card stays lit while you move between its siblings in step 2; a step-2 chip
     * carries only its own.
     */
    choose(event) {
        event.preventDefault();
        const slug = event.currentTarget.dataset.subcategory;
        if (!slug) {
            return;
        }

        for (const option of this.categoryTargets) {
            const owns = (option.dataset.subcategories ?? '').split(',');
            option.classList.toggle('on', owns.includes(slug));
        }

        for (const fieldset of this.fieldsetTargets) {
            fieldset.hidden = fieldset.dataset.subcategory !== slug;
            // The hidden sets are DISABLED too, so the posted form carries only
            // the fields the chosen kind of incident actually asks for — and a
            // category that carries no money has no money row in it at all.
            for (const input of fieldset.querySelectorAll('input, textarea, select')) {
                input.disabled = fieldset.hidden;
            }
        }

        const chosen = this.element.querySelector('[data-incident-report-subcategory]');
        if (chosen) {
            chosen.value = slug;
        }
    }
}
