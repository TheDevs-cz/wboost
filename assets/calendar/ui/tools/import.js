// „Nahrát z Excelu“ in Odpady and Akce a spolky (K10b, docs/calendar/excel-import-plan.md,
// architecture § "Import from a spreadsheet"). Both roles: importing is filling content.
//
// Contract: `importBar(ctx, kind, onTable)` → the bar at the top of a tool: a file button
// „Nahrát z Excelu“ (caption „xlsx, xls, ods nebo csv“) and the link „Stáhnout vzor“. The file goes
// to `api.importTable` (read on the server, never stored); its answer goes to `onTable(table)`.
// `mountImportReview(root, ctx, { kind, table, selected, onClose })` shows the review INSTEAD of the
// tool's body: sheet, shape / columns, mapping, a preview of what the write does and the skipped
// rows — and writes nothing until „Načíst …“. Then ONE `store.applyOps` (content ops are not
// undoable, hence the preview) and `onClose({ imported: [ids…] })`; „Zrušit“ → `onClose(null)`.
// While the review is shown the modal's „Hotovo“ is hidden (one primary); Esc / × still close.
// The plan comes from `state/import.js`, the same function for the preview and the write.
//
// DOM hooks: `[data-import-file]` (input), `[data-import-template]` (a), `[data-import-review]`,
// `[data-import-sheet]`, `[data-import-shape]`, `[data-import-col="date|name|club"]`,
// `[data-import-map="<group key>"]` (select), `[data-import-code="<club key>"]`, `[data-import-mode]`,
// `[data-import-target="<kind id>"]` / `[data-import-summary]`, `[data-import-issues]` (details) with
// `[data-import-issue="<row>"]`, `[data-import-cancel]`, `[data-import-apply]` (`data-count`).

import * as im from '../../state/import.js';
import { sorted } from '../../model/content.js';
import { BUILTIN_WASTE } from '../../model/defaults.js';
import { h, icon, segmented, selectField } from '../dom.js';
import { czDate, plural } from './datepicker.js';

const ACCEPT = '.xlsx,.xls,.ods,.csv,.tsv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet,text/csv';
const TERMS = ['termín', 'termíny', 'termínů'];
const EVENTS = ['akce', 'akce', 'akcí'];
const ROWS = ['řádek', 'řádky', 'řádků'];
const colName = (c) => String.fromCharCode(65 + (c % 26)) + (c >= 26 ? String(Math.floor(c / 26)) : '');

/** The bar „Nahrát z Excelu · xlsx, xls, ods nebo csv · Stáhnout vzor“. */
export function importBar(ctx, kind, onTable) {
    const input = h('input', { type: 'file', class: 'sr', accept: ACCEPT, 'aria-label': 'Nahrát z Excelu', 'data-import-file': kind });
    const label = h('label', { class: 'btn sm ghost cal-imp-btn' }, input, icon('upload', 's16'), h('span', null, h('b', null, 'Nahrát z Excelu'), h('span', { class: 'cap' }, 'xlsx, xls, ods nebo csv')));
    const urls = ctx.env.urls || {};
    const href = kind === 'waste' ? urls.importTemplateWaste : urls.importTemplateEvents;
    const template = href ? h('a', { class: 'cal-imp-vzor', href, download: '', 'data-import-template': kind }, icon('download', 's14'), 'Stáhnout vzor') : null;
    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file || label.dataset.busy) return;
        const limit = Number(ctx.env.maxFileSize) || 0;
        if (limit > 0 && file.size > limit) {
            ctx.toast('Soubor je větší než 10 MB.', true);
            return;
        }
        label.dataset.busy = '1';
        label.setAttribute('aria-busy', 'true');
        try {
            const table = await ctx.api.importTable(file);
            onTable(table);
        } catch (error) {
            ctx.toast((error && error.message) || 'Tabulku se nepodařilo přečíst', true);
        } finally {
            delete label.dataset.busy;
            label.removeAttribute('aria-busy');
        }
    });
    return h('div', { class: 'cal-imp-bar' }, label, template);
}

