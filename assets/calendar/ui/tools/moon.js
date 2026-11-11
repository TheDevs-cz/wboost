// Content tool „Fáze měsíce“ (handoff §4.6, map §8.11, architecture §B.6 `moonEdits`, deviation D6).
// Phase P6b.
//
// Contract: `ui/tools/index.js` owns the modal and the feature-off notice and calls
// `mountMoonTool(root, ctx)` with the modal body as `root`.
//
// The list shows the effective phases: `computeMoonPhases(year)` with the calendar's `moonEdits`
// applied (one phase per date). The tool never stores the list — after every change it works out
// the smallest set of edits that turns the computed list into the wanted one
// (`remove` for a computed phase that is gone, `add` for a date whose phase differs from the
// computed one) and writes only the difference to `moonEdits` in one `store.applyOps`. So editing a
// phase back to its computed value leaves no edit behind, and **Přepočítat podle roku** simply
// deletes every edit. View state (`ui.toolState.moon`): `{ view, ym }`.
//
// DOM hooks: `[data-moon-reset]`, `[data-moon-view] button[data-value="m|y"]`, `[data-month]`,
// `[data-moon-row="<date>"]` with `[data-moon-date]`, `[data-moon-phase="0…3"]`,
// `[data-moon-remove]`; `[data-moon-add]`; `[data-moon-empty]`.

import { MOON_PHASES } from '../../generate/czech.js';
import { addDays, iso } from '../../generate/dates.js';
import { computeMoonPhases, effectivePhases } from '../../generate/moon.js';
import { dateLimits, newId } from '../../model/content.js';
import { moonIcon } from '../../render/icons.js';
import { h, icon, segmented } from '../dom.js';
import { addButton, clampYm, dateField, keyedList, monthNav, readToolState, removeButton, writeToolState, ymOf, ymPrefix } from './datepicker.js';

const NOUN = ['fáze', 'fáze', 'fází'];

