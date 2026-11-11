// Colour picker of the calendar panels (handoff §6.1 "Colour picker", map §8.1). Owner: P5a; reused by P5b.
//
//   colorPicker({ value, onChange, palette, allowNone?, label?, attrs? }) → HTMLElement `.cal-swx`
//
//   value      '#RRGGBB' (upper case) or `null` (= Bez barvy; only meaningful with `allowNone`)
//   onChange   `(value: string | null) => void` — a discrete change (one undo step)
//   palette    string[] of '#RRGGBB' — use `colorPalette(env)`: neutral defaults + the project's brand colours
//   allowNone  adds the „Bez barvy“ swatch; clearing the HEX field then means no colour
//   label      aria-label of the swatch group (default `Barva`)
//   attrs      attributes of the root (`{ 'data-prop': 'month.color' }`)
//
// The element carries `setValue(value)` (no callback). DOM hooks: swatches `button[data-color="#RRGGBB"]`
// (`data-color=""` = Bez barvy, `data-custom` on the extra swatch of an off-palette colour, `aria-checked`),
// the HEX field `input[data-hex]`.
//
// HEX field: 3 or 6 digits, with or without `#`; Enter confirms and leaves; Esc restores; an invalid
// value is rejected with the toast `Zadejte barvu jako HEX, např. C84B55` and the old colour stays.

import { COLORS } from '../../model/defaults.js';
import { h, toast } from '../dom.js';

/** Neutral defaults offered before the brand colours (prototype `calPal`). */
export const NEUTRAL_COLORS = Object.freeze([COLORS.ink, COLORS.mut, '#FFFFFF', COLORS.paper, COLORS.band, COLORS.sat, COLORS.red]);

/** Neutral defaults + the project's brand colours (`env.palette`), without duplicates. */
export function colorPalette(env) {
    const out = [];
    for (const color of [...NEUTRAL_COLORS, ...((env && env.palette) || [])]) {
        const hex = normalizeHex(color);
        if (hex && !out.includes(hex)) out.push(hex);
    }
    return out;
}

/** `'c84b55'` / `'#C84B55'` / `'fa0'` → `'#C84B55'` / `'#FFAA00'`; `null` when the text is not a HEX colour. */
export function normalizeHex(text) {
    const raw = String(text === null || text === undefined ? '' : text)
        .trim()
        .replace(/^#/, '');
    if (/^[0-9a-f]{6}$/i.test(raw)) return `#${raw.toUpperCase()}`;
    if (/^[0-9a-f]{3}$/i.test(raw)) return `#${raw.replace(/./g, (ch) => ch + ch).toUpperCase()}`;
    return null;
}

export function colorPicker({ value, onChange, palette, allowNone = false, label = 'Barva', attrs }) {
    const colors = [];
    for (const color of palette || []) {
        const hex = normalizeHex(color);
        if (hex && !colors.includes(hex)) colors.push(hex);
    }
    let current = value ? normalizeHex(value) : null;

    const swatches = h('div', { class: 'cal-sws', role: 'radiogroup', 'aria-label': label });
    const input = h('input', { type: 'text', maxlength: '7', spellcheck: 'false', autocomplete: 'off', 'aria-label': 'Barva HEX', 'data-hex': '' });
    const el = h('div', { class: 'cal-swx', ...(attrs || {}) }, swatches, h('span', { class: 'inp sm cal-hex' }, h('span', { class: 'u', 'aria-hidden': 'true' }, '#'), input));

    const pick = (next) => {
        if (next === current) return;
        current = next;
        paint();
        if (onChange) onChange(next);
    };

    function mark(node, on) {
        node.classList.toggle('on', on);
        node.setAttribute('aria-checked', String(on));
    }

    function swatch(color, custom) {
        const on = color === current;
        const none = color === null;
        const name = none ? 'Bez barvy' : custom ? `Vlastní ${color}` : color;
        return h('button', {
            type: 'button',
            role: 'radio',
            class: `cal-swc${none ? ' none' : ''}${on ? ' on' : ''}`,
            style: none ? null : `--c:${color}`,
            'aria-checked': String(on),
            'aria-label': name,
            title: none ? 'Bez barvy' : custom ? `Vlastní barva ${color}` : color,
            'data-color': none ? '' : color,
            'data-custom': custom ? '' : null,
            onClick: () => pick(color),
        });
    }

    function showHex() {
        input.value = current ? current.slice(1) : '';
        input.placeholder = current ? '' : 'bez barvy';
    }

    // the fixed swatches are built once (a click keeps its focus); only the extra swatch comes and goes
    const fixed = [...(allowNone ? [null] : []), ...colors].map((color) => ({ color, node: swatch(color, false) }));
    swatches.append(...fixed.map((entry) => entry.node));
    let extra = null;

    function paint() {
        for (const entry of fixed) mark(entry.node, entry.color === current);
        const custom = Boolean(current) && !colors.includes(current);
        if (extra && (!custom || extra.dataset.color !== current)) {
            extra.remove();
            extra = null;
        }
        if (custom && !extra) {
            extra = swatch(current, true);
            swatches.append(extra);
        }
        if (document.activeElement !== input) showHex();
    }

    input.addEventListener('change', () => {
        const text = input.value.trim();
        if (text === '' && allowNone) {
            pick(null);
            showHex();
            return;
        }
        const hex = normalizeHex(text);
        if (!hex) {
            toast('Zadejte barvu jako HEX, např. C84B55', true);
            showHex();
            return;
        }
        pick(hex);
        showHex();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            showHex();
            input.blur();
        }
    });
    input.addEventListener('focus', () => input.select());

    paint();
    showHex();
    el.setValue = (next) => {
        const hex = next ? normalizeHex(next) : null;
        if (hex === current) return;
        current = hex;
        paint();
    };
    el.input = input;
    return el;
}
