// „Naskenovat design“ — the AI scan of a calendar page inside the wizard (ux.md §6, C1–C4;
// docs/architecture/calendars.md § "Calendar scan — UI").
//
// ONE object per workspace (`createScan(ctx)`, designers only, `ctx.scan`): it owns the state of
// the calendar's scan — the entry facts, the current scan, its proposal — polls while a scan is
// on its way IN ANY STEP (so the top bar can show the peach dot and the toast „Návrh rozvržení je
// připravený“), and draws itself into step 2 when that step is shown (`scan.mount(root)`): the
// action card under the layout cards, or the panel (upload → waiting → review → pictures).
//
// Markup is built as strings (scan/views.js — the template wizard's way, same `as-*` classes) and
// patched region by region (`morph`), so a 2 s poll never rebuilds a picture or steals the focus.
// Every value that did not come from this code goes through `esc()`.
//
// Contract consumed (docs/architecture/ai-scan.md § "Calendar scans"): `env.scan.urls` — entry,
// start, status, proposal, image, action, seen, accept, restore, picture; the stateless CSRF of the
// scan endpoints (kit.js `api`). Accept / restore answer the saved calendar → `store.load()`.
//
// Pictures for the proposal's places come from the disk (a file input, or a file dropped on a card /
// on the multi-drop zone) or from the project's gallery — the `Project:ImageGallery` modal
// `#calScanGalleryModal` of workspace.html.twig, which answers with the window event
// `asset-selected`; the server copies the gallery file's bytes to the scan.
//
// DOM hooks (e2e contract): [data-cal-scan-card], [data-cal-scan-panel][data-view="upload|chosen|
// wait|review|pictures|fail|gone"], [data-cal-action="scan-open|scan-start|scan-apply|scan-restore|
// scan-discard|scan-rescan|scan-pictures|scan-close"], file inputs #calScanFile / #calScanPic (always
// in the panel's markup — never created on the fly).
//
// Test hook: `window.__calendarScan` → `{ view(), scan(), proposal(), entry() }` (read-only).

import { api, announce, urlFor } from '../../ai-scan/kit.js';
import { CAL, startHint } from '../../ai-scan/copy.js';
import { MIN_SHORT_SIDE_CALENDAR, inspectFiles } from '../../ai-scan/file-checks.js';
import { morph } from '../../ai-scan/page/app.js';
import { observe, needsRender } from '../../ai-scan/page/wait.js';
import * as views from './scan/views.js';
import { cropPicture, previewMarkup } from './scan/preview.js';

const IN_PROGRESS = ['checking', 'queued', 'running'];
const ENDED_BADLY = ['stopped', 'failed', 'refused'];
const POLL_OPEN_MS = 2000;
const POLL_AWAY_MS = 10000;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * @param ctx  the workspace `Ctx` (store, env, toast, confirm, measurer, actions)
 * @returns the scan object, or null when this workspace has no scan (client mode, no addresses)
 */
export function createScan(ctx) {
    const { env } = ctx;
    if (env.mode !== 'designer' || !env.scan || !env.scan.urls) return null;
    return new CalendarScan(ctx);
}

