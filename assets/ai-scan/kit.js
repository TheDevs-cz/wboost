// AI sken — shared helpers of every surface (docs/architecture/ai-scan.md § "UI").
// Markup is built as strings (the mock's way, docs/ai-scan/ux-mock): EVERY value
// that did not come from this code goes through esc().

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (ch) => ESCAPES[ch]);
/** Nocturne mask icon. */
export const ic = (name, size = 's16') => `<span class="i i-${name}${size ? ` ${size}` : ''}" aria-hidden="true"></span>`;
export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

// ---- Czech counters (ux.md §2.4) -------------------------------------------------------
/** 1 · 2–4 · 5+ (0 takes the third form). */
export const pl = (n, one, few, many) => (n === 1 ? one : n >= 2 && n <= 4 ? few : many);
/** `z` / `ze` by the number that follows. */
export const zz = (n) => ([2, 3, 4, 6, 7, 12, 13, 14, 16, 17, 100, 200].includes(n) ? 'ze' : 'z');
export const mmss = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};
/** Seconds as a stage duration: `6 s`, `1 min 12 s`. */
export const duration = (seconds) => {
    const s = Math.max(0, Math.round(seconds));
    return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`;
};
/** `[from, to]` seconds → `3–6 min` / `během chvíle` (always a range, upper bound rounded up). */
export const minutesRange = (range) => {
    if (!Array.isArray(range) || range.length < 2) return null;
    const to = Math.ceil(range[1] / 60);
    if (range[1] < 60) return 'během chvíle';
    const from = Math.max(1, Math.floor(range[0] / 60));
    return from >= to ? `${from}–${from + 1} min` : `${from}–${to} min`;
};
const MONTHS_GEN = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
/** ISO → `12. 12. 2026`. */
export const dateCz = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : `${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()}`;
};
/** `2026-12-01` → `1. prosince`. */
export const firstOfMonthCz = (ymd) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
    return m ? `${Number(m[3])}. ${MONTHS_GEN[Number(m[2]) - 1]}` : '';
};
export const bytesCz = (bytes) => (bytes >= 1000000 ? `${(bytes / 1000000).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1000))} kB`);
/** `Před vámi je 1 sken · jsou {n} skeny · je {n} skenů` */
export const scansAhead = (n) => `Před vámi ${pl(n, 'je', 'jsou', 'je')} ${n} ${pl(n, 'sken', 'skeny', 'skenů')}`;
export const creditLine = (credits, prefix = 'Spotřebuje 1 sken') => {
    if (!credits || credits.limit === null || credits.limit === undefined) return prefix;
    if (credits.left === 1 && prefix === 'Spotřebuje 1 sken') return 'Spotřebuje poslední sken projektu v tomto měsíci';
    return `${prefix} · v projektu tento měsíc zbývá ${credits.left} ${zz(credits.limit)} ${credits.limit}`;
};

// ---- requests ----------------------------------------------------------------------------
// The scan POSTs use the stateless CSRF id `ai_scan`: the literal token below, checked against
// the request's Origin (config/packages/csrf.php).
const CSRF = 'csrf-token';

async function read(response) {
    let body = null;
    try { body = await response.json(); } catch (e) { body = null; }
    if (body && typeof body === 'object' && 'ok' in body) return { status: response.status, ...body };
    return { ok: response.ok, status: response.status, code: response.ok ? null : 'http_error', ...(body && typeof body === 'object' ? body : {}) };
}
async function send(url, init) {
    try {
        return await read(await fetch(url, { credentials: 'same-origin', ...init, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(init.headers || {}) } }));
    } catch (e) {
        return { ok: false, status: 0, code: 'network' };
    }
}
export const api = {
    get: (url) => send(url, { method: 'GET' }),
    post: (url, body = {}) => send(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _token: CSRF, ...body }) }),
    /** multipart; `fields` values may be strings, Blobs or arrays of Blobs (sent as `name[]`). */
    upload: (url, fields = {}) => {
        const form = new FormData();
        form.append('_token', CSRF);
        for (const [name, value] of Object.entries(fields)) {
            if (value === null || value === undefined) continue;
            if (Array.isArray(value)) value.forEach((v) => form.append(`${name}[]`, v, v.name));
            else if (value instanceof Blob) form.append(name, value, value.name || 'file');
            else form.append(name, String(value));
        }
        return send(url, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: form });
    },
};
/** A route pattern generated with the nil uuid → the URL of `id`. */
export const NIL = '00000000-0000-0000-0000-000000000000';
export const urlFor = (pattern, id) => String(pattern).replace(NIL, id);

// ---- dialogs ------------------------------------------------------------------------------
/**
 * A native modal <dialog> (focus stays inside, Escape closes, focus returns to the opener).
 * `html` is the body; controls with `data-close` close it; `data-autofocus` takes the focus.
 * Returns the element; `dialog.addEventListener('close', …)`. `alert: true` → role alertdialog.
 */
export function openDialog(html, { cls = '', alert = false, label = null, onClose = null } = {}) {
    const opener = document.activeElement;
    const dialog = document.createElement('dialog');
    dialog.className = `dlg as-dlg ${cls}`.trim();
    if (alert) dialog.setAttribute('role', 'alertdialog');
    dialog.innerHTML = html;
    const title = dialog.querySelector('h2');
    if (title) {
        title.id = title.id || `asDlgT${Date.now()}`;
        dialog.setAttribute('aria-labelledby', title.id);
    } else if (label) {
        dialog.setAttribute('aria-label', label);
    }
    document.body.append(dialog);
    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) dialog.close();
        else if (event.target === dialog && !alert) dialog.close(); // click on the scrim
    });
    dialog.addEventListener('close', () => {
        dialog.remove();
        if (onClose) onClose();
        if (opener && document.contains(opener) && typeof opener.focus === 'function') opener.focus({ preventScroll: true });
    });
    dialog.showModal();
    const first = dialog.querySelector('[data-autofocus]') || dialog.querySelector('button:not([data-x]), a[href], input, textarea, select');
    if (first) first.focus({ preventScroll: true });
    return dialog;
}
export const dialogHead = (title, extra = '') => `<div class="dlg-h"><h2>${esc(title)}${extra}</h2><button type="button" class="btn icon xs btn-ghost" data-close data-x aria-label="Zavřít">${ic('x')}</button></div>`;
/** Resolves true when the danger / primary button was pressed. */
export function confirmDialog({ title, text, cancel = 'Zrušit', confirm, danger = true }) {
    return new Promise((resolve) => {
        let answer = false;
        const dialog = openDialog(
            `${dialogHead(title)}<p>${esc(text)}</p><div class="dlg-f"><button type="button" class="btn sm btn-tertiary" data-close data-autofocus>${esc(cancel)}</button><button type="button" class="btn sm ${danger ? 'btn-danger' : 'btn-primary'}" data-confirm>${esc(confirm)}</button></div>`,
            { alert: true, onClose: () => resolve(answer) },
        );
        dialog.querySelector('[data-confirm]').addEventListener('click', () => { answer = true; dialog.close(); });
    });
}

/** Announces to screen readers through the page's visually hidden live region. */
export function announce(text) {
    let region = document.getElementById('asLive');
    if (!region) {
        region = document.createElement('div');
        region.id = 'asLive';
        region.className = 'sr';
        region.setAttribute('aria-live', 'polite');
        region.setAttribute('role', 'status');
        document.body.append(region);
    }
    region.textContent = '';
    window.setTimeout(() => { region.textContent = text; }, 50);
}

export const store = {
    get(key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
    set(key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
};
