import { Controller } from '@hotwired/stimulus';

/*
 * THE REPORT FLOW — the same behaviour in both of its containers.
 *
 * The flow lives on a ROUTE, in one of two containers: a slide-over panel when
 * the filing arrived from a record, the full page when it did not. Both post to
 * one endpoint and both gate the same three answers; the container is decided by
 * the address, never by a click, so nothing here opens or creates the flow.
 *
 * What this adds is the three things the server cannot do without a round trip:
 * swapping the sub-category's own field set, the gate, and — in the panel — the
 * slide, the dismissals and the guard on them.
 *
 * CLOSING THE PANEL IS EXPLICIT, AND ONLY EXPLICIT: the X, and Escape doing
 * exactly what the X does. Both lead to the address the X links to and both ASK
 * FIRST when anything has been typed. THE BACKDROP CLOSES NOTHING — a report in
 * progress must never be discardable by a click that missed the panel. And
 * ESCAPE BELONGS TO THE TOPMOST THING: with one of the source record's
 * photographs open over the panel, Escape closes the photograph and the panel
 * stays exactly where it was.
 *
 * STEP 2 IS THE CATEGORY'S OWN. Choosing a kind of incident swaps in that kind's
 * field set, and the money row is ABSENT — not disabled — for a sub-category that
 * carries no money. That is the design's contract and it is enforced by the
 * template, which renders no money row there at all.
 *
 * THE WHOLE FLOW WORKS WITHOUT THIS CONTROLLER: the panel ships out (the CSS only
 * hides it once this marks it as animated), the X and Cancel are real links, the
 * category is a radio in the noscript block, and the form posts normally to the
 * same endpoint, which refuses the same three omissions.
 */
export default class extends Controller {
    static targets = ['category', 'fieldset', 'gate', 'hint', 'file', 'slideover', 'form', 'headline'];

    connect() {
        this.gate();
        // A note copied in from an observation is routinely two lines long, so
        // the box is the size of its answer from the first paint — not after the
        // filer has scrolled a sentence sideways to read it.
        if (this.hasHeadlineTarget) {
            this.fit(this.headlineTarget);
        }
        this.onKey = (event) => {
            if ('Escape' === event.key && !this.somethingIsOpenOnTop()) {
                this.close(event);
            }
        };
        document.addEventListener('keydown', this.onKey);

        if (this.hasSlideoverTarget) {
            // What the form said before anybody touched it, so "has anything been
            // typed" is a comparison rather than a guess.
            this.pristine = this.snapshot();
            // Marking it animated is what hands the CSS permission to hide the
            // panel — until then it is simply out, so a filer with no JavaScript
            // never meets an empty screen.
            this.slideoverTarget.dataset.roAnimated = '';
            requestAnimationFrame(() => this.slideoverTarget.classList.add('open'));
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    /**
     * CLOSE THE PANEL — and ask first if there is anything to lose. The way out
     * is the address the X already points at, so with the controller or without
     * it, closing lands in the same place: back at the record this filing came
     * from.
     */
    close(event) {
        if (!this.hasSlideoverTarget) {
            return;
        }
        event?.preventDefault();

        if (this.isDirty() && !window.confirm('Close without filing? What you have typed will be lost.')) {
            return;
        }

        this.slideoverTarget.classList.remove('open');
        // Let the panel play out before the page changes under it; the address is
        // the same one the X links to, so this is the link, slowed down.
        const back = this.element.querySelector('.ro-dhd a.x')?.href;
        if (!back) {
            return;
        }
        window.setTimeout(() => {
            window.location.href = back;
        }, 240);
    }

    /**
     * IS SOMETHING OPEN ON TOP OF THE PANEL?
     *
     * ESCAPE BELONGS TO THE TOPMOST THING. A filer who opened one of the source
     * record's photographs and pressed Escape is closing THE PHOTOGRAPH — and
     * must not also lose the report behind it. The file preview is a separate
     * bundle's component on its own document listener, with no ordering between
     * the two, so this is READ OFF THE DOM rather than agreed between them:
     * whatever is above us is simply a modal dialog that is being rendered and
     * is not this panel's own.
     *
     * getClientRects() rather than offsetParent, because an overlay is normally
     * position:fixed, for which offsetParent is null whether it is open or not.
     */
    somethingIsOpenOnTop() {
        const own = this.element.querySelector('.ro-drawer');

        return [...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
            .some((dialog) => dialog !== own && 0 !== dialog.getClientRects().length);
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

    /** Whether anything has been answered since the form was rendered. */
    isDirty() {
        return this.hasFormTarget && this.snapshot() !== this.pristine;
    }

    snapshot() {
        if (!this.hasFormTarget) {
            return '';
        }

        return [...new FormData(this.formTarget).entries()]
            .map(([name, value]) => `${name}=${value}`)
            .join('&');
    }

    /**
     * Choose a kind of incident, or a sibling sub-category within it: that
     * sub-category's own field set replaces whatever was there.
     *
     * Both containers call this. A step-1 card or chip carries its whole kind's
     * slugs, so it stays lit while you move between its siblings in step 2; a
     * step-2 chip carries only its own.
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
