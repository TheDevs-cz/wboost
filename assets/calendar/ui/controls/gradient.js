// Gradient editor (handoff §6.1 "Gradient editor", map §8.8). Owner: P5a; reused wherever a `Fill` is edited.
//
//   gradientEditor({ value, onChange, onStart?, onCommit?, palette, path? }) → HTMLElement `.cal-gr`
//
//   value      `Fill['gradient']` = { on, type: 'linear' | 'radial', angle, stops: [{ color, opacity, pos }] }
//              (0° = left → right, 90° = top → bottom; opacity and pos 0–100)
//   onChange   `(gradient) => void` — always the complete next gradient (`on` and unknown keys kept)
//   onStart / onCommit   bracket the angle slider drag (one undo step, see `bind.js`)
//   palette    colours offered for a stop (`colorPalette(env)`)
//   path       style path of the gradient (`'bg.gradient'`) → `data-prop` hooks:
//              `<path>.stops.<i>.color` (swatch button), `.opacity`, `.pos`, `<path>.type`, `<path>.angle`
//
// Parts: preview + swap (`[data-grad-swap]`, reverses the stops), one row per stop (colour · Krytí ·
// Pozice · remove `[data-grad-remove="<i>"]` — shown only above two stops, so there are never fewer
// than two), `Přidat barvu` (`[data-grad-add]`: a new stop in the middle of the widest gap, colour
// mixed from its neighbours), Typ (Lineární | Kruhový) and, for linear, the angle slider.
// A click on a stop's colour opens the shared colour picker under that row (`[data-grad-picker]`).
//
// The element carries `setValue(gradient)`; rows are rebuilt only when the number of stops changes.

import { h, icon, selectField } from '../dom.js';
import { colorPicker, normalizeHex } from './color.js';
import { miniNumber, sliderRow } from './number.js';

function rgba(color, opacity) {
    const hex = normalizeHex(color) || '#FFFFFF';
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${Math.max(0, Math.min(100, opacity)) / 100})`;
}

/** CSS `background` showing the gradient like the renderer draws it. */
export function gradientCss(gradient) {
    const stops = gradient.stops
        .slice()
        .sort((a, b) => a.pos - b.pos)
        .map((s) => `${rgba(s.color, s.opacity)} ${s.pos}%`)
        .join(', ');
    if (gradient.type === 'radial') return `radial-gradient(ellipse closest-side, ${stops})`;
    // CSS: 0deg = bottom → top, 90deg = left → right; ours: 0° = left → right, 90° = top → bottom
    return `linear-gradient(${(Number(gradient.angle) || 0) + 90}deg, ${stops})`;
}

function mix(a, b) {
    const x = parseInt((normalizeHex(a) || '#FFFFFF').slice(1), 16);
    const y = parseInt((normalizeHex(b) || '#FFFFFF').slice(1), 16);
    const channel = (shift) => Math.round((((x >> shift) & 255) + ((y >> shift) & 255)) / 2);
    return `#${[16, 8, 0].map((shift) => channel(shift).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
}

/** The stops with one more stop in the middle of the widest gap (handoff §6.1). */
export function addStop(stops) {
    const sorted = stops.slice().sort((a, b) => a.pos - b.pos);
    let at = 0;
    let gap = -1;
    for (let i = 0; i < sorted.length - 1; i++) {
        const d = sorted[i + 1].pos - sorted[i].pos;
        if (d > gap) {
            gap = d;
            at = i;
        }
    }
    const left = sorted[at];
    const right = sorted[at + 1] || left;
    const stop = { color: mix(left.color, right.color), opacity: Math.round((left.opacity + right.opacity) / 10) * 5, pos: Math.round((left.pos + right.pos) / 2) };
    const index = stops.indexOf(left);
    return [...stops.slice(0, index + 1), stop, ...stops.slice(index + 1)];
}

/** The stops reversed: every position mirrored, order turned round (prototype `data-clgsw`). */
export function swapStops(stops) {
    return stops.map((s) => ({ ...s, pos: 100 - s.pos })).reverse();
}

