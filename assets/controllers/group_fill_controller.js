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
    static targets = ['preview', 'imageThumb', 'imageValue', 'exportButton'];

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
        const inputId = option.dataset.inputId;
        const imageId = option.dataset.imageId || '';
        const imageUrl = option.dataset.imageUrl || '';

        const hiddenField = this.imageValueTargets.find((element) => element.dataset.inputId === inputId);
        if (hiddenField) {
            hiddenField.value = imageId;
        }

        const thumb = this.imageThumbTargets.find((element) => element.dataset.inputId === inputId);
        if (thumb) {
            thumb.style.backgroundImage = imageUrl !== '' ? `url('${imageUrl}')` : 'none';
        }

        const modal = option.closest('.modal');
        if (modal) {
            modal.querySelectorAll('.group-fill-image-option').forEach((element) => {
                element.classList.toggle('selected', element === option);
            });
        }

        this.changed();
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
