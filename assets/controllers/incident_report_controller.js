import { Controller } from '@hotwired/stimulus';

/*
 * THE REPORT FLOW — one page, and the little it needs a round trip to avoid.
 *
 * The flow lives on ONE ROUTE, in ONE CONTAINER (the full page, ruled direction
 * A). There is no drawer to slide, dismiss or guard any more — a page cannot be
 * thrown away by a click beside it, so this controller no longer opens, closes or
 * animates anything. What it adds is only what the server cannot do without a
 * round trip: swapping the sub-category's own field set, and the gate.
 *
 * STEP 2 IS THE CATEGORY'S OWN. Choosing a kind of incident swaps in that kind's
 * field set, and the money row is ABSENT — not disabled — for a sub-category that
 * carries no money. That is the design's contract and it is enforced by the
 * template, which renders no money row there at all.
 *
 * THE WHOLE FLOW WORKS WITHOUT THIS CONTROLLER: the category is a radio in the
 * noscript block, the File control ships disabled and the form posts normally to
 * the same endpoint, which refuses the same three omissions.
 */
export default class extends Controller {
    static targets = ['category', 'fieldset', 'gate', 'hint', 'file', 'headline'];

    connect() {
        this.gate();
        // A note copied in from an observation is routinely two lines long, so
        // the box is the size of its answer from the first paint — not after the
        // filer has scrolled a sentence sideways to read it.
        if (this.hasHeadlineTarget) {
            this.fit(this.headlineTarget);
        }
    }

    /**
     * WHAT HAPPENED GROWS TO FIT WHAT HAPPENED. The answer is a sentence, and a
     * sentence that scrolls sideways past a cursor cannot be re-read before it is
     * filed. The `rows` attribute is the floor, so the box is never smaller than
     * the markup promised and never larger than its content needs.
     */
    grow(event) {
        this.fit(event.currentTarget);
    }

    fit(field) {
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight}px`;
    }

    /**
     * Choose a kind of incident, or a sibling sub-category within it: that
     * sub-category's own field set replaces whatever was there.
     *
     * A step-1 card carries its whole kind's slugs, so it stays lit while you move
     * between its siblings in step 2; a step-2 chip carries only its own.
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
            // sub-category that carries no money has no money row in it at all.
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
     * THE GATE. Three answers file an incident and nothing else does, so filing
     * stays shut until a kind is chosen, one line says what happened and the
     * place is marked — and a quiet line names whatever is still missing rather
     * than making anybody press a disabled button to find out.
     *
     * These are exactly the three the SERVER refuses a filing without — the
     * button ships disabled in the markup and only this can open it, so a
     * refusal never arrives as a surprise after the fact. The template renders
     * the same list on first paint, so a filing that arrived with its place
     * already answered is never asked for it again.
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
