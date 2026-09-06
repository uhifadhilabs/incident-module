import { Controller } from '@hotwired/stimulus';

/*
 * The filter row is a GET form (see dashboard/_filters.html.twig). The category
 * dropdown has no submit button of its own — choosing a category IS the request,
 * the way a filter chip's click was — so this controller submits the form the
 * moment the select changes. The search box needs nothing here: pressing Enter
 * submits the form natively, and the status chips are ordinary links.
 *
 * requestSubmit(), not submit(): it fires the form's submit event and honours
 * validation, and (unlike the bare property) it is a real user submit that Turbo
 * picks up as a navigation rather than a full reload.
 */
export default class extends Controller {
    submit() {
        if (typeof this.element.requestSubmit === 'function') {
            this.element.requestSubmit();

            return;
        }
        this.element.submit();
    }
}
