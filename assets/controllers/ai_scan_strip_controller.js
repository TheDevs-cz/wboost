/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { api, esc, mmss, minutesRange, dateCz, pl, urlFor } from '../ai-scan/kit.js';
import { pill, STAGES, failureShort } from '../ai-scan/copy.js';
import { retryScan, discardScan } from '../ai-scan/lists.js';

/*
 * T5 — the strip of scan cards above the template categories
 * (docs/ai-scan/ux.md T5): scans of this project that are not finished with.
 * The cards are drawn here from the rows of AiScanLists::strip() — inline
 * JSON at first, then `ai_scans_project?view=strip` every 10 s, but ONLY
 * while a card is checking / queued / running. No card → no section and no
 * request at all.
 */
const IN_PROGRESS = ['checking', 'queued', 'running'];
const POLL_MS = 10000;
/** Cards shown before "Zobrazit další" — the strip must not push the templates off the screen. */
const FOLDED = 6;

export default class extends Controller {
    static targets = ['list', 'count', 'more'];

    static values = {
        scans: Array,
        listUrl: String,
        pageUrl: String, // …/z-obrazku/{nil}
        imageUrl: String, // /ai-scan/{nil}/image/working
        actionUrl: String, // /ai-scan/{nil}/discard
    };

    connect() {
        this.rows = this.scansValue;
        this.readAt = Date.now();
        this.render();
        // `error` does not bubble — listen in the capture phase for the thumbnails' fallbacks.
        this.onImageError = (event) => this.imageFailed(event);
        this.element.addEventListener('error', this.onImageError, true);
        this.clock = window.setInterval(() => this.tick(), 1000);
        this.schedule();
    }

    disconnect() {
        this.element.removeEventListener('error', this.onImageError, true);
        window.clearInterval(this.clock);
        window.clearTimeout(this.timer);
    }

    schedule() {
        window.clearTimeout(this.timer);
        if (!this.rows.some((row) => IN_PROGRESS.includes(row.status))) return;
        this.timer = window.setTimeout(() => this.refresh(), POLL_MS);
    }

    async refresh() {
        if (document.hidden) { this.schedule(); return; }
        const answer = await api.get(this.listUrlValue);
        if (answer.ok && Array.isArray(answer.scans)) {
            this.rows = answer.scans;
            this.readAt = Date.now();
            this.render();
        }
        this.schedule();
    }

    tick() {
        const passed = (Date.now() - this.readAt) / 1000;
        this.element.querySelectorAll('[data-elapsed]').forEach((el) => { el.textContent = mmss(Number(el.dataset.elapsed) + passed); });
    }

    render() {
        this.element.hidden = this.rows.length === 0;
        this.countTarget.textContent = String(this.rows.length);
        const shown = this.expanded ? this.rows : this.rows.slice(0, FOLDED);
        this.listTarget.innerHTML = shown.map((row) => this.card(row)).join('');
        const rest = this.rows.length - shown.length;
        this.moreTarget.hidden = rest <= 0;
        this.moreTarget.textContent = `Zobrazit ${pl(rest, 'další', 'další', 'dalších')} ${rest}`;
    }

    showAll() {
        this.expanded = true;
        this.render();
        const next = this.listTarget.children[FOLDED];
        if (next) next.querySelector('a')?.focus();
    }

