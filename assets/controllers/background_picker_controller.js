import { Controller } from "@hotwired/stimulus";

/**
 * Gallery-backed background field for the add/edit template + group forms.
 *
 * One shared #backgroundGalleryModal per page (hosting the Project:ImageGallery
 * Live component, see _background_gallery_modal.html.twig) serves ANY number
 * of picker fields — the group form has one per dimension. Only the picker
 * that OPENED the modal consumes the gallery's `asset-selected` event (armed
 * flag); everyone else ignores it.
 *
 * Picking writes the gallery file's ID into the hidden form input — the
 * server resolves it into the file's storage path (ResolveGalleryBackground)
 * and REFERENCES it, exactly like the editor's "Pozadí" pick. Uploading
 * happens inside the modal through the regular gallery uploader, so a
 * freshly uploaded background lands IN the project gallery — the raw file
 * input this replaces used to bypass the gallery entirely (invisible there,
 * and existing gallery images were unpickable).
 */
export default class extends Controller {
    static targets = ["input", "preview", "clearButton"];
    static values = {
        modal: { type: String, default: "#backgroundGalleryModal" },
    };

    connect() {
        this._armed = false;
        this._thumbUrl = "";
        this._onSelected = (event) => this.assetSelected(event);
        window.addEventListener("asset-selected", this._onSelected);
        this._syncChrome();
    }

    disconnect() {
        window.removeEventListener("asset-selected", this._onSelected);
    }

    open() {
        const modalElement = document.querySelector(this.modalValue);
        if (!modalElement) {
            return;
        }

        this._armed = true;
        // Closing without a pick must disarm — otherwise a later pick made
        // for ANOTHER field would land here too.
        modalElement.addEventListener("hidden.bs.modal", () => {
            this._armed = false;
        }, { once: true });

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    assetSelected(event) {
        if (!this._armed || !event.detail || !event.detail.id) {
            return;
        }
        this._armed = false;

        this.inputTarget.value = event.detail.id;
        this._thumbUrl = event.detail.url || "";
        this._syncChrome();

        const modalElement = document.querySelector(this.modalValue);
        const modal = modalElement ? bootstrap.Modal.getInstance(modalElement) : null;
        if (modal) {
            modal.hide();
        }
    }

    clear() {
        this.inputTarget.value = "";
        this._thumbUrl = "";
        this._syncChrome();
    }

    _syncChrome() {
        const hasValue = this.inputTarget.value !== "";

        if (this.hasPreviewTarget) {
            this.previewTarget.classList.toggle("d-none", !hasValue || this._thumbUrl === "");
            this.previewTarget.style.backgroundImage = this._thumbUrl !== "" ? `url("${this._thumbUrl}")` : "";
        }
        if (this.hasClearButtonTarget) {
            this.clearButtonTarget.classList.toggle("d-none", !hasValue);
        }
    }
}
