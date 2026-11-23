// „Efekty“ — the layer effects of a picture (model/effects.js; docs/calendar/effects-plan.md).
// Reused by the editor panels (photo, logo, header and background pictures) and by the photo field of
// the cover / intro / contacts pages (`ui/pagecontent.js`).
//
//   effectsEditor({ value, onChange, onStart?, onCommit?, palette, path? }) → HTMLElement `.cal-fx`
//
//   value      the effect list (normalised; see model/effects.js)
//   onChange   `(list) => void` — always the complete next list
//   onStart / onCommit   bracket a slider drag (one undo step, `bind.js`)
//   palette    colours offered (`colorPalette(env)`)
//   path       style path of the list (`'photo.effects'`) → `data-prop` hooks `<path>.<i>.<key>`
//              (`on` on the eye button, `blend` on the select, the sliders' inputs, the colour
//              picker's root, the gradient editor's own hooks under `<path>.<i>`)
//
// One row per effect, in Photoshop's panel order (overlays above shadows, list order inside a kind):
// eye (on / off) · name (opens the settings below the row; one open at a time) · up / down inside its
// kind · Duplikovat · Odebrat; then one add button per kind (Vržený stín, Vnější záře, Překrytí
// přechodem, Vnitřní záře, Vnitřní stín). A new or duplicated effect goes on top of its kind and opens.
//
// DOM hooks: `[data-fx-row="<i>"][data-fx-kind]`, `[data-fx-open="<i>"]`, `[data-fx-up]`, `[data-fx-down]`,
// `[data-fx-copy]`, `[data-fx-remove]`, `[data-fx-add="dropShadow|outerGlow|gradient|innerGlow|innerShadow"]`.
//
// The element carries `setValue(list)`; rows are rebuilt only when the structure changes (count, kinds,
// order, the open row) — a value change repaints the controls in place, so a slider keeps its drag.

import { BLEND_MODES, EFFECT_KINDS, MAX_EFFECTS, displayOrder, effectLabel, moveEffect, newEffect } from '../../model/effects.js';
import { h, icon } from '../dom.js';
import { colorPicker } from './color.js';
import { gradientEditor } from './gradient.js';
import { sliderRow } from './number.js';

const blendLabel = (value) => (BLEND_MODES.find((m) => m.value === value) || BLEND_MODES[0]).label;

/** `<select>` of the 16 blend modes, separated into Photoshop's groups. */
function blendSelect({ value, onChange, attrs }) {
    const select = h('select', { 'aria-label': 'Režim prolnutí', ...(attrs || {}) });
    BLEND_MODES.forEach((mode, i) => {
        if (i > 0 && mode.group !== BLEND_MODES[i - 1].group) select.append(h('hr'));
        select.append(h('option', { value: mode.value }, mode.label));
    });
    select.value = value;
    select.addEventListener('change', () => onChange(select.value));
    const el = h('span', { class: 'inp sm sel cal-select' }, select, icon('chev-down', 's14'));
    el.setValue = (next) => {
        select.value = next;
    };
    return el;
}

const field = (label, control) => h('div', { class: 'cal-fxf' }, h('span', { class: 'lbl' }, label), control);

