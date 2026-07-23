import { Controller } from '@hotwired/stimulus';

/**
 * Group fill & export page: one unified form per group whose values fan out
 * to every member variant. This controller owns the LIVE PREVIEWS — after
 * the user stops typing it POSTs the whole form to each variant's preview
 * endpoint in parallel and swaps the returned PNGs in via blob URLs — plus
 * the image-picker modals and small form UX (Enter must not submit; the
 * submit button shows progress while the browser downloads the ZIP).
 *
 * The element this controller attaches to IS the <form>, so `new
 * FormData(this.element)` always carries the exact state the export POST
 * would send — previews and the ZIP can never disagree.
 */
export default class extends Controller {
    static targets = ['preview', 'imageThumb', 'imageValue', 'imageOptions', 'exportButton'];

    static values = {
        debounce: { type: Number, default: 900 },
    };

    initialize() {
        this.refreshTimer = null;
        this.exportTimer = null;
        // Keyed by preview endpoint URL (one per variant).
        this.aborters = new Map();
        this.objectUrls = new Map();
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
        clearTimeout(this.exportTimer);
        this.aborters.forEach((aborter) => aborter.abort());
        this.aborters.clear();
        this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
        this.objectUrls.clear();
    }

    changed() {
        clearTimeout(this.refreshTimer);
        this.previewTargets.forEach((img) => img.closest('.group-fill-preview-frame')?.classList.add('is-pending'));
        this.refreshTimer = setTimeout(() => this.refreshAll(), this.debounceValue);
    }

    // Enter in a fill field must never trigger the ZIP download — only the
    // explicit export button submits.
    blockEnter(event) {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type === 'text') {
            event.preventDefault();
        }
    }

    pickImage(event) {
        const option = event.currentTarget;

        this.selectImage(
            option.dataset.inputId,
            option.dataset.imageId || '',
            option.dataset.imageUrl || '',
            option,
        );
    }

    // Mirrors the chosen picture into the hidden form field (the value the
    // preview + ZIP export actually run on), the side-panel thumbnail and the
    // picker's selection ring.
    selectImage(inputId, imageId, imageUrl, option) {
        const hiddenField = this.imageValueTargets.find((element) => element.dataset.inputId === inputId);
        if (hiddenField) {
            hiddenField.value = imageId;
        }

        const thumb = this.imageThumbTargets.find((element) => element.dataset.inputId === inputId);
        if (thumb) {
            thumb.style.backgroundImage = imageUrl !== '' ? `url('${imageUrl}')` : 'none';
        }

        const options = this.imageOptionsTargets.find((element) => element.dataset.inputId === inputId);
        if (options) {
            options.querySelectorAll('.group-fill-image-option').forEach((element) => {
                element.classList.toggle('selected', element === option);
            });
        }

        this.changed();
    }

    /**
     * "Upload your own image" inside a picker modal. The file goes to the
     * group-scoped placeholder upload endpoint (which validates the slot and
     * the target folder server-side), then lands in the project gallery — so
     * it is appended to this picker as a regular option and auto-picked.
     */
    uploadImage(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (!file) {
            return;
        }

        const inputId = event.params.inputid;
        const uploadUrl = event.params.uploadurl;

        const formData = new FormData();
        formData.append('file', file);

        // With several allowed folders the picker renders a select and the
        // server requires an explicit choice; a single target resolves
        // server-side.
        const directorySelect = this.element.querySelector(`select[data-upload-directory="${inputId}"]`);
        if (directorySelect && directorySelect.value) {
            formData.append('directoryId', directorySelect.value);
        }

        input.disabled = true;
        this.setUploadStatus(inputId, 'Nahrávám…', 'busy');

        fetch(uploadUrl, { method: 'POST', body: formData, headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data) => {
                if (!data || !data.id || !data.url) {
                    this.setUploadStatus(inputId, 'Nahrání obrázku se nepovedlo.', 'error');
                    return;
                }

                const option = this.appendImageOption(inputId, data.id, data.url);
                this.selectImage(inputId, data.id, data.url, option);
                this.setUploadStatus(inputId, 'Obrázek nahrán a vybrán.', 'ok');
            })
            .catch(() => {
                this.setUploadStatus(inputId, 'Nahrání obrázku se nepovedlo. Zkuste to znovu.', 'error');
            })
            .finally(() => {
                input.value = '';
                input.disabled = false;
            });
    }

    appendImageOption(inputId, imageId, imageUrl) {
        const options = this.imageOptionsTargets.find((element) => element.dataset.inputId === inputId);
        if (!options) {
            return null;
        }

        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'group-fill-image-option';
        option.dataset.action = 'group-fill#pickImage';
        option.dataset.inputId = inputId;
        option.dataset.imageId = imageId;
        option.dataset.imageUrl = imageUrl;
        option.dataset.bsDismiss = 'modal';

        const img = document.createElement('img');
        img.src = imageUrl;
        img.alt = '';
        img.loading = 'lazy';
        option.appendChild(img);

        options.appendChild(option);

        return option;
    }

    // Inline busy / success / error feedback (no blocking alert).
    setUploadStatus(inputId, text, kind) {
        const status = this.element.querySelector(`[data-upload-status="${inputId}"]`);
        if (!status) {
            return;
        }

        status.textContent = text;
        status.className = `small mt-1 ${kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted'}`;
        status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    }

    // The download itself is handled natively by the browser (Content-
    // Disposition: attachment), which gives no completion event — show
    // progress optimistically and re-enable after a generous window.
    exportStarted() {
        if (!this.hasExportButtonTarget) {
            return;
        }

        const button = this.exportButtonTarget;
        const originalHtml = button.innerHTML;

        setTimeout(() => {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Generuji ZIP…';
        }, 0);

        clearTimeout(this.exportTimer);
        this.exportTimer = setTimeout(() => {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }, 20000);
    }

    refreshAll() {
        const formData = new FormData(this.element);
        this.previewTargets.forEach((img) => this.refreshOne(img, formData));
    }

    async refreshOne(img, formData) {
        const endpoint = img.dataset.previewEndpoint;
        if (!endpoint) {
            return;
        }

        this.aborters.get(endpoint)?.abort();
        const aborter = new AbortController();
        this.aborters.set(endpoint, aborter);

        const frame = img.closest('.group-fill-preview-frame');
        frame?.classList.add('is-loading');
        frame?.classList.remove('is-error');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                signal: aborter.signal,
            });

            if (!response.ok) {
                throw new Error(`Preview render failed with HTTP ${response.status}`);
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);

            const previousUrl = this.objectUrls.get(endpoint);
            if (previousUrl) {
                URL.revokeObjectURL(previousUrl);
            }
            this.objectUrls.set(endpoint, objectUrl);

            img.src = objectUrl;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            frame?.classList.add('is-error');
        } finally {
            // A newer refresh may already own this endpoint — only the
            // latest request clears the loading chrome.
            if (this.aborters.get(endpoint) === aborter) {
                this.aborters.delete(endpoint);
                frame?.classList.remove('is-loading', 'is-pending');
            }
        }
    }
}
