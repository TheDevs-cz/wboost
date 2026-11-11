// The editable content of the cover, the back and the generated special pages — one set of field
// sections for two surfaces: the right panel of step 4 (`ui/pagepanel.js`) and the „Upravit obsah
// stránky“ popover of step 5 (`ui/step5.js`), for the designer AND the client.
//
// Three content collections (model/content.js, `CalendarContentRules`):
//   `pageContent[<page key>]`  texts + the photo with its crop          client may edit
//   `pageGroups[<id>]`         groups of lines (contacts, waste plan)    client may edit
//   `pageSetup[<page key>]`    layout of the cover, logo, alignment,     designer only — the
//                              legends, the notes column                 sections are not built for
//                                                                        a client
// Every keystroke is a `putItem` (never undoable, saved item-wise, listed in Historie); the page in
// the middle redraws from the store. A field never rebuilds for a value — the sections are rebuilt
// only when their STRUCTURE changes (another layout, a group or a line added / removed / moved).
//
// Contract:
//   `hasPageContent(page): boolean`          the page has fields here (not a week, not an uploaded page)
//   `mountPageContent(root, ctx, opts): { destroy() }`
//       opts.page()   the page (of `store.pages()`) whose content is edited
//
// DOM hooks: `[data-content-field="<name>"]` on every input / textarea (`title subtitle note heading
// salutation body signName signRole notesHeading footer name address phone email web imprint`),
// `[data-counter]`, `[data-format="bold|italic"]`, `[data-content-photo]` (file input),
// `[data-photo-crop]` (drag surface), `[data-photo-zoom]`, `[data-photo-remove]`,
// `[data-cover-layout="<n>"]`, `[data-setup="logo|logoSize|align"] [data-value]`,
// `[data-setup-check="clubLegend|wasteLegend|notes|logo"]`, `[data-group="<id>"]`,
// `[data-group-heading]`, `[data-group-toggle]`, `[data-group-highlight]`, `[data-group-remove]`,
// `[data-group-add]`, `[data-line="<n>"]`, `[data-line-label]`, `[data-line-value]`,
// `[data-line-remove]`, `[data-line-add]`, `.cal-grip` (drag handles of groups and lines).
// Scope them with `.cal-pc` (or `[data-page-content="<page key>"]`, the root of the sections): the
// page SVG beside the panel carries `data-group` / `data-field` of its own.

import Sortable from 'sortablejs';
import { CONTENT_LIMITS, newId, nextPos, pageContentOf, pageGroupsOf, pageSetupOf, reorder } from '../model/content.js';
import { LIMITS } from '../model/defaults.js';
import { COVER_LAYOUTS, specialPhotoBox } from '../render/special.js';
import { checkRow, h, icon, segmented } from './dom.js';
import { renderThumb } from './thumb.js';
import { fileTile, uploadAsset } from './tools/photos.js';

const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
const clamp01 = (v) => Math.min(1, Math.max(0, v));
const round4 = (v) => Math.round(v * 10000) / 10000;
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** The content key of a page, `null` for pages without editable content. */
function contentKey(page) {
    if (!page) return null;
    if (page.kind === 'cover' || page.kind === 'back') return page.kind;
    if (page.kind !== 'special' || !page.special) return null;
    const { id, item } = page.special;
    return item && item.kind === id && pageContentOf({}, `sp:${id}`) ? `sp:${id}` : null;
}

export const hasPageContent = (page) => contentKey(page) !== null;