export function effectsEditor({ value, onChange, onStart, onCommit, palette, path }) {
    let current = Array.isArray(value) ? value : [];
    let open = null; // list index of the open row
    let shape = '';
    let rows = [];

    const prop = (i, key) => (path ? { 'data-prop': `${path}.${i}.${key}` } : {});
    const emit = (next, rebuild = true) => {
        current = next;
        if (rebuild) paint();
        if (onChange) onChange(next);
    };
    const setEffect = (i, patch, rebuild = false) => emit(current.map((e, k) => (k === i ? { ...e, ...patch } : e)), rebuild);
    const slide = (i, key) => ({
        onStart,
        onCommit,
        onChange: (v) => setEffect(i, { [key]: v }),
    });

    const list = h('div', { class: 'cal-fxl' });
    const empty = h('p', { class: 'cap cal-note' }, 'Žádné efekty. Přidejte stín, záři nebo překrytí přechodem.');
    const addButtons = EFFECT_KINDS.slice()
        .reverse()
        .map(({ kind, label }) =>
            h(
                'button',
                {
                    type: 'button',
                    class: 'btn xs btn-ghost cal-add',
                    'data-fx-add': kind,
                    onClick: () => {
                        if (current.length >= MAX_EFFECTS) return;
                        open = 0;
                        emit([newEffect(kind), ...current]);
                    },
                },
                icon('plus', 's16'),
                label,
            ),
        );

    function settings(effect, i) {
        const controls = [];
        const blend = blendSelect({ value: effect.blend, attrs: prop(i, 'blend'), onChange: (next) => setEffect(i, { blend: next }, true) });
        const opacity = sliderRow({ label: 'Krytí', limit: 'percent', unit: '%', value: effect.opacity, attrs: prop(i, 'opacity'), ...slide(i, 'opacity') });
        controls.push({ el: field('Režim prolnutí', blend), set: (e) => blend.setValue(e.blend) }, { el: opacity, set: (e) => opacity.setValue(e.opacity) });
        if (effect.kind !== 'gradient') {
            const shadow = effect.kind === 'dropShadow' || effect.kind === 'innerShadow';
            const inside = effect.kind === 'innerShadow' || effect.kind === 'innerGlow';
            const color = colorPicker({ value: effect.color, palette, label: shadow ? 'Barva stínu' : 'Barva záře', attrs: prop(i, 'color'), onChange: (next) => next && setEffect(i, { color: next }) });
            const rows = [
                ...(shadow
                    ? [
                          ['angle', 'Úhel', 'fxAngle', '°'],
                          ['distance', 'Vzdálenost', 'fxDistance', 'mm'],
                      ]
                    : []),
                ['spread', inside ? 'Zúžení' : 'Rozšíření', 'percent', '%'],
                ['size', 'Velikost', 'fxSize', 'mm'],
            ];
            const sliders = rows.map(([key, label, limit, unit]) => ({ key, el: sliderRow({ label, limit, unit, value: effect[key], attrs: prop(i, key), ...slide(i, key) }) }));
            controls.push({ el: field('Barva', color), set: (e) => color.setValue(e.color) });
            for (const s of sliders) controls.push({ el: s.el, set: (e) => s.el.setValue(e[s.key]) });
        } else if (effect.kind === 'gradient') {
            const gradient = gradientEditor({
                value: effect,
                palette,
                path: path ? `${path}.${i}` : null,
                types: ['linear', 'radial', 'reflected'],
                onStart,
                onCommit,
                onChange: (next) => setEffect(i, { type: next.type, angle: next.angle, stops: next.stops }),
            });
            const sliders = [
                ['scale', 'Měřítko', 'fxScale', '%'],
                ['x', 'Střed X', 'fxCentre', '%'],
                ['y', 'Střed Y', 'fxCentre', '%'],
            ].map(([key, label, limit, unit]) => ({ key, el: sliderRow({ label, limit, unit, value: effect[key], attrs: prop(i, key), ...slide(i, key) }) }));
            controls.push({ el: field('Přechod', gradient), set: (e) => gradient.setValue(e) });
            for (const s of sliders) controls.push({ el: s.el, set: (e) => s.el.setValue(e[s.key]) });
        }
        return controls;
    }

    function build() {
        const order = displayOrder(current);
        rows = order.map((i) => {
            const effect = current[i];
            const same = current.map((e, k) => (e.kind === effect.kind ? k : -1)).filter((k) => k >= 0);
            const eye = h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-fxe', ...prop(i, 'on'), onClick: () => setEffect(i, { on: !current[i].on }, true) });
            const summary = h('span', { class: 'cap' });
            const name = h(
                'button',
                {
                    type: 'button',
                    class: 'cal-fxn',
                    'data-fx-open': String(i),
                    'aria-expanded': String(open === i),
                    onClick: () => {
                        open = open === i ? null : i;
                        paint();
                    },
                },
                h('span', { class: 'cal-fxt' }, h('b', null, effectLabel(effect.kind)), summary),
                icon(open === i ? 'chev-up' : 'chev-down', 's14'),
            );
            const tool = (label, iconName, attrs, onClick, shown = true) => (shown ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', title: label, 'aria-label': label, ...attrs, onClick }, icon(iconName, 's16')) : null);
            const move = (direction) => {
                const next = moveEffect(current, i, direction);
                if (next === current) return;
                const other = next.indexOf(current[i]);
                if (open === i) open = other;
                else if (open === other) open = i;
                emit(next);
            };
            const head = h(
                'div',
                { class: 'cal-fxh' },
                eye,
                name,
                tool('Posunout výš', 'arrow-up', { 'data-fx-up': '' }, () => move(-1), same.indexOf(i) > 0),
                tool('Posunout níž', 'arrow-down', { 'data-fx-down': '' }, () => move(1), same.indexOf(i) < same.length - 1),
                tool('Duplikovat', 'copy', { 'data-fx-copy': '' }, () => {
                    if (current.length >= MAX_EFFECTS) return;
                    open = i;
                    emit([...current.slice(0, i), { ...current[i] }, ...current.slice(i)]);
                }),
                tool('Odebrat', 'trash', { 'data-fx-remove': '' }, () => {
                    open = open === null || open === i ? null : open > i ? open - 1 : open;
                    emit(current.filter((e, k) => k !== i));
                }),
            );
            const controls = open === i ? settings(effect, i) : [];
            const body = open === i ? h('div', { class: 'cal-fxb' }, ...controls.map((c) => c.el)) : null;
            const row = h('div', { class: 'cal-fxr', 'data-fx-row': String(i), 'data-fx-kind': effect.kind }, head, body);
            return {
                el: row,
                set(e) {
                    row.classList.toggle('off', !e.on);
                    eye.replaceChildren(icon(e.on ? 'eye' : 'eye-off', 's16'));
                    eye.title = e.on ? 'Skrýt efekt' : 'Zobrazit efekt';
                    eye.setAttribute('aria-label', eye.title);
                    eye.setAttribute('aria-pressed', String(e.on));
                    summary.textContent = blendLabel(e.blend);
                    for (const c of controls) c.set(e);
                },
                index: i,
            };
        });
        list.replaceChildren(...rows.map((r) => r.el));
    }

    function paint() {
        const next = `${current.map((e) => e.kind).join(',')}|${open}`;
        if (next !== shape) {
            shape = next;
            build();
        }
        for (const row of rows) row.set(current[row.index]);
        empty.hidden = current.length > 0;
        for (const button of addButtons) button.disabled = current.length >= MAX_EFFECTS;
    }

    const el = h('div', { class: 'cal-fx' }, list, empty, h('div', { class: 'cal-fxa' }, ...addButtons));
    paint();
    el.setValue = (next) => {
        const list2 = Array.isArray(next) ? next : [];
        if (list2 === current) return;
        if (open !== null && open >= list2.length) open = null;
        current = list2;
        paint();
    };
    return el;
}