export function mountMoonTool(root, ctx) {
    const { store } = ctx;
    const doc = () => store.getState().doc;
    const year = () => doc().design.year;
    const limits = () => dateLimits(year());

    const saved = readToolState(ctx, 'moon');
    const view = { view: saved.view === 'y' ? 'y' : 'm', ym: saved.ym !== undefined ? saved.ym : year() * 12 };
    const remember = (patch) => {
        Object.assign(view, patch);
        writeToolState(ctx, 'moon', patch);
    };

    /** Rows keep a stable key while their date is edited, so the row (and the caret) stays put. */
    let rows = [];
    let uid = 0;

    function reconcile() {
        const effective = effectivePhases(doc());
        const seen = new Set();
        rows = rows.filter((row) => effective.has(row.date) && !seen.has(row.date) && seen.add(row.date));
        for (const row of rows) row.phase = effective.get(row.date);
        for (const [date, phase] of effective) if (!seen.has(date)) rows.push({ uid: `m${++uid}`, date, phase });
        rows.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
    }

    /** Writes the edits that make the effective list equal `wanted` (Map date → phase). */
    function apply(wanted) {
        const computed = new Map(computeMoonPhases(year()).map((p) => [p.date, p.phase]));
        const needed = [];
        for (const [date, phase] of computed) if (!wanted.has(date)) needed.push({ op: 'remove', date, phase });
        for (const [date, phase] of wanted) if (computed.get(date) !== phase) needed.push({ op: 'add', date, phase });
        const key = (edit) => `${edit.op}|${edit.date}|${edit.phase}`;
        const open = new Map(needed.map((edit) => [key(edit), edit]));
        const ops = [];
        for (const [id, edit] of Object.entries(doc().content.moonEdits || {})) {
            if (open.has(key(edit))) open.delete(key(edit));
            else ops.push({ c: 'moonEdits', id, v: null });
        }
        for (const edit of open.values()) ops.push({ c: 'moonEdits', id: newId(), v: edit });
        if (ops.length) store.applyOps(ops);
        else render();
    }

    const wantedNow = () => new Map(rows.map((row) => [row.date, row.phase]));

    const reset = h(
        'button',
        {
            type: 'button',
            class: 'btn sm ghost',
            'data-moon-reset': '',
            onClick: () => {
                const ops = Object.keys(doc().content.moonEdits || {}).map((id) => ({ c: 'moonEdits', id, v: null }));
                if (ops.length) store.applyOps(ops);
                ctx.toast('Fáze přepočítány automaticky');
            },
        },
        icon('undo', 's16'),
        h('span'),
    );
    const viewSeg = segmented({
        options: [
            { value: 'm', label: 'Měsíc' },
            { value: 'y', label: 'Celý rok' },
        ],
        value: view.view,
        label: 'Zobrazení',
        attrs: { 'data-moon-view': '' },
        onChange: (value) => {
            remember({ view: value });
            render();
        },
    });
    const nav = monthNav({
        year: year(),
        limits: limits(),
        ym: clampYm(view.ym, limits()),
        noun: NOUN,
        counts: (ym) => {
            const prefix = ymPrefix(ym);
            return rows.filter((row) => row.date.startsWith(prefix)).length;
        },
        onChange: (ym) => {
            remember({ ym });
            render();
        },
    });
    view.ym = nav.ym();
    const listEl = h('div', { class: 'cal-evl2' });
    const empty = h('p', { class: 'cap', 'data-moon-empty': '' }, 'V tomto měsíci žádné fáze.');

    const list = keyedList(listEl, (key, first) => {
        const row = first;
        const date = dateField({
            value: row.date,
            ...limits(),
            attrs: { 'data-moon-date': '' },
            onChange: (value) => {
                if (rows.some((other) => other !== row && other.date === value)) {
                    ctx.toast('Na tento den už jedna fáze připadá', true);
                    return false;
                }
                row.date = value;
                if (view.view === 'm' && ymOf(value) !== view.ym) {
                    remember({ ym: ymOf(value) });
                    nav.set(view.ym);
                }
                apply(wantedNow());
                return true;
            },
        });
        date.classList.add('cal-evd');
        const buttons = MOON_PHASES.map((name, phase) =>
            h(
                'button',
                {
                    type: 'button',
                    role: 'radio',
                    title: name,
                    'aria-label': name,
                    'data-moon-phase': String(phase),
                    onClick: () => {
                        if (row.phase === phase) return;
                        row.phase = phase;
                        apply(wantedNow());
                    },
                    html: `<svg viewBox="0 0 20 20" aria-hidden="true">${moonIcon({ x: 3, y: 3, w: 14, h: 14 }, phase, 'currentColor').replace(/<title>.*?<\/title>/, '')}</svg>`,
                },
            ),
        );
        const label = h('span', { class: 'cal-mphl' });
        const remove = removeButton(
            'fázi',
            () => {
                rows = rows.filter((other) => other !== row);
                apply(wantedNow());
            },
            { 'data-moon-remove': '' },
        );
        const el = h('div', { class: 'cal-evx moon' }, date, h('div', { class: 'cal-mph4', role: 'radiogroup', 'aria-label': 'Fáze' }, buttons), label, remove);
        el.update = () => {
            el.dataset.moonRow = row.date;
            date.setValue(row.date);
            buttons.forEach((button, phase) => {
                const on = row.phase === phase;
                button.classList.toggle('on', on);
                button.setAttribute('aria-checked', String(on));
            });
            label.textContent = MOON_PHASES[row.phase] || '';
        };
        return el;
    });
    listEl.addEventListener('focusout', () => {
        setTimeout(() => {
            if (listEl.isConnected && !listEl.contains(document.activeElement)) render();
        }, 0);
    });

    function add() {
        const { min, max } = limits();
        let date = view.view === 'm' ? `${ymPrefix(view.ym)}-01` : iso(year(), 1, 1);
        if (date < min) date = min;
        const taken = new Set(rows.map((row) => row.date));
        while (taken.has(date) && date < max) date = addDays(date, 1);
        if (taken.has(date)) return;
        if (view.view === 'm' && ymOf(date) !== view.ym) {
            remember({ ym: ymOf(date) });
            nav.set(view.ym);
        }
        const wanted = wantedNow();
        wanted.set(date, 2);
        apply(wanted);
        const row = rows.find((r) => r.date === date);
        const el = row ? list.get(row.uid) : null;
        if (el) el.querySelector('[data-moon-date]').focus();
    }

    function render() {
        reconcile();
        reset.lastElementChild.textContent = `Přepočítat podle roku ${year()}`;
        reset.disabled = Object.keys(doc().content.moonEdits || {}).length === 0;
        viewSeg.setValue(view.view);
        nav.el.hidden = view.view !== 'm';
        nav.refresh();
        const prefix = ymPrefix(view.ym);
        const shown = rows.filter((row) => view.view === 'y' || row.date.startsWith(prefix));
        list.sync(shown.map((row) => [row.uid, row]));
        empty.hidden = shown.length > 0;
    }

    root.replaceChildren(h('div', { class: 'cal-mt' }, h('div', { class: 'cal-s4h' }, reset, viewSeg), nav.el, listEl, empty, addButton('Přidat fázi', add, { 'data-moon-add': '' })));
    render();

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'replace' || (event.type === 'content' && event.collection === 'moonEdits')) render();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
