// Content tool „Jména navíc“ (handoff §4.6, map §8.11, architecture §B.6 `names`, §E.11). Phase P6b.
//
// Contract: `ui/tools/index.js` owns the modal and calls `mountNamesTool(root, ctx)` with the modal
// body as `root`. Each row is one `names` item `{ date, name }` edited through `store.putItem`
// (history label = the date). A name is appended to the name day of its date („Karina / Vasil“);
// an empty name is kept in the list but never printed.
//
// DOM hooks: `[data-name-row="<id>"]` with `[data-name-date]`, `[data-name-text]`,
// `[data-name-remove]`; `[data-name-add]`.

import { nameDay } from '../../generate/czech.js';
import { iso, parts } from '../../generate/dates.js';
import { dateLimits, newId, sorted } from '../../model/content.js';
import { h } from '../dom.js';
import { addButton, czDate, dateField, keyedList, removeButton, textField } from './datepicker.js';

export function mountNamesTool(root, ctx) {
    const { store } = ctx;
    const year = () => store.getState().doc.design.year;
    const limits = () => dateLimits(year());
    const names = () => sorted(store.getState().doc.content.names || {}, 'date');
    const itemOf = (id) => (store.getState().doc.content.names || {})[id] || null;

    function put(id, patch) {
        const item = itemOf(id);
        if (!item) return;
        const next = { ...item, ...patch };
        store.putItem('names', id, next, czDate(next.date));
    }

    /** What the day will print: name day + every extra name of that date. */
    function printed(date) {
        const p = parts(date);
        const extra = names()
            .filter(([, item]) => item.date === date)
            .map(([, item]) => (item.name || '').trim());
        return [nameDay(p.m, p.d), ...extra].filter(Boolean).join(' / ');
    }

    const listEl = h('div', { class: 'cal-evl' });
    const list = keyedList(listEl, (id, item) => {
        const date = dateField({ value: item.date, ...limits(), attrs: { 'data-name-date': '' }, onChange: (value) => put(id, { date: value }) });
        const name = textField({ value: item.name, placeholder: 'Jméno', maxLength: 40, attrs: { 'data-name-text': '' }, onInput: (value) => put(id, { name: value }) });
        const preview = h('span', { class: 'cap cal-nmp', 'data-name-preview': '' });
        const remove = removeButton(item.name || 'jméno', () => store.removeItem('names', id, czDate((itemOf(id) || item).date)), { 'data-name-remove': '' });
        const row = h('div', { class: 'cal-evr names', 'data-name-row': id }, date, name, preview, remove);
        row.update = (next) => {
            date.setValue(next.date);
            name.setValue(next.name);
            preview.textContent = printed(next.date);
            remove.setWhat(next.name || 'jméno');
        };
        return row;
    });
    const render = () => list.sync(names());
    listEl.addEventListener('focusout', () => {
        setTimeout(() => {
            if (listEl.isConnected && !listEl.contains(document.activeElement)) render();
        }, 0);
    });

    function add() {
        const id = newId();
        const date = iso(year(), 1, 1);
        store.putItem('names', id, { date, name: '' }, czDate(date));
        const row = list.get(id);
        if (row) row.querySelector('[data-name-text]').focus();
    }

    root.replaceChildren(
        h('div', { class: 'cal-nt' }, h('p', { class: 'cap' }, 'Jméno se připíše ke jménům dne, např. „Karina / Vasil“.'), listEl, addButton('Přidat jméno', add, { 'data-name-add': '' })),
    );
    render();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'replace' || (event.type === 'content' && event.collection === 'names')) render();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
