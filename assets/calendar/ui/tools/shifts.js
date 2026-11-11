// Content tool „Šichtovnice“ (handoff §4.6, map §8.11, architecture §B.6 `shiftOps`). Phase P6b.
//
// Contract: `ui/tools/index.js` owns the modal and the feature-off notice and calls
// `mountShiftsTool(root, ctx)` with the modal body as `root`. One card per operation
// (`shiftOps` item: name, 1–4 shifts `{ letter, from, to }`, four crews); every edit is one
// `store.putItem('shiftOps', id, op)`. A preset replaces the shifts of the operation
// (`SHIFT_PRESETS`: 8 h × 3, 12 h × 2). Removing an operation asks first (deviation D2).
//
// DOM hooks: `[data-shift-op="<id>"]` with `[data-shift-name]`, `[data-shift-preset]` (select),
// `[data-shift-row="<n>"]` → `[data-shift-letter]`, `[data-shift-from]`, `[data-shift-to]`,
// `[data-shift-remove]`; `[data-shift-add]`, `[data-shift-crew="0…3"]`, `[data-shift-op-remove]`;
// `[data-shift-op-add]`.

import { SHIFT_PRESETS, defaultOperation } from '../../generate/shifts.js';
import { newId, nextPos, sorted } from '../../model/content.js';
import { h, icon, selectField } from '../dom.js';
import { addButton, keyedList, removeButton, textField } from './datepicker.js';

const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

/** `<input type="time">`; an emptied or half-typed value is put back when the field is left. */
function timeField({ value, label, onChange, attrs }) {
    let committed = value;
    const input = h('input', { type: 'time', value, 'aria-label': label, required: true, ...(attrs || {}) });
    input.addEventListener('change', () => {
        if (TIME.test(input.value) && input.value !== committed) {
            committed = input.value;
            onChange(committed);
        }
    });
    input.addEventListener('blur', () => {
        if (!TIME.test(input.value)) input.value = committed;
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') input.blur();
    });
    const el = h('span', { class: 'inp sm cal-sht' }, input);
    el.setValue = (next) => {
        committed = next;
        if (document.activeElement !== input && input.value !== next) input.value = next;
    };
    return el;
}

