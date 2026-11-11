// Shared kit of the five content tools of phase P6b (waste, events, names, moon, shifts):
// the month grid („Konkrétní data“), the month header + strip, and the small form fields the
// dialogs are built from (map §8.11 "Date picker"). CSS: calendar-tools.css.
//
// Rules every helper follows:
//   - a field is refreshed in place (`setValue`), never rebuilt, and a field that has focus is left
//     alone — the store answers every keystroke with a `content` event;
//   - dates are limited to `dateLimits(year)` (1 December of the previous year … 31 January of the
//     following year, handoff §4.6); a value outside the range, or one the caller refuses, is
//     dropped and the field shows the previous date again;
//   - months are linear indexes `ym = year * 12 + (month − 1)` inside this file's API, so the
//     14 reachable months need no special cases.

import { MONTHS, WEEKDAYS_SHORT, holidayName } from '../../generate/czech.js';
import { daysInMonth, iso, isValid, parts, weekday } from '../../generate/dates.js';
import { h, icon } from '../dom.js';

export const capital = (text) => text.charAt(0).toUpperCase() + text.slice(1);

/** `2027-01-02` → `2. 1. 2027`. */
export function czDate(isoDate) {
    const p = parts(isoDate);
    return `${p.d}. ${p.m}. ${p.y}`;
}

export const ymOf = (isoDate) => {
    const p = parts(isoDate);
    return p.y * 12 + p.m - 1;
};
export const ymYear = (ym) => Math.floor(ym / 12);
export const ymMonth = (ym) => (ym % 12) + 1;
export const ymPrefix = (ym) => iso(ymYear(ym), ymMonth(ym), 1).slice(0, 7);
export const ymTitle = (ym) => `${capital(MONTHS[ymMonth(ym) - 1])} ${ymYear(ym)}`;
export const clampYm = (ym, limits) => Math.min(ymOf(limits.max), Math.max(ymOf(limits.min), ym));

/** `1 termín` · `2 termíny` · `5 termínů`. */
export function plural(n, one, few, many) {
    return n === 1 ? one : n >= 2 && n <= 4 ? few : many;
}

/** Readable ink on a document colour (`#RRGGBB`): near-black on light fills, white on dark ones. */
export function inkOn(color) {
    const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color || '');
    if (!m) return '#FFFFFF';
    const [r, g, b] = [m[1], m[2], m[3]].map((x) => parseInt(x, 16));
    return 0.299 * r + 0.587 * g + 0.114 * b > 150 ? '#17151B' : '#FFFFFF';
}

/** Hands a document colour to the CSS as `--c` (fill) and `--ci` (ink on it); `null` clears both. */
export function setTint(el, color) {
    if (color) {
        el.style.setProperty('--c', color);
        el.style.setProperty('--ci', inkOn(color));
    } else {
        el.style.removeProperty('--c');
        el.style.removeProperty('--ci');
    }
}

// ---- view state that survives a re-open (`ui.toolState[<tool>]`) ---------------------------

export function readToolState(ctx, key) {
    const all = ctx.store.getState().ui.toolState || {};
    return all[key] || {};
}

export function writeToolState(ctx, key, patch) {
    const all = ctx.store.getState().ui.toolState || {};
    ctx.store.setUi({ toolState: { ...all, [key]: { ...(all[key] || {}), ...patch } } });
}

// ---- fields --------------------------------------------------------------------------------

const leaveOnEnter = (input) =>
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
        }
    });

/**
 * Text field in the kit frame. `onInput(value)` runs on every keystroke.
 * @returns `span.inp.sm` with `input` and `setValue(value)` (ignored while the field has focus)
 */
export function textField({ value, placeholder, label, maxLength, onInput, cls, attrs }) {
    const input = h('input', { type: 'text', value: value || '', placeholder, 'aria-label': label || placeholder, maxlength: maxLength ? String(maxLength) : null, autocomplete: 'off', ...(attrs || {}) });
    input.addEventListener('input', () => onInput(input.value));
    leaveOnEnter(input);
    const el = h('span', { class: `inp sm${cls ? ` ${cls}` : ''}` }, input);
    el.input = input;
    el.setValue = (next) => {
        if (document.activeElement !== input && input.value !== (next || '')) input.value = next || '';
    };
    return el;
}

/**
 * Native date field limited to `min … max`.
 *
 * `onChange(iso)` may answer `false` to refuse the date (the field then shows the previous one).
 * While the user types, half-typed dates (year `0002`…) are ignored; whatever is not accepted by
 * the time the field is left (blur / Enter) is put back. A date picked with the mouse is judged
 * at once.
 *
 * @returns `span.inp.sm.cal-date` with `input` and `setValue(iso)`
 */
