// Content tool „Odpady“ (handoff §4.6, map §8.11, architecture §B.6 `wasteKinds`, §E.11). Phase P6b.
//
// Contract: `ui/tools/index.js` opens the large modal (title, Hotovo, Esc), shows the "feature is
// off" notice with Zapnout when the step-1 feature is off, and otherwise calls
// `mountWasteTool(root, ctx)` with the modal body as `root`. Every edit is one
// `store.putItem('wasteKinds', id, kind)` (item-wise merge on save); removing a kind also removes
// its single collections (`wasteExtras`). View state (`ui.toolState.waste`): `{ kind, ym }`.
//
// DOM hooks: `[data-waste-tab="<id>"]`, `[data-waste-add]` (select), `[data-waste-on]`,
// `[data-waste-name]`, `[data-waste-place]`, `[data-waste-mode] button[data-value]`,
// `[data-weekday="0…6"]`, `[data-waste-interval]`, `[data-waste-from]`, `[data-waste-to]`,
// `[data-waste-total]`, `[data-date="<iso>"]` (month grid), `[data-month="1…12"][data-count]`,
// `[data-waste-count]`, `[data-waste-clear]`, `[data-waste-remove]`.

import { WEEKDAYS, WEEKDAYS_SHORT, WEEK_RULES } from '../../generate/czech.js';
import { addDays, iso } from '../../generate/dates.js';
import { wasteKindColor } from '../../generate/day.js';
import { wasteRuleOn } from '../../generate/waste.js';
import { dateLimits, newId, nextPos, sorted } from '../../model/content.js';
import { BUILTIN_WASTE } from '../../model/defaults.js';
import { binIcon } from '../../render/icons.js';
import { h, icon, segmented, selectField, switchRow } from '../dom.js';
import { dateField, keyedList, mountDatePicker, plural, readToolState, textField, writeToolState, ymOf } from './datepicker.js';

const TERMS = ['termín', 'termíny', 'termínů'];
const COLLECTIONS = ['svoz', 'svozy', 'svozů'];

const binSvg = (color) => h('span', { class: 'cal-bin', 'aria-hidden': 'true', html: `<svg viewBox="0 0 14 18">${binIcon({ x: 0, y: 0, w: 14, h: 18 }, color, '')}</svg>` });