export function mountPageContent(root, ctx, opts) {
    const { store, env } = ctx;
    const designer = env.mode === 'designer';
    const body = h('div', { class: 'cal-pc' });
    root.replaceChildren(body);

    let signature = null;
    let syncs = [];
    let sortables = [];
    let pendingFocus = null;
    /** Groups whose lines are shown; a new group opens, the first one is open from the start. */
    const open = new Set();
    let openSeeded = false;

    const content = () => store.getState().doc.content;
    const page = () => opts.page();
    const key = () => contentKey(page());
    const item = () => pageContentOf(content(), key());
    const setup = () => pageSetupOf(content(), key());
    const layoutOf = (p) => (p.kind === 'cover' ? setup().layout : p.special ? Number(p.special.item.layout) || 0 : 0);

    // ---- writes ------------------------------------------------------------------------------

    function writeContent(patch) {
        const k = key();
        if (!k) return;
        const next = { ...item(), ...patch };
        const stored = content().pageContent[k];
        if (same(next, pageContentOf({}, k))) {
            if (stored) store.removeItem('pageContent', k, page().title);
        } else store.putItem('pageContent', k, next, page().title);
    }

    function writeSetup(patch) {
        const k = key();
        if (!k || !designer) return;
        const next = { ...setup(), ...patch };
        if (same(next, pageSetupOf({}, k))) {
            if (content().pageSetup[k]) store.removeItem('pageSetup', k, page().title);
        } else store.putItem('pageSetup', k, next, page().title);
    }

    const groupOf = (id) => content().pageGroups[id] || null;
    function writeGroup(id, patch) {
        const group = groupOf(id);
        if (!group) return;
        store.putItem('pageGroups', id, { page: group.page, heading: group.heading, lines: group.lines, highlight: group.highlight === true, pos: group.pos, ...patch }, page().title);
    }

    // ---- field kit ----------------------------------------------------------------------------

    /** Label row with a live counter (`n / max`). */
    function labelRow(label, max) {
        const counter = max ? h('span', { class: 'cal-cnt', 'data-counter': '' }) : null;
        return { el: h('span', { class: 'cal-pcl' }, h('span', { class: 'lbl' }, label), counter), count: (n) => counter && (counter.textContent = `${n} / ${max}`) };
    }

    /** One text field bound to a key of the page's content. */
    function textField(name, label, max, o = {}) {
        const head = labelRow(label, max);
        const input = h(o.rows ? 'textarea' : 'input', {
            ...(o.rows ? { rows: String(o.rows) } : { type: 'text' }),
            maxlength: String(max),
            autocomplete: 'off',
            spellcheck: o.rows ? 'true' : 'false',
            placeholder: o.placeholder || '',
            'aria-label': label,
            'data-content-field': name,
        });
        input.addEventListener('input', () => {
            // an address has a fixed number of lines
            if (o.maxLines) {
                const rows = input.value.split('\n');
                if (rows.length > o.maxLines) input.value = rows.slice(0, o.maxLines).join('\n');
            }
            head.count(input.value.length);
            writeContent({ [name]: input.value });
        });
        if (!o.rows) {
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    // Enter belongs to the field (handoff §8), not to a popover around it
                    event.stopPropagation();
                    input.blur();
                }
            });
        }
        const sync = () => {
            const value = item()[name] || '';
            if (document.activeElement !== input && input.value !== value) input.value = value;
            head.count(input.value.length);
        };
        syncs.push(sync);
        const field = h('span', { class: `inp sm${o.rows ? ' area' : ''}` }, input);
        return { el: h('div', { class: 'cal-p4s' }, head.el, o.before || null, field, o.hint ? h('span', { class: 'cap' }, o.hint) : null), input };
    }

    /** The body of the intro: a textarea with „tučně“ / „kurzíva“ that wrap the selection in marks. */
    function bodyField() {
        const wrap = (mark) => {
            const input = field.input;
            const { selectionStart: a, selectionEnd: b, value } = input;
            const picked = value.slice(a, b) || 'text';
            const next = `${value.slice(0, a)}${mark}${picked}${mark}${value.slice(b)}`;
            if (next.length > CONTENT_LIMITS.body) return;
            input.value = next;
            input.focus();
            input.setSelectionRange(a + mark.length, a + mark.length + picked.length);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        };
        const tools = h(
            'span',
            { class: 'cal-pcf', role: 'group', 'aria-label': 'Formát textu' },
            h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-format': 'bold', title: 'Tučně — označený text obalí **hvězdičkami**', onClick: () => wrap('**') }, h('b', null, 'B'), 'Tučně'),
            h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-format': 'italic', title: 'Kurzíva — označený text obalí *hvězdičkami*', onClick: () => wrap('*') }, h('i', null, 'I'), 'Kurzíva'),
        );
        const field = textField('body', 'Text', CONTENT_LIMITS.body, { rows: 12, before: tools, hint: 'Odstavce oddělte prázdným řádkem. **tučně**, *kurzíva*.' });
        return field.el;
    }

    /** Photo with its crop: upload tile, a preview to drag the crop in, zoom, remove. */
    function photoField(label, slotLabel) {
        const p = page();
        const box = specialPhotoBox(store.getState().doc, p);
        if (!box) return null;
        const ratio = box.w / box.h;
        const assetOf = (photo) => (photo && env.assets[photo.asset]) || null;
        const tile = fileTile({
            asset: null,
            label,
            tip: (has) => (has ? 'Vyměnit fotku' : 'Nahrát fotku'),
            accept: PHOTO_ACCEPT,
            attrs: { 'data-content-photo': '' },
            onFiles: async ([file]) => {
                const info = await uploadAsset(ctx, file, 'photo');
                if (info) writeContent({ photo: { asset: info.id, zoom: 1, px: 0.5, py: 0.5 } });
            },
        });
        const image = h('img', { alt: '', draggable: 'false' });
        const surface = h('div', { class: 'cal-crop', 'data-photo-crop': '', title: 'Výřez posunete tažením', style: `aspect-ratio: ${ratio}; width: min(100%, ${Math.round(150 * ratio)}px)` }, image);
        const zoom = h('input', { type: 'range', min: String(LIMITS.photoZoom.min * 100), max: String(LIMITS.photoZoom.max * 100), step: '1', value: '100', 'aria-label': 'Zvětšení fotky', 'data-photo-zoom': '' });
        const zoomValue = h('output', { class: 'cal-5zv' }, '100 %');
        const remove = h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-photo-remove': '', onClick: () => writeContent({ photo: null }) }, icon('trash', 's14'), 'Odebrat');
        const hint = h('span', { class: 'cap' });
        const controls = h('div', { class: 'cal-cropc' }, h('span', { class: 'cal-5z' }, icon('minus', 's14'), zoom, icon('plus', 's14'), zoomValue), remove);

        /** Size of the drawn picture relative to the frame — the maths of `photoIn`. */
        const drawn = (asset, z) => {
            const r = asset.width > 0 && asset.height > 0 ? asset.width / asset.height : ratio;
            const hRel = Math.max(ratio / r, 1) * Math.max(1, z || 1);
            return { wRel: (hRel * r) / ratio, hRel };
        };
        const paint = (photo) => {
            const asset = assetOf(photo);
            tile.setAsset(asset);
            surface.hidden = !asset;
            controls.hidden = !asset;
            hint.textContent = asset ? 'Výřez posunete tažením v náhledu.' : slotLabel;
            if (!asset) return;
            const url = asset.previewUrl || asset.url;
            if (image.getAttribute('src') !== url) image.src = url;
            const { wRel, hRel } = drawn(asset, photo.zoom);
            image.style.width = `${wRel * 100}%`;
            image.style.height = `${hRel * 100}%`;
            image.style.left = `${(1 - wRel) * photo.px * 100}%`;
            image.style.top = `${(1 - hRel) * photo.py * 100}%`;
            if (document.activeElement !== zoom) zoom.value = String(Math.round(photo.zoom * 100));
            zoomValue.textContent = `${Math.round(photo.zoom * 100)} %`;
        };
        zoom.addEventListener('input', () => {
            const photo = item().photo;
            if (photo) writeContent({ photo: { ...photo, zoom: Math.min(LIMITS.photoZoom.max, Math.max(LIMITS.photoZoom.min, Number(zoom.value) / 100)) } });
        });
        surface.addEventListener('pointerdown', (event) => {
            const photo = item().photo;
            const asset = assetOf(photo);
            if (!asset || event.button > 0) return;
            event.preventDefault();
            surface.setPointerCapture(event.pointerId);
            const rect = surface.getBoundingClientRect();
            const { wRel, hRel } = drawn(asset, photo.zoom);
            const start = { x: event.clientX, y: event.clientY, px: photo.px, py: photo.py };
            const move = (e) => {
                const freeX = (1 - wRel) * rect.width;
                const freeY = (1 - hRel) * rect.height;
                const px = Math.abs(freeX) < 0.5 ? start.px : clamp01(start.px + (e.clientX - start.x) / freeX);
                const py = Math.abs(freeY) < 0.5 ? start.py : clamp01(start.py + (e.clientY - start.y) / freeY);
                writeContent({ photo: { ...photo, px: round4(px), py: round4(py) } });
            };
            const up = () => {
                surface.removeEventListener('pointermove', move);
                surface.removeEventListener('pointerup', up);
                surface.removeEventListener('pointercancel', up);
            };
            surface.addEventListener('pointermove', move);
            surface.addEventListener('pointerup', up);
            surface.addEventListener('pointercancel', up);
        });
        syncs.push(() => paint(item().photo));
        return h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, label), h('div', { class: 'cal-ftr' }, tile, h('div', { class: 'cal-ftb' }, hint)), surface, controls);
    }

    function setupSegment(name, label, options) {
        const control = segmented({ options, value: setup()[name], label, full: true, attrs: { 'data-setup': name }, onChange: (value) => writeSetup({ [name]: value }) });
        syncs.push(() => control.setValue(setup()[name]));
        return h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, label), control);
    }

    function setupCheck(name, label) {
        const control = checkRow({ label, checked: setup()[name] === true, attrs: { 'data-setup-check': name }, onChange: (on) => writeSetup({ [name]: on }) });
        syncs.push(() => control.setChecked(setup()[name] === true));
        return control;
    }

    /** Layout thumbnails of the cover — drawn with the real content. */
    function coverLayouts() {
        const p = page();
        const cards = COVER_LAYOUTS.map((name, layout) => {
            const picture = h('span', { class: 'cal-splp' });
            const button = h('button', { type: 'button', role: 'radio', class: 'cal-splc', 'aria-checked': 'false', 'data-cover-layout': String(layout), onClick: () => writeSetup({ layout }) }, picture, h('span', { class: 'cap' }, name));
            return { layout, button, picture };
        });
        let painted = null;
        syncs.push(() => {
            const state = store.getState();
            const current = setup().layout;
            for (const card of cards) {
                card.button.classList.toggle('on', card.layout === current);
                card.button.setAttribute('aria-checked', String(card.layout === current));
            }
            // the thumbnails follow the content, but not every keystroke repaints four pages at once
            const stamp = state.doc;
            if (painted === stamp) return;
            painted = stamp;
            clearTimeout(coverLayouts.timer);
            coverLayouts.timer = setTimeout(() => {
                const { doc } = store.getState();
                for (const card of cards) {
                    const variant = { ...doc, content: { ...doc.content, pageSetup: { ...doc.content.pageSetup, cover: { ...pageSetupOf(doc.content, 'cover'), layout: card.layout } } } };
                    try {
                        card.picture.innerHTML = renderThumb(variant, p, ctx, { holes: false });
                    } catch (error) {
                        console.error('[calendar] cover thumbnail failed', error);
                    }
                }
            }, cards[0].picture.firstChild ? 250 : 0);
        });
        const { width, height } = store.getState().doc.design.page;
        return h('div', { class: 'cal-p4s', style: `--cal-page-ratio: ${width / height}` }, h('span', { class: 'lbl' }, 'Rozvržení'), h('div', { class: 'cal-spl', role: 'radiogroup', 'aria-label': 'Rozvržení titulní strany' }, ...cards.map((card) => card.button)));
    }

    // ---- groups of lines -----------------------------------------------------------------------

    function groupsEditor(groupPage, label, addLabel) {
        const groups = pageGroupsOf(content(), groupPage);
        if (!openSeeded) {
            openSeeded = true;
            if (groups.length) open.add(groups[0][0]);
        }
        const cards = groups.map(([id, group]) => {
            const isOpen = open.has(id);
            const heading = h('input', { type: 'text', maxlength: String(CONTENT_LIMITS.groupHeading), autocomplete: 'off', placeholder: 'Nadpis skupiny', 'aria-label': 'Nadpis skupiny', 'data-group-heading': '' });
            heading.value = group.heading;
            heading.addEventListener('input', () => writeGroup(id, { heading: heading.value }));
            heading.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.stopPropagation();
                    heading.blur();
                }
            });
            syncs.push(() => {
                const current = groupOf(id);
                if (current && document.activeElement !== heading && heading.value !== current.heading) heading.value = current.heading;
            });
            const toggle = h(
                'button',
                {
                    type: 'button',
                    class: 'btn icon xs btn-ghost',
                    'aria-expanded': String(isOpen),
                    'aria-label': isOpen ? 'Sbalit řádky' : 'Rozbalit řádky',
                    title: isOpen ? 'Sbalit řádky' : `Rozbalit řádky (${group.lines.length})`,
                    'data-group-toggle': '',
                    onClick: () => {
                        if (open.has(id)) open.delete(id);
                        else open.add(id);
                        refresh();
                    },
                },
                icon(isOpen ? 'chev-down' : 'chev-right', 's16'),
            );
            const remove = h(
                'button',
                {
                    type: 'button',
                    class: 'btn icon xs btn-ghost',
                    'aria-label': 'Smazat skupinu',
                    title: 'Smazat skupinu',
                    'data-group-remove': '',
                    onClick: () => {
                        open.delete(id);
                        store.removeItem('pageGroups', id, page().title);
                    },
                },
                icon('trash', 's16'),
            );
            const head = h('div', { class: 'cal-gh' }, h('span', { class: 'cal-grip', title: 'Přetáhněte pro změnu pořadí', 'aria-hidden': 'true' }, icon('grip', 's14')), h('span', { class: 'inp sm' }, heading), toggle, remove);
            const children = [head];
            if (isOpen) {
                const rows = group.lines.map((line, index) => {
                    const write = (patch) => {
                        const current = groupOf(id);
                        if (!current) return;
                        writeGroup(id, { lines: current.lines.map((l, i) => (i === index ? { label: l.label, value: l.value, ...patch } : l)) });
                    };
                    const labelInput = h('input', { type: 'text', maxlength: String(CONTENT_LIMITS.lineLabel), autocomplete: 'off', placeholder: 'Popisek', 'aria-label': 'Popisek řádku', 'data-line-label': '' });
                    const valueInput = h('input', { type: 'text', maxlength: String(CONTENT_LIMITS.lineValue), autocomplete: 'off', placeholder: 'Text řádku', 'aria-label': 'Text řádku', 'data-line-value': '' });
                    labelInput.value = line.label;
                    valueInput.value = line.value;
                    labelInput.addEventListener('input', () => write({ label: labelInput.value }));
                    valueInput.addEventListener('input', () => write({ value: valueInput.value }));
                    for (const input of [labelInput, valueInput]) {
                        input.addEventListener('keydown', (event) => {
                            if (event.key !== 'Enter') return;
                            event.stopPropagation();
                            event.preventDefault();
                            // Enter in the last line continues with a new one, like a list
                            if (index === group.lines.length - 1 && group.lines.length < CONTENT_LIMITS.lines) addLine(id);
                            else input.blur();
                        });
                    }
                    syncs.push(() => {
                        const current = groupOf(id);
                        const now = current && current.lines[index];
                        if (!now) return;
                        if (document.activeElement !== labelInput && labelInput.value !== now.label) labelInput.value = now.label;
                        if (document.activeElement !== valueInput && valueInput.value !== now.value) valueInput.value = now.value;
                    });
                    return h(
                        'div',
                        { class: 'cal-gl', 'data-line': String(index) },
                        h('span', { class: 'cal-grip', title: 'Přetáhněte pro změnu pořadí', 'aria-hidden': 'true' }, icon('grip', 's14')),
                        h('span', { class: 'inp sm cal-gll' }, labelInput),
                        h('span', { class: 'inp sm cal-glv' }, valueInput),
                        h(
                            'button',
                            {
                                type: 'button',
                                class: 'btn icon xs btn-ghost',
                                'aria-label': 'Odebrat řádek',
                                title: 'Odebrat řádek',
                                'data-line-remove': '',
                                onClick: () => {
                                    const current = groupOf(id);
                                    if (current) writeGroup(id, { lines: current.lines.filter((_, i) => i !== index) });
                                },
                            },
                            icon('x', 's14'),
                        ),
                    );
                });
                const list = h('div', { class: 'cal-gls' }, ...rows);
                const highlight = checkRow({ label: 'Zvýraznit (tísňová čísla)', checked: group.highlight === true, attrs: { 'data-group-highlight': '' }, onChange: (on) => writeGroup(id, { highlight: on }) });
                const full = group.lines.length >= CONTENT_LIMITS.lines;
                children.push(
                    list,
                    h(
                        'div',
                        { class: 'cal-gf' },
                        h('button', { type: 'button', class: 'btn xs btn-ghost', 'data-line-add': '', disabled: full, title: full ? `Skupina může mít nejvýše ${CONTENT_LIMITS.lines} řádků` : null, onClick: () => addLine(id) }, icon('plus', 's14'), 'Řádek'),
                        highlight,
                    ),
                );
                if (rows.length > 1) {
                    sortables.push(
                        Sortable.create(list, {
                            handle: '.cal-grip',
                            draggable: '.cal-gl',
                            animation: 120,
                            forceFallback: true,
                            fallbackClass: 'cal-sp-ghost',
                            chosenClass: 'drag',
                            onEnd: () => {
                                const current = groupOf(id);
                                if (!current) return;
                                const order = [...list.querySelectorAll('[data-line]')].map((el) => Number(el.dataset.line));
                                const lines = order.map((i) => current.lines[i]).filter(Boolean);
                                if (lines.length === current.lines.length && order.some((i, k) => i !== k)) writeGroup(id, { lines });
                                // the rows are bound to their index: always rebuild them in the stored order
                                build();
                            },
                        }),
                    );
                }
            }
            return h('section', { class: `cal-g${isOpen ? ' open' : ''}${group.highlight ? ' hl' : ''}`, 'data-group': id }, ...children);
        });

        const list = h('div', { class: 'cal-gs' }, ...cards);
        if (cards.length > 1) {
            sortables.push(
                Sortable.create(list, {
                    handle: '.cal-gh > .cal-grip',
                    draggable: '.cal-g',
                    animation: 120,
                    forceFallback: true,
                    fallbackClass: 'cal-sp-ghost',
                    chosenClass: 'drag',
                    onEnd: () => {
                        const ids = [...list.querySelectorAll(':scope > [data-group]')].map((el) => el.dataset.group);
                        // the other page's groups keep their place relative to each other
                        const scoped = Object.fromEntries(pageGroupsOf(content(), groupPage));
                        const changed = reorder(scoped, ids);
                        const ops = Object.entries(changed).map(([id, group]) => ({ c: 'pageGroups', id, v: group, label: page().title }));
                        if (ops.length) store.applyOps(ops);
                        build();
                    },
                }),
            );
        }
        const total = Object.keys(content().pageGroups).length;
        const full = total >= CONTENT_LIMITS.groups;
        const add = h(
            'button',
            {
                type: 'button',
                class: 'btn sm btn-tertiary cal-gadd',
                'data-group-add': '',
                disabled: full,
                title: full ? `Skupin může být nejvýše ${CONTENT_LIMITS.groups}` : null,
                onClick: () => {
                    const id = newId();
                    open.add(id);
                    pendingFocus = `[data-group="${id}"] [data-group-heading]`;
                    store.putItem('pageGroups', id, { page: groupPage, heading: '', lines: [{ label: '', value: '' }], highlight: false, pos: nextPos(content().pageGroups) }, page().title);
                },
            },
            icon('plus', 's16'),
            addLabel,
        );
        return h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, label), cards.length ? null : h('span', { class: 'cap' }, 'Zatím tu nic není. Skupina má nadpis a řádky — volný text, nebo popisek a hodnotu (Telefon · 558 733 131).'), list, add);
    }

    function addLine(id) {
        const current = groupOf(id);
        if (!current || current.lines.length >= CONTENT_LIMITS.lines) return;
        pendingFocus = `[data-group="${id}"] [data-line="${current.lines.length}"] [data-line-label]`;
        writeGroup(id, { lines: [...current.lines, { label: '', value: '' }] });
    }

    // ---- the sections of each page ---------------------------------------------------------------

    function sections() {
        const p = page();
        const k = key();
        if (!k) return [];
        const year = store.getState().doc.design.year;
        const layout = layoutOf(p);
        const L = CONTENT_LIMITS;
        const out = [];
        const note = (text) => h('p', { class: 'cap cal-pcn' }, text);

        if (k === 'cover') {
            if (designer) out.push(coverLayouts());
            if (layout !== 3) {
                out.push(textField('title', 'Titulek', L.title, { placeholder: `Kalendář ${year}` }).el);
                out.push(textField('subtitle', 'Podtitul', L.subtitle, { placeholder: 'např. název obce' }).el);
                out.push(textField('note', 'Doplňkový řádek', L.coverNote, { placeholder: 'např. www.obec.cz' }).el);
            } else out.push(note('Rozvržení „Jen fotografie“ je pro hotovou grafiku titulní strany — texty se na ni netisknou.'));
            if (layout !== 2) out.push(photoField('Titulní fotografie', 'JPG, PNG nebo WebP. Fotka pokryje celou plochu.'));
            if (designer) {
                out.push(
                    setupSegment('logo', 'Logo', [
                        { value: 'none', label: 'Bez loga' },
                        { value: 'tl', label: 'Vlevo' },
                        { value: 'tc', label: 'Uprostřed' },
                        { value: 'tr', label: 'Vpravo' },
                        { value: 'title', label: 'U titulku' },
                    ]),
                );
                out.push(
                    setupSegment('logoSize', 'Velikost loga', [
                        { value: 's', label: 'Malé' },
                        { value: 'm', label: 'Střední' },
                        { value: 'l', label: 'Velké' },
                    ]),
                );
                const styles = store.getState().doc.design.layout ? store.getState().doc.design.layout.styles : null;
                if (!styles || !styles.logo.asset) out.push(note('Logo se bere ze stylu kalendáře — nahrajete ho u prvku Logo ve Stylu stránek. Leží nad fotografií.'));
            }
        } else if (k === 'sp:intro') {
            out.push(textField('heading', 'Nadpis', L.heading, { placeholder: p.title }).el);
            out.push(textField('salutation', 'Oslovení', L.salutation, { placeholder: 'např. Milí občané,' }).el);
            out.push(bodyField());
            out.push(textField('signName', 'Podpis — jméno', L.signName, { placeholder: 'např. Ing. Jana Nováková' }).el);
            out.push(textField('signRole', 'Podpis — funkce', L.signRole, { placeholder: 'např. starostka obce' }).el);
            if (layout !== 1) out.push(photoField(layout === 2 ? 'Portrét' : 'Fotografie', 'JPG, PNG nebo WebP. Průhledné PNG zůstane bez pozadí.'));
            if (designer) {
                out.push(
                    setupSegment('align', 'Zarovnání textu', [
                        { value: 'left', label: 'Vlevo' },
                        { value: 'justify', label: 'Do bloku' },
                    ]),
                );
            }
        } else if (k === 'sp:contacts') {
            out.push(textField('heading', 'Nadpis', L.heading, { placeholder: p.title }).el);
            out.push(groupsEditor('sp:contacts', 'Kontakty', 'Přidat skupinu'));
            if (layout === 3) out.push(photoField('Fotografie', 'JPG, PNG nebo WebP.'));
            if (designer) out.push(h('div', { class: 'cal-p4s' }, h('span', { class: 'lbl' }, 'Na stránce dále'), setupCheck('notes', 'Místo na poznámky (linky)'), setupCheck('clubLegend', 'Vysvětlivky spolků'), setupCheck('wasteLegend', 'Legenda svozu odpadů')));
            if (setup().notes) out.push(textField('notesHeading', 'Nadpis poznámek', L.notesHeading, { placeholder: 'Poznámky' }).el);
        } else if (k === 'sp:year') {
            out.push(textField('heading', 'Nadpis', L.heading, { placeholder: `${p.title} ${year + (p.special.item.nextYear ? 1 : 0)}` }).el);
            out.push(textField('note', 'Poznámka pod mřížkou', L.note, { placeholder: 'např. Státní svátky jsou vyznačeny červeně.' }).el);
        } else if (k === 'sp:waste') {
            out.push(textField('heading', 'Nadpis', L.heading, { placeholder: `${p.title} ${year}` }).el);
            out.push(textField('note', 'Poznámka pod mřížkou', L.note, { placeholder: 'např. Přistavujte prosím odpady před dnem svozu.' }).el);
            if (layout === 1) out.push(groupsEditor('sp:waste', 'Texty vedle mřížky', 'Přidat blok'));
            else if (designer) out.push(note('Texty vedle mřížky (velkoobjemový odpad, sběrný dvůr…) nabízí rozvržení „Mřížka + texty“.'));
        } else if (k === 'sp:notes') {
            out.push(textField('heading', 'Nadpis', L.heading, { placeholder: p.title }).el);
        } else if (k === 'back') {
            if (p.backVariant === 'contacts') {
                out.push(textField('name', 'Název', L.name, { placeholder: 'např. Obec Chotěbuz' }).el);
                out.push(textField('address', 'Adresa', L.address, { rows: 3, maxLines: L.addressLines, placeholder: 'Ulice a číslo\nPSČ a obec' }).el);
                out.push(textField('phone', 'Telefon', L.phone, { placeholder: '+420 …' }).el);
                out.push(textField('email', 'E-mail', L.email).el);
                out.push(textField('web', 'Web', L.web, { placeholder: 'www.…' }).el);
                out.push(textField('imprint', 'Tiráž', L.imprint, { placeholder: 'např. Vydala obec … · Grafika: …' }).el);
                if (designer) out.push(h('div', { class: 'cal-p4s' }, setupCheck('logo', 'Zobrazit logo')));
            } else {
                out.push(textField('heading', 'Nadpis', L.heading, { placeholder: `Přehled roku ${year}` }).el);
                out.push(textField('footer', 'Řádek pod přehledem', L.footer).el);
                out.push(textField('imprint', 'Tiráž', L.imprint, { placeholder: 'např. Vydala obec … · Grafika: …' }).el);
                out.push(note('Se zapnutým Ročním kalendáriem nese zadní strana místo přehledu roku kontakty.'));
            }
        }
        return out.filter(Boolean);
    }

    /** What decides which fields exist. */
    function signatureOf() {
        const p = page();
        const k = key();
        if (!k) return '';
        const groups = k === 'sp:contacts' || k === 'sp:waste' ? pageGroupsOf(content(), k).map(([id, g]) => `${id}:${g.lines.length}:${open.has(id) ? 1 : 0}:${g.highlight ? 1 : 0}`) : [];
        const s = setup();
        return [k, p.title, layoutOf(p), p.backVariant || '', s && s.notes ? 1 : 0, store.getState().doc.design.year, groups.join(',')].join('|');
    }

    function build() {
        for (const sortable of sortables) sortable.destroy();
        sortables = [];
        syncs = [];
        body.dataset.pageContent = key() || '';
        body.replaceChildren(...sections());
        // after the sections: building them decides which group starts open
        signature = signatureOf();
        for (const sync of syncs) sync();
        if (pendingFocus) {
            const target = body.querySelector(pendingFocus);
            pendingFocus = null;
            if (target) target.focus({ preventScroll: false });
        }
    }

    function refresh() {
        if (signatureOf() !== signature) build();
        else for (const sync of syncs) sync();
    }

    build();
    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || event.type === 'ui' || (event.type === 'layout' && event.live)) return;
        refresh();
    });

    return {
        destroy() {
            unsubscribe();
            clearTimeout(coverLayouts.timer);
            for (const sortable of sortables) sortable.destroy();
            root.replaceChildren();
        },
    };
}