export function gradientEditor({ value, onChange, onStart, onCommit, palette, path }) {
    let current = value;
    let openStop = null;
    let rows = [];

    const prop = (suffix) => (path ? { 'data-prop': `${path}.${suffix}` } : {});
    const emit = (next) => {
        current = next;
        paint();
        if (onChange) onChange(next);
    };
    const setStop = (index, patch) => emit({ ...current, stops: current.stops.map((s, i) => (i === index ? { ...s, ...patch } : s)) });

    const previewFill = h('span');
    const list = h('div', { class: 'cal-grl' });
    const type = selectField({
        label: 'Typ přechodu',
        value: current.type,
        attrs: prop('type'),
        options: [
            { value: 'linear', label: 'Lineární' },
            { value: 'radial', label: 'Kruhový' },
        ],
        onChange: (next) => emit({ ...current, type: next === 'radial' ? 'radial' : 'linear' }),
    });
    const angle = sliderRow({
        label: 'Úhel',
        ariaLabel: 'Úhel přechodu',
        min: 0,
        max: 360,
        step: 5,
        unit: '°',
        value: Number(current.angle) || 0,
        attrs: prop('angle'),
        onStart,
        onCommit,
        onChange: (next) => {
            current = { ...current, angle: next };
            previewFill.style.background = gradientCss(current);
            if (onChange) onChange(current);
        },
    });

    function buildRows() {
        openStop = null;
        const removable = current.stops.length > 2;
        rows = current.stops.map((stop, index) => {
            const swatch = h('button', {
                type: 'button',
                class: 'cal-grc',
                'aria-label': `Barva ${index + 1}`,
                'aria-expanded': 'false',
                ...prop(`stops.${index}.color`),
                onClick: () => {
                    openStop = openStop === index ? null : index;
                    paint();
                },
            });
            const opacity = miniNumber({ limit: 'percent', unit: '%', value: stop.opacity, label: `Krytí ${index + 1}`, attrs: prop(`stops.${index}.opacity`), onChange: (v) => setStop(index, { opacity: v }) });
            const pos = miniNumber({ min: 0, max: 100, unit: '%', value: stop.pos, label: `Pozice ${index + 1}`, attrs: prop(`stops.${index}.pos`), onChange: (v) => setStop(index, { pos: v }) });
            const remove = removable
                ? h(
                      'button',
                      {
                          type: 'button',
                          class: 'btn icon xs btn-ghost',
                          'aria-label': `Odebrat barvu ${index + 1}`,
                          'data-grad-remove': String(index),
                          onClick: () => {
                              if (current.stops.length <= 2) return;
                              emit({ ...current, stops: current.stops.filter((s, i) => i !== index) });
                          },
                      },
                      icon('x', 's16'),
                  )
                : h('span');
            const picker = colorPicker({ value: stop.color, palette, label: `Barva ${index + 1}`, onChange: (color) => color && setStop(index, { color }) });
            const pickerRow = h('div', { class: 'cal-grpk', 'data-grad-picker': String(index), hidden: true }, picker);
            return { swatch, opacity, pos, picker, pickerRow, nodes: [swatch, opacity, pos, remove, pickerRow] };
        });
        list.replaceChildren(h('span', { class: 'lbl' }, 'Barva'), h('span', { class: 'lbl' }, 'Krytí'), h('span', { class: 'lbl' }, 'Pozice'), h('span'), ...rows.flatMap((row) => row.nodes));
    }

    function paint() {
        if (rows.length !== current.stops.length) buildRows();
        previewFill.style.background = gradientCss(current);
        current.stops.forEach((stop, index) => {
            const row = rows[index];
            row.swatch.style.background = stop.color;
            row.swatch.title = stop.color;
            row.swatch.setAttribute('aria-expanded', String(openStop === index));
            row.opacity.setValue(stop.opacity);
            row.pos.setValue(stop.pos);
            row.picker.setValue(stop.color);
            row.pickerRow.hidden = openStop !== index;
        });
        type.setValue(current.type);
        angle.setValue(Number(current.angle) || 0);
        angle.hidden = current.type === 'radial';
    }

    const el = h(
        'div',
        { class: 'cal-gr' },
        h(
            'div',
            { class: 'cal-grh' },
            h('span', { class: 'cal-grp' }, previewFill),
            h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Prohodit barvy', title: 'Prohodit barvy', 'data-grad-swap': '', onClick: () => emit({ ...current, stops: swapStops(current.stops) }) }, icon('swap-horizontal', 's16')),
        ),
        list,
        h('button', { type: 'button', class: 'btn xs btn-ghost cal-add', 'data-grad-add': '', onClick: () => emit({ ...current, stops: addStop(current.stops) }) }, icon('plus', 's16'), 'Přidat barvu'),
        h('div', { class: 'cal-grt' }, h('span', { class: 'lbl' }, 'Typ'), type),
        angle,
    );

    paint();
    el.setValue = (next) => {
        if (next === current) return;
        current = next;
        paint();
    };
    return el;
}
