// AI sken — the wizard's page (ux.md §5, S1–S5). One object owns the state; every screen is a
// function of it that returns markup (views/*.js, the mock's structure), and `render()` patches
// the page region by region so a 2 s poll never rebuilds a picture or steals the focus.
//
// Contract consumed (docs/architecture/ai-scan.md § "Endpoints of the wizard"):
//   - boot JSON from templates/ai_scan/page.html.twig: project, scan (a list row or null), entry, urls
//   - GET status every 2 s while the scan is checking / queued / running
//   - GET proposal when ready / accepted; POST fix / preview / pictures / accept / action / rating / seen
//
// Markup hooks: data-act (click), data-chg (change), data-enter (Enter in an input), data-hl
// (hover / focus highlights the box `data-box` of the same key), data-k (identity of a control —
// focus and unsent text survive a re-render), data-region (a patchable part), data-elapsed +
// data-since (a ticking time).
import { $, $$, api, announce, esc, mmss, urlFor } from '../kit.js';
import { toast, toastAfterNavigation } from '../../shell/toast.js';
import * as upload from './upload.js';
import * as wait from './wait.js';
import * as review from './review.js';
import * as pictures from './pictures.js';

const IN_PROGRESS = ['checking', 'queued', 'running'];
const POLL_MS = 2000;

export class ScanApp {
    constructor(root, boot) {
        this.root = root;
        this.boot = boot;
        this.urls = boot.urls;
        this.project = boot.project;
        this.entry = boot.entry;
        this.scan = boot.scan;
        this.proposal = null;
        /** S1 / S2 */
        this.picked = null; // result of inspectFiles()
        this.uploadError = null; // { code, width, height }
        this.consent = [false, false];
        this.format = { mode: 'screen', choice: 'source', custom: '' };
        this.requestId = null;
        this.starting = false;
        this.startRefusal = null;
        /** S4 / S4b */
        this.step = 'review'; // review | pictures
        this.tab = 'decisions';
        this.layers = false;
        this.compare = 'slider';
        this.pos = 52;
        this.saving = 0;
        this.saved = false;
        this.merge = null; // null | Set of place ids
        this.menu = null; // place id whose ⋯ menu is open
        this.feedback = null; // null | 'down' | 'sent'
        this.pickFor = null; // gallery picker target: { place } | { multi: Set }
        this.files = new Map(); // gallery file id → File uploaded in this session (for "Vyplnit")
        this.busy = null; // name of the action in flight (accept, retry, …)
        this.actionRefusal = null;
        this.queue = Promise.resolve();
        this.actions = { ...upload.actions, ...wait.actions, ...review.actions, ...pictures.actions };
        this.timer = null;
        this.ticker = null;
        this.title = document.title.replace(/^.*?\|/, '|');
    }

    // ---- lifecycle --------------------------------------------------------------------------

    start() {
        const r = this.root;
        r.addEventListener('click', (event) => this.onClick(event));
        r.addEventListener('change', (event) => this.dispatch(event, 'chg'));
        r.addEventListener('keydown', (event) => this.onKey(event));
        r.addEventListener('pointerover', (event) => this.highlight(event, true));
        r.addEventListener('pointerout', (event) => this.highlight(event, false));
        r.addEventListener('focusin', (event) => this.highlight(event, true));
        r.addEventListener('focusout', (event) => this.highlight(event, false));
        this.onOutside = (event) => {
            if (this.menu !== null && !event.target.closest('.as-ph-menu')) { this.menu = null; this.render(); }
        };
        document.addEventListener('pointerdown', this.onOutside, true);
        this.onAsset = (event) => pictures.assetSelected(this, event.detail || {});
        window.addEventListener('asset-selected', this.onAsset);
        this.onPop = () => window.location.reload();
        window.addEventListener('popstate', this.onPop);
        upload.bindPageDrop(this);
        pictures.bindDrop(this);
        try { this.compare = window.localStorage.getItem('wb-as-cmp') === 'side' ? 'side' : 'slider'; } catch (e) { /* private mode */ }
        if (window.location.hash === '#obrazky') this.step = 'pictures';

        this.ticker = window.setInterval(() => this.tick(), 1000);
        this.enter(true);
    }

