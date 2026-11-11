// Shared DOM helpers and small widgets of the calendar UI (architecture §D.2). CSS: calendar.css.
//
// Widget contract: every widget is a function `(options) → HTMLElement`. The element carries the
// methods named in each doc block (`setValue`, `setChecked`, …) so a caller can refresh the control
// WITHOUT rebuilding it — a value change that came from the panel itself must never rebuild the
// panel (inputs keep focus, §D.6). `attrs` is merged onto the interactive element (use it for the
// `data-*` test hooks of §D.10, e.g. `{ 'data-prop': 'month.size' }`).

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** Escapes text for HTML markup (use `render/svg.js` `esc` inside SVG strings). */
export function esc(text) {
    return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, (ch) => HTML_ESCAPES[ch]);
}

function append(parent, child) {
    if (child === null || child === undefined || child === false || child === true) return;
    if (Array.isArray(child)) {
        for (const c of child) append(parent, c);
        return;
    }
    parent.append(child instanceof Node ? child : document.createTextNode(String(child)));
}

/**
 * `h('button', { class: 'btn', type: 'button', onClick: fn, dataset: { calAction: 'save' }, disabled: flag }, icon('check'), 'Uložit')`
 *
 * attrs: `class` · `dataset` (object) · `style` (string or object) · `on<Event>` listeners ·
 * `html` (raw innerHTML — only for markup produced by the renderer / already escaped) ·
 * anything else is an attribute (`true` → empty attribute, `false` / `null` / `undefined` → omitted).
 * Children: nodes, strings, numbers, nested arrays; `null` / `false` are skipped.
 */
export function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'dataset') Object.assign(el.dataset, value);
        else if (key === 'style' && typeof value === 'object') Object.assign(el.style, value);
        else if (key === 'html') el.innerHTML = value;
        else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
        else el.setAttribute(key, value === true ? '' : String(value));
    }
    append(el, children);
    return el;
}

/** `<span class="i i-{name} {size}" aria-hidden="true">` — the Nocturne mask icon (`size`: `s12` … `s32`). */
export function icon(name, size) {
    return h('span', { class: `i i-${name}${size ? ` ${size}` : ''}`, 'aria-hidden': 'true' });
}

export function debounce(fn, ms) {
    let timer = null;
    const run = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            timer = null;
            fn(...args);
        }, ms);
    };
    run.cancel = () => clearTimeout(timer);
    return run;
}

/** Calls `fn(event)` on a pointer press outside `el`; returns the function that stops listening. */
export function onOutside(el, fn) {
    const listener = (event) => {
        if (!el.contains(event.target)) fn(event);
    };
    document.addEventListener('pointerdown', listener, true);
    return () => document.removeEventListener('pointerdown', listener, true);
}

// ---- numbers and time -------------------------------------------------------------------

/** `12.5` → `12,5`; at most `decimals` places, trailing zeros dropped. */
export function formatNumber(value, decimals = 1) {
    const v = Number(value);
    if (!Number.isFinite(v)) return '';
    const rounded = Number(v.toFixed(decimals));
    return String(rounded === 0 ? 0 : rounded).replace('.', ',');
}

/** `"12,5"` / `"12.5 mm"` → `12.5`; `NaN` when the text is not a number. */
export function parseNumber(text) {
    const cleaned = String(text === null || text === undefined ? '' : text)
        .replace(/\s+/g, '')
        .replace(',', '.')
        .replace(/[^0-9.+-]+$/, '');
    if (cleaned === '' || !/^[+-]?(\d+\.?\d*|\.\d+)$/.test(cleaned)) return NaN;
    return Number(cleaned);
}

