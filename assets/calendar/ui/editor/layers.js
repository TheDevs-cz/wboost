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
// Own layers (feedback K20, docs/calendar/custom-layers-plan.md §4.1) — pictures and shapes in the
// same stack as the built-ins (`u:<id>` in `layout.order`, model/layers.js):
//   [data-cal-action="layer-add"]          „Přidat“ → the menu `.cal-laym[data-layer-add-menu]` with
//     [data-layer-add="image|gallery|rect|rounded|ellipse|line"]   (gallery only with the gallery modal)
//   rows `[data-layer="u:<id>"]` (kind icon, name or the numbered fallback), the eye
//   `[data-layer-eye="u:<id>"]` (`hidden` on the `u:` box), the row menu `[data-layer-menu-of="u:<id>"]`
//   → `[data-layer-action="rename|duplicate|delete"]`; rename edits the name in place
//   (`input[data-layer-name="u:<id>"]`, Enter / blur saves, Esc cancels)
//   - „Přidat“ creates the layer centred on the page, on TOP of the stack, selected, in one
//     `store.change` (one undo step); a picture layer appears only once its upload / gallery copy
//     answered (no empty picture layers); delete is undoable, so it never asks
//   - own layers are on every week page in P1 (page sets come in P2)
//
// Rules kept here:
//   - reorder, hide / show are one `store.change` each (undoable); the eye of Popisek fotografie
//     switches `features.captions` (§B.5, deviation D3), the others the `hidden` flag of the box
//     (of the element's FIRST frame: on a 14-day page Měsíc and Číslo týdne have two frames,
//     `month` / `month2` and `wk` / `wk2`, one row here, and the eye hides both)
//   - a row that is not drawn (hidden, or its feature is off) is dimmed; it can be selected only
//     when that makes sense — Číslo týdne while „Zobrazení týdnů“ is off (its panel says so)
//   - selecting the Kalendárium opens its parts list
//   - Nahrát layout: `api.importLayout` → `store.replaceLayout` (one undo step) → toast; a file is
//     checked with `parseLayoutFile` first and rejected without changing anything

import Sortable from 'sortablejs';
import { ELEMENTS, PARTS, availableParts, elementLabel, isElementVisible } from '../../model/elements.js';
import { exportLayoutFile, parseLayoutFile } from '../../model/normalize.js';
import { KIND_ICONS, addLayer, defaultLayerBox, duplicateLayer, isLayerKey, layerIdOf, layerKey, layerLabels, normalizeLayer, removeLayer, setLayer } from '../../model/layers.js';
import { FALLBACK_ACCENT } from '../../model/layouts.js';
import { newId } from '../../model/content.js';
import { bleedOf } from '../../model/defaults.js';
import { pageBounds } from '../../editor/geometry.js';
import { h, icon, onOutside } from '../dom.js';
import { hasGallery, pickGalleryAsset } from '../gallery-pick.js';
import { layerOfMenuEntry } from './props-layer.js';

