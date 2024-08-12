import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    toggle(event) {
        const url = event.target.checked
            ? event.target.dataset.urlChecked
            : event.target.dataset.urlUnchecked;

        // TODO: change to Turbo.visit() but must return valid response
        Turbo.fetch(url);
    }
}
