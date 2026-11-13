/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { retryScan, discardScan, restoreCalendarLayout } from '../ai-scan/lists.js';

/*
 * "Historie skenů" (templates/ai_scan/history.html.twig, docs/ai-scan/ux.md
 * §8): the rows are server-rendered; this runs the two row actions —
 * "Zkusit znovu" (opens the scan page) and "Zahodit" (asks first, then the
 * page reloads so the row shows `Zahozeno`), for a calendar scan also "Vrátit
 * původní rozvržení" (asks first when the layout was edited since) — and steps a thumbnail down
 * through its fallback pictures when the first one does not exist.
 */
export default class extends Controller {
    static values = { pageUrl: String, actionUrl: String };

    connect() {
        // `error` does not bubble — capture phase.
        this.onImageError = (event) => {
            const img = event.target;
            if (!(img instanceof HTMLImageElement)) return;
            // `a|b`: the next pictures to try (a scan that never passed the pre-check has only its original).
            const [next, ...rest] = (img.dataset.fallback || '').split('|').filter(Boolean);
            if (!next) { img.remove(); return; }
            img.dataset.fallback = rest.join('|');
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
        const started = await retryScan({ actionUrl: this.actionUrlValue, pageUrl: button.dataset.pageUrl || this.pageUrlValue, id: button.dataset.id });
        if (!started) button.disabled = false;
    }

    async restore(event) {
        const button = event.currentTarget;
        button.disabled = true;
        if (await restoreCalendarLayout({ url: button.dataset.url })) window.location.reload();
        else button.disabled = false;
    }

    async discard(event) {
        const button = event.currentTarget;
        if (await discardScan({ actionUrl: this.actionUrlValue, id: button.dataset.id, proposal: button.dataset.proposal === '1' })) {
            window.location.reload();
        }
    }
}