const EYES = ['cap', 'logo', 'month', 'wk', 'qr'];
const NOT_A_LAYOUT = 'Soubor neobsahuje layout kalendáře';
const FOCUS_KEYS = ['layer', 'layerEye', 'layerToggle', 'partRow', 'layerMenu', 'layerMenuOf', 'layerAdd'];
const PICTURE_ACCEPT = 'image/svg+xml,image/png,image/jpeg,image/webp';

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
    const addButton = h('button', { type: 'button', class: 'btn xs btn-ghost cal-ladd', 'data-cal-action': 'layer-add', 'aria-haspopup': 'menu', 'aria-expanded': 'false', title: 'Přidat vlastní vrstvu — obrázek nebo tvar', onClick: () => toggleAddMenu() }, icon('plus', 's16'), 'Přidat');
    const pictureInput = h('input', { type: 'file', class: 'visually-hidden', accept: PICTURE_ACCEPT, tabindex: '-1', 'aria-hidden': 'true', 'data-layer-file': '' });
    const addHost = h('div', { class: 'cal-lay' }, addButton, pictureInput);
    const list = h('div', { class: 'cal-els' });
    const fixed = h('div', { class: 'cal-els cal-els-fixed' });
    const panel = h('section', { class: `cal-layers${embedded ? ' embedded' : ''}` }, h('div', { class: 'cal-layh' }, h('h3', null, 'Vrstvy'), h('div', { class: 'cal-layb' }, addHost, menuHost)), list, fixed);
    root.replaceChildren(panel);

    const design = () => store.getState().doc.design;
    let menu = null;
    let offOutside = null;
    let busy = false;
    // own layers: the „Přidat“ menu, the open row menu, the row being renamed (view state only)
    let addMenu = null;
    let offAddOutside = null;
    let rowMenuOf = null;
    let offRowOutside = null;
    let renaming = null;

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
        if (isLayerKey(key)) return layerRow(key, state);
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

    /** A row of an own layer: kind icon, name (or the input while renamed), the row menu, the eye. */
    function layerRow(key, state) {
        const d = state.doc.design;
        const layer = d.layout.layers[layerIdOf(key)];
        const selection = state.ui.selection;
        const selected = Boolean(selection && selection.el === key);
        const label = layerLabels(d.layout)[key] || '';
        const hidden = Boolean(d.layout.boxes[key] && d.layout.boxes[key].hidden);
        const kindIcon = layer.kind === 'rect' && layer.radius > 0 ? 'square-round' : KIND_ICONS[layer.kind];
        let name;
        if (renaming === key) {
            name = h('input', { type: 'text', class: 'cal-lren', 'data-layer-name': key, maxlength: '60', value: layer.name, placeholder: label, 'aria-label': 'Název vrstvy', autocomplete: 'off' });
            let done = false;
            const finish = (save) => {
                if (done) return;
                done = true;
                renaming = null;
                const next = normalizeLayer({ ...layer, name: name.value });
                if (save && next && next.name !== layer.name) store.change((l) => setLayer(l, layerIdOf(key), next));
                else render();
            };
            name.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    finish(true);
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    finish(false);
                }
            });
            name.addEventListener('blur', () => finish(true));
        }
        const select = h('button', { type: 'button', class: 'cal-eln', 'data-layer': key, 'aria-pressed': String(selected), 'aria-disabled': selected || selectable(key) ? null : 'true', onClick: () => toggleSelection(key) }, icon(kindIcon, 's16'), renaming === key ? null : h('span', { class: 'cal-elt' }, label));
        const children = [h('span', { class: 'cal-grip', 'aria-hidden': 'true' }, icon('grip', 's14'))];
        if (renaming === key) children.push(h('span', { class: 'cal-eln cal-eln-edit' }, icon(kindIcon, 's16'), name));
        else children.push(select);
        const menuOpen = rowMenuOf === key;
        children.push(
            h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-lmenu', 'data-layer-menu-of': key, 'aria-haspopup': 'menu', 'aria-expanded': String(menuOpen), 'aria-label': `Možnosti vrstvy: ${label}`, title: 'Možnosti vrstvy', onClick: () => toggleRowMenu(key) }, icon('more', 's16')),
            h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-eye', 'data-layer-eye': key, 'aria-pressed': String(hidden), 'aria-label': `${hidden ? 'Zobrazit' : 'Skrýt'}: ${label}`, title: hidden ? 'Zobrazit' : 'Skrýt', onClick: () => toggleEye(key) }, icon(hidden ? 'eye-off' : 'eye', 's16')),
        );
        if (menuOpen) children.push(rowMenu(key));
        return h('div', { class: `cal-elr cal-own${selected ? ' sel' : ''}${hidden ? ' off' : ''}` }, ...children);
    }

    function rowMenu(key) {
        const entry = (action, iconName, text, onClick, extra = {}) => h('button', { type: 'button', role: 'menuitem', class: `cal-laym-i${extra.danger ? ' danger' : ''}`, 'data-layer-action': action, onClick }, icon(iconName, 's16'), h('span', null, text));
        const el = h(
            'div',
            { class: 'cal-laym cal-rowm', role: 'menu', 'aria-label': 'Možnosti vrstvy', onKeydown: (event) => menuKeys(event, closeRowMenu) },
            entry('rename', 'edit', 'Přejmenovat', () => startRename(key)),
            entry('duplicate', 'copy', 'Duplikovat', () => duplicate(key)),
            h('span', { class: 'menu-sep', role: 'separator' }),
            entry('delete', 'trash', 'Smazat vrstvu', () => remove(key), { danger: true }),
        );
        return el;
    }

    /** Esc closes a menu (and never reaches the editor keys); arrows move between its entries. */
    function menuKeys(event, close) {
        const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]')];
        const at = items.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = items[(at + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
            if (next) next.focus();
        }
    }

    function toggleRowMenu(key) {
        if (rowMenuOf === key) closeRowMenu();
        else {
            closeAddMenu();
            rowMenuOf = key;
            // rows are rebuilt on every store event: the outside test looks for the menu, not a node
            if (!offRowOutside) {
                const listener = (event) => {
                    if (!event.target.closest || !event.target.closest('.cal-rowm, [data-layer-menu-of]')) closeRowMenu();
                };
                document.addEventListener('pointerdown', listener, true);
                offRowOutside = () => document.removeEventListener('pointerdown', listener, true);
            }
            render();
            const first = panel.querySelector('.cal-rowm [role="menuitem"]');
            if (first) first.focus({ preventScroll: true });
        }
    }

    function closeRowMenu(refocus = false) {
        if (offRowOutside) offRowOutside();
        offRowOutside = null;
        if (rowMenuOf === null) return;
        const key = rowMenuOf;
        rowMenuOf = null;
        render();
        if (refocus) {
            const again = panel.querySelector(`[data-layer-menu-of="${key}"]`);
            if (again) again.focus({ preventScroll: true });
        }
    }

    function startRename(key) {
        closeRowMenu();
        renaming = key;
        render();
        const input = panel.querySelector(`[data-layer-name="${key}"]`);
        if (input) {
            input.focus();
            input.select();
        }
    }

    function duplicate(key) {
        closeRowMenu();
        const d = design();
        const id = newId();
        store.change((layout) => duplicateLayer(layout, layerIdOf(key), id, pageBounds(d.page, bleedOf(d.print))));
        store.setUi({ selection: { el: layerKey(id) } });
    }

    function remove(key) {
        closeRowMenu();
        store.change((layout) => removeLayer(layout, layerIdOf(key)));
        ctx.toast('Vrstva smazána — Ctrl+Z ji vrátí');
    }

    // ---- „Přidat“ ----------------------------------------------------------------------------

    function addMenuEl() {
        const entry = (kind, iconName, text) => h('button', { type: 'button', role: 'menuitem', class: 'cal-laym-i', 'data-layer-add': kind, onClick: () => chooseAdd(kind) }, icon(iconName, 's16'), h('span', null, text));
        return h(
            'div',
            { class: 'cal-laym cal-addm', role: 'menu', 'aria-label': 'Přidat vrstvu', 'data-layer-add-menu': '', onKeydown: (event) => menuKeys(event, closeAddMenu) },
            entry('image', 'upload', 'Obrázek — nahrát'),
            hasGallery() ? entry('gallery', 'image', 'Obrázek z galerie') : null,
            h('span', { class: 'menu-sep', role: 'separator' }),
            entry('rect', 'square', 'Obdélník'),
            entry('rounded', 'square-round', 'Zaoblený obdélník'),
            entry('ellipse', 'circle', 'Elipsa'),
            entry('line', 'minus', 'Čára'),
        );
    }

    function toggleAddMenu() {
        if (addMenu) closeAddMenu();
        else openAddMenu();
    }

    function openAddMenu() {
        if (busy) return;
        closeRowMenu();
        closeMenu();
        addMenu = addMenuEl();
        addHost.append(addMenu);
        addButton.setAttribute('aria-expanded', 'true');
        offAddOutside = onOutside(addHost, () => closeAddMenu());
        const first = addMenu.querySelector('[role="menuitem"]');
        if (first) first.focus({ preventScroll: true });
    }

    function closeAddMenu(refocus = false) {
        if (offAddOutside) offAddOutside();
        offAddOutside = null;
        if (!addMenu) return;
        addMenu.remove();
        addMenu = null;
        addButton.setAttribute('aria-expanded', 'false');
        if (refocus) addButton.focus({ preventScroll: true });
    }

    /** The colour of a new shape: the project's first brand colour (else the accent of the module). */
    const firstColour = () => env.accent || FALLBACK_ACCENT;

    /** Adds a layer centred on the page, on top of the stack, and selects it — one undo step. */
    function create(entry, extra = {}, aspect) {
        const d = design();
        if (!d.layout) return;
        const layer = layerOfMenuEntry(entry, firstColour(), extra);
        const id = newId();
        store.change((layout) => addLayer(layout, id, layer, defaultLayerBox(layer.kind, d.page, aspect)));
        store.setUi({ selection: { el: layerKey(id) } });
    }

    function chooseAdd(entry) {
        closeAddMenu();
        if (entry === 'image') pictureInput.click();
        else if (entry === 'gallery') pictureFrom(() => pickGalleryAsset(ctx, 'image'));
        else create(entry);
    }

    async function pictureFrom(request) {
        if (busy) return;
        busy = true;
        panel.classList.add('is-busy');
        addButton.setAttribute('aria-busy', 'true');
        try {
            const info = await request();
            if (!info) return;
            const aspect = info.width > 0 && info.height > 0 ? info.width / info.height : undefined;
            create('image', { asset: info.id }, aspect);
        } catch (error) {
            ctx.toast((error && error.message) || 'Obrázek se nepodařilo nahrát', true);
        } finally {
            busy = false;
            panel.classList.remove('is-busy');
            addButton.removeAttribute('aria-busy');
        }
    }

    pictureInput.addEventListener('change', () => {
        const file = pictureInput.files && pictureInput.files[0];
        pictureInput.value = '';
        if (!file) return;
        const limit = Number(env.maxFileSize) || 0;
        if (limit > 0 && file.size > limit) {
            ctx.toast('Soubor je větší než 10 MB.', true);
            return;
        }
        pictureFrom(async () => {
            const info = await api.upload(file, 'image');
            store.addAsset(info);
            return info;
        });
    });

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
        filter: '.cal-tw, .cal-eye, .cal-lmenu, .cal-lren, .cal-rowm',
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
        closeAddMenu();
        closeRowMenu();
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
            if (offAddOutside) offAddOutside();
            if (offRowOutside) offRowOutside();
            sortable.destroy();
            if (store.getState().ui.layoutMenuOpen) store.setUi({ layoutMenuOpen: false });
            root.replaceChildren();
        },
    };
}
