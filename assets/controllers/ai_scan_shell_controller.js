import { Controller } from '@hotwired/stimulus';
import { toast, showPendingToast } from '../shell/toast.js';

/*
 * The shell's AI scan signals (docs/ai-scan/ux.md §7, docs/architecture/app-shell.md
 * § "AI scan signals"): the peach ring / count on the sidebar's "Šablony" item,
 * the dot on the phone's menu button, and one toast when a scan of the viewer
 * ends while they are somewhere else.
 *
 * NO request unless there is something to wait for: the page polls
 * `GET /ai-scans/mine` every 10 s only when it was rendered with an unfinished
 * scan of the viewer (`poll` value) or after the window event `ai-scan:changed`
 * (a surface started / retried / cancelled a scan — one poll at once). It stops
 * when nothing is in progress and rests while the tab is hidden (one poll when
 * it comes back).
 *
 * `known` — ids of the scans this page knows as unfinished. A scan that leaves
 * that set in `ready` / `stopped` / `failed` / `refused` gets its toast, except
 * the scan the page itself shows (`<html data-as-scan="{id}">`, set by the scan
 * page — it announces the end itself). The badge is redrawn from every answer.
 */
const POLL_MS = 10000;
const IN_PROGRESS = ['checking', 'queued', 'running'];
const DIRTY = '.ed-state--dirty:not(.d-none), .cal-status[data-cal-status]:not([data-cal-status="clean"]), .group-variant-dirty:not(.d-none)';
const plural = (n, one, few, many) => (n === 1 ? one : n >= 2 && n <= 4 ? few : many);

export default class extends Controller {
    static values = {
        url: String,
        poll: Boolean,
        known: Array,
        projectId: String,
        // Route `ai_scan_page`.
        page: { type: String, default: '/project/__PROJECT__/sablony/z-obrazku/__SCAN__' },
    };

    connect() {
        this.known = new Set(this.knownValue);
        this.timer = null;
        this.busy = false;
        this.onChanged = () => this.pollNow();
        this.onVisible = () => { if (!document.hidden && this.active) this.pollNow(); };
        window.addEventListener('ai-scan:changed', this.onChanged);
        document.addEventListener('visibilitychange', this.onVisible);
        showPendingToast();
        this.active = this.pollValue;
        if (this.active) this.schedule();
    }

    disconnect() {
        window.removeEventListener('ai-scan:changed', this.onChanged);
        document.removeEventListener('visibilitychange', this.onVisible);
        window.clearTimeout(this.timer);
    }

    schedule() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.tick(), POLL_MS);
    }

    tick() {
        if (document.hidden) return; // visibilitychange polls when the tab comes back
        this.pollNow();
    }

    async pollNow() {
        if (this.busy || !this.urlValue) return;
        this.active = true;
        this.busy = true;
        window.clearTimeout(this.timer);
        let body = null;
        try {
            const response = await fetch(this.urlValue, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            // A session that ended answers with the login page (or 401 / 403): stop asking.
            const json = response.ok && (response.headers.get('Content-Type') || '').includes('json');
            body = json ? await response.json() : null;
            if (!json && !response.redirected && response.status >= 500) body = undefined; // a hiccup: try again later
        } catch (e) {
            body = undefined; // offline: try again later
        }
        this.busy = false;
        if (body === null) { this.active = false; return; }
        if (body && Array.isArray(body.scans)) this.apply(body.scans);
        if (this.active) this.schedule();
    }

    apply(scans) {
        const shown = document.documentElement.dataset.asScan || '';
        const now = new Set();
        let inProgress = 0;
        let attention = 0;
        let any = false;

        for (const scan of scans) {
            const unfinished = IN_PROGRESS.includes(scan.status);
            const waiting = !unfinished && !scan.seen && scan.status !== 'cancelled';
            if (unfinished) now.add(scan.id);
            if (unfinished || waiting) any = true;
            if (scan.projectId === this.projectIdValue && scan.kind === 'template') {
                if (unfinished) inProgress++;
                else if (waiting) attention++;
            }
            if (!unfinished && this.known.has(scan.id) && scan.id !== shown) this.notify(scan);
        }

        this.known = now;
        this.active = now.size > 0;
        this.badge(inProgress, attention, any);
    }

    notify(scan) {
        const name = scan.name || scan.fileName || 'bez názvu';
        const prefix = scan.projectId !== this.projectIdValue && scan.projectName ? `${scan.projectName}: ` : '';
        const href = this.pageValue.replace('__PROJECT__', scan.projectId).replace('__SCAN__', scan.id);
        // An editor with unsaved work must not be left by a click on a toast.
        const newTab = document.querySelector(DIRTY) !== null;
        const action = (label) => ({ label, href, newTab });
        if (scan.status === 'ready') {
            toast(`${prefix}Návrh šablony „${name}“ je připravený ke kontrole`, { ai: true, key: `scan-${scan.id}`, action: action('Zkontrolovat') });
        } else if (scan.status === 'stopped') {
            toast(`${prefix}Sken „${name}“ potřebuje jiný obrázek`, { key: `scan-${scan.id}`, action: action('Zobrazit') });
        } else if (scan.status === 'failed' || scan.status === 'refused') {
            toast(`${prefix}Sken „${name}“ se nepodařil`, { error: true, key: `scan-${scan.id}`, action: action('Zobrazit') });
        }
    }

    /** Redraws the signals in place — the same markup templates/_shell/_ai_scan_signal.html.twig renders. */
    badge(inProgress, attention, any) {
        const item = document.querySelector('[data-ai-scan-nav]');
        if (item) {
            item.querySelectorAll('[data-ai-scan-signal]').forEach((el) => el.remove());
            let html = '';
            if (inProgress > 0) html += '<span class="as-ring hide-c" data-ai-scan-signal role="img" aria-label="Sken běží"></span>';
            if (attention > 0) {
                const text = `${attention} ${plural(attention, 'sken čeká', 'skeny čekají', 'skenů čeká')} na vaši pozornost`;
                html += `<span class="count as-pch hide-c" data-ai-scan-signal title="${text}"><span aria-hidden="true">${attention}</span><span class="sr">${text}</span></span>`;
            }
            if (inProgress > 0 || attention > 0) html += '<span class="as-ndot" data-ai-scan-signal aria-hidden="true"></span>';
            item.insertAdjacentHTML('beforeend', html);
        }
        const menu = document.querySelector('.mbar [data-action~="shell#openDrawer"]');
        if (menu) {
            menu.querySelectorAll('[data-ai-scan-signal]').forEach((el) => el.remove());
            menu.setAttribute('aria-label', any ? 'Otevřít menu — sken čeká na vaši pozornost' : 'Otevřít menu');
            if (any) menu.insertAdjacentHTML('beforeend', '<span class="as-ndot" data-ai-scan-signal aria-hidden="true"></span>');
        }
    }
}
