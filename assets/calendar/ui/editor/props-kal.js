// Kalendárium panels: the whole-block panel and every part panel (handoff §6.1 "Kalendárium", §6.2;
// map §8.8 "Kalendárium, whole block", §8.9; architecture §D.6 "Properties"). Owner: P5b.
//
// Contract: `mountKalProps(root, ctx): { destroy() }` — called by `props.js` (P5a) whenever
// `ui.selection.el === 'cal'`, with a fresh child element (`div.cal-props-kal[data-props="cal"]`).
// It reads `ui.selection.part` / `.frame` itself. `props.js` destroys and re-mounts it on every
// selection change (element, frame, part), after an undo / redo, after a `features` / `type` change
// and after a save response that really changed the layout. Between those moments this module keeps
// its controls fresh itself: every control is built ONCE and refreshed in place on `layout` /
// `content` / `ui` / `replace` (a field keeps its focus while the page redraws); only the small
// lists whose rows come and go (icon order, waste kinds, shift columns, the cell diagram) are
// rebuilt, and only when their structure changes — never for a typed name.
//
// Writing rules (the same as every other panel): a discrete control → one `store.change`; a slider
// → `beginChange` / `updateLive` / `commitChange` (one undo step per drag), both through
// `styleBinding`. The two content shortcuts of deviation D2 — Šichtovnice › Sloupce and the waste
// kinds — write CONTENT (`putItem` / `applyOps`, not undoable; a removal asks first).
//
// DOM hooks (§D.10): the panel root carries `data-kal-panel="block" | "<part>"`; the title is
// `[data-props-title]`; the back arrow of a part panel `[data-cal-action="part-back"]`; every style
// control `data-prop="<path in styles>"` (`num.size`, `cal.numberSide`, `lines.style`, `cal.iconGrid`,
// `icons.order` on the order list, …); the Velikosti sliders additionally `data-size="<kind>"`
// (`num` = the Čísla slider that writes all four number styles). Ikony: order rows
// `[data-icon-order="<category>"]` (focusable, Alt+↑/↓ moves) with the opener `[data-icon-open]`,
// `[data-cal-action="icon-add"]` + menu items `[data-icon-add="<category>"]`,
// `[data-icon-remove="<category>"]`, layout buttons `[data-prop="cal.iconGrid"] [data-value]`, the
// cell diagram `[data-icon-cell="<n>"]`, the cell size `input[data-cell-size]` and alignment
// `[data-cell-align]`. Akce: `[data-cal-action="evbox-reset"]`. Šichtovnice: `[data-shift-col="<id>"]`,
// `[data-shift-col-name]`, `[data-shift-col-remove]`, `[data-cal-action="shift-col-add"]`. Odpady:
// `[data-waste-kind="<id>"]`, `[data-waste-kind-name]`, `[data-waste-kind-remove]`,
// `[data-cal-action="waste-kind-add"]` + `[data-waste-add="<kind id> | own"]`. Custom icon tiles:
// `input[type=file][data-upload="icon"][data-icon-slot="<slot>"]` and `[data-icon-reset="<slot>"]`.
//
// Decisions that the handoff leaves open:
//   - „Zobrazovat odpady“ switches whether Odpady is one of the icons of the row (`icons.order`),
//     which is the only place waste prints — it is part of the layout and undoable. Switching the
//     whole feature stays in step 1.
//   - Ikony v řádku lists the categories that are enabled in step 1; a category that is in the stored
//     order but switched off keeps its place for when it returns.
//   - an alignment stored as "follow the number side" (`null`) is shown as the side it resolves to.

import Sortable from 'sortablejs';
import { BUILTIN_WASTE, LIMITS, clampLimit } from '../../model/defaults.js';
import { newId, nextPos, sorted } from '../../model/content.js';
import { ICON_CATEGORIES, activeIconCategories, addableIconCategories, elementLabel, partLabel } from '../../model/elements.js';
import { MOON_PHASES, SEASONS } from '../../generate/czech.js';
import { wasteKindColor } from '../../generate/day.js';
import { defaultOperation } from '../../generate/shifts.js';
import { binIcon, clockIcon, moonIcon, seasonIcon, starIcon } from '../../render/icons.js';
import { checkRow, formatNumber, h, icon, onOutside, parseNumber, switchRow } from '../dom.js';
import { alignControl, sideControl, valignControl } from '../controls/align.js';
import { createRefresher, getPath, setStyle, styleBinding, stylesOf } from '../controls/bind.js';
import { colorPalette, colorPicker } from '../controls/color.js';
import { limitField, sliderRow } from '../controls/number.js';
import { note, panelHead, panelRoot, section, setShown } from '../controls/panel.js';
import { positionSection } from '../controls/position.js';
import { textProps } from '../controls/text.js';

const ALL_PAGES = 'Platí pro všechny strany kalendáře';
const ALL_DAYS = 'Kalendárium · platí pro všechny dny';
const NUMBER_PARTS = ['num', 'numSat', 'numSun', 'numHol'];
const SIDES = [
    { value: 'start', label: 'Vlevo' },
    { value: 'middle', label: 'Na střed' },
    { value: 'end', label: 'Vpravo' },
];
const ICON_ACCEPT = 'image/svg+xml,image/png,image/jpeg';
const PREVIEW_BOX = { x: 3, y: 3, w: 18, h: 18 };
const CATEGORY_LABEL = Object.fromEntries(ICON_CATEGORIES.map((c) => [c.key, c.label]));

// the four icon layouts: [value, title, cells of the 4 × 2 diagram as [column, row, columns, rows], glyph]
const GRIDS = [
    ['4', '4 za sebou', [[0, 0, 1, 2], [1, 0, 1, 2], [2, 0, 1, 2], [3, 0, 1, 2]], '<rect x="1" y="5" width="3.5" height="8" rx="1"/><rect x="5.3" y="5" width="3.5" height="8" rx="1"/><rect x="9.6" y="5" width="3.5" height="8" rx="1"/><rect x="13.9" y="5" width="3.5" height="8" rx="1"/>'],
    ['2x2', '2 + 2', [[0, 0, 2, 1], [2, 0, 2, 1], [0, 1, 2, 1], [2, 1, 2, 1]], '<rect x="4" y="3" width="4.6" height="5" rx="1"/><rect x="9.4" y="3" width="4.6" height="5" rx="1"/><rect x="4" y="10" width="4.6" height="5" rx="1"/><rect x="9.4" y="10" width="4.6" height="5" rx="1"/>'],
    ['1+2', '1 + 2', [[0, 0, 4, 1], [0, 1, 2, 1], [2, 1, 2, 1]], '<rect x="4" y="3" width="10" height="5.5" rx="1"/><rect x="4" y="10" width="4.6" height="5" rx="1"/><rect x="9.4" y="10" width="4.6" height="5" rx="1"/>'],
    ['2+1', '2 + 1', [[0, 0, 2, 1], [2, 0, 2, 1], [0, 1, 4, 1]], '<rect x="4" y="3" width="4.6" height="5" rx="1"/><rect x="9.4" y="3" width="4.6" height="5" rx="1"/><rect x="4" y="9.5" width="10" height="5.5" rx="1"/>'],
];
const gridCellsOf = (grid) => (GRIDS.find(([value]) => value === grid) || GRIDS[0])[2];
/** The double-width cell of `1 + 2` / `2 + 1` takes the largest icon that fits unless a size is set. */
const isDoubleCell = (grid, cell) => (grid === '1+2' && cell === 0) || (grid === '2+1' && cell === 2);