export function mountImportReview(root, ctx, { kind, table, selected, onClose }) {
    const { store } = ctx;
    const content = () => store.getState().doc.content;
    const year = () => store.getState().doc.design.year;
    const view = { sheet: 0, shape: null, mapping: null, mode: 'replace', cols: null, clubChoice: {} };

    const body = h('div', { class: 'cal-imp-body' });
    const apply = h('button', { type: 'button', class: 'btn btn-primary', 'data-import-apply': '' }, 'Načíst');
    const cancel = h('button', { type: 'button', class: 'btn ghost', 'data-import-cancel': '', onClick: () => onClose(null) }, 'Zrušit');
    const el = h(
        'div',
        { class: 'cal-imp', 'data-import-review': kind },
        h('div', { class: 'cal-imp-head' }, icon('file', 's16'), h('b', null, table.name || 'Tabulka'), table.truncated ? h('span', { class: 'cap' }, '— tabulka je velká, načte se jen prvních 2000 řádků') : null),
        body,
        h('div', { class: 'cal-imp-foot' }, cancel, apply),
    );
    root.append(el);
    // one primary at a time (design-system §7): the modal's „Hotovo“ waits until the review is gone
    const modal = root.closest('.modal');
    const done = modal ? modal.querySelector('[data-cal-action="modal-done"]') : null;
    if (done) done.style.display = 'none';

    let plan = null;
    apply.addEventListener('click', () => {
        if (!plan || !plan.count) return;
        store.applyOps(plan.ops);
        ctx.toast(plan.toast);
        onClose({ imported: plan.imported });
    });

    const rows = () => (table.sheets[view.sheet] || { rows: [] }).rows;

    function sheetPicker() {
        if (table.sheets.length < 2) return null;
        return field(
            'List',
            selectField({
                options: table.sheets.map((s, i) => ({ value: i, label: s.name || `List ${i + 1}` })),
                value: view.sheet,
                label: 'List tabulky',
                attrs: { 'data-import-sheet': '' },
                onChange: (value) => {
                    view.sheet = Number(value);
                    view.shape = null;
                    view.mapping = null;
                    view.cols = null;
                    view.clubChoice = {};
                    build();
                },
            }),
        );
    }

    function issuesBlock(issues) {
        const skipped = issues.filter((i) => !i.note);
        const notes = issues.filter((i) => i.note);
        const out = [];
        const list = (items) => h('ul', { class: 'cal-imp-issues' }, ...items.map((i) => h('li', { 'data-import-issue': String(i.row) }, h('b', null, `Řádek ${i.row}`), ` ${i.text} — ${im.ISSUE_TEXTS[i.reason] || i.reason}`)));
        if (skipped.length) {
            out.push(h('details', { class: 'cal-imp-det warn', 'data-import-issues': 'skipped' }, h('summary', null, icon('alert', 's14'), `Přeskočeno ${skipped.length} ${plural(skipped.length, ...ROWS)}`), list(skipped)));
        }
        if (notes.length) {
            out.push(h('details', { class: 'cal-imp-det', 'data-import-issues': 'notes' }, h('summary', null, icon('info', 's14'), `Upozornění (${notes.length})`), list(notes)));
        }
        return out;
    }

    function setApply(count, noun) {
        apply.disabled = count === 0;
        apply.dataset.count = String(count);
        apply.textContent = count ? `Načíst ${count} ${plural(count, ...noun)}` : 'Není co načíst';
    }

    // ---- waste ---------------------------------------------------------------------------------

    function buildWaste() {
        const read = im.readWaste(rows(), year(), view.shape || undefined);
        if (!view.shape) view.shape = read.shape;
        const kinds = sorted(content().wasteKinds || {}, 'pos');
        if (!view.mapping) view.mapping = im.suggestWasteMapping(content(), read.groups, selected);
        for (const group of read.groups) if (!view.mapping[group.key]) view.mapping[group.key] = im.suggestWasteMapping(content(), [group], selected)[group.key];

        const shape = field(
            'Uspořádání tabulky',
            selectField({
                options: Object.entries(im.WASTE_SHAPES).map(([value, label]) => ({ value, label })),
                value: read.shape || 'rows',
                label: 'Uspořádání tabulky',
                attrs: { 'data-import-shape': '' },
                onChange: (value) => {
                    view.shape = value;
                    view.mapping = null;
                    build();
                },
            }),
        );
        const top = h('div', { class: 'cal-imp-row' }, sheetPicker(), shape);
        if (!read.groups.length) {
            body.replaceChildren(top, h('p', { class: 'cal-empty-note', 'data-import-summary': 'empty' }, 'V tabulce jsem nenašel žádné termíny svozu. Zkuste jiný list nebo uspořádání — nebo si stáhněte vzor.'), ...issuesBlock(read.issues));
            plan = null;
            setApply(0, TERMS);
            return;
        }

        const used = new Set(kinds.map(([, k]) => k.builtin).filter(Boolean));
        const targetOptions = (group) => [
            ...kinds.map(([id, k]) => ({ value: id, label: k.name || 'Bez názvu' })),
            ...Object.entries(BUILTIN_WASTE)
                .filter(([key]) => !used.has(key))
                .map(([key, def]) => ({ value: `new:${key}`, label: `Nový druh: ${def.name}` })),
            { value: 'new', label: `Nový druh: ${group.label.slice(0, im.MAX_KIND_NAME)}` },
            { value: 'skip', label: 'Nenačítat' },
        ];
        const mapping = h(
            'div',
            { class: 'cal-imp-map' },
            h('span', { class: 'lbl' }, 'V souboru'),
            h('span', { class: 'lbl' }, 'Druh v kalendáři'),
            ...read.groups.flatMap((group) => [
                h('span', { class: 'cal-imp-src' }, h('b', null, group.label), h('span', { class: 'cap' }, `${group.dates.length} ${plural(group.dates.length, ...TERMS)}`)),
                selectField({
                    options: targetOptions(group),
                    value: view.mapping[group.key],
                    label: `Druh v kalendáři pro „${group.label}“`,
                    attrs: { 'data-import-map': group.key },
                    onChange: (value) => {
                        view.mapping[group.key] = value;
                        build();
                    },
                }),
            ]),
        );
        const mode = segmented({
            options: [
                { value: 'replace', label: 'Nahradit termíny' },
                { value: 'add', label: 'Přidat k termínům' },
            ],
            value: view.mode,
            label: 'Co s dosavadními termíny',
            attrs: { 'data-import-mode': '' },
            onChange: (value) => {
                view.mode = value;
                build();
            },
        });

        const waste = im.planWaste(content(), read, view.mapping, view.mode);
        const summary = h(
            'ul',
            { class: 'cal-imp-sum', 'data-import-summary': '' },
            ...waste.targets.map((t) => {
                let text;
                if (t.refused) text = `víc než ${im.MAX_WASTE_DATES} termínů — nenačte se`;
                else if (t.isNew) text = `nový druh — ${t.imported} ${plural(t.imported, ...TERMS)}`;
                else if (view.mode === 'add') text = `přidá se ${t.imported} ${plural(t.imported, ...TERMS)}${t.before ? ` k ${t.before} ${t.before === 1 ? 'stávajícímu' : 'stávajícím'}` : ''}`;
                else text = `${t.after} ${plural(t.after, ...TERMS)}${t.before ? ` (nahradí ${t.before} ${plural(t.before, 'stávající', 'stávající', 'stávajících')})` : ''}`;
                if (t.wasRule && !t.refused) text += ' — místo pravidelného svozu';
                return h('li', { class: t.refused ? 'refused' : '', 'data-import-target': t.id }, h('b', null, t.name), ` — ${text}`);
            }),
        );
        body.replaceChildren(top, mapping, h('div', { class: 'cal-imp-row' }, field('Dosavadní termíny', mode)), h('span', { class: 'lbl' }, 'Po načtení'), summary, ...issuesBlock(read.issues));
        const imported = waste.ops.map((op) => op.id);
        plan = { ops: waste.ops, count: waste.total, imported, toast: `Načteno ${waste.total} ${plural(waste.total, ...TERMS)}` };
        setApply(waste.total, TERMS);
    }

    // ---- events --------------------------------------------------------------------------------

    function buildEvents() {
        const all = rows();
        if (!view.cols) view.cols = im.detectEventColumns(all, year());
        const read = im.readEvents(all, year(), view.cols);
        const header = view.cols.header >= 0 ? all[view.cols.header] : [];
        const width = all.reduce((max, row) => Math.max(max, row.length), 0);
        const colOptions = (none) => [...(none ? [{ value: -1, label: '—' }] : []), ...Array.from({ length: width }, (_, c) => ({ value: c, label: `${colName(c)}${header[c] ? ` · ${String(header[c]).slice(0, 30)}` : ''}` }))];
        const colPick = (key, title, none) =>
            field(
                title,
                selectField({
                    options: colOptions(none),
                    value: view.cols[key],
                    label: `Sloupec: ${title}`,
                    attrs: { 'data-import-col': key },
                    onChange: (value) => {
                        view.cols = { ...view.cols, [key]: Number(value) };
                        view.clubChoice = {};
                        build();
                    },
                }),
            );
        const top = h('div', { class: 'cal-imp-row' }, sheetPicker(), colPick('date', 'Datum', false), colPick('name', 'Název akce', false), colPick('club', 'Pořadatel', true));
        if (!read.items.length) {
            body.replaceChildren(top, h('p', { class: 'cal-empty-note', 'data-import-summary': 'empty' }, 'V tabulce jsem nenašel žádné akce s datem a názvem. Zkontrolujte sloupce — nebo si stáhněte vzor.'), ...issuesBlock(read.issues));
            plan = null;
            setApply(0, EVENTS);
            return;
        }

        const clubs = im.eventClubs(content(), read.items);
        const known = sorted(content().clubs || {}, 'pos');
        const clubRows = clubs.flatMap((club) => {
            const choice = view.clubChoice[club.key] || { to: club.suggested, code: club.proposed };
            view.clubChoice[club.key] = choice;
            const select = selectField({
                options: [
                    ...known.map(([id, c]) => ({ value: id, label: `${c.code || '—'}${c.name ? ` — ${c.name}` : ''}` })),
                    { value: 'new', label: 'Nový spolek' },
                    { value: 'none', label: 'Bez pořadatele' },
                ],
                value: choice.to,
                label: `Pořadatel v kalendáři pro „${club.name}“`,
                attrs: { 'data-import-map': club.key },
                onChange: (value) => {
                    view.clubChoice[club.key] = { ...choice, to: value };
                    build();
                },
            });
            let code = null;
            if (choice.to === 'new') {
                const input = h('input', { type: 'text', value: choice.code || '', maxlength: String(im.MAX_CLUB_CODE), 'aria-label': `Zkratka nového spolku „${club.name}“`, title: 'Zkratka (nejvýše 6 znaků)', 'data-import-code': club.key });
                input.addEventListener('change', () => {
                    view.clubChoice[club.key] = { ...view.clubChoice[club.key], code: input.value.trim().slice(0, im.MAX_CLUB_CODE) };
                    build();
                });
                code = h('span', { class: 'inp sm cal-imp-code' }, input);
            }
            return [h('span', { class: 'cal-imp-src' }, h('b', null, club.name), h('span', { class: 'cap' }, `${club.count} ${plural(club.count, ...EVENTS)}`)), h('span', { class: 'cal-imp-pick' }, select, code)];
        });
        const mapping = clubs.length ? h('div', { class: 'cal-imp-map' }, h('span', { class: 'lbl' }, 'Pořadatel v souboru'), h('span', { class: 'lbl' }, 'Spolek v kalendáři'), ...clubRows) : null;

        const events = im.planEvents(content(), read, clubs, view.clubChoice);
        const newClubs = events.ops.filter((op) => op.c === 'clubs').length;
        const summary = h(
            'ul',
            { class: 'cal-imp-sum', 'data-import-summary': '' },
            h('li', null, h('b', null, `${events.added} ${plural(events.added, ...EVENTS)}`), ' se přidá'),
            events.duplicates ? h('li', null, `${events.duplicates} ${plural(events.duplicates, ...EVENTS)} už v kalendáři je nebo se v tabulce opakuje — přeskočí se`) : null,
            newClubs ? h('li', null, `${newClubs} ${plural(newClubs, 'nový spolek', 'nové spolky', 'nových spolků')}`) : null,
        );
        body.replaceChildren(top, mapping || h('p', { class: 'cap' }, 'Tabulka nemá sloupec s pořadatelem — akce se načtou bez něj.'), h('span', { class: 'lbl' }, 'Po načtení'), summary, ...issuesBlock([...read.issues, ...events.issues].sort((a, b) => a.row - b.row)));
        const ops = events.ops.map((op) => (op.c === 'events' ? { ...op, label: czDate(op.v.date) } : op));
        const firstDate = ops.filter((op) => op.c === 'events').map((op) => op.v.date).sort()[0] || null;
        plan = { ops, count: events.added, imported: firstDate ? [firstDate] : [], toast: `Načteno ${events.added} ${plural(events.added, ...EVENTS)}` };
        setApply(events.added, EVENTS);
    }

    function build() {
        if (kind === 'waste') buildWaste();
        else buildEvents();
    }

    build();
    return {
        destroy() {
            el.remove();
            if (done) done.style.display = '';
        },
    };
}

function field(label, control) {
    return h('label', { class: 'cal-imp-f' }, h('span', { class: 'lbl' }, label), control);
}