export function dateField({ value, min, max, label = 'Datum', onChange, onOutOfRange, attrs }) {
    let committed = value;
    let typing = false;
    const input = h('input', { type: 'date', value, min, max, 'aria-label': label, required: true, ...(attrs || {}) });
    const settle = (final) => {
        const next = input.value;
        if (next === committed) return;
        const ok = isValid(next) && next >= min && next <= max;
        if (ok && onChange(next) !== false) {
            committed = next;
            return;
        }
        if (!final) return;
        if (isValid(next) && !ok && onOutOfRange) onOutOfRange(next);
        input.value = committed;
    };
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            settle(true);
            typing = false;
            input.blur();
        } else if (event.key === 'Escape') {
            input.value = committed;
        } else if (event.key.length === 1 || event.key === 'ArrowUp' || event.key === 'ArrowDown' || event.key === 'Backspace') {
            typing = true;
        }
    });
    input.addEventListener('change', () => settle(!typing));
    input.addEventListener('blur', () => {
        typing = false;
        settle(true);
    });
    const el = h('span', { class: 'inp sm cal-date' }, input);
    el.input = input;
    el.setValue = (next) => {
        committed = next;
        if (document.activeElement !== input && input.value !== next) input.value = next;
    };
    return el;
}

/** Trash button of a row: `aria-label` = `Odebrat {what}`, tooltip `Odebrat`. */
export function removeButton(what, onClick, attrs) {
    const button = h('button', { type: 'button', class: 'btn icon xs ghost cal-rm', title: 'Odebrat', 'aria-label': `Odebrat ${what}`, onClick, ...(attrs || {}) }, icon('trash', 's16'));
    button.setWhat = (next) => button.setAttribute('aria-label', `Odebrat ${next}`);
    return button;
}

/** `+ Přidat …` ghost button under a list. */
export function addButton(label, onClick, attrs) {
    return h('button', { type: 'button', class: 'btn sm ghost cal-add', onClick, ...(attrs || {}) }, icon('plus', 's16'), label);
}

/**
 * Keeps one element per item in `container`, in the given order, without rebuilding rows.
 *
 * `create(id, item)` → element with an `update(item)` method (called right after `create` too). While a field inside the list has
 * focus the existing rows are NOT re-ordered (moving a focused input drops the caret); the order
 * is put right on the next `sync` after the focus left — callers re-sync on `focusout`.
 *
 * @returns `{ sync(entries: [id, item][]), get(id), size() }`
 */
export function keyedList(container, create) {
    const rows = new Map();
    return {
        sync(entries) {
            const keep = new Set(entries.map(([id]) => id));
            for (const [id, el] of rows) {
                if (!keep.has(id)) {
                    el.remove();
                    rows.delete(id);
                }
            }
            const busy = container.contains(document.activeElement);
            let cursor = container.firstElementChild;
            for (const [id, item] of entries) {
                let el = rows.get(id);
                if (!el) {
                    el = create(id, item);
                    rows.set(id, el);
                }
                el.update(item);
                if (busy && el.parentNode === container) continue;
                if (el === cursor) cursor = cursor.nextElementSibling;
                else container.insertBefore(el, busy ? null : cursor);
            }
        },
        get: (id) => rows.get(id),
        size: () => rows.size,
    };
}

// ---- month header + strip --------------------------------------------------------------------

/**
 * `‹  Leden 2027  ›` and the strip of the twelve months of the calendar year with count badges.
 *
 * @param o.year      calendar year (the strip shows its twelve months)
 * @param o.limits    `dateLimits(year)` — the arrows also reach December before / January after
 * @param o.ym        month shown first
 * @param o.counts    `(ym) => number` badge of a month (0 = none)
 * @param o.noun      `[one, few, many]` words of the strip's aria-label, e.g. svoz / svozy / svozů
 * @param o.color     badge colour (CSS colour; default lavender)
 * @param o.onChange  `(ym) => void`
 * @returns `{ el, ym(), set(ym), refresh() }`
 */
