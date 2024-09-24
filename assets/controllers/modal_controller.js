import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    modal = null;

    initialize() {
        // Use the global Bootstrap instance
        this.modal = window.bootstrap.Modal.getOrCreateInstance(this.element);
        window.addEventListener('modal:close', () => this.hideModals() );
    }

    hideModals() {
        this.modal.hide();
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    }
}
