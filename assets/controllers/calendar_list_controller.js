/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { normalizeDocument } from '../calendar/model/normalize.js';
import { buildPages, weekPages } from '../calendar/generate/pages.js';
import { pluralPages } from '../calendar/generate/czech.js';
import { createCanvasMeasurer, ensureFontsLoaded, usedFamilies } from '../calendar/render/measure.js';
import { fetchHistory } from '../calendar/state/api.js';
import { relativeTime, toast } from '../calendar/ui/dom.js';
import { openExport } from '../calendar/ui/export.js';
import { openHistory } from '../calendar/ui/history.js';
import { mountLazyThumb, renderThumb } from '../calendar/ui/thumb.js';

const PRESET_WORDS = { photoLeft: 'fotka vlevo', photoRight: 'fotka vpravo', twoSided: 'dvoustranné' };

/**
 * Projekt › Kalendáře (docs/calendar/architecture.md §D.3). The page works
 * without this controller (open, create, delete are plain links and forms);
 * it completes every card:
 *
 *   env    <script type="application/json"> shared by all cards: { projectName, fonts, palette, currentYear, … }
 *   card   li.cal-card[data-calendar-id]
 *   doc    <script type="application/json"> per card: { urls: { workspace, history, card } } — addresses only
 *   thumb  .cal-thumb  ← inline SVG of the first week page (the cover while no layout exists), lazy
 *   meta   .cal-meta   ← "Týdenní · 2027 · fotka vlevo · 55 stran"
 *   time   <time datetime> ← relative time ("před 5 min")
 *
 * The page carries no calendar document. A card fetches its own
 * (`urls.card` → `{ calendar, assets }`) when it scrolls into view, then
 * fills `meta` and `thumb`; until then — and if the fetch fails — the
 * server-rendered summary stays. `data-cal-ready="1"` is set on the element
 * once every card that was in view at the start is complete (document
 * fetched, fonts usable, thumbnail drawn or queued).
 *
 * `[data-cal-action="history"]` opens Historie; `[data-cal-action="export-pdf"]`
 * (cards whose calendar has a layout) opens the Stáhnout PDF dialog right
 * here — it needs the card's export address and the shared CSRF token only.
 * The toast left in sessionStorage['wb-cal-toast'] by Dokončit is shown once;
 * when sessionStorage['wb-cal-toast-calendar'] names a card that can be
 * exported, the toast offers „Stáhnout PDF“ for it — the obvious next step.
 */
export default class extends Controller {
    static targets = ['env', 'card', 'doc', 'thumb', 'meta', 'time'];

    connect() {
        const shared = this.hasEnvTarget ? JSON.parse(this.envTarget.textContent) : {};
        const palette = Array.isArray(shared.palette) ? shared.palette : [];
        const fonts = Array.isArray(shared.fonts) ? shared.fonts : [];
        const accent = palette.find((c) => c !== '#FFFFFF' && c !== '#000000') || null;
        const measurer = createCanvasMeasurer();
        this.thumbs = [];
        this.abort = typeof AbortController === 'function' ? new AbortController() : null;
        const signal = this.abort ? this.abort.signal : undefined;

        /** Fetches the card's document and completes the card; never rejects. */
        const complete = async (card) => {
            const script = this.docTargets.find((el) => card.contains(el));
            const thumb = this.thumbTargets.find((el) => card.contains(el));
            const meta = this.metaTargets.find((el) => card.contains(el));
            try {
                const url = script ? (JSON.parse(script.textContent).urls || {}).card : null;
                if (!url) return;
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (!this.thumbs) return; // disconnected meanwhile

                const env = { ...shared, fonts, accent, assets: data.assets || {} };
                const doc = normalizeDocument(data.calendar, env);
                const pages = buildPages(doc);
                const { design } = doc;
                if (meta) {
                    meta.textContent = [design.type === 'fortnight' ? 'Dvojtýdenní' : 'Týdenní', design.year, design.layout ? PRESET_WORDS[design.preset] : null, `${pages.length} ${pluralPages(pages.length)}`]
                        .filter((part) => part !== null && part !== undefined)
                        .join(' · ');
                }
                if (!thumb) return;
                const page = design.layout ? weekPages(pages)[0] : pages[0];
                thumb.style.aspectRatio = `${design.page.width} / ${design.page.height}`;
                if (!page) return;
                const lazy = mountLazyThumb(thumb, () => renderThumb(doc, page, { env, measurer }));
                this.thumbs.push(lazy);
                // text is measured with the project's fonts: draw again once they are usable
                await ensureFontsLoaded(fonts, design.layout ? usedFamilies(design.layout) : []).catch(() => null);
                lazy.refresh();
            } catch (error) {
                if (!signal || !signal.aborted) console.error('[calendar] card failed', error);
            }
        };

        const ready = (loads) => {
            Promise.all(loads).then(() => {
                if (this.thumbs) this.element.dataset.calReady = '1';
            });
        };

        if (typeof IntersectionObserver === 'function') {
            let first = true;
            this.cardObserver = new IntersectionObserver(
                (entries) => {
                    const loads = [];
                    for (const entry of entries) {
                        if (!entry.isIntersecting) continue;
                        this.cardObserver.unobserve(entry.target);
                        loads.push(complete(entry.target));
                    }
                    // the first delivery reports every card: the ones in view make the page "ready"
                    if (first) {
                        first = false;
                        ready(loads);
                    }
                },
                { rootMargin: '200px' },
            );
            for (const card of this.cardTargets) this.cardObserver.observe(card);
            if (!this.cardTargets.length) ready([]);
        } else {
            ready(this.cardTargets.map(complete));
        }

        for (const time of this.timeTargets) {
            const text = relativeTime(time.getAttribute('datetime'));
            if (text) {
                time.title = time.textContent.trim();
                time.textContent = text;
            }
        }

        const exportOf = (button) => openExport({ name: button.dataset.calendarName || '', url: button.dataset.exportUrl, csrf: shared.csrf, toast });

        this.onClick = (event) => {
            const pdf = event.target.closest('[data-cal-action="export-pdf"]');
            if (pdf && this.element.contains(pdf)) {
                event.preventDefault();
                exportOf(pdf);
                return;
            }
            const chip = event.target.closest('[data-cal-action="history"]');
            if (!chip || !this.element.contains(chip)) return;
            event.preventDefault();
            openHistory({ name: chip.dataset.calendarName || '', load: () => fetchHistory(chip.dataset.historyUrl) });
        };
        this.element.addEventListener('click', this.onClick);

        try {
            const message = sessionStorage.getItem('wb-cal-toast');
            const finishedId = sessionStorage.getItem('wb-cal-toast-calendar');
            sessionStorage.removeItem('wb-cal-toast-calendar');
            if (message) {
                sessionStorage.removeItem('wb-cal-toast');
                const card = finishedId ? this.cardTargets.find((el) => el.dataset.calendarId === finishedId) : null;
                const pdf = card ? card.querySelector('[data-cal-action="export-pdf"]') : null;
                toast(message, false, pdf ? { label: 'Stáhnout PDF', onClick: () => exportOf(pdf) } : null);
            }
        } catch (e) {
            // no sessionStorage: nothing to show
        }
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
        if (this.cardObserver) this.cardObserver.disconnect();
        this.cardObserver = null;
        if (this.abort) this.abort.abort();
        for (const thumb of this.thumbs) thumb.destroy();
        this.thumbs = null;
    }
}