class CalendarScan {
    constructor(ctx) {
        this.ctx = ctx;
        this.store = ctx.store;
        this.env = ctx.env;
        this.urls = ctx.env.scan.urls;
        this.listeners = new Set();
        this.root = null;
        /** null until the first answer of `entry`; false when it cannot be read. */
        this.entry = null;
        this.scan = null;
        this.proposal = null;
        this.open = false;
        // S1 / S2
        this.picked = null;
        this.uploadError = null;
        this.consent = [false, false];
        this.requestId = null;
        this.starting = false;
        this.startRefusal = null;
        // C4
        this.step = 'review';
        this.ticked = new Set();
        this.compare = window.innerWidth < 700 ? 'slider' : 'side';
        this.pos = 52;
        this.photo = null; // { key, url, width, height } — the photo region of the original
        this.drawn = null; // signature of the proposal preview on screen
        this.pendingSlot = null;
        this.gallerySlot = null; // the place the gallery modal was opened for
        this.layers = false; // „Ukázat vrstvy“
        this.feedback = null; // null | 'down' (the note field is open) | 'sent'
        this.busy = null;
        this.actionRefusal = null;
        this.timer = null;
        this.ticker = window.setInterval(() => this.tick(), 1000);
        this.destroyed = false;

        this.onClick = (event) => this.dispatch(event, 'act');
        this.onChange = (event) => this.dispatch(event, 'chg');
        this.onKey = (event) => {
            views.sliderKey(this, event);
            const el = event.key === 'Enter' && event.target.closest ? event.target.closest('[data-enter]') : null;
            if (el && this.root && this.root.contains(el) && ACTIONS[el.dataset.enter]) { event.preventDefault(); ACTIONS[el.dataset.enter](this, el, event); }
        };
        // hover / focus of a row or a picture card highlights its box over the proposal
        this.onHover = (event) => {
            const el = event.target.closest ? event.target.closest('[data-hl]') : null;
            if (!el || !this.root || !this.root.contains(el)) return;
            const on = event.type === 'mouseover' || event.type === 'focusin';
            this.root.querySelectorAll(`[data-box="${CSS.escape(el.dataset.hl)}"]`).forEach((box) => box.classList.toggle('hl', on));
        };
        // files dragged from the desktop: the upload zone, a picture card, the multi-drop zone
        const zoneOf = (event) => (event.target.closest ? event.target.closest('[data-dropzone]') : null);
        const hasFiles = (event) => Boolean(event.dataTransfer) && [...(event.dataTransfer.types || [])].includes('Files');
        this.onDragOver = (event) => {
            const zone = zoneOf(event);
            if (!zone || !hasFiles(event) || zone.disabled) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
            zone.classList.add('over');
        };
        this.onDragLeave = (event) => {
            const zone = zoneOf(event);
            if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove('over');
        };
        this.onDrop = (event) => {
            const zone = zoneOf(event);
            if (!zone || !hasFiles(event)) return;
            event.preventDefault();
            zone.classList.remove('over');
            if (zone.disabled) return;
            const files = [...event.dataTransfer.files];
            if (zone.dataset.dropzone === 'page') this.take(files);
            else if (zone.dataset.dropzone === 'slot' && files.length === 1) this.holdPicture(zone.dataset.slot, files[0]);
            else this.holdMany(files, zone.dataset.dropzone === 'slot' ? zone.dataset.slot : null);
        };
        this.onAsset = (event) => this.galleryPicked(event.detail || {});
        window.addEventListener('asset-selected', this.onAsset);
        this.unsubscribe = this.store.subscribe((event) => {
            // „Použít rozvržení“ is disabled while the calendar has unsaved changes
            if (event.type === 'status' && this.root && ['review', 'pictures'].includes(this.view())) this.render();
        });

        const wanted = new URLSearchParams(window.location.search).get('sken');
        this.boot(wanted);
        window.__calendarScan = { view: () => this.view(), scan: () => this.scan, proposal: () => this.proposal, entry: () => this.entry };
    }

    // ---- lifecycle --------------------------------------------------------------------------

    /** First load: the entry, the scan to show, and — for a link `?sken=…` — step 2 with the panel. */
    async boot(wanted) {
        await this.refreshEntry();
        if (this.destroyed || !this.entry) { this.changed(); return; }
        const rows = this.entry.scans || [];
        let scan = UUID.test(wanted || '') ? rows.find((row) => row.id === wanted) || null : null;
        if (!scan && UUID.test(wanted || '')) {
            // a scan the entry no longer lists (applied, discarded…): ask for it by id
            const answer = await api.get(urlFor(this.urls.status, wanted));
            if (answer.ok && answer.scan && answer.scan.calendarId === this.store.getState().doc.id) scan = { ...answer.scan, mine: true, seen: true };
        }
        scan = scan || rows.find((row) => IN_PROGRESS.includes(row.status)) || rows.find((row) => row.status === 'ready') || rows.find((row) => row.mine && !row.seen && ENDED_BADLY.includes(row.status)) || null;
        this.scan = scan;
        if (wanted) {
            // the address stays clean: a reload must not force step 2 again
            const url = new URL(window.location.href);
            url.searchParams.delete('sken');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
            this.open = Boolean(scan) || this.scannable();
            if (this.store.getState().doc.design.step !== 2) this.store.setStep(2);
        }
        await this.enter(true);
    }

    destroy() {
        this.destroyed = true;
        window.clearTimeout(this.timer);
        window.clearInterval(this.ticker);
        window.removeEventListener('asset-selected', this.onAsset);
        this.unsubscribe();
        this.unmount();
        this.dropPhoto();
        if (this.picked && this.picked.previewUrl) URL.revokeObjectURL(this.picked.previewUrl);
        delete document.documentElement.dataset.asScan;
        if (window.__calendarScan) delete window.__calendarScan;
    }

