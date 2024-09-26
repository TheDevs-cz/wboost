import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["modal"]

    showModal(event) {
        event.preventDefault();

        const modal = window.bootstrap.Modal.getOrCreateInstance(this.modalTarget);
        modal.show();
    }
}
