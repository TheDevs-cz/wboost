// Vrstvy (architecture §D.6 "Vrstvy", handoff §5.7, §5.8, map §8.7) — owner P4.
//
// `mountLayers(root, ctx, opts: { embedded: boolean }): { destroy() }`
//
//   .cal-layh                 „Vrstvy“ + [data-layer-menu] (Nahrát layout) + the menu `.cal-laym`
//   .cal-els                  one `.cal-eli[data-layer-item]` per element of `layout.order` (top row =
//                             top layer; the QR row only while `features.qr`), reordered with SortableJS
//     .cal-elr                grip · [data-layer="<el>"] (click selects, again deselects) ·
//                             [data-layer-toggle] (Kalendárium parts) · [data-layer-eye="<el>"]
//     .cal-parts              [data-part-row="<part>"] of `availableParts`, group label „Číslo dne“
//   .cal-elr.fixed            Pozadí — locked, always last, outside the sortable list
//
// Rules kept here:
//   - reorder, hide / show are one `store.change` each (undoable); the eye of Popisek fotografie
//     switches `features.captions` (§B.5, deviation D3), the others the `hidden` flag of the box
//   - a row that is not drawn (hidden, or its feature is off) is dimmed; it can be selected only
//     when that makes sense — Číslo týdne while „Zobrazení týdnů“ is off (its panel says so)
//   - selecting the Kalendárium opens its parts list
//   - Nahrát layout: `api.importLayout` → `store.replaceLayout` (one undo step) → toast; a file is
//     checked with `parseLayoutFile` first and rejected without changing anything

import Sortable from 'sortablejs';
import { ELEMENTS, PARTS, availableParts, elementLabel, isElementVisible } from '../../model/elements.js';
import { exportLayoutFile, parseLayoutFile } from '../../model/normalize.js';
import { h, icon, onOutside } from '../dom.js';

const EYES = ['cap', 'logo', 'month', 'wk', 'qr'];
const NOT_A_LAYOUT = 'Soubor neobsahuje layout kalendáře';
const FOCUS_KEYS = ['layer', 'layerEye', 'layerToggle', 'partRow', 'layerMenu'];

const spec = (key) => ELEMENTS.find((e) => e.key === key);

function slug(name) {
    const text = String(name)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    return text || 'kalendar';
}