/** `právě teď` · `před 5 min` · `před 3 h` · `11. 11. 2026` (map §8.1). */
export function relativeTime(iso, now = Date.now()) {
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return '';
    const minutes = Math.floor((now - then) / 60000);
    if (minutes < 1) return 'právě teď';
    if (minutes < 60) return `před ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `před ${hours} h`;
    return new Date(then).toLocaleDateString('cs-CZ');
}

// ---- toast ------------------------------------------------------------------------------

/** Kit toast (`.toasts` > `.toast-n`, design-system §3.10). */
export function toast(message, error = false) {
    let stack = document.querySelector('.toasts');
    if (!stack) {
        stack = h('div', { class: 'toasts', 'aria-live': 'polite' });
        document.body.append(stack);
    }
    const node = h('div', { class: error ? 'toast-n err' : 'toast-n', role: error ? 'alert' : 'status' }, icon(error ? 'alert' : 'check-circle', 's18'), String(message));
    stack.append(node);
    setTimeout(() => node.remove(), error ? 6000 : 3600);
    return node;
}

// ---- dialogs ----------------------------------------------------------------------------

/**
 * Shows a Bootstrap modal that may be stacked on another open one (a confirm from inside a tool
 * dialog). Bootstrap handles one modal at a time, so the stacked case is repaired here:
 * - the new dialog and its backdrop are lifted above the dialog below;
 * - when it closes, <body> keeps `modal-open` (Bootstrap removes it with ANY modal), the focus
 *   trap of the dialog below — switched off while the new dialog is open, or it would pull focus
 *   back — is switched on again, and focus returns to the element that opened the dialog, so Esc
 *   and Tab act on the dialog below again.
 * Esc always reaches only the dialog that holds focus, i.e. the top one.
 */
function showModal(el, onHidden) {
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const below = [...document.querySelectorAll('.modal.show')].pop() || null;
    document.body.append(el);
    const modal = new window.bootstrap.Modal(el);
    // the focus trap of the dialog below would pull focus back out of the new dialog
    const trapBelow = below ? (window.bootstrap.Modal.getInstance(below) || {})._focustrap : null;
    const canTrap = Boolean(trapBelow && typeof trapBelow.deactivate === 'function' && typeof trapBelow.activate === 'function');
    if (canTrap) trapBelow.deactivate();
    el.addEventListener(
        'hidden.bs.modal',
        () => {
            modal.dispose();
            el.remove();
            const parent = below && below.isConnected && below.classList.contains('show') ? below : null;
            if (parent) {
                document.body.classList.add('modal-open');
                if (canTrap) trapBelow.activate();
                const target = opener && opener.isConnected && parent.contains(opener) && !opener.disabled ? opener : parent;
                target.focus({ preventScroll: true });
            }
            onHidden();
            // the answer may have removed the opener (a deleted row): the dialog below takes focus
            if (parent) {
                setTimeout(() => {
                    if (parent.isConnected && parent.classList.contains('show') && !document.querySelector('.modal.show ~ .modal.show') && !parent.contains(document.activeElement)) parent.focus({ preventScroll: true });
                }, 0);
            }
        },
        { once: true },
    );
    modal.show();
    if (below) {
        const base = Number(getComputedStyle(below).zIndex) || 1055;
        el.style.zIndex = String(base + 10);
        const backdrop = [...document.querySelectorAll('.modal-backdrop')].pop();
        if (backdrop) backdrop.style.zIndex = String(base + 5);
    }
    return modal;
}

/**
 * A question with any number of answers. Resolves with the `key` of the pressed button, or `null`
 * when the dialog is dismissed (Esc, backdrop, close button).
 *
 * `buttons`: `[{ key, label, kind?: 'primary' | 'danger' | 'tertiary' | 'ghost' }]`, rendered left to
 * right with `data-cal-choice="<key>"`. The root is `.cal-dialog`.
 */
export function choiceDialog({ title, text, buttons, danger = false }) {
    return new Promise((resolve) => {
        let answer = null;
        const footer = h('div', { class: 'modal-footer' });
        const el = h(
            'div',
            { class: 'modal fade cal-dialog', tabindex: '-1', role: danger ? 'alertdialog' : 'dialog', 'aria-modal': 'true', 'aria-label': title },
            h(
                'div',
                { class: 'modal-dialog modal-dialog-centered' },
                h(
                    'div',
                    { class: 'modal-content' },
                    h('div', { class: 'modal-header' }, h('h2', { class: 'modal-title' }, title), h('button', { type: 'button', class: 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': 'Zavřít' })),
                    h('div', { class: 'modal-body cal-dialog-text' }, text),
                    footer,
                ),
            ),
        );
        const modal = showModal(el, () => resolve(answer));
        for (const button of buttons) {
            const kind = button.kind || 'tertiary';
            footer.append(
                h(
                    'button',
                    {
                        type: 'button',
                        class: `btn sm btn-${kind}`,
                        'data-cal-choice': button.key,
                        onClick: () => {
                            answer = button.key;
                            modal.hide();
                        },
                    },
                    button.label,
                ),
            );
        }
    });
}

/**
 * Replaces the native `confirm()` (which freezes browser automation). Resolves `true` when
 * confirmed. Buttons: `[data-cal-choice="cancel"]`, `[data-cal-choice="confirm"]`.
 */
export async function confirmDialog({ title, text, confirmLabel, cancelLabel = 'Zrušit', danger = false }) {
    const answer = await choiceDialog({
        title,
        text,
        danger,
        buttons: [
            { key: 'cancel', label: cancelLabel, kind: 'tertiary' },
            { key: 'confirm', label: confirmLabel, kind: danger ? 'danger' : 'primary' },
        ],
    });
    return answer === 'confirm';
}

/**
 * A large Bootstrap modal for tools and lists, closed by **Hotovo**, Esc, the backdrop or the ✕.
 *
 * @param o.title    heading text
 * @param o.body     Node placed into the scrolling body
 * @param o.size     `'lg'` | `'xl'` (default: the standard 520 px dialog)
 * @param o.onClose  called once, after the modal is gone
 * @param o.footer   optional Node(s) placed left of **Hotovo**
 * @returns `{ el, body, close() }` — `body` is the `.modal-body` element
 */
export function openModal({ title, body, size, onClose, footer }) {
    const bodyEl = h('div', { class: 'modal-body' }, body);
    const el = h(
        'div',
        { class: 'modal fade cal-modal', tabindex: '-1', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
        h(
            'div',
            { class: `modal-dialog modal-dialog-centered modal-dialog-scrollable${size ? ` modal-${size}` : ''}` },
            h(
                'div',
                { class: 'modal-content' },
                h('div', { class: 'modal-header' }, h('h2', { class: 'modal-title' }, title), h('button', { type: 'button', class: 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': 'Zavřít' })),
                bodyEl,
                h('div', { class: 'modal-footer' }, footer || null, h('button', { type: 'button', class: 'btn sm btn-primary', 'data-bs-dismiss': 'modal', 'data-cal-action': 'modal-done' }, 'Hotovo')),
            ),
        ),
    );
    let closed = false;
    const modal = showModal(el, () => {
        closed = true;
        if (onClose) onClose();
    });
    return {
        el,
        body: bodyEl,
        close() {
            if (!closed) modal.hide();
        },
    };
}

// ---- widgets ----------------------------------------------------------------------------

function decimalsOf(step) {
    const text = String(step);
    const dot = text.indexOf('.');
    return dot < 0 ? 0 : text.length - dot - 1;
}

/**
 * Number stepper `− [ 12,5 ] mm +` (prototype `.tc-num`).
 *
 * @param o.value     number
 * @param o.min       lower limit (typed values are corrected to it)
 * @param o.max       upper limit
 * @param o.step      step of the − / + buttons and of ArrowUp / ArrowDown (default 1)
 * @param o.decimals  places kept for typed values (default: those of `step`, at least 1)
 * @param o.unit      suffix text (`mm`, `pt`, `%`)
 * @param o.label     aria-label of the group and of the input
 * @param o.onChange  `(value: number) => void` — called for every accepted value
 * @param o.onStart   called before `onChange` of one user action (store.beginChange for sliders)
 * @param o.onCommit  called after it (store.commitChange)
 * @param o.attrs     attributes of the `<input>` (`{ 'data-field': 'x' }`, `{ 'data-prop': … }`)
 * @returns HTMLElement `.cal-num` with `setValue(value)` (no callbacks) and `input` (the `<input>`)
 *
 * Enter confirms and leaves the field; Esc restores the shown value; a value that is not a
 * number is dropped; an out-of-range value is corrected and the field shows the corrected number.
 */
export function numberField({ value, min = -Infinity, max = Infinity, step = 1, decimals, unit, label, onChange, onStart, onCommit, attrs }) {
    const places = decimals === undefined ? Math.max(1, decimalsOf(step)) : decimals;
    let current = Number(value);
    const input = h('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', spellcheck: 'false', 'aria-label': label, ...(attrs || {}) });
    const show = () => {
        input.value = formatNumber(current, places);
    };
    const accept = (next) => {
        let v = Math.min(max, Math.max(min, next));
        v = Number(v.toFixed(places));
        const changed = v !== current;
        current = v;
        show();
        if (!changed) return;
        if (onStart) onStart();
        if (onChange) onChange(v);
        if (onCommit) onCommit();
    };
    const nudge = (direction) => accept(Math.round((current + direction * step) / step) * step);
    input.addEventListener('change', () => {
        const typed = parseNumber(input.value);
        if (Number.isNaN(typed)) show();
        else accept(typed);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
        } else if (event.key === 'Escape') {
            show();
            input.blur();
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            nudge(event.key === 'ArrowUp' ? 1 : -1);
        }
    });
    input.addEventListener('focus', () => input.select());
    const el = h(
        'span',
        { class: 'cal-num', role: 'group', 'aria-label': label },
        h('button', { type: 'button', 'aria-label': 'Méně', tabindex: '-1', onClick: () => nudge(-1) }, icon('minus', 's14')),
        input,
        unit ? h('span', { class: 'u', 'aria-hidden': 'true' }, unit) : null,
        h('button', { type: 'button', 'aria-label': 'Více', tabindex: '-1', onClick: () => nudge(1) }, icon('plus', 's14')),
    );
    show();
    el.input = input;
    el.setValue = (next) => {
        current = Number(next);
        if (document.activeElement !== input) show();
    };
    return el;
}

/**
 * Checkbox row (kit `.cb` over a hidden native input).
 *
 * @param o.label     text or Node
 * @param o.checked   boolean
 * @param o.mixed     tri-state "some": shows a dash, `aria-checked="mixed"`
 * @param o.onChange  `(checked: boolean) => void`
 * @param o.attrs     attributes of the `<input>`
 * @returns HTMLElement `label.cal-check` with `setChecked(checked, mixed?)` and `input`
 */
export function checkRow({ label, checked, mixed = false, onChange, attrs, strong = false }) {
    const input = h('input', { type: 'checkbox', class: 'sr', ...(attrs || {}) });
    const box = h('span', { class: 'cb', 'aria-hidden': 'true' });
    const text = h(strong ? 'b' : 'span', { class: 'cal-check-t' }, label);
    const el = h('label', { class: 'cal-check' }, input, box, text);
    const paint = (on, some) => {
        input.checked = on;
        input.indeterminate = some && !on;
        if (some && !on) input.setAttribute('aria-checked', 'mixed');
        else input.removeAttribute('aria-checked');
        box.className = `cb${on ? ' on' : some ? ' ind' : ''}`;
        box.replaceChildren(on ? icon('check') : some ? h('span', { class: 'cal-cbm' }) : icon('check'));
        el.classList.toggle('on', on);
    };
    input.addEventListener('change', () => {
        const on = input.checked;
        paint(on, false);
        if (onChange) onChange(on);
    });
    paint(checked === true, mixed === true);
    el.input = input;
    el.setChecked = (on, some = false) => paint(on === true, some === true);
    return el;
}

/**
 * Switch row (kit `.sw.sm` over `input[role=switch]`).
 *
 * @param o.label / o.checked / o.onChange / o.attrs  as `checkRow`
 * @returns HTMLElement `label.cal-switch` with `setChecked(checked)` and `input`
 */
export function switchRow({ label, checked, onChange, attrs }) {
    const input = h('input', { type: 'checkbox', role: 'switch', class: 'sr', ...(attrs || {}) });
    const knob = h('span', { class: 'sw sm', 'aria-hidden': 'true' });
    const el = h('label', { class: 'cal-switch' }, input, knob, h('span', { class: 'cal-switch-t' }, label));
    const paint = (on) => {
        input.checked = on;
        knob.classList.toggle('on', on);
    };
    input.addEventListener('change', () => {
        paint(input.checked);
        if (onChange) onChange(input.checked);
    });
    paint(checked === true);
    el.input = input;
    el.setChecked = (on) => paint(on === true);
    return el;
}

/**
 * Segmented control (kit `.seg`); each option is a `button[aria-pressed]` carrying `data-value`.
 *
 * @param o.options   `[{ value, label?, icon?, title?, attrs? }]` — `icon` = icon name, `title` = tooltip / aria-label
 * @param o.value     current value
 * @param o.onChange  `(value) => void` (not called for the already active option)
 * @param o.label     aria-label of the group
 * @param o.soft      neutral active state (`.seg.soft`) — default true
 * @param o.full      stretch to the container width
 * @param o.attrs     attributes of the group element
 * @returns HTMLElement `.seg` with `setValue(value)`
 */
export function segmented({ options, value, onChange, label, soft = true, full = false, attrs }) {
    let current = value;
    const el = h('div', { class: `seg cal-seg${soft ? ' soft' : ''}${full ? ' full' : ''}`, role: 'group', 'aria-label': label, ...(attrs || {}) });
    const buttons = options.map((option) => {
        const button = h(
            'button',
            { type: 'button', 'data-value': String(option.value), title: option.title, 'aria-label': option.label ? null : option.title, ...(option.attrs || {}) },
            option.icon ? icon(option.icon, 's16') : null,
            option.label || null,
        );
        button.addEventListener('click', () => {
            if (option.value === current) return;
            paint(option.value);
            if (onChange) onChange(option.value);
        });
        el.append(button);
        return { option, button };
    });
    function paint(next) {
        current = next;
        for (const { option, button } of buttons) {
            const on = option.value === next;
            button.classList.toggle('on', on);
            button.setAttribute('aria-pressed', String(on));
        }
    }
    paint(current);
    el.setValue = paint;
    return el;
}

/**
 * Native select in the kit field (`.inp.sm.sel`).
 *
 * @param o.options   `[{ value, label, disabled? }]` (values are compared as strings)
 * @param o.value / o.onChange `(value: string) => void` / o.label (aria) / o.attrs (on the `<select>`)
 * @returns HTMLElement with `setValue(value)`, `setOptions(options, value)` and `select`
 */
export function selectField({ options, value, onChange, label, attrs }) {
    const select = h('select', { 'aria-label': label, ...(attrs || {}) });
    const fill = (list, selected) => {
        select.replaceChildren(...list.map((o) => h('option', { value: String(o.value), disabled: o.disabled === true }, o.label)));
        select.value = String(selected);
    };
    fill(options, value);
    select.addEventListener('change', () => {
        if (onChange) onChange(select.value);
    });
    const el = h('span', { class: 'inp sm sel cal-select' }, select, icon('chev-down', 's14'));
    el.select = select;
    el.setValue = (next) => {
        select.value = String(next);
    };
    el.setOptions = fill;
    return el;
}