export function monthNav({ year, limits, ym, counts, noun, color, onChange }) {
    const lo = ymOf(limits.min);
    const hi = ymOf(limits.max);
    let current = Math.min(hi, Math.max(lo, ym));
    const title = h('b', { 'aria-live': 'polite' });
    const prev = h('button', { type: 'button', class: 'btn icon xs ghost', 'aria-label': 'Předchozí měsíc', 'data-month-step': '-1', onClick: () => go(current - 1) }, icon('chev-left', 's16'));
    const next = h('button', { type: 'button', class: 'btn icon xs ghost', 'aria-label': 'Další měsíc', 'data-month-step': '1', onClick: () => go(current + 1) }, icon('chev-right', 's16'));
    const strip = h('div', { class: 'cal-dpm', role: 'group', 'aria-label': 'Měsíce' });
    const buttons = MONTHS.map((name, index) => {
        const badge = h('i', { hidden: true });
        const button = h('button', { type: 'button', 'data-month': String(index + 1), onClick: () => go(year * 12 + index) }, capital(name.slice(0, 3)), badge);
        strip.append(button);
        return { button, badge, name, target: year * 12 + index };
    });
    const el = h('div', { class: 'cal-mnav' }, h('div', { class: 'cal-dph' }, prev, title, next), strip);

    function paint() {
        title.textContent = ymTitle(current);
        prev.disabled = current <= lo;
        next.disabled = current >= hi;
        setTint(strip, color || null);
        for (const { button, badge, name, target } of buttons) {
            const n = counts ? counts(target) : 0;
            const on = target === current;
            button.classList.toggle('on', on);
            button.setAttribute('aria-pressed', String(on));
            button.setAttribute('aria-label', `${name} ${year}${n && noun ? `, ${n} ${plural(n, ...noun)}` : ''}`);
            badge.hidden = n === 0;
            badge.textContent = String(n);
            button.dataset.count = String(n);
        }
    }
    function go(target) {
        const to = Math.min(hi, Math.max(lo, target));
        if (to === current) return;
        current = to;
        paint();
        if (onChange) onChange(current);
    }
    paint();
    return {
        el,
        ym: () => current,
        set(target) {
            current = Math.min(hi, Math.max(lo, target));
            paint();
        },
        setColor(next) {
            color = next;
            paint();
        },
        refresh: paint,
    };
}

// ---- month grid ------------------------------------------------------------------------------

/**
 * Month grid whose days toggle (map §8.11 "Date picker").
 *
 * @param root     element the picker fills
 * @param options  `{ year, month?, ym?, min, max, isMarked(iso): boolean, onPick(iso), counts?(year, month): number,
 *                    color?, noun?, onMonth?(ym) }` — `month` is 1-based within `year`; `counts` feeds the
 *                 month strip badges
 * @returns `{ setMonth(year, month), refresh(), setColor(css), ym(), destroy() }`
 */
export function mountDatePicker(root, options) {
    const { year, min, max, isMarked, onPick } = options;
    const limits = { min, max };
    const grid = h('div', { class: 'cal-dpg' });
    const nav = monthNav({
        year,
        limits,
        ym: options.ym !== undefined ? options.ym : year * 12 + ((options.month || 1) - 1),
        counts: options.counts ? (ym) => options.counts(ymYear(ym), ymMonth(ym)) : null,
        noun: options.noun || ['svoz', 'svozy', 'svozů'],
        color: options.color,
        onChange: (ym) => {
            build();
            if (options.onMonth) options.onMonth(ym);
        },
    });
    // header above the grid, month strip under it (prototype order)
    const el = h('div', { class: 'cal-dpk' }, nav.el.firstElementChild, grid, nav.el.lastElementChild);
    let color = options.color || null;
    let cells = [];

    function build() {
        const y = ymYear(nav.ym());
        const m = ymMonth(nav.ym());
        const first = iso(y, m, 1);
        const nodes = WEEKDAYS_SHORT.map((t) => h('span', { class: 'cal-dpw', 'aria-hidden': 'true' }, t));
        for (let k = 0; k < weekday(first); k++) nodes.push(h('span', { 'aria-hidden': 'true' }));
        cells = [];
        for (let d = 1, last = daysInMonth(y, m); d <= last; d++) {
            const date = iso(y, m, d);
            const holiday = holidayName(date);
            const button = h(
                'button',
                {
                    type: 'button',
                    class: `cal-dp${weekday(date) > 4 || holiday ? ' we' : ''}`,
                    'data-date': date,
                    'aria-label': `${d}. ${m}. ${y}${holiday ? ` — ${holiday}` : ''}`,
                    title: holiday || null,
                    disabled: date < min || date > max,
                    onClick: () => onPick(date),
                },
                String(d),
            );
            cells.push({ date, button });
            nodes.push(button);
        }
        grid.replaceChildren(...nodes);
        mark();
    }
    function mark() {
        setTint(el, color);
        for (const { date, button } of cells) {
            const on = isMarked(date) === true;
            button.classList.toggle('on', on);
            button.setAttribute('aria-pressed', String(on));
        }
    }
    build();
    root.replaceChildren(el);

    return {
        setMonth(y, m) {
            nav.set(y * 12 + m - 1);
            build();
        },
        refresh() {
            nav.refresh();
            mark();
        },
        setColor(next) {
            color = next;
            nav.setColor(next);
            mark();
        },
        ym: () => nav.ym(),
        destroy() {
            root.replaceChildren();
        },
    };
}