export function mountLayers(root, ctx, opts = {}) {
    const { store, env, api } = ctx;
    const embedded = opts.embedded === true;

    const menuButton = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'data-layer-menu': '', 'aria-label': 'Nahrát layout', title: 'Nahrát layout', 'aria-haspopup': 'menu', 'aria-expanded': 'false', onClick: () => store.setUi({ layoutMenuOpen: !store.getState().ui.layoutMenuOpen }) }, icon('upload', 's16'));
    const fileInput = h('input', { type: 'file', class: 'visually-hidden', accept: '.json,application/json', tabindex: '-1', 'aria-hidden': 'true', 'data-layout-file': '' });
    const menuHost = h('div', { class: 'cal-lay' }, menuButton, fileInput);
    const list = h('div', { class: 'cal-els' });
    const fixed = h('div', { class: 'cal-els cal-els-fixed' });
    const panel = h('section', { class: `cal-layers${embedded ? ' embedded' : ''}` }, h('div', { class: 'cal-layh' }, h('h3', null, 'Vrstvy'), menuHost), list, fixed);
    root.replaceChildren(panel);

    const design = () => store.getState().doc.design;
    let menu = null;
    let offOutside = null;
    let busy = false;

    // ---- selection ------------------------------------------------------------------------

    function selectable(key) {
        const d = design();
        if (key === 'bg' || isElementVisible(d, key)) return true;
        return key === 'wk' && !d.features.weekNumbers;
    }

    function toggleSelection(key) {
        const selection = store.getState().ui.selection;
        if (selection && selection.el === key) store.setUi({ selection: null });
        else if (selectable(key)) store.setUi({ selection: key === 'cal' ? { el: 'cal', frame: 0 } : { el: key } });
    }

    function selectPart(part) {
        const selection = store.getState().ui.selection;
        const frame = selection && selection.el === 'cal' ? selection.frame || 0 : 0;
        if (selection && selection.el === 'cal' && selection.part === part) store.setUi({ selection: { el: 'cal', frame } });
        else store.setUi({ selection: { el: 'cal', frame, part } });
    }

    function toggleEye(key) {
        if (key === 'cap') {
            store.change((layout) => layout, { captions: !design().features.captions });
            return;
        }
        store.change((layout) => {
            const box = { ...layout.boxes[key] };
            if (box.hidden) delete box.hidden;
            else box.hidden = true;
            return { ...layout, boxes: { ...layout.boxes, [key]: box } };
        });
    }

    // ---- rows -----------------------------------------------------------------------------

    function row(key, state, partsOpen) {
        const d = state.doc.design;
        const selection = state.ui.selection;
        const selected = Boolean(selection && selection.el === key);
        const label = elementLabel(key);
        const drawn = key === 'bg' || isElementVisible(d, key);
        const hiddenByEye = key === 'cap' ? !d.features.captions : Boolean(d.layout.boxes[key] && d.layout.boxes[key].hidden);
        const info = spec(key);
        const select = h('button', { type: 'button', class: 'cal-eln', 'data-layer': key, 'aria-pressed': String(selected), 'aria-disabled': selected || selectable(key) ? null : 'true', onClick: () => toggleSelection(key) }, icon(info ? info.icon : 'layers', 's16'), h('span', { class: 'cal-elt' }, label));
        const children = [h('span', { class: 'cal-grip', 'aria-hidden': 'true' }, icon(key === 'bg' ? 'lock' : 'grip', 's14')), select];
        if (key === 'cal') {
            children.push(
                h(
                    'button',
                    { type: 'button', class: `btn icon xs btn-ghost cal-tw${partsOpen ? ' open' : ''}`, 'data-layer-toggle': 'cal', 'aria-expanded': String(partsOpen), 'aria-label': partsOpen ? 'Sbalit části kalendária' : 'Rozbalit části kalendária', title: partsOpen ? 'Sbalit části' : 'Rozbalit části', onClick: () => store.setUi({ partsOpen: !partsOpen }) },
                    icon('chev-down', 's16'),
                ),
            );
        }
        if (EYES.includes(key)) {
            children.push(
                h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-eye', 'data-layer-eye': key, 'aria-pressed': String(hiddenByEye), 'aria-label': `${hiddenByEye ? 'Zobrazit' : 'Skrýt'}: ${label}`, title: hiddenByEye ? 'Zobrazit' : 'Skrýt', onClick: () => toggleEye(key) }, icon(hiddenByEye ? 'eye-off' : 'eye', 's16')),
            );
        }
        return h('div', { class: `cal-elr${selected ? ' sel' : ''}${drawn ? '' : ' off'}${key === 'bg' ? ' fixed' : ''}` }, ...children);
    }

    function partsList(state) {
        const selection = state.ui.selection;
        const current = selection && selection.el === 'cal' ? selection.part || null : null;
        const out = h('div', { class: 'cal-parts', role: 'group', 'aria-label': 'Části kalendária' });
        for (const key of availableParts(state.doc.design)) {
            const part = PARTS.find((p) => p.key === key);
            if (key === 'num') out.append(h('span', { class: 'cal-pgrp' }, 'Číslo dne'));
            const label = part.group ? part.label.replace('Číslo — ', '') : part.label;
            out.append(h('button', { type: 'button', class: `cal-part${part.group ? ' sub' : ''}${current === key ? ' sel' : ''}`, 'data-part-row': key, 'aria-pressed': String(current === key), onClick: () => selectPart(key) }, label));
        }
        return out;
    }

    function focusKey() {
        const active = document.activeElement;
        if (!active || !panel.contains(active)) return null;
        for (const key of FOCUS_KEYS) {
            if (active.dataset[key] !== undefined) return `[data-${key.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)}="${active.dataset[key]}"]`;
        }
        return null;
    }

    let dragging = false;

    function render() {
        const state = store.getState();
        const d = state.doc.design;
        // a row being dragged must not be rebuilt under the pointer; `onEnd` renders
        if (!d.layout || dragging) return;
        const focused = focusKey();
        const selection = state.ui.selection;
        const partsOpen = Boolean((selection && selection.el === 'cal' && selection.part) || state.ui.partsOpen);
        const keys = d.layout.order.filter((key) => key !== 'qr' || d.features.qr);
        list.replaceChildren(...keys.map((key) => h('div', { class: 'cal-eli', 'data-layer-item': key }, row(key, state, partsOpen), key === 'cal' && partsOpen ? partsList(state) : null)));
        fixed.replaceChildren(row('bg', state, false));
        renderMenu(state);
        if (focused) {
            const again = panel.querySelector(focused);
            if (again && again !== document.activeElement) again.focus({ preventScroll: true });
        }
    }

    // ---- reorder --------------------------------------------------------------------------

    const sortable = Sortable.create(list, {
        draggable: '.cal-eli',
        handle: '.cal-elr',
        filter: '.cal-tw, .cal-eye',
        preventOnFilter: false,
        forceFallback: true,
        fallbackTolerance: 4,
        animation: 120,
        ghostClass: 'cal-el-ghost',
        chosenClass: 'cal-el-chosen',
        dragClass: 'cal-el-drag',
        onStart() {
            dragging = true;
            document.documentElement.classList.add('is-sort-dragging');
        },
        onEnd() {
            dragging = false;
            document.documentElement.classList.remove('is-sort-dragging');
            const shown = [...list.querySelectorAll(':scope > [data-layer-item]')].map((el) => el.dataset.layerItem);
            store.change((layout) => {
                // rows that are not listed (QR while the feature is off) keep their place in the stack
                const order = shown.slice();
                layout.order.forEach((key, index) => {
                    if (!order.includes(key)) order.splice(Math.min(index, order.length), 0, key);
                });
                return order.join() === layout.order.join() ? layout : { ...layout, order };
            });
            render();
        },
    });

    // ---- Nahrát layout ----------------------------------------------------------------------

    function closeMenu() {
        if (store.getState().ui.layoutMenuOpen) store.setUi({ layoutMenuOpen: false });
    }

    function item(attrs, iconName, title, note) {
        return h('button', { type: 'button', role: 'menuitem', class: 'cal-laym-i', ...attrs }, icon(iconName, 's16'), h('span', null, title, ' ', h('span', { class: 'cap' }, note)));
    }

    function renderMenu(state) {
        const open = state.ui.layoutMenuOpen === true;
        menuButton.setAttribute('aria-expanded', String(open));
        if (!open) {
            if (menu) menu.remove();
            menu = null;
            if (offOutside) offOutside();
            offOutside = null;
            return;
        }
        const d = state.doc.design;
        const siblings = (env.siblings || []).filter((s) => s.type === d.type && s.hasLayout);
        const next = h(
            'div',
            { class: 'cal-laym', role: 'menu', 'aria-label': 'Nahrát layout' },
            ...siblings.map((s) => item({ 'data-layout-from': s.id, onClick: () => loadFrom(s) }, 'calendar', s.name, 'Layout z kalendáře v projektu')),
            item({ 'data-cal-action': 'layout-file', onClick: () => { closeMenu(); fileInput.click(); } }, 'file', 'Ze souboru layoutu', '.json uložený z Wboostu'),
            h('span', { class: 'menu-sep', role: 'separator' }),
            item({ 'data-cal-action': 'layout-save', onClick: saveFile }, 'download', 'Uložit layout do souboru', '.json pro použití v jiném kalendáři'),
        );
        if (menu) menu.replaceWith(next);
        else menuHost.append(next);
        menu = next;
        if (!offOutside) offOutside = onOutside(menuHost, closeMenu);
    }

    async function apply(request, message) {
        if (busy) return;
        busy = true;
        panel.classList.add('is-busy');
        try {
            const answer = await request();
            store.replaceLayout(answer.layout, answer.assets);
            ctx.toast(message(answer));
        } catch (error) {
            ctx.toast((error && error.message) || 'Layout se nepodařilo načíst', true);
        } finally {
            busy = false;
            panel.classList.remove('is-busy');
        }
    }

    function loadFrom(sibling) {
        closeMenu();
        apply(() => api.importLayout({ from: sibling.id }), (answer) => `Layout načten z „${answer.name || sibling.name}“ — Ctrl+Z vrátí původní`);
    }

    async function loadFile() {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (!file) return;
        let text = '';
        try {
            text = await file.text();
        } catch (e) {
            text = '';
        }
        const d = design();
        const parsed = parseLayoutFile(text, d);
        if (!parsed.ok) {
            ctx.toast(NOT_A_LAYOUT, true);
            return;
        }
        apply(() => api.importLayout({ layout: { layout: parsed.layout, sourceCalendar: parsed.sourceCalendar, type: d.type } }), () => 'Layout načten ze souboru — Ctrl+Z vrátí původní');
    }

    function saveFile() {
        closeMenu();
        const doc = store.getState().doc;
        const blob = new Blob([JSON.stringify(exportLayoutFile(doc), null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const link = h('a', { href: url, download: `${slug(doc.design.name)}-layout.json`, hidden: true });
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    fileInput.addEventListener('change', loadFile);

    // ---- wiring ---------------------------------------------------------------------------

    let wasCal = Boolean(store.getState().ui.selection && store.getState().ui.selection.el === 'cal');
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'content' || (event.type === 'layout' && event.live)) return;
        if (event.type === 'ui') {
            if (!event.keys.some((key) => key === 'selection' || key === 'partsOpen' || key === 'layoutMenuOpen')) return;
            // selecting the Kalendárium opens its parts
            const selection = store.getState().ui.selection;
            const isCal = Boolean(selection && selection.el === 'cal');
            const opened = isCal && !wasCal && !store.getState().ui.partsOpen;
            wasCal = isCal;
            if (opened) {
                store.setUi({ partsOpen: true });
                return;
            }
        }
        render();
    });
    render();

    return {
        destroy() {
            unsubscribe();
            if (offOutside) offOutside();
            sortable.destroy();
            if (store.getState().ui.layoutMenuOpen) store.setUi({ layoutMenuOpen: false });
            root.replaceChildren();
        },
    };
}