export function mountWasteTool(root, ctx) {
    const { store } = ctx;
    const year = () => store.getState().doc.design.year;
    const limits = () => dateLimits(year());
    const kinds = () => sorted(store.getState().doc.content.wasteKinds || {}, 'pos');
    const kindOf = (id) => (store.getState().doc.content.wasteKinds || {})[id] || null;
    const colorOf = (id, kind) => {
        const layout = store.getState().doc.design.layout;
        return wasteKindColor(layout ? layout.styles : null, id, kind);
    };

    let selected = readToolState(ctx, 'waste').kind || null;
    let panel = null; // { signature, update(kind) }

    const tabsEl = h('div', { class: 'cal-wtab-list' });
    const tabs = keyedList(tabsEl, (id) => {
        let bin = h('span', { class: 'cal-bin', 'aria-hidden': 'true' });
        const name = h('span');
        const button = h('button', { type: 'button', role: 'tab', class: 'cal-wtab', 'data-waste-tab': id, onClick: () => select(id) }, bin, name);
        button.update = (kind) => {
            const color = colorOf(id, kind);
            if (button.dataset.color !== color) {
                button.dataset.color = color;
                const next = binSvg(color);
                bin.replaceWith(next);
                bin = next;
            }
            name.textContent = kind.name || 'Bez názvu';
            button.classList.toggle('on', id === selected);
            button.classList.toggle('off', !kind.on);
            button.setAttribute('aria-selected', String(id === selected));
            button.tabIndex = id === selected ? 0 : -1;
        };
        return button;
    });
    const addSelect = selectField({ options: [{ value: '', label: '+ Přidat druh' }], value: '', label: 'Přidat druh odpadu', attrs: { 'data-waste-add': '' }, onChange: (value) => addKind(value) });
    addSelect.classList.add('cal-wadd');
    const panelEl = h('div', { class: 'cal-wpanel', role: 'tabpanel' });
    const el = h('div', { class: 'cal-wt' }, h('div', { class: 'cal-wtabs', role: 'tablist', 'aria-label': 'Druhy odpadu' }, tabsEl, addSelect), panelEl);
    root.replaceChildren(el);

    tabsEl.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        const ids = kinds().map(([id]) => id);
        const at = ids.indexOf(selected);
        const next = ids[at + (event.key === 'ArrowRight' ? 1 : -1)];
        if (!next) return;
        event.preventDefault();
        select(next);
        tabs.get(next).focus();
    });

    function put(id, patch) {
        const kind = kindOf(id);
        if (kind) store.putItem('wasteKinds', id, { ...kind, ...patch });
    }

    function select(id) {
        if (id === selected) return;
        selected = id;
        writeToolState(ctx, 'waste', { kind: id });
        render();
    }

    function addKind(value) {
        addSelect.setValue('');
        if (!value) return;
        const all = store.getState().doc.content.wasteKinds || {};
        const builtin = value === 'own' ? null : value;
        const id = builtin && !all[builtin] ? builtin : newId();
        store.putItem('wasteKinds', id, {
            builtin,
            name: builtin ? BUILTIN_WASTE[builtin].name : 'Nový druh odpadu',
            on: true,
            place: '',
            mode: 'rule',
            weekdays: [1],
            interval: '2e',
            from: null,
            to: null,
            dates: [],
            pos: nextPos(all),
        });
        selected = id;
        writeToolState(ctx, 'waste', { kind: id });
        render();
        const name = panelEl.querySelector('[data-waste-name]');
        if (name) {
            name.focus();
            name.select();
        } else {
            tabs.get(id).focus();
        }
    }

    async function removeKind(id) {
        const kind = kindOf(id);
        if (!kind) return;
        const ok = await ctx.confirm({ title: `Odebrat druh odpadu „${kind.name}“?`, text: 'Termíny a nastavení se smažou.', confirmLabel: 'Odebrat', danger: true });
        if (!ok || !kindOf(id)) return;
        const extras = Object.entries(store.getState().doc.content.wasteExtras || {}).filter(([, extra]) => extra.kind === id);
        store.applyOps([{ c: 'wasteKinds', id, v: null }, ...extras.map(([extraId]) => ({ c: 'wasteExtras', id: extraId, v: null }))]);
    }

    /** Collections the rule gives inside the reachable date range. */
    function ruleTotal(kind) {
        const { min, max } = limits();
        let n = 0;
        for (let date = min; date <= max; date = addDays(date, 1)) if (wasteRuleOn(kind, date, year())) n++;
        return n;
    }

    function buildRule(id) {
        const days = WEEKDAYS_SHORT.map((label, k) =>
            h(
                'button',
                {
                    type: 'button',
                    class: 'cal-wd',
                    'data-weekday': String(k),
                    title: WEEKDAYS[k],
                    'aria-label': WEEKDAYS[k],
                    onClick: () => {
                        const current = kindOf(id).weekdays || [];
                        const next = current.includes(k) ? current.filter((x) => x !== k) : [...current, k].sort((a, b) => a - b);
                        if (!next.length) {
                            ctx.toast('Vyberte aspoň jeden den', true);
                            return;
                        }
                        put(id, { weekdays: next });
                    },
                },
                label,
            ),
        );
        const interval = selectField({
            options: WEEK_RULES.map((rule) => ({ value: rule.key, label: rule.label })),
            value: kindOf(id).interval,
            label: 'Interval',
            attrs: { 'data-waste-interval': '' },
            onChange: (value) => put(id, { interval: value }),
        });
        const from = () => kindOf(id).from || iso(year(), 1, 1);
        const to = () => kindOf(id).to || iso(year(), 12, 31);
        const refuse = () => {
            ctx.toast('Konec svozu je před začátkem', true);
            return false;
        };
        const fromField = dateField({
            value: from(),
            ...limits(),
            label: 'První svoz od',
            attrs: { 'data-waste-from': '' },
            onChange: (value) => (value > to() ? refuse() : put(id, { from: value, to: to() })),
        });
        const toField = dateField({
            value: to(),
            ...limits(),
            label: 'Poslední svoz do',
            attrs: { 'data-waste-to': '' },
            onChange: (value) => (value < from() ? refuse() : put(id, { from: from(), to: value })),
        });
        const total = h('p', { class: 'cap cal-wtotal', 'data-waste-total': '' });
        const node = h(
            'div',
            { class: 'cal-wrule' },
            h('div', { class: 'cal-wfield' }, h('span', { class: 'lbl' }, 'Každé'), h('div', { class: 'cal-wds', role: 'group', 'aria-label': 'Dny svozu (lze vybrat více)' }, days)),
            h('div', { class: 'cal-wfield' }, h('span', { class: 'lbl' }, 'V týdnech'), interval),
            h('div', { class: 'cal-wfield' }, h('span', { class: 'lbl' }, 'Od – do'), h('div', { class: 'cal-wm' }, fromField, h('span', { class: 'cap', 'aria-hidden': 'true' }, '–'), toField)),
            total,
        );
        return {
            node,
            update(kind) {
                days.forEach((button, k) => {
                    const on = (kind.weekdays || []).includes(k);
                    button.classList.toggle('on', on);
                    button.setAttribute('aria-pressed', String(on));
                });
                interval.setValue(kind.interval);
                fromField.setValue(from());
                toField.setValue(to());
                const n = ruleTotal(kind);
                total.textContent = `Vychází ${n} ${plural(n, ...COLLECTIONS)}`;
                total.dataset.wasteTotal = String(n);
            },
        };
    }

    function buildDates(id) {
        const host = h('div');
        const state = readToolState(ctx, 'waste');
        const picker = mountDatePicker(host, {
            year: year(),
            ym: state.ym !== undefined ? state.ym : ymOf(iso(year(), 1, 1)),
            ...limits(),
            color: colorOf(id, kindOf(id)),
            isMarked: (date) => (kindOf(id).dates || []).includes(date),
            counts: (y, m) => {
                const prefix = iso(y, m, 1).slice(0, 7);
                return (kindOf(id).dates || []).filter((date) => date.startsWith(prefix)).length;
            },
            onPick: (date) => {
                const current = kindOf(id).dates || [];
                put(id, { dates: current.includes(date) ? current.filter((x) => x !== date) : [...current, date].sort() });
            },
            onMonth: (ym) => writeToolState(ctx, 'waste', { ym }),
        });
        const count = h('span', { class: 'cap', 'data-waste-count': '' });
        const clear = h(
            'button',
            {
                type: 'button',
                class: 'btn xs ghost',
                'data-waste-clear': '',
                onClick: async () => {
                    const n = (kindOf(id).dates || []).length;
                    const ok = await ctx.confirm({ title: 'Vymazat všechny termíny?', text: `${kindOf(id).name}: ${n} ${plural(n, ...TERMS)} se odebere.`, confirmLabel: 'Vymazat vše', danger: true });
                    if (ok && kindOf(id)) put(id, { dates: [] });
                },
            },
            'Vymazat vše',
        );
        const node = h('div', { class: 'cal-wdates' }, host, h('div', { class: 'cal-dpf' }, count, clear));
        return {
            node,
            update(kind) {
                picker.setColor(colorOf(id, kind));
                picker.refresh();
                const n = (kind.dates || []).length;
                count.textContent = n ? `Vybráno ${n} ${plural(n, ...TERMS)}` : 'Klikněte na dny svozu';
                count.dataset.wasteCount = String(n);
                clear.hidden = n === 0;
            },
        };
    }

    function buildPanel(id, kind) {
        const on = switchRow({ label: 'Zobrazovat v kalendáři', checked: kind.on, attrs: { 'data-waste-on': '' }, onChange: (checked) => put(id, { on: checked }) });
        const remove = h('button', { type: 'button', class: 'btn sm ghost danger', 'data-waste-remove': '', onClick: () => removeKind(id) }, icon('trash', 's16'), 'Odebrat druh');
        const head = h('div', { class: 'cal-wph' }, on, remove);
        if (!kind.on) {
            panelEl.replaceChildren(head);
            return { update: (next) => on.setChecked(next.on) };
        }
        const binHost = h('span', { class: 'cal-bin-host' });
        const title = kind.builtin ? h('b') : null;
        const name = kind.builtin
            ? null
            : textField({ value: kind.name, label: 'Název druhu odpadu', maxLength: 30, cls: 'cal-wnm', attrs: { 'data-waste-name': '' }, onInput: (value) => put(id, { name: value }) });
        const place = textField({ value: kind.place, placeholder: 'Místo svozu (nepovinné)', label: 'Místo svozu', maxLength: 40, cls: 'cal-wpl', attrs: { 'data-waste-place': '' }, onInput: (value) => put(id, { place: value }) });
        const mode = segmented({
            options: [
                { value: 'rule', label: 'Pravidelně' },
                { value: 'dates', label: 'Konkrétní data' },
            ],
            value: kind.mode,
            label: 'Způsob zadání svozu',
            attrs: { 'data-waste-mode': '' },
            onChange: (value) => put(id, { mode: value }),
        });
        const body = kind.mode === 'dates' ? buildDates(id) : buildRule(id);
        panelEl.replaceChildren(head, h('div', { class: 'cal-wcard' }, h('div', { class: 'cal-wch' }, binHost, title, name, place), mode, body.node));
        let binColor = null;
        return {
            update(next) {
                on.setChecked(next.on);
                const color = colorOf(id, next);
                if (color !== binColor) {
                    binColor = color;
                    binHost.replaceChildren(binSvg(color));
                }
                if (title) title.textContent = next.name;
                if (name) name.setValue(next.name);
                place.setValue(next.place);
                mode.setValue(next.mode);
                body.update(next);
            },
        };
    }

    function render() {
        const list = kinds();
        if (!list.some(([id]) => id === selected)) selected = list.length ? list[0][0] : null;
        tabs.sync(list);
        const used = new Set(list.map(([, kind]) => kind.builtin).filter(Boolean));
        addSelect.setOptions(
            [
                { value: '', label: '+ Přidat druh' },
                ...Object.entries(BUILTIN_WASTE)
                    .filter(([key]) => !used.has(key))
                    .map(([key, def]) => ({ value: key, label: def.name })),
                { value: 'own', label: 'Vlastní druh…' },
            ],
            '',
        );
        const kind = selected ? kindOf(selected) : null;
        if (!kind) {
            panel = null;
            panelEl.replaceChildren(h('p', { class: 'cal-empty-note' }, 'Přidejte druh odpadu.'));
            return;
        }
        const signature = `${selected}|${kind.on}|${kind.mode}|${kind.builtin || ''}`;
        if (!panel || panel.signature !== signature) {
            panel = buildPanel(selected, kind);
            panel.signature = signature;
        }
        panelEl.setAttribute('aria-label', kind.name);
        panel.update(kind);
    }

    render();
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'replace' || (event.type === 'layout' && !event.live) || (event.type === 'content' && event.collection === 'wasteKinds')) render();
    });

    return {
        destroy() {
            unsubscribe();
            root.replaceChildren();
        },
    };
}