/**
 * A refresher with child scopes: a list that is rebuilt clears only its own trackers.
 * `track(read, apply)` / `refresh()` / `clear()` as `createRefresher`, plus `child()`.
 */
function createScope() {
    const own = createRefresher();
    let kids = [];
    return {
        track: own.track,
        refresh() {
            own.refresh();
            for (const kid of kids.slice()) kid.refresh();
        },
        clear() {
            own.clear();
            kids = [];
        },
        child() {
            const kid = createScope();
            kids.push(kid);
            return kid;
        },
    };
}

export function mountKalProps(root, ctx) {
    const { store, env } = ctx;
    const palette = colorPalette(env);
    const scope = createScope();
    const cleanups = [];
    const getDesign = () => store.getState().doc.design;
    const getContent = () => store.getState().doc.content;
    const styles = () => stylesOf(store);
    const selection = store.getState().ui.selection || { el: 'cal' };
    const part = selection.part || null;
    let position = null;

    // ---- bound controls ---------------------------------------------------------------------

    /** Controls bound to one style path; every control registers its own refresh in `sc`. */
    function createKit(sc) {
        const bound = (path, make, readValue, write) => {
            const binding = styleBinding(store, path, write);
            const read = readValue || binding.get;
            const control = make(binding, read());
            sc.track(read, (value) => control.setValue(value));
            return control;
        };
        return {
            scope: sc,
            number: (path, limit, unit, label, step) => bound(path, (b, value) => limitField({ limit, step, unit, label, value, attrs: b.attrs, onChange: b.onChange })),
            slider: (path, limit, unit, label, o = {}) =>
                bound(path, (b, value) => sliderRow({ label, ariaLabel: o.ariaLabel, limit, unit, value, attrs: { ...b.attrs, ...(o.attrs || {}) }, onStart: b.onStart, onChange: b.onChange, onCommit: b.onCommit }), null, o.write),
            color: (path, label, allowNone = false) => bound(path, (b, value) => colorPicker({ value, palette, label, allowNone, attrs: b.attrs, onChange: b.onChange })),
            toggle(path, label) {
                const binding = styleBinding(store, path);
                const control = switchRow({ label, checked: binding.get() === true, attrs: binding.attrs, onChange: binding.onChange });
                sc.track(binding.get, (value) => control.setChecked(value === true));
                return control;
            },
            /** `readValue` resolves a stored `null` ("follow the number side") to what is drawn. */
            side: (path, label, options, readValue) => bound(path, (b, value) => sideControl({ label, options, value, attrs: b.attrs, onChange: b.onChange }), readValue),
            align: (path, label, readValue) => bound(path, (b, value) => alignControl({ label, value, attrs: b.attrs, onChange: b.onChange }), readValue),
            valign: (path, label) => bound(path, (b, value) => valignControl({ label, value, attrs: b.attrs, onChange: b.onChange })),
            text: (path) => bound(path, (b, value) => textProps({ value, fonts: env.fonts, path, onChange: b.patch })),
            /** Font block + colour of a text style. */
            textBlock(path, colorLabel = 'Barva') {
                return [this.text(path), section(colorLabel, this.color(`${path}.color`, colorLabel))];
            },
            /** Shows `node` only while `test()` holds. */
            when(node, test) {
                const check = () => test(styles(), getDesign()) === true;
                setShown(node, check());
                sc.track(check, (shown) => setShown(node, shown));
                return node;
            },
        };
    }

    /**
     * A block whose children are rebuilt whenever `key()` changes (a string describing its
     * structure). `build(kit)` returns the nodes; the kit's trackers die with the block.
     */
    function dynamic(sc, key, build, className = 'cal-sub') {
        const host = h('div', { class: className });
        const sub = sc.child();
        const fill = () => {
            sub.clear();
            host.replaceChildren(...[].concat(build(createKit(sub))).filter(Boolean));
        };
        fill();
        sc.track(key, fill);
        return host;
    }

    const change = (path, value) => store.change((layout) => setStyle(layout, path, value));
    /** What a `null` alignment resolves to (§E.6): numbers follow the number side, free texts lie away from it. */
    const numberSide = () => styles().cal.numberSide;
    const awayFromNumber = () => (numberSide() === 'end' ? 'start' : 'end');

    // ---- small widgets ----------------------------------------------------------------------

    /** Plain text field writing on every keystroke; `setValue` leaves a focused field alone. */
    function nameField({ value, label, maxlength, placeholder, attrs, onInput }) {
        const input = h('input', { type: 'text', value: value || '', maxlength: String(maxlength), 'aria-label': label, placeholder: placeholder || null, autocomplete: 'off', ...(attrs || {}) });
        input.addEventListener('input', () => onInput(input.value));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            }
        });
        const el = h('span', { class: 'inp sm cal-icn' }, input);
        el.input = input;
        el.setValue = (next) => {
            if (document.activeElement !== input && input.value !== (next || '')) input.value = next || '';
        };
        return el;
    }

    /**
     * Small upload tile of one icon: the built-in drawing (or the uploaded picture) on paper; a
     * click picks a file. `draw()` returns SVG markup for the 24 × 24 preview box.
     * @returns `label.cal-icp` with `setValue(assetId | null)` and `redraw()`
     */
    function iconTile({ value, slot, label, draw, onChange }) {
        let current = value || null;
        let busy = false;
        const input = h('input', { type: 'file', class: 'sr', accept: ICON_ACCEPT, 'aria-label': label, 'data-upload': 'icon', 'data-icon-slot': slot });
        const view = h('span', { class: 'cal-icv', 'aria-hidden': 'true' });
        const el = h('label', { class: 'cal-icp' }, input, view);

        function paint() {
            const asset = current ? env.assets[current] : null;
            const src = asset ? asset.previewUrl || asset.url : null;
            if (src) view.replaceChildren(h('img', { src, alt: '' }));
            else view.innerHTML = `<svg viewBox="0 0 24 24">${draw()}</svg>`;
            el.title = current ? 'Vyměnit vlastní ikonu' : 'Nahrát vlastní ikonu (SVG, PNG)';
            if (current) el.dataset.asset = current;
            else delete el.dataset.asset;
            if (busy) el.dataset.busy = '1';
            else delete el.dataset.busy;
        }

        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            input.value = '';
            if (!file || busy) return;
            const limit = Number(env.maxFileSize) || 0;
            if (limit > 0 && file.size > limit) {
                ctx.toast('Soubor je větší než 10 MB.', true);
                return;
            }
            busy = true;
            paint();
            try {
                const info = await ctx.api.upload(file, 'icon');
                store.addAsset(info);
                busy = false;
                current = info.id;
                paint();
                onChange(info.id);
                ctx.toast('Vlastní ikona nahrána');
            } catch (error) {
                busy = false;
                paint();
                ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
            }
        });

        paint();
        el.setValue = (next) => {
            if ((next || null) === current) return;
            current = next || null;
            paint();
        };
        el.redraw = paint;
        return el;
    }

    /** Text button putting the built-in icon back; shown only while a custom icon is set. */
    function resetButton(sc, slot, text, read, onReset, iconOnly = false) {
        const button = iconOnly
            ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': text, title: text, 'data-icon-reset': slot, onClick: onReset }, icon('undo', 's16'))
            : h('button', { type: 'button', class: 'cal-mphr', 'data-icon-reset': slot, onClick: onReset }, text);
        const has = () => Boolean(read());
        setShown(button, has());
        sc.track(has, (shown) => setShown(button, shown));
        return button;
    }

    /** A button that opens a small menu under itself (`Přidat ikonu`, `Přidat druh odpadu`). */
    function addMenu({ label, action, itemAttr, items, onPick, disabledTip }) {
        let open = false;
        let off = null;
        const button = h('button', { type: 'button', class: 'btn sm btn-ghost cal-add', 'data-cal-action': action, 'aria-expanded': 'false', 'aria-haspopup': 'menu', onClick: () => toggle(!open) }, icon('plus', 's16'), label);
        const menu = h('div', { class: 'cal-icmenu', role: 'menu', hidden: true });
        const el = h('div', { class: 'cal-icadd' }, button, menu);

        function toggle(next) {
            const list = items();
            open = next && list.length > 0;
            button.setAttribute('aria-expanded', String(open));
            menu.hidden = !open;
            if (off) off();
            off = null;
            if (!open) return;
            menu.replaceChildren(
                ...list.map((item) =>
                    h(
                        'button',
                        {
                            type: 'button',
                            role: 'menuitem',
                            [itemAttr]: item.key,
                            onClick: () => {
                                toggle(false);
                                onPick(item.key);
                            },
                        },
                        item.label,
                    ),
                ),
            );
            off = onOutside(el, () => toggle(false));
        }
        el.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !open) return;
            // Esc closes the menu, not the part
            event.preventDefault();
            event.stopPropagation();
            toggle(false);
            button.focus();
        });
        el.sync = () => {
            const none = items().length === 0;
            button.disabled = none;
            button.title = none && disabledTip ? disabledTip : '';
            if (none && open) toggle(false);
        };
        cleanups.push(() => off && off());
        el.sync();
        return el;
    }

    // ---- icon order (Ikony v řádku, Fáze měsíce › Pozice) -----------------------------------------

    const activeOrder = () => activeIconCategories(getDesign());

    /** Writes a new order of the ACTIVE categories; switched-off ones keep their slots. */
    function writeActiveOrder(next) {
        const current = activeOrder();
        if (next.length !== current.length || next.every((key, i) => key === current[i])) return;
        store.change((layout) => {
            let i = 0;
            return setStyle(layout, 'icons.order', layout.styles.icons.order.map((key) => (current.includes(key) ? next[i++] : key)));
        });
    }

    const activeWasteKinds = () => sorted(getContent().wasteKinds || {}, 'pos').filter(([, kind]) => kind.on);

    function categoryName(key) {
        if (key === 'ev') return styles().icons.ev.name || 'Akce';
        return CATEGORY_LABEL[key] || key;
    }

    /**
     * The ordered list of icon categories. Drag a row (SortableJS) or press Alt+↑ / Alt+↓ on a
     * focused row. `expand(key, kit)` (optional) gives the settings shown under an opened row.
     */
    function orderList(sc, { highlight = null, expand = null } = {}) {
        let opened = null;
        let sortable = null;
        const list = h('div', { class: 'cal-ord', 'data-prop': 'icons.order' });
        const empty = h('span', { class: 'cap' }, 'Zatím žádné ikony.');
        const sub = sc.child();

        function move(key, delta) {
            const order = activeOrder();
            const from = order.indexOf(key);
            const to = from + delta;
            if (from < 0 || to < 0 || to >= order.length) return;
            order.splice(to, 0, order.splice(from, 1)[0]);
            writeActiveOrder(order);
            const row = list.querySelector(`[data-icon-order="${key}"]`);
            if (row) row.focus({ preventScroll: true });
        }

        function fill() {
            sub.clear();
            const kit = createKit(sub);
            const order = activeOrder();
            if (opened && !order.includes(opened)) opened = null;
            setShown(empty, order.length === 0);
            setShown(list, order.length > 0);
            list.replaceChildren(
                ...order.map((key, index) => {
                    const isOpen = Boolean(expand) && opened === key;
                    const title = h('span', { class: 'cal-ordtt' }, h('span', { class: 'cal-ordtn' }, categoryName(key)));
                    if (key === 'ev') sub.track(() => categoryName('ev'), (text) => (title.firstChild.textContent = text));
                    if (key === 'waste') {
                        const names = () => activeWasteKinds().map(([, kind]) => kind.name).join(', ');
                        const cap = h('span', { class: 'cap' }, names());
                        sub.track(names, (text) => (cap.textContent = text));
                        title.append(cap);
                    }
                    const row = h(
                        'div',
                        {
                            class: `cal-ordr drg${highlight === key ? ' hl' : ''}`,
                            tabindex: '0',
                            role: 'group',
                            'aria-label': `${index + 1}. ${categoryName(key)}`,
                            'data-icon-order': key,
                            onKeydown: (event) => {
                                if (!event.altKey || event.target !== row || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
                                event.preventDefault();
                                move(key, event.key === 'ArrowUp' ? -1 : 1);
                            },
                        },
                        h('span', { class: 'cal-grip', 'aria-hidden': 'true', title: 'Přetáhněte pro změnu pořadí' }, icon('grip', 's14')),
                        h('span', { class: 'cal-ordn' }, String(index + 1)),
                        expand
                            ? h(
                                  'button',
                                  {
                                      type: 'button',
                                      class: 'cal-ordt cal-ordb',
                                      'data-icon-open': key,
                                      'aria-expanded': String(isOpen),
                                      onClick: () => {
                                          opened = isOpen ? null : key;
                                          fill();
                                          const again = list.querySelector(`[data-icon-open="${key}"]`);
                                          if (again) again.focus({ preventScroll: true });
                                      },
                                  },
                                  title,
                                  icon('chev-down', 's14'),
                              )
                            : h('span', { class: 'cal-ordt' }, title),
                    );
                    return h('div', { class: `cal-orw${isOpen ? ' open' : ''}`, 'data-icon-item': key }, row, isOpen ? h('div', { class: 'cal-orx' }, ...[].concat(expand(key, kit)).filter(Boolean)) : null);
                }),
            );
        }

        fill();
        sc.track(() => activeOrder().join(','), fill);
        sortable = Sortable.create(list, {
            draggable: '.cal-orw',
            handle: '.cal-ordr',
            forceFallback: true,
            fallbackTolerance: 4,
            animation: 120,
            ghostClass: 'cal-ord-ghost',
            chosenClass: 'cal-ord-chosen',
            dragClass: 'cal-ord-drag',
            onStart: () => document.documentElement.classList.add('is-sort-dragging'),
            onEnd() {
                document.documentElement.classList.remove('is-sort-dragging');
                const next = [...list.querySelectorAll(':scope > [data-icon-item]')].map((el) => el.dataset.iconItem);
                writeActiveOrder(next);
                // the DOM is put back in the order of the state (also when nothing changed)
                fill();
            },
        });
        cleanups.push(() => sortable.destroy());

        return h('div', { class: 'cal-sub' }, empty, list, note('1 = nejblíž ke kraji řádku, na druhé straně než čísla.'));
    }

    const removeFromIcons = (key) =>
        h(
            'button',
            { type: 'button', class: 'btn xs btn-ghost cal-icrm', 'data-icon-remove': key, onClick: () => change('icons.order', styles().icons.order.filter((k) => k !== key)) },
            icon('trash', 's14'),
            'Odebrat z ikon',
        );

    // ---- waste kinds (Odpady part, Ikony › Odpady) — content shortcut of D2 ---------------------

    function putKind(id, patch) {
        const kind = (getContent().wasteKinds || {})[id];
        if (kind) store.putItem('wasteKinds', id, { ...kind, ...patch });
    }

    function patchKindStyle(id, patch) {
        store.change((layout) => {
            const kinds = layout.styles.waste.kinds || {};
            return setStyle(layout, 'waste.kinds', { ...kinds, [id]: { color: null, icon: null, ...(kinds[id] || {}), ...patch } });
        });
    }

    async function removeKind(id) {
        const kind = (getContent().wasteKinds || {})[id];
        if (!kind) return;
        const ok = await ctx.confirm({ title: `Odebrat druh odpadu „${kind.name}“?`, text: 'Termíny a nastavení se smažou.', confirmLabel: 'Odebrat', danger: true });
        if (!ok || !(getContent().wasteKinds || {})[id]) return;
        const extras = Object.entries(getContent().wasteExtras || {}).filter(([, extra]) => extra.kind === id);
        store.applyOps([{ c: 'wasteKinds', id, v: null }, ...extras.map(([extraId]) => ({ c: 'wasteExtras', id: extraId, v: null }))]);
    }

    /** What `Přidat druh odpadu` offers: kinds that exist but are off, missing built-ins, an own kind. */
    function addableKinds() {
        const all = getContent().wasteKinds || {};
        const used = new Set(Object.values(all).map((kind) => kind.builtin).filter(Boolean));
        return [
            ...sorted(all, 'pos')
                .filter(([, kind]) => !kind.on)
                .map(([id, kind]) => ({ key: id, label: kind.name || 'Bez názvu' })),
            ...Object.entries(BUILTIN_WASTE)
                .filter(([key]) => !used.has(key))
                .map(([key, def]) => ({ key: `new:${key}`, label: def.name })),
            { key: 'own', label: 'Vlastní druh…' },
        ];
    }

    function addKind(key, host) {
        const all = getContent().wasteKinds || {};
        let id = key;
        if (all[key]) {
            putKind(key, { on: true });
        } else {
            const builtin = key.startsWith('new:') ? key.slice(4) : null;
            id = builtin && !all[builtin] ? builtin : newId();
            store.putItem('wasteKinds', id, { builtin, name: builtin ? BUILTIN_WASTE[builtin].name : 'Nový druh odpadu', on: true, place: '', mode: 'rule', weekdays: [1], interval: '2e', from: null, to: null, dates: [], pos: nextPos(all) });
        }
        const input = host.querySelector(`[data-waste-kind="${id}"] [data-waste-kind-name]`);
        if (input && key === 'own') {
            input.focus();
            input.select();
        }
    }

    function wasteKinds(sc) {
        const host = dynamic(
            sc,
            () =>
                activeWasteKinds()
                    .map(([id]) => `${id}:${(styles().waste.kinds[id] || {}).icon ? 1 : 0}`)
                    .join(','),
            (kit) =>
                activeWasteKinds().map(([id, kind]) => {
                    const own = () => styles().waste.kinds[id] || {};
                    const liveKind = () => (getContent().wasteKinds || {})[id] || kind;
                    const color = () => wasteKindColor(styles(), id, liveKind());
                    const tile = iconTile({ value: own().icon, slot: `waste:${id}`, label: `Vlastní ikona: ${kind.name}`, draw: () => binIcon(PREVIEW_BOX, color(), ''), onChange: (asset) => patchKindStyle(id, { icon: asset }) });
                    kit.scope.track(color, () => tile.redraw());
                    const name = nameField({ value: kind.name, label: 'Název', maxlength: 30, attrs: { 'data-waste-kind-name': '' }, onInput: (text) => putKind(id, { name: text }) });
                    kit.scope.track(() => liveKind().name, (text) => name.setValue(text));
                    const remove = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': `Odebrat ${kind.name}`, title: 'Odebrat', 'data-waste-kind-remove': '', onClick: () => removeKind(id) }, icon('trash', 's16'));
                    kit.scope.track(() => liveKind().name, (text) => remove.setAttribute('aria-label', `Odebrat ${text}`));
                    let second;
                    if (own().icon) {
                        second = h('span', { class: 'cal-icc' }, h('span', { class: 'cap' }, 'Vlastní ikona'), h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-icon-reset': `waste:${id}`, onClick: () => patchKindStyle(id, { icon: null }) }, icon('undo', 's14'), 'Vrátit původní'));
                    } else {
                        const picker = colorPicker({ value: color(), palette, label: `Barva: ${kind.name}`, attrs: { 'data-prop': `waste.kinds.${id}.color` }, onChange: (value) => patchKindStyle(id, { color: value }) });
                        kit.scope.track(color, (value) => picker.setValue(value));
                        second = h('span', { class: 'cal-icc' }, picker);
                    }
                    return h('div', { class: 'cal-icr', 'data-waste-kind': id }, tile, name, remove, second);
                }),
            'cal-icl',
        );
        const add = addMenu({ label: 'Přidat druh odpadu', action: 'waste-kind-add', itemAttr: 'data-waste-add', items: addableKinds, onPick: (key) => addKind(key, host) });
        return [host, add];
    }

    // ---- moon phases, single icons -------------------------------------------------------------

    function moonPhases(kit) {
        const sc = kit.scope;
        return h(
            'div',
            { class: 'cal-mph' },
            ...MOON_PHASES.map((name, phase) => {
                const on = () => styles().moon.phases[phase] !== false;
                const own = () => styles().moon.icons[phase] || null;
                const check = checkRow({ label: h('span', { class: 'sr' }, `Zobrazovat ${name}`), checked: on(), attrs: { 'data-prop': `moon.phases.${phase}` }, onChange: (value) => change(`moon.phases.${phase}`, value) });
                check.classList.add('cal-mphk');
                const tile = iconTile({ value: own(), slot: `moon:${phase}`, label: `Vlastní ikona: ${name}`, draw: () => moonIcon(PREVIEW_BOX, phase, styles().moon.color), onChange: (asset) => change(`moon.icons.${phase}`, asset) });
                const row = h('div', { class: `cal-mphc${on() ? '' : ' off'}` }, check, tile, h('span', { class: 'cal-mphn' }, name), resetButton(sc, `moon:${phase}`, 'Vrátit společnou', own, () => change(`moon.icons.${phase}`, null)));
                sc.track(on, (value) => {
                    check.setChecked(value);
                    row.classList.toggle('off', !value);
                });
                sc.track(own, (value) => tile.setValue(value));
                sc.track(() => styles().moon.color, () => tile.redraw());
                return row;
            }),
        );
    }

    /** Stepper (0.2 mm) + slider (0.1 mm) of the moon size — two controls of one value. */
    function moonSize(kit) {
        return h('div', { class: 'cal-szr' }, kit.number('moon.size', 'moonSize', 'mm', 'Velikost', 0.2), kit.slider('moon.size', 'moonSize', 'mm', '', { ariaLabel: 'Velikost ikonky', attrs: { 'data-size-slider': 'moon' } }));
    }

    /** One custom-icon row: tile · „Výchozí ikona“ / „Vlastní ikona“ · reset. */
    function singleIcon(kit, { path, slot, label, draw, redrawOn }) {
        const sc = kit.scope;
        const read = () => getPath(styles(), path) || null;
        const tile = iconTile({ value: read(), slot, label, draw, onChange: (asset) => change(path, asset) });
        const caption = h('span', { class: 'cap' }, read() ? 'Vlastní ikona' : 'Výchozí ikona');
        sc.track(read, (value) => {
            tile.setValue(value);
            caption.textContent = value ? 'Vlastní ikona' : 'Výchozí ikona';
        });
        if (redrawOn) sc.track(redrawOn, () => tile.redraw());
        return h('div', { class: 'cal-icr one' }, tile, caption, resetButton(sc, slot, 'Vrátit výchozí ikonu', read, () => change(path, null), true));
    }

    // ---- panels -------------------------------------------------------------------------------

    function blockPanel(kit) {
        const features = getDesign().features;
        position = positionSection(ctx, 'cal');
        const allNumbers = (layout, value) => NUMBER_PARTS.reduce((next, key) => setStyle(next, `${key}.size`, value), layout);
        const size = (key, path, label, limit, unit, write) => kit.slider(path, limit, unit, label, { attrs: { 'data-size': key }, write });
        const sliders = [size('num', 'num.size', 'Čísla', 'sliderNum', 'pt', allNumbers), size('day', 'day.size', 'Den v týdnu', 'sliderDay', 'pt'), size('name', 'name.size', 'Jména', 'sliderText', 'pt'), size('hol', 'hol.size', 'Svátek', 'sliderText', 'pt')];
        if (features.timeChange || features.seasons) sliders.push(size('time', 'time.size', 'Změna času', 'sliderText', 'pt'));
        if (features.events) sliders.push(size('ev', 'ev.size', 'Akce', 'sliderText', 'pt'));
        if (features.shifts) sliders.push(size('shift', 'shift.size', 'Šichtovnice', 'sliderText', 'pt'));
        sliders.push(kit.when(size('icon', 'cal.iconSize', 'Ikony', 'iconSize', 'mm'), () => activeOrder().length > 0));
        if (features.moon) sliders.push(kit.when(size('moon', 'moon.size', 'Fáze měsíce', 'moonSize', 'mm'), () => activeOrder().includes('moon')));

        return [
            panelHead(elementLabel('cal'), ALL_PAGES),
            position.el,
            section(
                'Názvy dnů',
                kit.side('cal.dayFormat', 'Názvy dnů', [
                    { value: 'full', label: 'Celé' },
                    { value: 'short', label: 'Zkratky' },
                ]),
            ),
            section(
                'Den v týdnu',
                kit.side('cal.dayPosition', 'Den v týdnu', [
                    { value: 'side', label: 'Vedle čísla' },
                    { value: 'above', label: 'Nad číslem' },
                ]),
                kit.when(
                    h(
                        'div',
                        { class: 'cal-sub' },
                        h('span', { class: 'lbl' }, 'Zarovnání dne'),
                        kit.side('cal.dayAlign', 'Zarovnání dne', SIDES, () => styles().cal.dayAlign || styles().cal.numAlign || numberSide()),
                    ),
                    (s) => s.cal.dayPosition === 'above',
                ),
            ),
            section('Číslo dne', kit.side('cal.numberSide', 'Číslo dne', SIDES)),
            section('Pozadí kalendária', kit.color('cal.bg.color', 'Pozadí kalendária', true), kit.when(kit.slider('cal.bg.opacity', 'percent', '%', 'Krytí'), (s) => Boolean(s.cal.bg.color))),
            section('Velikosti', h('div', { class: 'cal-szl' }, ...sliders), note('Barvy a písma jednotlivých částí upravíte ve Vrstvách › Kalendárium.')),
        ];
    }

    const PART_PANELS = {
        number: (kit) => [section('Zarovnání (všechna čísla)', kit.align('cal.numAlign', 'Zarovnání (všechna čísla)', () => styles().cal.numAlign || numberSide())), ...kit.textBlock(part)],

        day: (kit) => [...kit.textBlock('day', 'Barva — všední dny'), section('Sobota', kit.color('cal.satColor', 'Sobota')), section('Neděle a státní svátky', kit.color('cal.sunColor', 'Neděle a státní svátky'))],

        name: (kit) => kit.textBlock('name'),

        hol: (kit) => kit.textBlock('hol'),

        line: (kit) => [
            section(null, kit.toggle('lines.on', 'Linky mezi dny')),
            kit.when(
                h(
                    'div',
                    { class: 'cal-props-more' },
                    section('Tloušťka', kit.number('lines.width', 'lineWidth', 'pt', 'Tloušťka')),
                    section(
                        'Styl',
                        kit.side('lines.style', 'Styl linky', [
                            { value: 'solid', label: 'Plná' },
                            { value: 'dash', label: 'Čárkovaná' },
                            { value: 'dot', label: 'Tečkovaná' },
                        ]),
                    ),
                    section('Barva linek', kit.color('lines.color', 'Barva linek')),
                ),
                (s) => s.lines.on,
            ),
        ],

        moon: (kit) => [
            kit.when(section('Pozice', orderList(kit.scope, { highlight: 'moon' })), () => activeOrder().length > 1 && activeOrder().includes('moon')),
            section('Velikost ikonky', moonSize(kit)),
            section('Svislé zarovnání', kit.valign('moon.valign', 'Svislé zarovnání')),
            section('Barva', kit.color('moon.color', 'Barva')),
            section('Fáze', moonPhases(kit)),
        ],

        time(kit) {
            const caption = note('');
            const text = () => (styles().time.position === 'sep' ? 'Vlastní místo v řádku dne — zarovnání níže.' : 'Pod jménem a svátkem, ve stejném sloupci.');
            caption.textContent = text();
            kit.scope.track(text, (value) => (caption.textContent = value));
            return [
                ...kit.textBlock('time'),
                section(
                    'Kde se zobrazí',
                    kit.side('time.position', 'Kde se zobrazí', [
                        { value: 'text', label: 'S textem dne' },
                        { value: 'sep', label: 'Samostatně' },
                    ]),
                    caption,
                ),
                kit.when(
                    h('div', { class: 'cal-props-more' }, section('Zarovnání', kit.align('time.align', 'Zarovnání', () => styles().time.align || awayFromNumber())), section('Svislé zarovnání', kit.valign('time.valign', 'Svislé zarovnání'))),
                    (s) => s.time.position === 'sep',
                ),
            ];
        },

        ev(kit) {
            const reset = h(
                'button',
                {
                    type: 'button',
                    class: 'btn xs btn-ghost cal-add',
                    'data-cal-action': 'evbox-reset',
                    onClick: () =>
                        store.change((layout) => {
                            const offsets = { ...layout.styles.offsets };
                            delete offsets.ev;
                            return { ...layout, styles: { ...layout.styles, ev: { ...layout.styles.ev, box: null }, offsets } };
                        }),
                },
                icon('undo', 's14'),
                'Výchozí velikost pole',
            );
            return [
                ...kit.textBlock('ev'),
                section('Zarovnání', kit.align('ev.align', 'Zarovnání', () => styles().ev.align || awayFromNumber())),
                section('Svislé zarovnání', kit.valign('ev.valign', 'Svislé zarovnání')),
                section(
                    'Pole pro akce',
                    note('Červeně čárkované pole v každém řádku — chyťte ho a posuňte, úchyty měňte velikost. Co se nevejde, ukáže vykřičník.'),
                    kit.when(reset, (s) => Boolean(s.ev.box || s.offsets.ev)),
                ),
            ];
        },

        shift(kit) {
            const ops = () => sorted(getContent().shiftOps || {}, 'pos');
            const put = (id, patch) => {
                const op = (getContent().shiftOps || {})[id];
                if (op) store.putItem('shiftOps', id, { ...op, ...patch });
            };
            async function remove(id) {
                const op = (getContent().shiftOps || {})[id];
                if (!op) return;
                const ok = await ctx.confirm({ title: `Odebrat provoz „${op.name}“?`, text: 'Termíny a nastavení se smažou.', confirmLabel: 'Odebrat', danger: true });
                if (ok && (getContent().shiftOps || {})[id]) store.removeItem('shiftOps', id);
            }
            const columns = dynamic(
                kit.scope,
                () =>
                    ops()
                        .map(([id]) => id)
                        .join(','),
                (sub) => {
                    const list = ops();
                    return list.map(([id, op], index) => {
                        const live = () => (getContent().shiftOps || {})[id] || op;
                        const name = nameField({ value: op.name, label: 'Název sloupce', maxlength: 30, attrs: { 'data-shift-col-name': '' }, onInput: (text) => put(id, { name: text }) });
                        name.classList.remove('cal-icn');
                        sub.scope.track(() => live().name, (text) => name.setValue(text));
                        const del = list.length > 1 ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': `Odebrat sloupec ${op.name}`, title: 'Odebrat', 'data-shift-col-remove': '', onClick: () => remove(id) }, icon('trash', 's14')) : null;
                        if (del) sub.scope.track(() => live().name, (text) => del.setAttribute('aria-label', `Odebrat sloupec ${text}`));
                        return h('div', { class: 'cal-ordr', 'data-shift-col': id }, h('span', { class: 'cal-ordn' }, String(index + 1)), name, del);
                    });
                },
                'cal-ord',
            );
            const add = h(
                'button',
                {
                    type: 'button',
                    class: 'btn sm btn-ghost cal-add',
                    'data-cal-action': 'shift-col-add',
                    onClick: () => {
                        const all = getContent().shiftOps || {};
                        store.putItem('shiftOps', newId(), { ...defaultOperation(Object.keys(all).length + 1), pos: nextPos(all) });
                    },
                },
                icon('plus', 's16'),
                'Přidat sloupec',
            );
            return [
                ...kit.textBlock('shift'),
                section(
                    'Pozadí sloupců',
                    kit.side('shift.bgMode', 'Pozadí sloupců', [
                        { value: 'alt', label: 'Střídavě' },
                        { value: 'all', label: 'Všechny' },
                        { value: 'none', label: 'Žádné' },
                    ]),
                    kit.when(h('div', { class: 'cal-sub' }, h('span', { class: 'lbl' }, 'Barva pozadí'), kit.color('shift.bg', 'Barva pozadí', true)), (s) => s.shift.bgMode !== 'none'),
                ),
                section('Sloupce', columns, add, note('Rozpis směn (8 h / 12 h) nastavíte v kroku Obsah.')),
            ];
        },

        waste(kit) {
            const shown = () => styles().icons.order.includes('waste');
            const toggle = switchRow({
                label: 'Zobrazovat odpady',
                checked: shown(),
                attrs: { 'data-prop': 'icons.order.waste' },
                onChange: (on) => change('icons.order', on ? ['waste', ...styles().icons.order.filter((k) => k !== 'waste')] : styles().icons.order.filter((k) => k !== 'waste')),
            });
            kit.scope.track(shown, (value) => toggle.setChecked(value));
            return [
                section(null, toggle, kit.when(h('div', { class: 'cal-sub' }, ...wasteKinds(kit.scope)), shown)),
                kit.when(
                    h(
                        'div',
                        { class: 'cal-props-more' },
                        section('Text u ikon', kit.toggle('cal.placeLabels', 'Místo svozu vedle ikony')),
                        kit.when(h('div', { class: 'cal-props-more' }, ...kit.textBlock('placeText', 'Barva textu')), (s) => s.cal.placeLabels),
                    ),
                    shown,
                ),
            ];
        },

        icon(kit) {
            const sc = kit.scope;
            const grid = () => styles().cal.iconGrid;
            const cellIndex = () => Math.min(Number(store.getState().ui.iconCell) || 0, gridCellsOf(grid()).length - 1);
            const cellConf = () => ((styles().cal.iconCells || {})[grid()] || {})[cellIndex()] || {};
            const writeCell = (patch) =>
                store.change((layout) => {
                    const g = layout.styles.cal.iconGrid;
                    const all = layout.styles.cal.iconCells || {};
                    const cells = all[g] || {};
                    const index = cellIndex();
                    return setStyle(layout, 'cal.iconCells', { ...all, [g]: { ...cells, [index]: { size: null, align: 'middle', ...(cells[index] || {}), ...patch } } });
                });

            // Rozvržení ikon v řádku
            const gridButtons = GRIDS.map(([value, title, , glyph]) => h('button', { type: 'button', role: 'radio', 'data-value': value, 'aria-label': title, title, onClick: () => value !== grid() && change('cal.iconGrid', value), html: `<svg viewBox="0 0 18 18" width="22" height="22" fill="currentColor" aria-hidden="true">${glyph}</svg>` }));
            const gridGroup = h('div', { class: 'seg soft cal-seg cal-icgr', role: 'radiogroup', 'aria-label': 'Rozvržení ikon', 'data-prop': 'cal.iconGrid' }, ...gridButtons);
            const paintGrid = (value) => {
                for (const button of gridButtons) {
                    const on = button.dataset.value === value;
                    button.classList.toggle('on', on);
                    button.setAttribute('aria-checked', String(on));
                }
            };
            paintGrid(grid());
            sc.track(grid, paintGrid);

            // Buňka: the diagram is rebuilt per layout, size and alignment follow the chosen cell
            const map = dynamic(
                sc,
                () => `${grid()}|${cellIndex()}`,
                () =>
                    gridCellsOf(grid()).map(([x, y, w, hh], index) =>
                        h(
                            'button',
                            {
                                type: 'button',
                                class: index === cellIndex() ? 'on' : '',
                                style: `grid-column:${x + 1} / span ${w};grid-row:${y + 1} / span ${hh}`,
                                'data-icon-cell': String(index),
                                'aria-pressed': String(index === cellIndex()),
                                'aria-label': `Buňka ${index + 1}`,
                                onClick: () => store.setUi({ iconCell: index }),
                            },
                            String(index + 1),
                        ),
                    ),
                'cal-icmap',
            );
            const spec = LIMITS.iconCellSize;
            const sizeInput = h('input', { type: 'text', inputmode: 'decimal', autocomplete: 'off', spellcheck: 'false', placeholder: 'max', 'aria-label': 'Velikost ikony', 'data-cell-size': '' });
            /** The size a cell uses: its own, else the default — a double cell without one takes what fits (`null`). */
            const cellSize = () => {
                const own = cellConf().size;
                if (own) return own;
                return isDoubleCell(grid(), cellIndex()) ? null : styles().cal.iconSize;
            };
            const showSize = () => {
                const value = cellSize();
                sizeInput.value = value === null ? '' : formatNumber(value, 1);
            };
            const setSize = (value) => {
                const next = value === null ? null : clampLimit('iconCellSize', value);
                if (next !== (cellConf().size || null) || next === null) writeCell({ size: next });
                showSize();
            };
            const step = (direction) => {
                const base = cellSize();
                setSize(base === null ? styles().cal.iconSize : Math.round((base + direction * spec.step) / spec.step) * spec.step);
            };
            sizeInput.addEventListener('change', () => {
                const text = sizeInput.value.trim();
                if (text === '') {
                    // an empty field = no own size (the default; "max" in a double cell)
                    if (cellConf().size) writeCell({ size: null });
                    showSize();
                    return;
                }
                const typed = parseNumber(text);
                if (Number.isNaN(typed)) showSize();
                else setSize(typed);
            });
            sizeInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    sizeInput.blur();
                } else if (event.key === 'Escape') {
                    showSize();
                    sizeInput.blur();
                } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    step(event.key === 'ArrowUp' ? 1 : -1);
                }
            });
            sizeInput.addEventListener('focus', () => sizeInput.select());
            showSize();
            sc.track(() => `${grid()}|${cellIndex()}|${cellSize()}`, () => document.activeElement !== sizeInput && showSize());
            const sizeField = h(
                'span',
                { class: 'cal-num', role: 'group', 'aria-label': 'Velikost ikony v buňce' },
                h('button', { type: 'button', 'aria-label': 'Menší', tabindex: '-1', onClick: () => step(-1) }, icon('minus', 's14')),
                sizeInput,
                h('span', { class: 'u', 'aria-hidden': 'true' }, 'mm'),
                h('button', { type: 'button', 'aria-label': 'Větší', tabindex: '-1', onClick: () => step(1) }, icon('plus', 's14')),
            );
            const cellAlign = alignControl({ label: 'Zarovnání v buňce', value: cellConf().align || 'middle', attrs: { 'data-cell-align': '' }, onChange: (value) => writeCell({ align: value }) });
            sc.track(() => cellConf().align || 'middle', (value) => cellAlign.setValue(value));

            // Ikony v řádku
            const expand = (key, sub) => {
                if (key === 'moon') return [h('span', { class: 'lbl' }, 'Fáze'), moonPhases(sub), h('span', { class: 'lbl' }, 'Velikost'), moonSize(sub), h('span', { class: 'lbl' }, 'Barva'), sub.color('moon.color', 'Barva'), removeFromIcons(key)];
                if (key === 'waste') return [...wasteKinds(sub.scope), removeFromIcons(key)];
                if (key === 'ev') {
                    const perEvent = () => styles().icons.ev.perEvent === true;
                    const name = nameField({ value: styles().icons.ev.name, label: 'Název', maxlength: 30, placeholder: 'Ikona akce', attrs: { 'data-prop': 'icons.ev.name' }, onInput: (text) => change('icons.ev.name', text) });
                    sub.scope.track(() => styles().icons.ev.name, (text) => name.setValue(text));
                    const tile = iconTile({ value: styles().icons.ev.asset, slot: 'ev', label: 'Vlastní ikona: Akce', draw: () => starIcon(PREVIEW_BOX, styles().ev.color, ''), onChange: (asset) => change('icons.ev.asset', asset) });
                    sub.scope.track(() => styles().icons.ev.asset, (value) => tile.setValue(value));
                    sub.scope.track(() => styles().ev.color, () => tile.redraw());
                    const own = h('span', { class: 'cal-icc' }, h('span', { class: 'cap' }, 'Vlastní ikona'), h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-icon-reset': 'ev', onClick: () => change('icons.ev.asset', null) }, icon('undo', 's14'), 'Vrátit původní'));
                    const row = h('div', { class: 'cal-icr' }, tile, name, h('span'), sub.when(own, (s) => Boolean(s.icons.ev.asset) && !s.icons.ev.perEvent));
                    sub.scope.track(perEvent, (value) => row.classList.toggle('noicon', value));
                    row.classList.toggle('noicon', perEvent());
                    const each = checkRow({ label: 'Každá akce má vlastní ikonu', checked: perEvent(), attrs: { 'data-prop': 'icons.ev.perEvent' }, onChange: (value) => change('icons.ev.perEvent', value) });
                    sub.scope.track(perEvent, (value) => each.setChecked(value));
                    return [row, each, sub.when(note('Ikonu ke každé akci nahrajete v kroku Obsah › Akce.'), (s) => s.icons.ev.perEvent === true), removeFromIcons(key)];
                }
                if (key === 'season') {
                    const color = () => styles().icons.season.color;
                    const four = h(
                        'div',
                        { class: 'cal-mph' },
                        ...SEASONS.map((name, q) => {
                            const own = () => styles().icons.season.assets[q] || null;
                            const tile = iconTile({ value: own(), slot: `season:${q}`, label: `Vlastní ikona: ${name}`, draw: () => seasonIcon(PREVIEW_BOX, q, color(), ''), onChange: (asset) => change(`icons.season.assets.${q}`, asset) });
                            sub.scope.track(own, (value) => tile.setValue(value));
                            sub.scope.track(color, () => tile.redraw());
                            return h('div', { class: 'cal-mphc nock' }, tile, h('span', { class: 'cal-mphn' }, name), resetButton(sub.scope, `season:${q}`, 'Vrátit výchozí', own, () => change(`icons.season.assets.${q}`, null)));
                        }),
                    );
                    return [
                        sub.side('icons.season.mode', 'Počet ikon', [
                            { value: 'four', label: '4 ikony' },
                            { value: 'one', label: '1 ikona' },
                        ]),
                        sub.when(four, (s) => s.icons.season.mode !== 'one'),
                        sub.when(singleIcon(sub, { path: 'icons.season.asset', slot: 'season', label: 'Vlastní ikona: Změna ročního období', draw: () => seasonIcon(PREVIEW_BOX, 2, color(), ''), redrawOn: color }), (s) => s.icons.season.mode === 'one'),
                        sub.when(note('Každé období má vlastní symbol: jaro, léto, podzim, zima.'), (s) => s.icons.season.mode !== 'one' && s.icons.season.assets.every((a) => !a)),
                        h('span', { class: 'lbl' }, 'Barva'),
                        sub.color('icons.season.color', 'Barva'),
                        removeFromIcons(key),
                    ];
                }
                if (key === 'time') {
                    const color = () => styles().icons.time.color;
                    return [
                        singleIcon(sub, { path: 'icons.time.asset', slot: 'time', label: 'Vlastní ikona: Změna času', draw: () => clockIcon(PREVIEW_BOX, color(), ''), redrawOn: color }),
                        sub.when(h('div', { class: 'cal-sub' }, h('span', { class: 'lbl' }, 'Barva'), sub.color('icons.time.color', 'Barva')), (s) => !s.icons.time.asset),
                        removeFromIcons(key),
                    ];
                }
                return [];
            };
            const add = addMenu({
                label: 'Přidat ikonu',
                action: 'icon-add',
                itemAttr: 'data-icon-add',
                disabledTip: 'Vše, co je zapnuté v kroku 1, už je v ikonách',
                items: () => addableIconCategories(getDesign()).map((key) => ({ key, label: CATEGORY_LABEL[key] })),
                onPick: (key) => change('icons.order', [...styles().icons.order.filter((k) => k !== key), key]),
            });
            sc.track(() => addableIconCategories(getDesign()).join(','), () => add.sync());

            return [
                section('Rozvržení ikon v řádku', gridGroup),
                section('Buňka', map, h('div', { class: 'cal-icset' }, sizeField, cellAlign)),
                section('Velikost ikon (výchozí)', kit.number('cal.iconSize', 'iconSize', 'mm', 'Velikost ikon')),
                section('Ikony v řádku', orderList(sc, { expand }), add, note('Nabízí se to, co máte zapnuté v kroku 1. Termíny (svoz, akce…) nastavíte v kroku Obsah.')),
                kit.when(section(null, kit.toggle('cal.emptyIconSlot', 'Prázdné místo pro ikonu u každého dne')), () => activeOrder().length === 0),
            ];
        },
    };

    // ---- mount ------------------------------------------------------------------------------------

    const backToBlock = () => {
        const now = store.getState().ui.selection;
        if (!now || now.el !== 'cal') return;
        store.setUi({ selection: now.frame === undefined ? { el: 'cal' } : { el: 'cal', frame: now.frame } });
    };

    const kit = createKit(scope);
    let children;
    if (part) {
        const builder = PART_PANELS[NUMBER_PARTS.includes(part) ? 'number' : part];
        const head = panelHead(partLabel(part), ALL_DAYS, { back: { label: 'Zpět na kalendárium', title: 'Zpět (Esc)', onClick: backToBlock } });
        const back = head.querySelector('button');
        if (back) back.dataset.calAction = 'part-back';
        children = [head, ...(builder ? [].concat(builder(kit)).filter(Boolean) : [])];
    } else {
        children = blockPanel(kit);
    }
    const panel = panelRoot(...children);
    panel.dataset.kalPanel = part || 'block';
    root.replaceChildren(panel);

    function refresh() {
        if (!getDesign().layout) return;
        if (position) position.refresh();
        scope.refresh();
    }

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'layout' || event.type === 'content' || event.type === 'replace' || event.type === 'undo') refresh();
        else if (event.type === 'ui' && event.keys.includes('iconCell')) refresh();
    });

    return {
        destroy() {
            unsubscribe();
            for (const cleanup of cleanups.splice(0)) cleanup();
            scope.clear();
            root.replaceChildren();
        },
    };
}
