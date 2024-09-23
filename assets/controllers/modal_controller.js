import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    modal = null;

    initialize() {
        this.modal = Modal.getOrCreateInstance(this.element);
        window.addEventListener('modal:close', () => this.hideModals() );
    }

    hideModals() {
        this.modal.hide();
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    }
}