    card(row) {
        const url = urlFor(this.pageUrlValue, row.id);
        const name = esc(row.name || row.fileName || 'Sken');
        const by = row.mine || !row.requestedBy ? '' : ` · Sken spustil(a) ${esc(row.requestedBy)}`;
        const show = (label, cls = 'btn-ghost') => `<a href="${esc(url)}" class="btn xs ${cls}">${label}</a>`;
        const discard = row.actions.includes('discard') ? `<button type="button" class="btn xs btn-ghost" data-action="ai-scan-strip#discard" data-id="${esc(row.id)}">Zahodit</button>` : '';
        let cap; let acts; let tail = ''; let dim = false; let run = false; let which = 'preview';
        switch (row.status) {
            case 'checking':
                cap = `Trvá to pár vteřin${by}`;
                acts = show('Zobrazit');
                break;
            case 'queued':
                if (row.waiting) cap = 'Sken čeká — AI sken teď nepracuje';
                else if (row.queuePosition > 0) cap = `${row.queuePosition} před vámi${minutesRange(row.estimatedStartSeconds) ? ` · ${this.startsIn(row.estimatedStartSeconds)}` : ''}`;
                else cap = 'jste na řadě';
                cap += by;
                acts = show('Zobrazit');
                break;
            case 'running': {
                const stage = STAGES.find((s) => s.id === row.stage);
                cap = `${stage ? `${stage.label} · ` : ''}<span data-elapsed="${Number(row.runningSeconds) || 0}">${mmss(Number(row.runningSeconds) || 0)}</span>${by}`;
                acts = show('Zobrazit průběh');
                run = true;
                break;
            }
            case 'ready':
                cap = `vyprší ${dateCz(row.expiresAt)}${by}`;
                acts = show('Zkontrolovat', 'btn-tertiary');
                which = 'proposal';
                break;
            case 'failed':
                dim = true;
                tail = row.try > 1 ? ` · ${row.try}. pokus` : '';
                cap = `${failureShort(row.status, row.code)} · nezapočítal se${by}`;
                acts = `${row.actions.includes('retry') ? `<button type="button" class="btn xs btn-ghost" data-action="ai-scan-strip#retry" data-id="${esc(row.id)}">Zkusit znovu</button>` : show('Zobrazit')}${discard}`;
                break;
            default: // stopped, refused
                dim = true;
                cap = `${failureShort(row.status, row.code)}${by}`;
                acts = `${show('Zobrazit')}${discard}`;
        }
        const image = row.hasPicture ? `<img src="${esc(this.image(row.id, which))}" alt="" loading="lazy" data-fallback="${which === 'working' ? 'original' : 'working'}" data-id="${esc(row.id)}">` : '';
        return `<li class="as-scan${dim ? ' dim' : ''}" data-scan="${esc(row.id)}" data-status="${esc(row.status)}"><div class="as-scan-th${run ? ' run' : ''}">${image}</div><div class="as-scan-b"><a href="${esc(url)}" class="as-scan-n" title="${name}">${name}</a>${pill(row.status, tail)}<span class="cap">${cap}</span><div class="as-scan-acts">${acts}</div></div></li>`;
    }

    startsIn(range) {
        const text = minutesRange(range);
        return text === 'během chvíle' ? text : `asi za ${text}`;
    }

    image(id, which) {
        return urlFor(this.imageUrlValue, id).replace(/\/image\/[a-z]+$/, `/image/${which}`);
    }

    /** A missing picture (no preview yet, HEIC original): next source, then none. */
    imageFailed(event) {
        const img = event.target;
        if (!(img instanceof HTMLImageElement) || !img.dataset.id) return;
        const next = img.dataset.fallback;
        if (!next) { img.remove(); return; }
        img.dataset.fallback = next === 'working' ? 'original' : '';
        img.src = this.image(img.dataset.id, next);
    }

    async retry(event) {
        const button = event.currentTarget;
        button.disabled = true;
        const started = await retryScan({ actionUrl: this.actionUrlValue, pageUrl: this.pageUrlValue, id: button.dataset.id });
        if (!started) button.disabled = false;
    }

    async discard(event) {
        const id = event.currentTarget.dataset.id;
        const row = this.rows.find((r) => r.id === id);
        if (!(await discardScan({ actionUrl: this.actionUrlValue, id, proposal: Boolean(row && row.status === 'ready') }))) return;
        this.rows = this.rows.filter((r) => r.id !== id);
        this.render();
    }
}
