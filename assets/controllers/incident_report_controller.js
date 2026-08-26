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
    static targets = ['sheet', 'category', 'fieldset', 'gate', 'hint', 'file'];

    connect() {
        this.lastTrigger = null;
        this.gate();
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

        this.gate();
    }

    /**
     * THE GATE. Steps 1 and 2 are required and step 3 is not: filing stays shut
     * until a kind is chosen, one line says what happened and the place is
     * marked, and a quiet line names whatever is still missing rather than
     * making anybody press a disabled button to find out.
     *
     * These are exactly the three the SERVER refuses a filing without — the
     * button ships disabled in the markup and only this can open it, so a
     * refusal never arrives as a surprise after the fact.
     */
    gate() {
        const missing = [];
        if ('' === this.answer('[data-incident-report-subcategory]')) {
            missing.push('choose a category');
        }
        if ('' === this.answer('[name="title"]')) {
            missing.push('describe what happened');
        }
        if (!this.hasPosition()) {
            missing.push('mark where it happened');
        }

        if (this.hasFileTarget) {
            this.fileTarget.disabled = 0 !== missing.length;
        }
        // The gate REPLACES the reassuring line rather than crowding in beside
        // it: both are the same quiet note in the same place, and only one of
        // them is worth reading at a time.
        if (this.hasGateTarget) {
            this.gateTarget.textContent = missing.join(' · ');
            this.gateTarget.hidden = 0 === missing.length;
        }
        if (this.hasHintTarget) {
            this.hintTarget.hidden = 0 !== missing.length;
        }
    }

    /**
     * What is currently ANSWERED for a field. The field sets that are not the
     * chosen kind's are disabled, so only the live one is asked — the same rule
     * the posted form obeys.
     */
    answer(selector) {
        for (const field of this.element.querySelectorAll(selector)) {
            if (!field.disabled) {
                return field.value.trim();
            }
        }

        return '';
    }

    /** A place is two readable numbers, which is what the server stores as a point. */
    hasPosition() {
        const lat = this.answer('[name="lat"]');
        const lng = this.answer('[name="lng"]');

        return '' !== lat && '' !== lng && !Number.isNaN(Number(lat)) && !Number.isNaN(Number(lng));
    }
}
