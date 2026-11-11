// Number fields and sliders of the property panels (handoff §6.1 "Number fields", limits table).
// Owner: P5a; reused by P5b. The plain stepper itself is `numberField` of `ui/dom.js`; this file adds
// the `LIMITS` table of `model/defaults.js` and the slider.
//
//   limitField({ limit, value, unit, label, onChange, step?, attrs? })      − [ 12,5 ] mm +
//   sliderRow({ label, value, limit | min/max/step, unit, onStart?, onChange, onCommit?, … })
//   miniNumber({ value, limit, unit, label, onChange, attrs? })              compact [ 35 % ]
//   showNumber(field, value)                                                 force a stepper to show a value
//
// All of them: decimal comma, a value outside the range is corrected to the nearest limit and the
// field shows the corrected number, Enter confirms and leaves the field, Esc restores.
// Every element carries `setValue(value)` (no callbacks fired).

import { LIMITS, clampLimit } from '../../model/defaults.js';
import { formatNumber, h, numberField, parseNumber } from '../dom.js';

function decimalsOf(step) {
    const text = String(step);
    const dot = text.indexOf('.');
    return dot < 0 ? 0 : text.length - dot - 1;
}

/**
 * Makes a `numberField` show `value` even while it has focus (`setValue` alone leaves a focused
 * field untouched). Call it after a change whose result the store corrected (X / Y / Š / V).
 */
export function showNumber(field, value, decimals = 1) {
    field.setValue(value);
    field.input.value = formatNumber(value, decimals);
}

/**
 * Stepper bound to one row of `LIMITS` (`fontSize`, `pad`, `percent`, `generic`, `photoBorder`,
 * `shadowOffset`, `lineWidth`, `iconSize`, `iconCellSize`, `moonSize`, …).
 *
 * @param o.limit     key of `LIMITS` — gives min, max and the grid typed values are rounded to
 * @param o.step      step of the − / + buttons when it differs from the grid (Fáze měsíce: 0.2)
 * @param o.value / o.unit / o.label / o.onChange / o.onStart / o.onCommit / o.attrs  as `numberField`
 * @returns HTMLElement `.cal-num` with `setValue(value)` and `input`
 */
export function limitField({ limit, step, value, unit, label, onChange, onStart, onCommit, attrs }) {
    const spec = LIMITS[limit];
    if (!spec) throw new Error(`Unknown limit "${limit}"`);
    const places = Math.max(1, decimalsOf(spec.step));
    let shown = clampLimit(limit, value);
    const field = numberField({
        value: shown,
        min: spec.min,
        max: spec.max,
        step: step || spec.step,
        decimals: places,
        unit,
        label,
        attrs,
        onChange(next) {
            // typed values land on the grid of the limit (the normaliser would move them there on reload)
            const snapped = clampLimit(limit, next);
            if (snapped !== next) {
                setValue(snapped);
                field.input.value = formatNumber(snapped, places);
            }
            if (snapped === shown) return;
            shown = snapped;
            if (onStart) onStart();
            if (onChange) onChange(snapped);
            if (onCommit) onCommit();
        },
    });
    const setValue = field.setValue;
    field.setValue = (next) => {
        shown = Number(next);
        setValue(next);
    };
    return field;
}

/**
 * Slider row `Krytí ──●── 35 %` (prototype `.cal-szi`). The page redraws while it is dragged and
 * the whole drag is ONE undo step: `onStart` → `onChange` … → `onCommit` (see `bind.js`).
 *
 * @param o.label      row label (also the aria-label unless `o.ariaLabel`)
 * @param o.limit      key of `LIMITS`, or give `o.min` / `o.max` / `o.step`
 * @param o.unit       `%`, `pt`, `mm`, `°` (the degree sign is written without a space)
 * @param o.value / o.onStart / o.onChange / o.onCommit
 * @param o.attrs      attributes of the `<input type="range">` (`data-prop`)
 * @returns HTMLElement `label.cal-szi` with `setValue(value)` and `input`
 */
export function sliderRow({ label, ariaLabel, limit, min, max, step, unit, value, onStart, onChange, onCommit, attrs }) {
    const spec = limit ? LIMITS[limit] : { min, max, step };
    const places = decimalsOf(spec.step);
    let active = false;
    const input = h('input', { type: 'range', min: spec.min, max: spec.max, step: spec.step, 'aria-label': ariaLabel || label, ...(attrs || {}) });
    const out = h('span', { class: 'cal-szv' });
    const paint = (v) => {
        out.textContent = `${formatNumber(v, places)}${unit === '°' ? '°' : unit ? ` ${unit}` : ''}`;
    };
    const read = () => Number(Number(input.value).toFixed(places));
    input.addEventListener('input', () => {
        if (!active) {
            active = true;
            if (onStart) onStart();
        }
        paint(read());
        if (onChange) onChange(read());
    });
    const finish = () => {
        if (!active) return;
        active = false;
        if (onCommit) onCommit();
    };
    input.addEventListener('change', finish);
    input.addEventListener('blur', finish);
    const el = h('label', { class: 'cal-szi' }, h('span', { class: 'cal-szt' }, label), input, out);
    el.input = input;
    el.setValue = (next) => {
        if (active) return;
        input.value = String(next);
        paint(Number(next));
    };
    input.value = String(value);
    paint(Number(value));
    return el;
}

/**
 * Compact number box without stepper buttons (gradient stops).
 *
 * @param o.limit     key of `LIMITS`, or `o.min` / `o.max` (+ `o.decimals`, default 0)
 * @param o.value / o.unit / o.label (aria) / o.onChange / o.attrs (on the `<input>`)
 * @returns HTMLElement `.inp.sm.cal-grn` with `setValue(value)` and `input`
 */
export function miniNumber({ limit, min = 0, max = 100, decimals = 0, value, unit, label, onChange, attrs }) {
    const fix = (v) => (limit ? clampLimit(limit, v) : Number(Math.min(max, Math.max(min, v)).toFixed(decimals)));
    const places = limit ? decimalsOf(LIMITS[limit].step) : decimals;
    let current = fix(Number(value));
    const input = h('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', spellcheck: 'false', 'aria-label': label, ...(attrs || {}) });
    const show = () => {
        input.value = formatNumber(current, places);
    };
    input.addEventListener('change', () => {
        const typed = parseNumber(input.value);
        if (Number.isNaN(typed)) {
            show();
            return;
        }
        const next = fix(typed);
        const changed = next !== current;
        current = next;
        show();
        if (changed && onChange) onChange(next);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            show();
            input.blur();
        }
    });
    input.addEventListener('focus', () => input.select());
    const el = h('span', { class: 'inp sm cal-grn' }, input, unit ? h('span', { class: 'u', 'aria-hidden': 'true' }, unit) : null);
    el.input = input;
    el.setValue = (next) => {
        current = fix(Number(next));
        if (document.activeElement !== input) show();
    };
    show();
    return el;
}