    stop() {
        window.clearTimeout(this.timer);
        window.clearInterval(this.ticker);
        document.removeEventListener('pointerdown', this.onOutside, true);
        window.removeEventListener('asset-selected', this.onAsset);
        window.removeEventListener('popstate', this.onPop);
        upload.unbindPageDrop(this);
        delete document.documentElement.dataset.asScan;
    }

    /** Called whenever `this.scan` was replaced by a fresh status: picks the screen. */
    async enter(first = false) {
        const scan = this.scan;
        document.documentElement.dataset.asScan = scan ? scan.id : '';
        if (!scan) { this.render(); return; }

        if (IN_PROGRESS.includes(scan.status)) {
            wait.observe(this, scan);
            this.render();
            this.schedule();
            return;
        }
        window.clearTimeout(this.timer);
        if (scan.status === 'ready' || scan.status === 'accepted') {
            await this.loadProposal();
            if (!this.proposal || !this.proposal.placeholders.length) this.step = 'review';
        }
        this.render();
        this.seen();
        if (!first) this.focusHeading();
    }

    schedule() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.poll(), POLL_MS);
    }

    async poll() {
        const scan = this.scan;
        if (!scan || !IN_PROGRESS.includes(scan.status)) return;
        const answer = await api.get(urlFor(this.urls.status, scan.id));
        if (this.scan !== scan) return; // the user moved on meanwhile
        if (!answer.ok || !answer.scan) {
            // A blip keeps the last known state on screen; the next poll tries again.
            if (answer.status === 404 || answer.status === 403) { window.location.reload(); return; }
            this.schedule();
            return;
        }
        const before = scan.status;
        const beforeStage = scan.stage;
        this.scan = { ...scan, ...answer.scan };
        if (IN_PROGRESS.includes(this.scan.status)) {
            wait.observe(this, this.scan);
            if (before !== this.scan.status || beforeStage !== this.scan.stage) announce(wait.announcement(this.scan));
            this.render();
            this.schedule();
            return;
        }
        // The end: S4 opens by itself, S5 replaces the status card.
        wait.observe(this, this.scan);
        this.notifyShell();
        if (this.scan.status === 'ready') {
            announce('Návrh je připravený.');
            toast('Návrh je připravený');
        } else {
            announce(wait.announcement(this.scan));
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
        return this.entry;
    }

    /** "Seen" = the requester's browser got the final state on the scan surface (ux.md §7). */
    seen() {
        const scan = this.scan;
        if (!scan || IN_PROGRESS.includes(scan.status) || !scan.mine || scan.seen) return;
        scan.seen = true;
        api.post(urlFor(this.urls.seen, scan.id)).then(() => this.notifyShell());
    }

    /** The shell's badge reads the user's scans again (ai_scan_shell_controller). */
    notifyShell() {
        window.dispatchEvent(new CustomEvent('ai-scan:changed', { detail: { scanId: this.scan ? this.scan.id : null } }));
    }

    // ---- which screen -------------------------------------------------------------------------

    view() {
        const scan = this.scan;
        if (!scan) return this.picked ? 'chosen' : 'upload';
        if (IN_PROGRESS.includes(scan.status)) return 'wait';
        if (scan.status === 'ready' && this.proposal) return this.step === 'pictures' && this.proposal.placeholders.length ? 'pictures' : 'review';
        if (scan.status === 'accepted') return 'readonly';
        if (['stopped', 'failed', 'refused'].includes(scan.status)) return 'fail';
        return 'gone';
    }

    render() {
        const view = this.view();
        const html = {
            upload: () => upload.uploadView(this),
            chosen: () => upload.chosenView(this),
            wait: () => wait.waitView(this),
            fail: () => wait.failView(this),
            gone: () => wait.goneView(this),
            review: () => review.reviewView(this),
            readonly: () => review.readonlyView(this),
            pictures: () => pictures.picturesView(this),
        }[view]();
        this.root.dataset.view = view;
        const keep = this.snapshot();
        morph(this.root, html);
        this.restore(keep);
        this.setTitle(view);
        this.tick();
        if (view === 'review' || view === 'readonly' || view === 'pictures') review.afterRender(this);
    }

    setTitle(view) {
        const scan = this.scan;
        const name = scan ? (this.proposal && this.proposal.name) || scan.name || scan.fileName || 'sken' : '';
        const lead = {
            upload: 'Šablona z obrázku',
            chosen: 'Šablona z obrázku',
            wait: scan && { checking: `Kontrola… — ${name}`, queued: `Ve frontě — ${name}`, running: `Sken běží — ${name}` }[scan.status],
            review: `✓ Návrh je připravený — ${name}`,
            pictures: `Fotky a loga — ${name}`,
            readonly: `Sken — ${name}`,
            fail: scan && wait.failTitle(scan),
            gone: 'Šablona z obrázku',
        }[view];
        document.title = `${lead} ${this.title}`;
    }

    focusHeading() {
        const heading = $('[data-focus]', this.root);
        if (heading) heading.focus({ preventScroll: false });
    }

    // ---- patching helpers -------------------------------------------------------------------

    snapshot() {
        const active = document.activeElement;
        const keep = { k: null, start: null, end: null, dirty: {}, scroll: {} };
        if (active && this.root.contains(active) && active.dataset.k) {
            keep.k = active.dataset.k;
            try { keep.start = active.selectionStart; keep.end = active.selectionEnd; } catch (e) { /* not a text control */ }
        }
        $$('input[data-k], textarea[data-k]', this.root).forEach((el) => {
            if (el.type !== 'checkbox' && el.type !== 'file' && el.value !== el.defaultValue) keep.dirty[el.dataset.k] = [el.defaultValue, el.value];
        });
        $$('[data-scroll]', this.root).forEach((el) => { keep.scroll[el.dataset.scroll] = el.scrollTop; });
        return keep;
    }

    restore(keep) {
        for (const [k, [was, value]] of Object.entries(keep.dirty)) {
            const el = $(`[data-k="${CSS.escape(k)}"]`, this.root);
            if (el && el.defaultValue === was && el.value !== value) el.value = value;
        }
        for (const [key, top] of Object.entries(keep.scroll)) {
            const el = $(`[data-scroll="${CSS.escape(key)}"]`, this.root);
            if (el && el.scrollTop !== top) el.scrollTop = top;
        }
        if (keep.k && !(document.activeElement && this.root.contains(document.activeElement) && document.activeElement.dataset.k === keep.k)) {
            const el = $(`[data-k="${CSS.escape(keep.k)}"]`, this.root);
            if (el && !el.disabled) {
                el.focus({ preventScroll: true });
                try { if (keep.start !== null && el.setSelectionRange) el.setSelectionRange(keep.start, keep.end); } catch (e) { /* not a text control */ }
            }
        }
    }

    tick() {
        const now = Date.now();
        $$('[data-elapsed]', this.root).forEach((el) => {
            const text = mmss((now - Number(el.dataset.since)) / 1000);
            if (el.textContent !== text) el.textContent = text;
        });
        if (this.view() === 'wait' && wait.needsRender(this)) this.render();
    }

    // ---- events ---------------------------------------------------------------------------------

    onClick(event) {
        const el = event.target.closest('[data-act]');
        if (!el || !this.root.contains(el) || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
        const fn = this.actions[el.dataset.act];
        if (!fn) return;
        if (el.tagName === 'A') event.preventDefault();
        fn(this, el, event);
    }

    dispatch(event, kind) {
        const el = event.target.closest(`[data-${kind}]`);
        if (!el || !this.root.contains(el)) return;
        const fn = this.actions[el.dataset[kind]];
        if (fn) fn(this, el, event);
    }

    onKey(event) {
        if (event.key === 'Enter' && event.target.dataset && event.target.dataset.enter) {
            event.preventDefault();
            const fn = this.actions[event.target.dataset.enter];
            if (fn) fn(this, event.target, event);
            return;
        }
        if (event.key === 'Escape' && this.menu !== null) {
            const opener = this.menu;
            this.menu = null;
            this.render();
            const button = $(`[data-k="phmenu${CSS.escape(opener)}"]`, this.root);
            if (button) button.focus();
            event.stopPropagation();
            return;
        }
        if (event.target.closest('.as-handle')) review.sliderKey(this, event);
    }

    highlight(event, on) {
        const el = event.target.closest ? event.target.closest('[data-hl]') : null;
        if (!el) return;
        const key = el.dataset.hl;
        $$(`[data-box="${CSS.escape(key)}"]`, this.root).forEach((box) => box.classList.toggle('hl', on));
    }

    // ---- navigation -------------------------------------------------------------------------------

    scanUrl(id) { return urlFor(this.urls.page, id); }

    /** The address bar follows the scan without a reload (one URL per scan). */
    pushScan(scan) {
        const url = scan ? this.scanUrl(scan.id) : this.urls.new;
        if (window.location.pathname !== url) window.history.pushState({}, '', url);
    }

    leave(url, message = null, options = {}) {
        if (message) toastAfterNavigation(message, options);
        window.location.assign(url);
    }

    /** The original as the browser can always show it (the scanner's normalised copy). */
    originalUrl() { return this.image('working'); }

    /** The proposal's picture: the review's own render once a fix was rendered, else the kept candidate. */
    proposalUrl() {
        const preview = this.proposal ? this.proposal.preview : { seq: 0 };
        return this.image('proposal', `?v=${preview.seq}`);
    }

    image(which, query = '') {
        return `${urlFor(this.urls.image, this.scan.id).replace(/\/original$/, `/${which}`)}${query}`;
    }

    // ---- fixes (S4, S4b) --------------------------------------------------------------------------

    /** One fix at a time, in the order of the clicks; the answer's proposal replaces the state. */
    fix(fix, { silent = false } = {}) {
        this.saving += 1;
        this.saved = false;
        this.render();
        const run = async () => {
            const answer = await api.post(urlFor(this.urls.fix, this.scan.id), { fix });
            this.saving -= 1;
            return this.fixed(answer, silent);
        };
        this.queue = this.queue.then(run, run);
        return this.queue;
    }

    fixed(answer, silent = false) {
        if (answer.ok) {
            if (answer.proposal) this.proposal = answer.proposal;
            this.saved = true;
            this.previewFailed = answer.previewStale === true;
        } else if (answer.code === 'wrong_state') {
            // Somebody created or discarded the proposal in another tab.
            window.location.reload();
            return answer;
        } else if (!silent) {
            toast(fixError(answer), { error: true });
        }
        this.render();
        return answer;
    }
}

function fixError(answer) {
    if (answer.code === 'network') return 'Změnu se nepodařilo uložit. Zkontrolujte připojení a zkuste to znovu.';
    if (answer.code === 'invalid_fix' && answer.field === 'name') return 'Název musí mít 3 až 30 znaků.';
    if (answer.code === 'invalid_fix' && answer.field === 'size') return 'Obrázek je větší než 10 MB. Zmenšete ho nebo ho uložte jako JPG.';
    if (answer.code === 'invalid_fix' && answer.field === 'type') return 'Tento soubor neumíme. Nahrajte obrázek JPG, PNG, WebP nebo HEIC.';
    if (answer.code === 'invalid_fix') return 'Tuhle změnu nejde použít — návrh by po ní nešel vykreslit.';
    if (answer.code === 'renderer_busy') return 'Náhled se teď nepodařilo obnovit. Zkuste to za chvíli.';
    return 'Změnu se nepodařilo uložit. Zkuste to znovu.';
}
export { fixError };

/**
 * Replaces `parent`'s children by `html`, reusing every child whose `data-region` key and
 * markup did not change (same node — pictures do not reload, scroll and focus stay); a region
 * marked `data-deep` is patched child by child the same way.
 */
export function morph(parent, html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    const next = [...template.content.children];
    const old = [...parent.children];
    const used = new Set();
    next.forEach((node, index) => {
        const key = node.dataset.region;
        let target = node;
        const match = key ? old.find((child) => !used.has(child) && child.dataset.region === key && child.tagName === node.tagName) : null;
        if (match) {
            const markup = node.outerHTML;
            if (match.__html === markup) {
                target = match;
            } else if (node.hasAttribute('data-deep')) {
                for (const attr of [...match.attributes]) if (!node.hasAttribute(attr.name)) match.removeAttribute(attr.name);
                for (const attr of [...node.attributes]) if (match.getAttribute(attr.name) !== attr.value) match.setAttribute(attr.name, attr.value);
                morph(match, node.innerHTML);
                match.__html = markup;
                target = match;
            }
            if (target === match) used.add(match);
        }
        if (target === node) stamp(node);
        const current = parent.children[index];
        if (current !== target) parent.insertBefore(target, current || null);
    });
    while (parent.children.length > next.length) parent.lastElementChild.remove();
}

/** Remembers the markup of a fresh node and of every region inside it. */
function stamp(node) {
    if (node.dataset.region) node.__html = node.outerHTML;
    node.querySelectorAll('[data-region]').forEach((inner) => { inner.__html = inner.outerHTML; });
}

export { esc };