export function mountShiftsTool(root, ctx) {
    const { store } = ctx;
    const ops = () => sorted(store.getState().doc.content.shiftOps || {}, 'pos');
    const opOf = (id) => (store.getState().doc.content.shiftOps || {})[id] || null;

    function put(id, patch) {
        const op = opOf(id);
        if (op) store.putItem('shiftOps', id, { ...op, ...patch });
    }
    const putShift = (id, index, patch) => {
        const op = opOf(id);
        if (op && op.shifts[index]) put(id, { shifts: op.shifts.map((shift, i) => (i === index ? { ...shift, ...patch } : shift)) });
    };

    async function removeOp(id) {
        const op = opOf(id);
        if (!op) return;
        const ok = await ctx.confirm({ title: `Odebrat provoz „${op.name}“?`, text: 'Termíny a nastavení se smažou.', confirmLabel: 'Odebrat', danger: true });
        if (ok && opOf(id)) store.removeItem('shiftOps', id);
    }

    const listEl = h('div', { class: 'cal-shl' });
    const empty = h('p', { class: 'cal-empty-note', 'data-shift-empty': '' }, 'Zatím žádný provoz.');

    const list = keyedList(listEl, (id, first) => {
        const name = textField({ value: first.name, placeholder: 'Název provozu', maxLength: 30, attrs: { 'data-shift-name': '' }, onInput: (value) => put(id, { name: value }) });
        const preset = selectField({
            options: [
                { value: '', label: 'Předvolba…' },
                { value: '8', label: '8 h · 3 směny' },
                { value: '12', label: '12 h · 2 směny' },
            ],
            value: '',
            label: 'Předvolba',
            attrs: { 'data-shift-preset': '' },
            onChange: (value) => {
                preset.setValue('');
                if (SHIFT_PRESETS[value]) put(id, { shifts: SHIFT_PRESETS[value].map(([letter, from, to]) => ({ letter, from, to })) });
            },
        });
        const remove = removeButton(first.name || 'provoz', () => removeOp(id), { 'data-shift-op-remove': '' });
        const shiftsEl = h('div', { class: 'cal-shs' });
        // rows are keyed by their index: a shift has no identity of its own
        const shifts = keyedList(shiftsEl, (key) => {
            const index = Number(key);
            const letter = textField({ value: '', placeholder: 'R', label: 'Písmeno směny', maxLength: 2, cls: 'cal-shl1', attrs: { 'data-shift-letter': '' }, onInput: (value) => putShift(id, index, { letter: value }) });
            const from = timeField({ value: '06:00', label: 'Od', attrs: { 'data-shift-from': '' }, onChange: (value) => putShift(id, index, { from: value }) });
            const to = timeField({ value: '14:00', label: 'Do', attrs: { 'data-shift-to': '' }, onChange: (value) => putShift(id, index, { to: value }) });
            const drop = h(
                'button',
                {
                    type: 'button',
                    class: 'btn icon xs ghost',
                    'aria-label': 'Odebrat směnu',
                    title: 'Odebrat směnu',
                    'data-shift-remove': '',
                    onClick: () => {
                        const op = opOf(id);
                        if (op && op.shifts.length > 1) put(id, { shifts: op.shifts.filter((_, i) => i !== index) });
                    },
                },
                icon('trash', 's16'),
            );
            const row = h('div', { class: 'cal-shr', 'data-shift-row': key }, letter, from, h('span', { class: 'cal-shd', 'aria-hidden': 'true' }, '–'), to, drop);
            row.update = ({ shift, only }) => {
                letter.setValue(shift.letter);
                from.setValue(shift.from);
                to.setValue(shift.to);
                drop.style.visibility = only ? 'hidden' : '';
                drop.disabled = only;
            };
            return row;
        });
        const addShift = h(
            'button',
            {
                type: 'button',
                class: 'btn xs ghost cal-add',
                'data-shift-add': '',
                onClick: () => {
                    const op = opOf(id);
                    if (!op || op.shifts.length >= 4) return;
                    put(id, { shifts: [...op.shifts, { letter: '', from: '06:00', to: '14:00' }] });
                    const row = shifts.get(String(op.shifts.length));
                    if (row) row.querySelector('[data-shift-letter]').focus();
                },
            },
            icon('plus', 's14'),
            'Přidat směnu',
        );
        const crews = [0, 1, 2, 3].map((q) =>
            textField({
                value: '',
                label: `Četa ${q + 1}`,
                maxLength: 2,
                cls: 'cal-shl1',
                attrs: { 'data-shift-crew': String(q) },
                onInput: (value) => {
                    const op = opOf(id);
                    if (op) put(id, { crews: op.crews.map((crew, i) => (i === q ? value : crew)) });
                },
            }),
        );
        const card = h(
            'section',
            { class: 'cal-shc', 'data-shift-op': id },
            h('div', { class: 'cal-shh' }, name, preset, remove),
            h('div', { class: 'cal-shg' }, h('div', { class: 'cal-shb' }, h('span', { class: 'lbl' }, 'Směny'), shiftsEl, addShift), h('div', { class: 'cal-shb' }, h('span', { class: 'lbl' }, 'Čety'), h('div', { class: 'cal-shcr' }, crews))),
        );
        card.update = (op) => {
            card.setAttribute('aria-label', op.name || 'Provoz');
            name.setValue(op.name);
            remove.setWhat(op.name || 'provoz');
            shifts.sync(op.shifts.map((shift, index) => [String(index), { shift, only: op.shifts.length === 1 }]));
            addShift.hidden = op.shifts.length >= 4;
            crews.forEach((field, q) => field.setValue(op.crews[q] || ''));
        };
        return card;
    });

    function addOp() {
        const all = store.getState().doc.content.shiftOps || {};
        const id = newId();
        store.putItem('shiftOps', id, { ...defaultOperation(Object.keys(all).length + 1), pos: nextPos(all) });
        const card = list.get(id);
        if (card) {
            const input = card.querySelector('[data-shift-name]');
            input.focus();
            input.select();
        }
    }

    function render() {
        const all = ops();
        list.sync(all);
        empty.hidden = all.length > 0;
    }

    root.replaceChildren(h('div', { class: 'cal-st' }, listEl, empty, addButton('Přidat provoz', addOp, { 'data-shift-op-add': '' })));
    render();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'replace' || (event.type === 'content' && event.collection === 'shiftOps')) render();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
