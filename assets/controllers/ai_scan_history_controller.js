/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { retryScan, discardScan } from '../ai-scan/lists.js';

/*
 * "Historie skenů" (templates/ai_scan/history.html.twig, docs/ai-scan/ux.md
 * §8): the rows are server-rendered; this runs the two row actions —
 * "Zkusit znovu" (opens the scan page) and "Zahodit" (asks first, then the
 * page reloads so the row shows `Zahozeno`) — and steps a thumbnail down to
 * its fallback picture when the first one does not exist.
 */
export default class extends Controller {
    static values = { pageUrl: String, actionUrl: String };

    connect() {
        // `error` does not bubble — capture phase.
        this.onImageError = (event) => {
            const img = event.target;
            if (!(img instanceof HTMLImageElement)) return;
            const next = img.dataset.fallback;
            if (!next) { img.remove(); return; }
            img.dataset.fallback = '';
            img.src = next;
        };
        this.element.addEventListener('error', this.onImageError, true);
        // Pictures that failed before this controller connected.
        this.element.querySelectorAll('img[data-fallback]').forEach((img) => {
            if (img.complete && img.naturalWidth === 0) this.onImageError({ target: img });
        });
    }

    disconnect() {
        this.element.removeEventListener('error', this.onImageError, true);
    }

    async retry(event) {
        const button = event.currentTarget;
        button.disabled = true;
        const started = await retryScan({ actionUrl: this.actionUrlValue, pageUrl: this.pageUrlValue, id: button.dataset.id });
        if (!started) button.disabled = false;
    }

    async discard(event) {
        const button = event.currentTarget;
        if (await discardScan({ actionUrl: this.actionUrlValue, id: button.dataset.id, proposal: button.dataset.proposal === '1' })) {
            window.location.reload();
        }
    }
}