    subscribe(fn) {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    /** Tells the top bar (dot, tertiary Pokračovat) and redraws step 2. */
    changed() {
        for (const fn of [...this.listeners]) fn();
        this.render();
    }

    /** A scan targets the calendar's own type: a 7-day layout or the 14-day one (two week columns). */
    scannable() { return ['week', 'fortnight'].includes(this.store.getState().doc.design.type); }

    /** A scan of this calendar is on its way (the peach dot of step 2). */
    unfinished() { return Boolean(this.scan && IN_PROGRESS.includes(this.scan.status)); }

    /** A proposal waits for the review and the panel is not showing it. */
    attention() { return Boolean(this.scan && this.scan.status === 'ready' && !(this.open && this.root)); }

    /** The panel shows a lavender primary — the top bar's Pokračovat steps back to tertiary. */
    primaryShown() {
        if (!this.open || !this.root) return false;
        return ['chosen', 'review', 'pictures', 'fail'].includes(this.view());
    }

    // ---- mounting into step 2 ---------------------------------------------------------------

    mount(root) {
        this.root = root;
        root.addEventListener('click', this.onClick);
        root.addEventListener('change', this.onChange);
        root.addEventListener('keydown', this.onKey);
        for (const type of ['mouseover', 'mouseout', 'focusin', 'focusout']) root.addEventListener(type, this.onHover);
        root.addEventListener('dragover', this.onDragOver);
        root.addEventListener('dragleave', this.onDragLeave);
        root.addEventListener('drop', this.onDrop);
        this.unbindSlider = views.bindSlider(this, root);
        this.drawn = null;
        this.render();
        if (this.unfinished()) this.schedule();
        this.changed();
        return { destroy: () => this.unmount() };
    }

    unmount() {
        const root = this.root;
        if (!root) return;
        root.removeEventListener('click', this.onClick);
        root.removeEventListener('change', this.onChange);
        root.removeEventListener('keydown', this.onKey);
        for (const type of ['mouseover', 'mouseout', 'focusin', 'focusout']) root.removeEventListener(type, this.onHover);
        root.removeEventListener('dragover', this.onDragOver);
        root.removeEventListener('dragleave', this.onDragLeave);
        root.removeEventListener('drop', this.onDrop);
        if (this.unbindSlider) this.unbindSlider();
        root.replaceChildren();
        this.root = null;
        for (const fn of [...this.listeners]) fn();
    }

    // ---- which screen -----------------------------------------------------------------------

    view() {
        const scan = this.scan;
        if (!scan) return this.picked ? 'chosen' : 'upload';
        if (IN_PROGRESS.includes(scan.status)) return 'wait';
        if (scan.status === 'ready' && this.proposal && this.proposal.layout) return this.step === 'pictures' && this.proposal.pictures.length ? 'pictures' : 'review';
        if (ENDED_BADLY.includes(scan.status)) return 'fail';
        return 'gone';
    }

    render() {
        const root = this.root;
        if (!root) return;
        if (!this.scannable() || this.entry === false) { root.replaceChildren(); root.hidden = true; return; }
        root.hidden = false;
        const active = document.activeElement;
        const key = active && root.contains(active) && active.dataset.k ? active.dataset.k : null;
        morph(root, this.open ? views.panel(this) : views.card(this));
        if (key && !(document.activeElement && root.contains(document.activeElement))) {
            const again = root.querySelector(`[data-k="${CSS.escape(key)}"]`);
            if (again && !again.disabled) again.focus({ preventScroll: true });
        }
        this.tick();
        if (this.open && ['review', 'pictures'].includes(this.view())) this.drawProposal();
    }

    /** The live render of the proposal (kept out of the patched markup: an SVG of a whole page). */
    drawProposal() {
        const slots = this.root ? [...this.root.querySelectorAll('[data-proposal]')] : [];
        if (!slots.length) return;
        const p = this.proposal;
        const signature = JSON.stringify([p.layout, p.preset, [...this.ticked].sort(), p.pictures.map((x) => (x.picture ? x.picture.url : null)), this.photo ? this.photo.url : null, this.store.getState().doc.revision]);
        if (signature !== this.drawn || slots.some((slot) => !slot.firstChild)) {
            let markup = '';
            try {
                markup = previewMarkup(this.store.getState().doc, p, this.ctx, { ticked: [...this.ticked], photo: this.photo });
            } catch (error) {
                console.error('[calendar] scan preview failed', error);
            }
            for (const slot of slots) slot.innerHTML = markup;
            this.drawn = signature;
        }
        this.loadPhoto();
        this.measureSource();
    }

    /**
     * The pixel size of the uploaded page, when the proposal does not carry it (a scan whose intake
     * reported none): read from the working copy itself, so crops and outlines can be placed.
     */
    async measureSource() {
        const p = this.proposal;
        if (!p || (p.source && p.source.width && p.source.height) || this.measuring === p) return;
        this.measuring = p;
        try {
            const img = new Image();
            img.src = this.image('working');
            await img.decode();
            if (this.proposal !== p || !img.naturalWidth) return;
            p.source = { width: img.naturalWidth, height: img.naturalHeight };
            this.render();
        } catch (e) { /* the pictures stay without crops */ }
    }

    /** The photo region of the original, cut in the browser for the preview only. */
    async loadPhoto() {
        const p = this.proposal;
        const key = p && p.photoBox ? `${this.scan.id}:${p.photoBox.join(',')}` : null;
        if (!key || (this.photo && this.photo.key === key) || this.photoLoading === key) return;
        this.photoLoading = key;
        const cut = await cropPicture(this.image('working'), p.photoBox);
        this.photoLoading = null;
        if (!cut || this.destroyed || !this.proposal || !this.scan || `${this.scan.id}:${(this.proposal.photoBox || []).join(',')}` !== key) {
            if (cut) URL.revokeObjectURL(cut.url);
            return;
        }
        this.dropPhoto();
        this.photo = { key, ...cut };
        this.drawProposal();
    }

    dropPhoto() {
        if (this.photo) URL.revokeObjectURL(this.photo.url);
        this.photo = null;
    }

    tick() {
        const root = this.root;
        if (!root) return;
        const now = Date.now();
        root.querySelectorAll('[data-elapsed]').forEach((el) => {
            const s = Math.max(0, Math.floor((now - Number(el.dataset.since)) / 1000));
            const text = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
            if (el.textContent !== text) el.textContent = text;
        });
        if (this.scan && IN_PROGRESS.includes(this.scan.status) && needsRender(this)) this.render();
    }

    // ---- the scan ---------------------------------------------------------------------------

    /** Called whenever `this.scan` was replaced: loads what its state needs and redraws. */
    async enter(first = false) {
        const scan = this.scan;
        document.documentElement.dataset.asScan = scan ? scan.id : '';
        window.clearTimeout(this.timer);
        if (scan && IN_PROGRESS.includes(scan.status)) {
            observe(this, scan);
            this.changed();
            this.schedule();
            return;
        }
        if (scan && ['ready', 'accepted'].includes(scan.status)) {
            await this.loadProposal();
            if (scan.status === 'ready' && this.proposal) {
                this.ticked = new Set((this.proposal.features || []).map((f) => f.key));
                if (!this.proposal.pictures.length) this.step = 'review';
            }
        } else {
            this.proposal = null;
        }
        this.changed();
        if (this.open && this.root) this.seen();
        if (!first && this.open && this.root) this.focusHeading();
    }

    schedule() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.poll(), this.open && this.root ? POLL_OPEN_MS : POLL_AWAY_MS);
    }

    async poll() {
        const scan = this.scan;
        if (this.destroyed || !scan || !IN_PROGRESS.includes(scan.status)) return;
        const answer = await api.get(urlFor(this.urls.status, scan.id));
        if (this.destroyed || this.scan !== scan) return;
        if (!answer.ok || !answer.scan) {
            if (answer.status === 404 || answer.status === 403) { this.scan = null; await this.refreshEntry(); await this.enter(); return; }
            this.schedule(); // a blip keeps the last known state on screen
            return;
        }
        const before = scan.status;
        const beforeStage = scan.stage;
        this.scan = { ...scan, ...answer.scan };
        if (IN_PROGRESS.includes(this.scan.status)) {
            observe(this, this.scan);
            if (this.open && this.root && (before !== this.scan.status || beforeStage !== this.scan.stage)) announce(views.announcement(this.scan));
            this.changed();
            this.schedule();
            return;
        }
        observe(this, this.scan);
        this.notifyShell();
        const shown = this.open && this.root;
        if (this.scan.status === 'ready') {
            announce('Návrh rozvržení je připravený.');
            this.ctx.toast('Návrh rozvržení je připravený', false, shown ? null : { label: 'Zkontrolovat', onClick: () => this.show() });
        } else if (this.scan.status === 'stopped') {
            this.ctx.toast('Sken potřebuje jiný obrázek', false, shown ? null : { label: 'Zobrazit', onClick: () => this.show() });
        } else if (['failed', 'refused'].includes(this.scan.status)) {
            this.ctx.toast('Sken se nepodařil', true, shown ? null : { label: 'Zobrazit', onClick: () => this.show() });
        }
        await this.enter();
    }

    async loadProposal() {
        const answer = await api.get(urlFor(this.urls.proposal, this.scan.id));
        if (answer.ok) {
            if (answer.scan) this.scan = { ...this.scan, ...answer.scan };
            this.proposal = answer.proposal || null;
        }
        return answer;
    }

    async refreshEntry() {
        const answer = await api.get(this.urls.entry);
        if (answer.ok && answer.entry) this.entry = answer.entry;
        else if (this.entry === null) this.entry = false;
        return this.entry;
    }

    /** "Seen" = the requester's browser got the final state on the scan surface (ux.md §7). */
    seen() {
        const scan = this.scan;
        if (!scan || IN_PROGRESS.includes(scan.status) || !scan.mine || scan.seen) return;
        scan.seen = true;
        api.post(urlFor(this.urls.seen, scan.id)).then(() => this.notifyShell());
    }

    notifyShell() {
        window.dispatchEvent(new CustomEvent('ai-scan:changed', { detail: { scanId: this.scan ? this.scan.id : null } }));
    }

    image(which, query = '') {
        return `${urlFor(this.urls.image, this.scan.id).replace(/\/working$/, `/${which}`)}${query}`;
    }

    focusHeading() {
        const heading = this.root ? this.root.querySelector('[data-focus]') : null;
        if (heading) heading.focus({ preventScroll: false });
    }

    /** Step 2 with the panel on the current scan (toasts, the card, a link). */
    show() {
        this.open = true;
        if (this.store.getState().doc.design.step !== 2) this.store.setStep(2);
        this.changed();
        this.seen();
        if (this.unfinished()) this.schedule();
        window.setTimeout(() => {
            const panel = this.root ? this.root.querySelector('[data-cal-scan-panel]') : null;
            if (panel) panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            this.focusHeading();
        }, 0);
    }

    close() {
        this.open = false;
        this.changed();
        const button = this.root ? this.root.querySelector('[data-cal-action="scan-open"]') : null;
        if (button) button.focus({ preventScroll: true });
    }

    // ---- events -----------------------------------------------------------------------------

    dispatch(event, kind) {
        const el = event.target.closest(`[data-${kind}]`);
        if (!el || !this.root || !this.root.contains(el) || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        const fn = ACTIONS[el.dataset[kind]];
        if (!fn) return;
        if (kind === 'act' && el.tagName === 'A') event.preventDefault();
        fn(this, el, event);
    }

    // ---- S1 / S2 ----------------------------------------------------------------------------

    async take(files) {
        // a calendar page may be smaller than a design; under 600 px the checks say so
        const result = await inspectFiles(files, { minShortSide: MIN_SHORT_SIDE_CALENDAR, goodShortSide: views.LOW_RESOLUTION_SHORT_SIDE });
        if (!result || this.scan) return;
        if (this.picked && this.picked.previewUrl) URL.revokeObjectURL(this.picked.previewUrl);
        if (result.error) {
            this.uploadError = { code: result.error, width: result.width, height: result.height };
            this.picked = null;
            this.changed();
            return;
        }
        this.picked = result;
        this.uploadError = null;
        this.startRefusal = null;
        this.requestId = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `r-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        this.changed();
        this.focusHeading();
        // the queue and the limits may have moved since the workspace was opened
        this.refreshEntry().then(() => { if (!this.scan) this.render(); });
    }

    async start() {
        const picked = this.picked;
        if (!picked || this.starting || views.blocked(this)) return;
        this.starting = true;
        this.render();
        const answer = await api.upload(this.urls.start, {
            picture: picked.file,
            kind: 'calendar',
            calendarId: this.store.getState().doc.id,
            requestId: this.requestId, // the same id on a repeat: a retry never makes a second scan
            consent: !this.entry.consent.given && this.consent[0] && this.consent[1] ? '1' : '0',
        });
        this.starting = false;
        if (answer.ok && answer.scan) {
            this.entry.consent.given = true;
            this.scan = { ...answer.scan, name: picked.file.name, fileName: picked.file.name, mine: true, seen: false, hasPicture: true };
            this.notifyShell();
            await this.enter();
            return;
        }
        if (answer.code === 'unsupported_image') {
            const code = { type: 'type', bytes: 'bytes', small: 'small', side: 'large', pixels: 'large' }[answer.reason] || 'type';
            this.uploadError = { code, width: picked.width, height: picked.height };
            this.picked = null;
            this.changed();
            return;
        }
        if (answer.code === 'network' || answer.code === 'http_error') {
            this.render();
            this.ctx.toast('Nahrání se nepodařilo. Zkontrolujte připojení a zkuste to znovu.', true, { label: 'Zkusit znovu', onClick: () => this.start() });
            return;
        }
        if (answer.code === 'consent_required') this.entry.consent.given = false;
        this.startRefusal = answer.code;
        await this.refreshEntry();
        this.render();
        this.ctx.toast(startHint(answer.code, this.entry) || 'Sken se teď nepodařilo spustit. Zkuste to za chvíli.', true);
    }

    /** Back to the upload zone (the scan on screen stays in the history). */
    another() {
        this.scan = null;
        this.proposal = null;
        this.picked = null;
        this.uploadError = null;
        this.actionRefusal = null;
        this.step = 'review';
        this.feedback = null;
        this.dropPhoto();
        document.documentElement.dataset.asScan = '';
        this.changed();
        this.focusHeading();
    }

    /**
     * „Naskenovat znovu“: the same picture again (the stored original goes back to the chosen-file
     * screen — a new scan, with the credit line in front of the user) or the upload zone for a
     * better one. The proposal on screen stays in the history either way.
     */
    async rescan() {
        const mode = await views.rescanDialog(this);
        if (!mode || !this.scan) return;
        if (mode === 'new') { this.another(); return; }
        let file = null;
        try {
            const response = await fetch(this.image('original'), { credentials: 'same-origin' });
            if (response.ok) { const blob = await response.blob(); file = new File([blob], this.scan.fileName || 'stranka-kalendare', { type: blob.type }); }
        } catch (e) { file = null; }
        if (!file) { this.ctx.toast('Původní obrázek se nepodařilo načíst. Nahrajte ho znovu.', true); return; }
        this.another();
        await this.refreshEntry();
        await this.take([file]);
    }

    // ---- „Jak se sken povedl?“ -------------------------------------------------------------------

    async rate(rating) {
        if (rating === -1) {
            this.feedback = 'down';
            this.render();
            const input = this.root ? this.root.querySelector('[data-k="fbt"]') : null;
            if (input) input.focus();
        }
        const answer = await api.post(urlFor(this.urls.rating, this.scan.id), { rating });
        if (!answer.ok || !this.proposal) return;
        this.proposal.rating = rating;
        if (rating === 1) { this.feedback = 'sent'; this.ctx.toast('Díky za hodnocení'); }
        this.render();
    }

    async sendFeedback() {
        const input = this.root ? this.root.querySelector('[data-k="fbt"]') : null;
        const note = input ? input.value.trim() : '';
        await api.post(urlFor(this.urls.rating, this.scan.id), note ? { rating: -1, note } : { rating: -1 });
        if (this.proposal) this.proposal.rating = -1;
        this.feedback = 'sent';
        this.render();
        this.ctx.toast('Díky za hodnocení');
    }

    async act(name, action) {
        if (this.busy) return null;
        this.busy = name;
        this.render();
        const answer = await api.post(urlFor(this.urls.action, this.scan.id).replace(/\/cancel$/, `/${action}`));
        this.busy = null;
        return answer;
    }

    async again(name, action) {
        const answer = await this.act(name, action);
        if (!answer) return;
        if (answer.ok && answer.scan) {
            this.actionRefusal = null;
            this.scan = { ...this.scan, ...answer.scan, seen: false };
            this.notifyShell();
            await this.enter();
            return;
        }
        if (answer.code === 'wrong_state') { await this.resync(); return; }
        this.actionRefusal = answer.code === 'network' || answer.code === 'http_error' ? null : answer.code;
        await this.refreshEntry();
        this.render();
        this.ctx.toast(startHint(answer.code, this.entry) || 'Sken se teď nepodařilo spustit. Zkuste to za chvíli.', true);
    }

    /** The scan changed behind this page's back (another tab): read it again. */
    async resync() {
        const answer = this.scan ? await api.get(urlFor(this.urls.status, this.scan.id)) : { ok: false };
        if (answer.ok && answer.scan) this.scan = { ...this.scan, ...answer.scan };
        else this.scan = null;
        await this.refreshEntry();
        await this.enter();
    }

    async cancel() {
        const running = this.scan.status === 'running';
        const yes = await this.ctx.confirm({
            title: 'Zrušit sken?',
            text: running ? 'Sken už běží. Zastavíme ho, ale započítá se. Obrázek u nás zůstane 30 dní.' : 'Sken se zastaví a nezapočítá se. Obrázek u nás zůstane 30 dní, můžete ho použít znovu.',
            cancelLabel: 'Pokračovat ve skenu',
            confirmLabel: 'Zrušit sken',
            danger: true,
        });
        if (!yes) return;
        const answer = await this.act('cancel', 'cancel');
        if (!answer) return;
        this.notifyShell();
        if (!answer.ok) { await this.resync(); return; }
        this.ctx.toast('Sken zrušen');
        // back to S2 with the picture when the file is still in this page
        this.scan = null;
        document.documentElement.dataset.asScan = '';
        await this.refreshEntry();
        await this.enter();
    }

    async discard() {
        const proposal = this.scan.status === 'ready';
        const yes = await this.ctx.confirm(proposal
            ? { title: 'Zahodit návrh?', text: 'Návrh se smaže a sken zůstane započítaný. Obrázky, které jste k němu nahráli, smažeme také. Kalendář se nezmění.', confirmLabel: 'Zahodit', danger: true }
            : { title: 'Zahodit sken?', text: 'Sken zmizí ze seznamů a jeho obrázek smažeme.', confirmLabel: 'Zahodit', danger: true });
        if (!yes) return;
        const answer = await this.act('discard', 'discard');
        if (!answer) return;
        if (!answer.ok && answer.code !== 'wrong_state') {
            this.render();
            this.ctx.toast('Sken se nepodařilo zahodit. Zkuste to znovu.', true);
            return;
        }
        this.notifyShell();
        this.ctx.toast(proposal ? 'Návrh zahozen' : 'Sken zahozen');
        this.open = false;
        this.another();
        await this.refreshEntry();
        this.changed();
    }

    // ---- pictures (C4b) ---------------------------------------------------------------------

    /** A picture for one place: a `File` from the disk, or `{ fileId }` of a gallery picture. → ok? */
    async holdPicture(slot, source) {
        if (!source || !slot || this.busy || !this.proposal) return false;
        this.busy = `picture:${slot}`;
        this.render();
        const answer = await api.upload(urlFor(this.urls.picture, this.scan.id), source instanceof File ? { slot, picture: source } : { slot, fileId: source.fileId });
        this.busy = null;
        if (answer.ok && answer.proposal) {
            this.replaceProposal(answer.proposal);
        } else if (answer.code === 'wrong_state') {
            await this.resync();
            return false;
        } else {
            this.ctx.toast(pictureError(answer, source instanceof File), true);
        }
        this.render();
        return Boolean(answer.ok);
    }

    /**
     * Several files at once (the multi-drop zone, or several files dropped on a card): they go to
     * the places without a picture in order — `first` (a card's slot) before the others.
     */
    async holdMany(files, first = null) {
        const list = [...(files || [])];
        if (!list.length || !this.proposal || this.busy) return;
        const empty = this.proposal.pictures.filter((item) => !item.picture).sort((a, b) => Number(Boolean(a.current)) - Number(Boolean(b.current))).map((item) => item.slot);
        const slots = first ? [first, ...empty.filter((slot) => slot !== first)] : empty;
        let assigned = 0;
        for (const file of list) {
            const slot = slots[assigned];
            if (!slot) break;
            // eslint-disable-next-line no-await-in-loop -- one picture at a time, in the order given
            if (!(await this.holdPicture(slot, file))) break;
            assigned += 1;
        }
        const left = list.length - assigned;
        if (assigned > 0) this.ctx.toast(`${views.assignedText(assigned)}${left > 0 ? ` · ${left} jsme nepoužili` : ''}`);
        else if (!slots.length) this.ctx.toast('Žádné prázdné místo — obrázky jsme nepřiřadili', true);
    }

    // ---- the gallery as a source ------------------------------------------------------------

    galleryModal() {
        const el = document.getElementById('calScanGalleryModal');
        return el && window.bootstrap ? { el, modal: window.bootstrap.Modal.getOrCreateInstance(el) } : null;
    }

    hasGallery() { return Boolean(document.getElementById('calScanGalleryModal')); }

    pickFromGallery(slot) {
        const host = this.galleryModal();
        if (!host) { this.ctx.toast('Galerii se nepodařilo otevřít. Načtěte stránku znovu.', true); return; }
        this.gallerySlot = slot;
        host.modal.show();
    }

    /** `asset-selected` of the gallery modal: the picked picture goes to the place it was opened for. */
    galleryPicked(detail) {
        const slot = this.gallerySlot;
        if (!slot || !detail.id) return;
        this.gallerySlot = null;
        const host = this.galleryModal();
        if (host) host.modal.hide();
        this.holdPicture(slot, { fileId: detail.id });
    }

    /** A fresh proposal document from the server; what the browser measured about the page stays. */
    replaceProposal(next) {
        const before = this.proposal;
        if (before && before.source && before.source.width && !(next.source && next.source.width)) next.source = before.source;
        this.proposal = next;
    }

    async dropPicture(slot) {
        this.busy = `picture:${slot}`;
        this.render();
        const answer = await api.upload(urlFor(this.urls.picture, this.scan.id), { slot, remove: '1' });
        this.busy = null;
        if (answer.ok && answer.proposal) this.replaceProposal(answer.proposal);
        else if (answer.code === 'wrong_state') { await this.resync(); return; }
        else this.ctx.toast('Obrázek se nepodařilo odebrat. Zkuste to znovu.', true);
        this.render();
    }

    // ---- apply / restore ------------------------------------------------------------------------

    async apply() {
        if (this.busy || this.store.isDirty()) return;
        this.busy = 'apply';
        this.render();
        const answer = await api.post(urlFor(this.urls.accept, this.scan.id), { features: [...this.ticked] });
        this.busy = null;
        if (!answer.ok || !answer.document) {
            if (answer.code === 'wrong_state') { await this.resync(); this.ctx.toast('Návrh už není k dispozici.', true); return; }
            this.render();
            this.ctx.toast(answer.code === 'invalid_fix' && answer.field === 'pictures' ? 'Některý z vybraných obrázků se nepodařilo použít. Vyberte jiný a zkuste to znovu.'
                : answer.code === 'temporarily_unavailable' ? 'Rozvržení se teď nepodařilo použít. Zkuste to za chvíli.'
                    : 'Rozvržení se nepodařilo použít. Zkuste to znovu.', true);
            return;
        }
        const scanId = this.scan.id;
        this.notifyShell();
        this.open = false;
        this.another();
        // the saved calendar replaces the document; the step history starts again
        this.store.load(answer.document);
        if (this.store.getState().doc.design.step !== 3) this.store.setStep(3);
        this.entry.applied = answer.restoreUntil ? { scanId, restoreUntil: answer.restoreUntil } : null;
        this.changed();
        this.ctx.toast('Rozvržení převzato ze skenu', false, answer.restoreUntil ? { label: 'Vrátit původní', onClick: () => this.restore(scanId) } : null);
        this.refreshEntry().then(() => this.changed());
    }

    /**
     * „Vrátit původní rozvržení“: the layout, layout choice, switches the calendar had before the
     * scan was applied — nothing else. Asks first when the layout was edited since.
     */
    async restore(scanId, confirmed = false) {
        if (this.restoring) return;
        if (this.store.isDirty()) {
            this.ctx.toast('Nejdřív uložte rozpracované změny.', true, { label: 'Uložit', onClick: () => this.ctx.actions.save() });
            return;
        }
        this.restoring = true;
        this.render();
        const answer = await api.post(urlFor(this.urls.restore, scanId), confirmed ? { confirm: '1' } : {});
        this.restoring = false;
        if (answer.code === 'layout_changed') {
            this.render();
            const yes = await this.ctx.confirm({
                title: 'Vrátit původní rozvržení?',
                text: 'Od použití skenu jste rozvržení upravili. Vrácením o tyto úpravy přijdete. Texty, fotky a obsah stránek zůstanou.',
                confirmLabel: 'Vrátit původní',
                danger: true,
            });
            if (yes) await this.restore(scanId, true);
            return;
        }
        if (!answer.ok || !answer.document) {
            this.render();
            this.ctx.toast(answer.code === 'wrong_state' ? 'Původní rozvržení už vrátit nejde — uchovali jsme ho jen do data uvedeného u návrhu.' : 'Původní rozvržení se nepodařilo vrátit. Zkuste to znovu.', true);
            if (answer.code === 'wrong_state') this.refreshEntry().then(() => this.changed());
            return;
        }
        if (this.entry) this.entry.applied = null;
        if (this.scan && this.scan.id === scanId) { this.open = false; this.another(); }
        this.store.load(answer.document);
        this.changed();
        this.ctx.toast('Původní rozvržení vráceno');
    }
}

function pictureError(answer, fromDisk = true) {
    if (answer.code === 'invalid_fix' && answer.field === 'size') return 'Obrázek je větší než 10 MB. Zmenšete ho nebo ho uložte jako JPG.';
    if (answer.code === 'invalid_fix' && answer.field === 'type') return fromDisk ? 'Tento soubor neumíme. Nahrajte obrázek JPG, PNG, WebP nebo SVG.' : 'Tento obrázek z galerie tady použít nejde. Vyberte JPG, PNG, WebP nebo SVG.';
    if (answer.code === 'invalid_fix' && answer.field === 'picture') return 'Obrázek už v galerii není. Vyberte jiný.';
    if (answer.code === 'network') return 'Obrázek se nepodařilo nahrát. Zkontrolujte připojení a zkuste to znovu.';
    return 'Obrázek se nepodařilo nahrát. Zkuste to znovu.';
}

const click = (scan, selector) => { const input = scan.root.querySelector(selector); if (input) { input.value = ''; input.click(); } };

const ACTIONS = {
    open: (scan) => scan.show(),
    close: (scan) => scan.close(),
    pick: (scan) => click(scan, '#calScanFile'),
    fileInput: (scan, el) => scan.take(el.files),
    consent: (scan, el) => { scan.consent[Number(el.dataset.i)] = el.checked; scan.render(); },
    start: (scan) => scan.start(),
    privacy: () => views.privacyDialog(),
    request: (scan) => views.requestDialog(scan),
    toggleStages: (scan, el) => { window.setTimeout(() => { scan.stagesOpen = el.parentElement.open; }, 0); },
    cancel: (scan) => scan.cancel(),
    retry: (scan) => scan.again('retry', 'retry'),
    continueAnyway: (scan) => scan.again('continue', 'continue'),
    other: (scan) => scan.another(),
    discard: (scan) => scan.discard(),
    rescan: (scan) => scan.rescan(),
    toggleLayers: (scan, el) => { scan.layers = el.checked; scan.drawn = null; scan.render(); },
    rate: (scan, el) => scan.rate(Number(el.dataset.v)),
    sendFeedback: (scan) => scan.sendFeedback(),
    pictureGallery: (scan, el) => scan.pickFromGallery(el.dataset.slot),
    picturesPick: (scan) => click(scan, '#calScanPics'),
    picturesInput: (scan, el) => scan.holdMany(el.files),
    compareMode: (scan, el) => { scan.compare = el.dataset.v; scan.drawn = null; scan.render(); },
    feature: (scan, el) => { if (el.checked) scan.ticked.add(el.dataset.key); else scan.ticked.delete(el.dataset.key); scan.render(); },
    toPictures: (scan) => { scan.step = 'pictures'; scan.drawn = null; scan.changed(); scan.focusHeading(); },
    toReview: (scan) => { scan.step = 'review'; scan.drawn = null; scan.changed(); scan.focusHeading(); },
    picturePick: (scan, el) => { scan.pendingSlot = el.dataset.slot; click(scan, '#calScanPic'); },
    pictureInput: (scan, el) => { const slot = scan.pendingSlot; scan.pendingSlot = null; if (slot && el.files && el.files[0]) scan.holdPicture(slot, el.files[0]); },
    pictureDrop: (scan, el) => scan.dropPicture(el.dataset.slot),
    save: (scan) => scan.ctx.actions.save(),
    apply: (scan) => scan.apply(),
    restore: (scan, el) => scan.restore(el.dataset.scan),
};
