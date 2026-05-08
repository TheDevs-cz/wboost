import { Controller } from "@hotwired/stimulus";

/**
 * Stage 7: thin glue controller for the Project:ImageGallery modal.
 *
 * Two responsibilities:
 *   1. Translate a "click on a thumbnail" into a single semantic
 *      `asset-selected` CustomEvent on the modal root, carrying the asset's
 *      public URL and id. The host editor's controller (canvas-editor) listens
 *      for this event on the modal element and routes the URL to either
 *      setBackgroundImage or addImageToCanvas based on the mode it opened the
 *      modal in.
 *   2. Forward the gallery-uploader's `uploaded` event as the same
 *      `asset-selected` event, so a freshly-uploaded image is immediately
 *      routed onto the canvas without the user having to click the new
 *      thumbnail. The Live Component will pick it up on its next render.
 *
 * The canvas-editor orchestrator is responsible for closing the modal once
 * the asset has been processed, so the orchestrator owns the user-facing
 * "select → close" flow end-to-end.
 */
export default class extends Controller {
    static targets = ["refreshTrigger"];

    /**
     * Triggered by `data-action="click->image-gallery#select"` on each
     * thumbnail button.
     */
    select(event) {
        const url = event.params.url;
        const path = event.params.path;
        const id = event.params.id;
        if (!url) {
            return;
        }
        this.dispatchSelected({ url, path, id });
    }

    /**
     * Triggered by the gallery-uploader controller after a successful upload
     * via the `gallery-uploader:uploaded` event we listen for in connect().
     */
    onUploaded(event) {
        const { url, path, id } = event.detail || {};
        if (!url) {
            return;
        }
        this.dispatchSelected({ url, path, id });

        // Tell the Live Component to re-fetch the asset list so the freshly
        // uploaded image is in the grid the next time the user opens the
        // modal. Click the hidden trigger that carries the live#action
        // wiring — programmatic Live Component invocation without coupling
        // this controller to the Live Component JS bundle.
        if (this.hasRefreshTriggerTarget) {
            this.refreshTriggerTarget.click();
        }
    }

    connect() {
        // Listen for the upload controller's bubbled event. We can't use
        // declarative data-action on the form because the form is rendered
        // inside this same root and the action would race with the
        // gallery-uploader controller's own connect() — wiring it here makes
        // the dependency direction explicit (image-gallery hosts uploader).
        this._boundOnUploaded = this.onUploaded.bind(this);
        this.element.addEventListener('gallery-uploader:uploaded', this._boundOnUploaded);
    }

    disconnect() {
        if (this._boundOnUploaded) {
            this.element.removeEventListener('gallery-uploader:uploaded', this._boundOnUploaded);
        }
    }

    dispatchSelected({ url, path, id }) {
        // Fire on the modal's root element so the host page can listen via
        // `@window` or by binding directly to the modal element. We use a
        // raw CustomEvent (not Stimulus' this.dispatch) because Stimulus
        // mangles the name into "<prefix>:<name>" — the canvas-editor
        // orchestrator subscribes to the literal "asset-selected" name so
        // this stays decoupled from the controller identifier.
        this.element.dispatchEvent(new CustomEvent('asset-selected', {
            detail: { url, path, id },
            bubbles: true,
        }));
    }
}
