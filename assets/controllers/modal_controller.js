import { Controller } from '@hotwired/stimulus';

/**
 * A Bootstrap modal that closes itself:
 *
 * - on the window `modal:close` event (dispatched by Live components);
 * - after its Turbo-enabled form saved — `turbo:submit-end` with
 *   `detail.success` (a 2xx answer; the manual preview's pencil modals get a
 *   Turbo Stream that updates the page behind the modal, see
 *   Services/Manual/ManualPreviewTurboStream). A validation error comes back
 *   as 422 → success false → the modal stays open showing the errors.
 *
 * The stream never replaces the modal element itself, so Bootstrap's own
 * hide() removes the backdrop and `body.modal-open` as usual.
 */
export default class extends Controller {
    modal = null;

    initialize() {
        // Use the global Bootstrap instance
        this.modal = window.bootstrap.Modal.getOrCreateInstance(this.element);
        window.addEventListener('modal:close', () => this.hideModals() );
        this.element.addEventListener('turbo:submit-end', (event) => {
            if (event.detail?.success) {
                this.modal.hide();
            }
        });
    }

    hideModals() {
        this.modal.hide();
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach(backdrop => backdrop.remove());
    }
}
