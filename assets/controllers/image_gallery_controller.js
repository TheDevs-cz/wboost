import { Controller } from "@hotwired/stimulus";

/**
 * Stage 7: thin glue controller for the Project:ImageGallery component
 * (editor modal + standalone gallery page).
 *
 * Two responsibilities:
 *   1. Translate a "click on a thumbnail" into a single semantic
 *      `asset-selected` CustomEvent on the modal root, carrying the asset's
 *      public URL and id (plus its file name and pixel size when known). The host editor's controller (canvas-editor) listens
 *      for this event on the modal element and routes the URL to either
 *      setBackgroundImage or addImageToCanvas based on the mode it opened the
 *      modal in.
 *   2. Turn the gallery-uploader's per-file `uploaded` events into in-place
 *      grid feedback: refresh the Live component (debounced — a fast batch
 *      re-renders once) and pulse the freshly uploaded thumbnails once they
 *      appear, matched by data-storage-path. There is deliberately NO
 *      auto-select after an upload: the user stays in the open folder and
 *      SEES the file land there — in the modal one click on the highlighted
 *      thumbnail then places it on the canvas.
 *
 * The Live component dispatches no DOM event after a morph, so freshly
 * rendered thumbnails are detected with a MutationObserver (the codebase's
 * standard pattern for reacting to Live re-renders). The observer watches
 * childList only, and highlighting mutates class attributes — no feedback
 * loop is possible.
 */
export default class extends Controller {
    static targets = ["refreshTrigger"];

    // How long an upload stays eligible for a highlight pulse (Live round
    // trips included), and how long the pulse itself shows.
    static HIGHLIGHT_WINDOW_MS = 10000;
    static HIGHLIGHT_SHOW_MS = 4000;

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
        // Tile metadata rides along for hosts that want it (a layer name, a
        // size check); consumers that only read url/path/id are unaffected.
        // Stimulus casts a numeric param to a Number and leaves '' as ''.
        const name = event.params.name || null;
        const width = Number.isFinite(event.params.width) ? event.params.width : null;
        const height = Number.isFinite(event.params.height) ? event.params.height : null;
        this.dispatchSelected({ url, path, id, name, width, height });
    }

    /**
     * Triggered once per uploaded FILE by the gallery-uploader controller via
     * the bubbling `gallery-uploader:uploaded` event. Detail: `{ asset }`.
     */
    onUploaded(event) {
        const asset = event.detail ? event.detail.asset : null;
        if (!asset || !asset.path) {
            return;
        }

        this._pendingHighlights.set(asset.path, Date.now() + this.constructor.HIGHLIGHT_WINDOW_MS);

        // Debounced so a burst of small files re-renders the grid once; a
        // slow big file still gets its own refresh when it lands.
        clearTimeout(this._refreshTimer);
        this._refreshTimer = setTimeout(() => this.refresh(), 350);
    }

    refresh() {
        // Click the hidden trigger that carries the live#action wiring —
        // programmatic Live Component invocation without coupling this
        // controller to the Live Component JS bundle.
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

        // storagePath → highlight-eligibility deadline. Entries survive
        // several morphs (each upload in a batch re-renders the grid) so a
        // pulse a later morph wiped is re-applied until the window closes.
        this._pendingHighlights = new Map();
        this._refreshTimer = null;

        this._observer = new MutationObserver(() => this._applyHighlights());
        this._observer.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        if (this._boundOnUploaded) {
            this.element.removeEventListener('gallery-uploader:uploaded', this._boundOnUploaded);
        }
        if (this._observer) {
            this._observer.disconnect();
            this._observer = null;
        }
        clearTimeout(this._refreshTimer);
    }

    _applyHighlights() {
        if (!this._pendingHighlights || this._pendingHighlights.size === 0) {
            return;
        }

        const now = Date.now();
        for (const [path, deadline] of this._pendingHighlights) {
            if (deadline < now) {
                this._pendingHighlights.delete(path);
                continue;
            }
            this.element.querySelectorAll(`.gallery-asset[data-storage-path="${CSS.escape(path)}"]`).forEach((el) => {
                if (el.classList.contains('gallery-asset--new')) {
                    return;
                }
                el.classList.add('gallery-asset--new');
                setTimeout(() => el.classList.remove('gallery-asset--new'), this.constructor.HIGHLIGHT_SHOW_MS);
            });
        }
    }

    dispatchSelected({ url, path, id, name = null, width = null, height = null }) {
        // Fire on the modal's root element so the host page can listen via
        // `@window` or by binding directly to the modal element. We use a
        // raw CustomEvent (not Stimulus' this.dispatch) because Stimulus
        // mangles the name into "<prefix>:<name>" — the canvas-editor
        // orchestrator subscribes to the literal "asset-selected" name so
        // this stays decoupled from the controller identifier.
        this.element.dispatchEvent(new CustomEvent('asset-selected', {
            detail: { url, path, id, name, width, height },
            bubbles: true,
        }));
    }
}
