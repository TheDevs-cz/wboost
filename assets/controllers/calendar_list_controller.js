/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { normalizeDocument } from '../calendar/model/normalize.js';
import { buildPages, weekPages } from '../calendar/generate/pages.js';
import { pluralPages } from '../calendar/generate/czech.js';
import { createCanvasMeasurer, ensureFontsLoaded, usedFamilies } from '../calendar/render/measure.js';
import { fetchHistory } from '../calendar/state/api.js';
import { relativeTime, toast } from '../calendar/ui/dom.js';
import { openHistory } from '../calendar/ui/history.js';
import { mountLazyThumb, renderThumb } from '../calendar/ui/thumb.js';

const PRESET_WORDS = { photoLeft: 'fotka vlevo', photoRight: 'fotka vpravo', twoSided: 'dvoustranné' };

/**
 * Projekt › Kalendáře (docs/calendar/architecture.md §D.3). The page works
 * without this controller (open, create, delete are plain links and forms);
 * it completes every card from the JSON next to it:
 *
 *   env    <script type="application/json"> shared by all cards: { projectName, fonts, palette, currentYear, … }
 *   card   li.cal-card[data-calendar-id]
 *   doc    <script type="application/json"> per card: { calendar, assets, urls: { workspace, history } }
 *   thumb  .cal-thumb  ← inline SVG of the first week page (the cover while no layout exists), lazy
 *   meta   .cal-meta   ← "Týdenní · 2027 · fotka vlevo · 55 stran"
 *   time   <time datetime> ← relative time ("před 5 min")
 *
 * `[data-cal-action="history"]` opens Historie; the toast left in
 * sessionStorage['wb-cal-toast'] by Dokončit is shown once.
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
        const families = new Set();

        for (const card of this.cardTargets) {
            const script = this.docTargets.find((el) => card.contains(el));
            const thumb = this.thumbTargets.find((el) => card.contains(el));
            const meta = this.metaTargets.find((el) => card.contains(el));
            if (!script) continue;
            try {
                const data = JSON.parse(script.textContent);
                const env = { ...shared, fonts, accent, assets: data.assets || {} };
                const doc = normalizeDocument(data.calendar, env);
                const pages = buildPages(doc);
                const { design } = doc;
                if (meta) {
                    meta.textContent = [design.type === 'fortnight' ? 'Dvojtýdenní' : 'Týdenní', design.year, design.layout ? PRESET_WORDS[design.preset] : null, `${pages.length} ${pluralPages(pages.length)}`]
                        .filter((part) => part !== null && part !== undefined)
                        .join(' · ');
                }
                if (thumb) {
                    const page = design.layout ? weekPages(pages)[0] : pages[0];
                    thumb.style.aspectRatio = `${design.page.width} / ${design.page.height}`;
                    if (page) this.thumbs.push(mountLazyThumb(thumb, () => renderThumb(doc, page, { env, measurer })));
                    if (design.layout) for (const family of usedFamilies(design.layout)) families.add(family);
                }
            } catch (error) {
                console.error('[calendar] card failed', error);
            }
        }

        // text is measured with the project's fonts: draw again once they are usable
        ensureFontsLoaded(fonts, [...families])
            .catch(() => null)
            .then(() => {
                for (const thumb of this.thumbs) thumb.refresh();
                this.element.dataset.calReady = '1';
            });

        for (const time of this.timeTargets) {
            const text = relativeTime(time.getAttribute('datetime'));
            if (text) {
                time.title = time.textContent.trim();
                time.textContent = text;
            }
        }

        this.onClick = (event) => {
            const chip = event.target.closest('[data-cal-action="history"]');
            if (!chip || !this.element.contains(chip)) return;
            event.preventDefault();
            openHistory({ name: chip.dataset.calendarName || '', load: () => fetchHistory(chip.dataset.historyUrl) });
        };
        this.element.addEventListener('click', this.onClick);

        try {
            const message = sessionStorage.getItem('wb-cal-toast');
            if (message) {
                sessionStorage.removeItem('wb-cal-toast');
                toast(message);
            }
        } catch (e) {
            // no sessionStorage: nothing to show
        }
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
        for (const thumb of this.thumbs) thumb.destroy();
        this.thumbs = [];
    }
}
