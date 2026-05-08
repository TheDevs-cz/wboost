import { Controller } from "@hotwired/stimulus";

/**
 * Stage 7: small upload-form controller for the Project:ImageGallery modal's
 * "Nahrát nový" tab.
 *
 * Live Components don't have a built-in story for streaming a File through a
 * LiveProp, so the form posts directly to the existing `project_upload_file`
 * route via fetch(). On success it dispatches:
 *   - `asset-uploaded` (with { url, id }): consumed by the parent
 *     image-gallery controller, which forwards it as `asset-selected` so the
 *     uploaded image is immediately routed onto the canvas.
 *   - `gallery-uploader:uploaded` on window: the host page can listen via
 *     `data-action="gallery-uploader:uploaded@window->live#emit"` (or via
 *     `live#action`) to trigger a Live Component re-render so the new asset
 *     shows up in the grid the next time the user opens the gallery tab.
 */
export default class extends Controller {
    static targets = ["file", "error", "submitButton"];

    static values = {
        url: String,
    };

    async submit(event) {
        event.preventDefault();

        const file = this.fileTarget.files[0];
        if (!file) {
            return;
        }

        this.errorTarget.classList.add('d-none');
        this.errorTarget.textContent = '';
        this.submitButtonTarget.disabled = true;

        try {
            const formData = new FormData();
            formData.append('upload_project_file[file]', file);

            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                },
            });

            const data = await response.json();

            if (response.ok && data.filePath) {
                // Reset the form so a follow-up upload starts clean.
                this.element.reset();

                // Tell the parent gallery to route the freshly-uploaded asset
                // through its normal `asset-selected` flow.
                this.dispatch('uploaded', {
                    detail: {
                        url: data.filePath,
                        path: data.storagePath,
                    },
                    bubbles: true,
                });
            } else {
                this.showError(data.error || 'Nahrání se nepovedlo.');
            }
        } catch (error) {
            console.error('Gallery upload failed:', error);
            this.showError('Nahrání se nepovedlo. Zkuste to prosím znovu.');
        } finally {
            this.submitButtonTarget.disabled = false;
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.classList.remove('d-none');
    }
}
