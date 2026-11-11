// Everything a user can do ON the large step-5 page (handoff §4.7, §10.14; architecture §D.7
// "Step 5"). Owner: phase P7a; used only by `step5.js`. CSS: calendar-preview.css.
//
// `mountStep5Overlay(overlayRoot, ctx, opts): { update(geometry, scale, page, view), outerSides(geometry), isCompact(geometry, scale), destroy() }`
//   overlayRoot    element lying exactly over the page picture (its origin = the SVG's top-left)
//   opts.controls  element that receives the photo controls row (`.cal-5ph`)
//   opts.picture   `() => SVGElement | null` — the page SVG; used ONLY to move the photo's
//                  `<image>` while the user drags or zooms (no re-render and no re-decoding of a
//                  print-size photo per pointer event). Positions never come from SVG nodes.
//   update(geometry, scale, page, view)
//                  `geometry` = `store.geometry(page)` (null for a non-week page), `scale` = px per
//                  mm, `view = { box: viewBox in mm, area: { x0, y0, x1, y1 } }` — `area` is the
//                  part of the page that is drawn (bleed box for the designer, trim for the client);
//                  `view.compact` = the day rows are too low for a button each (see below)
//   isCompact(geometry, scale)
//                  true when a day row is lower than `COMPACT_ROW` px at this scale (a phone, most of
//                  all a 14-day page): round buttons per row would overlap their neighbours. Then
//                  the ROW ITSELF is the button — `button.cal-5row[data-day-row="<date>"]` lies over
//                  each day row and opens the same plus menu — there are no `[data-day-plus]`
//                  buttons, and the warning / comment marks shrink to the row height.
//                  `.cal-5o.compact` marks the mode; `step5.js` narrows the gutters and shows the hint.
//
// What it draws (elements are reused between updates, so focus survives a re-render):
//   .cal-5ps[data-photo-surface]      drag surface over the photo frame: dragging pans the crop
//   [data-photo-upload] (file input), [data-photo-zoom] (range 100–300), [data-photo-remove]
//   [data-caption-edit]               pencil at the caption → caption editor (`n / 80 znaků`)
//   [data-day-plus="<date>"]          plus per day row, on the OUTER side of its frame
//   [data-day-row="<date>"]           compact mode only: the day row as the button of the plus menu
//   [data-day-comments="<date>"]      bubble with the number of comments → the thread
//   [data-day-warning="<date>"], [data-photo-warning]   warning marks (title = the messages)
// Popovers carry `data-cal-popover="plus | waste | event | comment | caption"`; inside:
//   [data-plus-option="waste|event|comment"], [data-day-remove="<collection>/<id>"],
//   [data-field="kind|name|club|text|caption"], [data-counter]
//
// Every change is a content item write with a label (`putItem` / `removeItem`), so it is saved
// item-wise and the server lists it in Historie. Nothing here touches the layout.

import { parts } from '../generate/dates.js';
import { LIMITS } from '../model/defaults.js';
import { newId, sorted } from '../model/content.js';
import { isElementVisible } from '../model/elements.js';
import { h, icon, relativeTime } from './dom.js';
import { closePopover, openPopover } from './popover.js';
import { dayWarnings, photoWarnings, warningTip } from './warnings.js';

const CAPTION_MAX = 80;
const EVENT_MAX = 80;
const COMMENT_MAX = 500;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const GAP = 6;
/** Below this row height (px) the per-day buttons are replaced by tappable rows. */
export const COMPACT_ROW = 20;
const DRAG_PX = 3;

const clamp01 = (v) => Math.min(1, Math.max(0, v));
const round4 = (v) => Math.round(v * 10000) / 10000;
const dayLabel = (date) => {
    const p = parts(date);
    return `${p.d}. ${p.m}.`;
};
const dayTitle = (date) => {
    const p = parts(date);
    return `${p.d}. ${p.m}. ${p.y}`;
};

/** Drawn size (mm) of a photo in its frame: cover scale × zoom — the maths of `photoIn`. */
function drawnSize(box, asset, zoom) {
    const ratio = asset.width / asset.height;
    const dh = Math.max(box.w / ratio, box.h) * Math.max(1, zoom || 1);
    return { dw: dh * ratio, dh };
}

/** Which page sides carry day buttons: a frame's outer side is the one nearer to its page edge. */
export function outerSides(geometry, pageWidth) {
    const sides = { left: false, right: false };
    if (!geometry) return sides;
    for (const frame of geometry.frames) {
        if (frame.box.x + frame.box.w / 2 < pageWidth / 2) sides.left = true;
        else sides.right = true;
    }
    return sides;
}

export function mountStep5Overlay(overlayRoot, ctx, opts = {}) {
    const { store, env } = ctx;
    const nodes = new Map();
    let last = { geometry: null, scale: 1, page: null, view: null };
    let pageKey = null;
    let busy = false;

    const design = () => store.getState().doc.design;
    const content = () => store.getState().doc.content;
    const photoItem = () => (last.page ? content().pagePhotos[last.page.key] || null : null);
    const assetOf = (item) => (item && env.assets[item.asset]) || null;
    const photoBox = () => design().layout.boxes.photo;

    // ---- photo controls (built once, updated in place) ---------------------------------------

    const fileInput = h('input', { type: 'file', class: 'sr', accept: PHOTO_TYPES.join(','), 'data-photo-upload': true });
    const uploadText = h('span', null, 'Nahrát fotku');
    const uploadLabel = h('label', { class: 'btn sm btn-tertiary cal-5up' }, fileInput, icon('image', 's16'), uploadText);
    const zoomInput = h('input', { type: 'range', min: String(LIMITS.photoZoom.min * 100), max: String(LIMITS.photoZoom.max * 100), step: '1', value: '100', 'aria-label': 'Zvětšení fotky', 'data-photo-zoom': true });
    const zoomValue = h('output', { class: 'cal-5zv' }, '100 %');
    const zoomWrap = h('span', { class: 'cal-5z' }, icon('minus', 's14'), zoomInput, icon('plus', 's14'), zoomValue);
    const hint = h('span', { class: 'cap cal-5hint' }, 'Fotku posunete tažením');
    const removeButton = h('button', { type: 'button', class: 'btn icon sm btn-ghost', 'data-photo-remove': true, 'aria-label': 'Odebrat fotku', title: 'Odebrat fotku' }, icon('trash', 's16'));
    const controls = h('div', { class: 'cal-5ph', hidden: true }, uploadLabel, zoomWrap, hint, removeButton);
    if (opts.controls) opts.controls.append(controls);

    const photoImage = () => {
        const svg = opts.picture ? opts.picture() : null;
        return svg ? svg.querySelector('g[data-el="photo"] image') : null;
    };

    /** Moves the photo's `<image>` without a re-render (live feedback of a pan or a zoom). */
    function previewCrop(crop) {
        const image = photoImage();
        const asset = assetOf(photoItem());
        if (!image || !asset || !(asset.width > 0) || !(asset.height > 0)) return;
        const box = photoBox();
        const { dw, dh } = drawnSize(box, asset, crop.zoom);
        image.setAttribute('x', String(box.x + (box.w - dw) * crop.px));
        image.setAttribute('y', String(box.y + (box.h - dh) * crop.py));
        image.setAttribute('width', String(dw));
        image.setAttribute('height', String(dh));
    }

    function writeCrop(patch) {
        const item = photoItem();
        if (!item || !last.page) return;
        store.putItem('pagePhotos', last.page.key, { asset: item.asset, zoom: item.zoom, px: item.px, py: item.py, ...patch }, last.page.title);
    }

    async function upload(file) {
        const page = last.page;
        if (!file || !page || busy) return;
        if (!PHOTO_TYPES.includes(file.type)) {
            ctx.toast('Fotka musí být JPG, PNG nebo WebP.', true);
            return;
        }
        if (env.maxFileSize && file.size > env.maxFileSize) {
            ctx.toast('Soubor je větší než 10 MB.', true);
            return;
        }
        busy = true;
        uploadLabel.classList.add('busy');
        fileInput.disabled = true;
        try {
            const info = await ctx.api.upload(file, 'photo');
            store.addAsset(info);
            // a new or replaced photo always starts centred at 100 % (handoff §8)
            store.putItem('pagePhotos', page.key, { asset: info.id, zoom: 1, px: 0.5, py: 0.5 }, page.title);
        } catch (error) {
            ctx.toast((error && error.message) || 'Soubor se nepodařilo nahrát', true);
        } finally {
            busy = false;
            uploadLabel.classList.remove('busy');
            fileInput.disabled = false;
        }
    }

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        upload(file);
    });
    zoomInput.addEventListener('input', () => {
        const item = photoItem();
        if (!item) return;
        const zoom = Number(zoomInput.value) / 100;
        zoomValue.textContent = `${zoomInput.value} %`;
        previewCrop({ zoom, px: item.px, py: item.py });
    });
    zoomInput.addEventListener('change', () => {
        const zoom = Math.min(LIMITS.photoZoom.max, Math.max(LIMITS.photoZoom.min, Number(zoomInput.value) / 100));
        const item = photoItem();
        if (item && zoom !== item.zoom) writeCrop({ zoom });
    });
    removeButton.addEventListener('click', () => {
        if (photoItem() && last.page) store.removeItem('pagePhotos', last.page.key, last.page.title);
    });

    // ---- photo drag surface -------------------------------------------------------------------

    const surface = h('div', { class: 'cal-5ps', 'data-photo-surface': true, hidden: true });
    overlayRoot.append(surface);

    surface.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        const item = photoItem();
        const asset = assetOf(item);
        if (!item || !asset || !(asset.width > 0) || !(asset.height > 0)) return;
        const box = photoBox();
        const { dw, dh } = drawnSize(box, asset, item.zoom);
        const freeX = dw - box.w > 0.01;
        const freeY = dh - box.h > 0.01;
        if (!freeX && !freeY) return;
        event.preventDefault();
        closePopover();
        const scale = last.scale;
        const start = { x: event.clientX, y: event.clientY, px: item.px, py: item.py };
        let crop = null;
        surface.setPointerCapture(event.pointerId);
        surface.classList.add('drag');
        const move = (e) => {
            const dx = e.clientX - start.x;
            const dy = e.clientY - start.y;
            if (!crop && Math.hypot(dx, dy) < DRAG_PX) return;
            // the image moves with the pointer: x = box.x + (box.w − dw) · px
            crop = {
                zoom: item.zoom,
                px: freeX ? clamp01(start.px + dx / scale / (box.w - dw)) : start.px,
                py: freeY ? clamp01(start.py + dy / scale / (box.h - dh)) : start.py,
            };
            previewCrop(crop);
        };
        const up = () => {
            surface.removeEventListener('pointermove', move);
            surface.removeEventListener('pointerup', up);
            surface.removeEventListener('pointercancel', up);
            surface.classList.remove('drag');
            if (crop) writeCrop({ px: round4(crop.px), py: round4(crop.py) });
        };
        surface.addEventListener('pointermove', move);
        surface.addEventListener('pointerup', up);
        surface.addEventListener('pointercancel', up);
    });

    // ---- popovers -----------------------------------------------------------------------------

    const kindsOn = () => (design().features.waste ? sorted(content().wasteKinds, 'pos').filter(([, kind]) => kind.on) : []);
    const eventsOn = () => design().features.events === true;
    const refocus = (selector) => () => overlayRoot.querySelector(selector);

    function counter(input, max) {
        const out = h('span', { class: 'cap cal-5cnt', 'data-counter': true, 'aria-live': 'polite' });
        const sync = () => {
            out.textContent = `${input.value.length} / ${max} znaků`;
        };
        input.addEventListener('input', sync);
        sync();
        return out;
    }

    function wasteContent(date) {
        const kinds = kindsOn();
        const select = h('select', { 'aria-label': 'Druh odpadu', 'data-field': 'kind' }, ...kinds.map(([id, kind]) => h('option', { value: id }, kind.name)));
        return {
            kind: 'waste',
            title: `Odpad — ${dayTitle(date)}`,
            body: h('span', { class: 'inp sel' }, select, icon('chev-down', 's14')),
            confirmLabel: 'Přidat',
            onConfirm: () => {
                const entry = kinds.find(([id]) => id === select.value);
                if (!entry) return false;
                const exists = Object.values(content().wasteExtras).some((extra) => extra.kind === entry[0] && extra.date === date);
                // an extra collection on this date only — the kind's regular rule is untouched
                if (!exists) store.putItem('wasteExtras', newId(), { kind: entry[0], date }, dayLabel(date));
                ctx.toast(`Svoz přidán — ${entry[1].name}, ${dayLabel(date)}`);
                return true;
            },
        };
    }

    function eventContent(date) {
        const clubs = sorted(content().clubs, 'pos');
        const name = h('input', { type: 'text', maxlength: String(EVENT_MAX), placeholder: 'Název akce', 'aria-label': 'Název akce', 'data-field': 'name', autocomplete: 'off' });
        const club = clubs.length
            ? h('select', { 'aria-label': 'Pořadatel', 'data-field': 'club' }, h('option', { value: '' }, 'Bez pořadatele'), ...clubs.map(([id, item]) => h('option', { value: id }, item.name || item.code)))
            : null;
        return {
            kind: 'event',
            title: `Akce — ${dayTitle(date)}`,
            body: h('div', { class: 'cal-pop-form' }, h('span', { class: 'inp' }, name), counter(name, EVENT_MAX), club ? h('span', { class: 'inp sel' }, club, icon('chev-down', 's14')) : null),
            confirmLabel: 'Přidat',
            onConfirm: () => {
                const text = name.value.trim();
                if (!text) {
                    name.focus();
                    return false;
                }
                store.putItem('events', newId(), { date, name: text.slice(0, EVENT_MAX), club: club && club.value ? club.value : null, icon: null }, dayLabel(date));
                ctx.toast(`Akce přidána — ${dayLabel(date)}`);
                return true;
            },
        };
    }

    function commentContent(date) {
        const thread = sorted(content().comments, 'date')
            .filter(([, item]) => item.date === date)
            .sort((a, b) => String(a[1].at || '').localeCompare(String(b[1].at || '')));
        const text = h('textarea', { rows: '3', maxlength: String(COMMENT_MAX), placeholder: 'Napište komentář…', 'aria-label': 'Komentář', 'data-field': 'text' });
        return {
            kind: 'comment',
            title: `Komentář — ${dayTitle(date)}`,
            body: h(
                'div',
                { class: 'cal-pop-form' },
                thread.length
                    ? h(
                          'div',
                          { class: 'cal-5cms', 'data-comment-thread': true },
                          ...thread.map(([, item]) => h('div', { class: 'cal-5cm' }, h('b', null, item.author === 'client' ? 'Klient' : 'Grafik'), h('span', null, item.text), h('span', { class: 'cap' }, relativeTime(item.at)))),
                      )
                    : null,
                h('span', { class: 'inp area' }, text),
            ),
            confirmLabel: 'Odeslat',
            onConfirm: () => {
                const value = text.value.trim();
                if (!value) {
                    text.focus();
                    return false;
                }
                // author and time are the server's business; these are what the page shows until the save
                store.putItem('comments', newId(), { date, text: value.slice(0, COMMENT_MAX), author: env.mode === 'designer' ? 'designer' : 'client', at: new Date().toISOString() }, dayLabel(date));
                return true;
            },
        };
    }

    function plusContent(date, pop) {
        const options = [];
        if (kindsOn().length) options.push(['waste', 'trash', 'Odpad', 'svoz v tento den', wasteContent]);
        if (eventsOn()) options.push(['event', 'calendar', 'Akce', 'událost v kalendáři', eventContent]);
        options.push(['comment', 'edit', 'Komentář', 'poznámka pro grafika', commentContent]);

        const kinds = content().wasteKinds;
        const added = [];
        if (design().features.waste) {
            for (const [id, extra] of Object.entries(content().wasteExtras)) {
                if (extra.date === date) added.push({ collection: 'wasteExtras', id, name: (kinds[extra.kind] && kinds[extra.kind].name) || 'Odpad', iconName: 'trash' });
            }
        }
        if (eventsOn()) {
            for (const [id, event] of sorted(content().events, 'date')) {
                if (event.date === date && (event.name || '').trim() !== '') added.push({ collection: 'events', id, name: event.name.trim(), iconName: 'calendar' });
            }
        }
        const body = h(
            'div',
            { class: 'cal-pop-form' },
            h(
                'div',
                { class: 'cal-5ch', style: { gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` } },
                ...options.map(([key, iconName, label, sub, build]) => h('button', { type: 'button', 'data-plus-option': key, onClick: () => pop().setContent(build(date)) }, icon(iconName, 's20'), h('b', null, label), h('span', { class: 'cap' }, sub))),
            ),
            added.length
                ? h(
                      'div',
                      { class: 'cal-5day' },
                      h('h4', null, 'V tento den'),
                      h(
                          'ul',
                          null,
                          ...added.map((item) =>
                              h(
                                  'li',
                                  null,
                                  icon(item.iconName, 's14'),
                                  h('span', null, item.name),
                                  h(
                                      'button',
                                      {
                                          type: 'button',
                                          class: 'btn icon xs btn-ghost',
                                          'data-day-remove': `${item.collection}/${item.id}`,
                                          'aria-label': `Odebrat ${item.name}`,
                                          title: `Odebrat ${item.name}`,
                                          onClick: () => {
                                              store.removeItem(item.collection, item.id, dayLabel(date));
                                              pop().setContent(plusContent(date, pop));
                                          },
                                      },
                                      icon('x', 's14'),
                                  ),
                              ),
                          ),
                      ),
                  )
                : null,
        );
        return { kind: 'plus', title: `Přidat — ${dayTitle(date)}`, body };
    }

    function openPlus(date, anchor) {
        let pop = null;
        pop = openPopover({ ...plusContent(date, () => pop), anchor, returnFocus: refocus(`[data-day-plus="${date}"], [data-day-row="${date}"]`) });
    }

    function openComments(date, anchor) {
        openPopover({ ...commentContent(date), anchor, returnFocus: refocus(`[data-day-comments="${date}"], [data-day-plus="${date}"], [data-day-row="${date}"]`) });
    }

    function openCaption(anchor) {
        const page = last.page;
        if (!page) return;
        const stored = content().pageCaptions[page.key];
        const input = h('input', { type: 'text', maxlength: String(CAPTION_MAX), value: stored ? stored.text : '', 'aria-label': 'Popisek fotky', 'data-field': 'caption', autocomplete: 'off' });
        openPopover({
            kind: 'caption',
            title: 'Popisek fotky',
            body: h('div', { class: 'cal-pop-form' }, h('span', { class: 'inp' }, input), counter(input, CAPTION_MAX)),
            confirmLabel: 'Uložit',
            anchor,
            returnFocus: refocus('[data-caption-edit]'),
            onConfirm: () => {
                const text = input.value.trim().slice(0, CAPTION_MAX);
                if (text) store.putItem('pageCaptions', page.key, { text }, page.title);
                else if (content().pageCaptions[page.key]) store.removeItem('pageCaptions', page.key, page.title);
                return true;
            },
        });
    }

    // ---- page buttons -------------------------------------------------------------------------

    /** Reuses the node of `key` (so a focused button survives an update) or creates it. */
    function node(key, seen, create) {
        let el = nodes.get(key);
        if (!el) {
            el = create();
            nodes.set(key, el);
            overlayRoot.append(el);
        }
        seen.add(key);
        return el;
    }

    function put(el, x, y) {
        el.style.left = `${Math.round(x * 10) / 10}px`;
        el.style.top = `${Math.round(y * 10) / 10}px`;
    }

    function update(geometry, scale, page, view) {
        if (pageKey !== (page ? page.key : null)) {
            pageKey = page ? page.key : null;
            closePopover();
        }
        last = { geometry, scale, page, view };
        const seen = new Set();
        const d = design();
        const week = Boolean(geometry && page && page.kind === 'week' && d.layout && view);
        const X = (mm) => (mm - view.box.x) * scale;
        const Y = (mm) => (mm - view.box.y) * scale;

        // photo controls + drag surface
        const photoOn = week && isElementVisible(d, 'photo');
        const item = photoOn ? photoItem() : null;
        const asset = assetOf(item);
        controls.hidden = !photoOn;
        uploadText.textContent = item ? 'Vyměnit fotku' : 'Nahrát fotku';
        zoomWrap.hidden = !item;
        hint.hidden = !item;
        removeButton.hidden = !item;
        if (item && document.activeElement !== zoomInput) {
            zoomInput.value = String(Math.round(item.zoom * 100));
            zoomValue.textContent = `${zoomInput.value} %`;
        }
        surface.hidden = !photoOn;
        if (photoOn) {
            const box = d.layout.boxes.photo;
            const x0 = Math.max(box.x, view.area.x0);
            const y0 = Math.max(box.y, view.area.y0);
            const x1 = Math.min(box.x + box.w, view.area.x1);
            const y1 = Math.min(box.y + box.h, view.area.y1);
            put(surface, X(x0), Y(y0));
            surface.style.width = `${Math.max(0, (x1 - x0) * scale)}px`;
            surface.style.height = `${Math.max(0, (y1 - y0) * scale)}px`;
            surface.classList.toggle('can', Boolean(asset && asset.width > 0 && asset.height > 0));
        }

        if (week) {
            const warningsByDate = dayWarnings(ctx, page.index);
            const comments = store.dayContext().commentsByDate;
            const W = d.page.width;
            const compact = view.compact === true;
            overlayRoot.classList.toggle('compact', compact);
            let size = 22;
            // compact: only marks are left, and they must fit their row
            for (const frame of geometry.frames) size = Math.min(size, compact ? Math.max(8, Math.min(14, Math.floor(frame.rowHeight * scale) - 2)) : Math.max(16, Math.floor(frame.rowHeight * scale) - 2));
            overlayRoot.style.setProperty('--cal-5b', `${size}px`);
            const step = size + (compact ? 2 : 4);

            // warning mark of the photo
            const ofPhoto = photoWarnings(ctx, page.index);
            if (photoOn && ofPhoto.length) {
                const box = d.layout.boxes.photo;
                const mark = node('warn:photo', seen, () => h('span', { class: 'cal-5b wr', 'data-photo-warning': true, role: 'img' }, '!'));
                mark.title = warningTip(ofPhoto);
                mark.setAttribute('aria-label', mark.title);
                put(mark, X(Math.max(box.x, view.area.x0)) + 8, Y(Math.max(box.y, view.area.y0)) + 8);
            }

            // caption pencil at the top right corner of the caption box
            if (isElementVisible(d, 'cap')) {
                const box = d.layout.boxes.cap;
                const pencil = node('cap', seen, () =>
                    h('button', { type: 'button', class: 'cal-5b pen', 'data-caption-edit': true, 'aria-label': 'Upravit popisek', title: 'Upravit popisek', onClick: (event) => openCaption(event.currentTarget) }, icon('edit', 's14')),
                );
                const x = Math.min(Math.max(X(box.x + box.w) - size / 2, X(view.area.x0) + 2), X(view.area.x1) - size - 2);
                const y = Math.min(Math.max(Y(box.y) - size / 2, Y(view.area.y0) + 2), Y(view.area.y1) - size - 2);
                put(pencil, x, y);
            }

            for (const frame of geometry.frames) {
                const left = frame.box.x + frame.box.w / 2 < W / 2;
                const dir = left ? -1 : 1;
                const edge = left ? X(Math.max(frame.box.x, view.area.x0)) - GAP - size : X(Math.min(frame.box.x + frame.box.w, view.area.x1)) + GAP;
                for (const row of frame.rows) {
                    const y = Y(row.y + row.h / 2) - size / 2;
                    const date = row.date;
                    let slot = 0;
                    if (compact) {
                        // the row is the button: the whole day, as wide as its frame
                        const x0 = Math.max(frame.box.x, view.area.x0);
                        const x1 = Math.min(frame.box.x + frame.box.w, view.area.x1);
                        const hit = node(`row:${date}`, seen, () =>
                            h('button', { type: 'button', class: 'cal-5row', 'data-day-row': date, 'aria-label': `Přidat do dne ${dayLabel(date)}`, title: 'Přidat odpad, akci nebo komentář', onClick: (event) => openPlus(date, event.currentTarget) }),
                        );
                        put(hit, X(x0), Y(row.y));
                        hit.style.width = `${Math.max(0, Math.round((x1 - x0) * scale * 10) / 10)}px`;
                        hit.style.height = `${Math.max(0, Math.round(row.h * scale * 10) / 10)}px`;
                    } else {
                        const plus = node(`plus:${date}`, seen, () =>
                            h('button', { type: 'button', class: 'cal-5b pl', 'data-day-plus': date, 'aria-label': 'Přidat do dne', title: 'Přidat odpad, akci nebo komentář', onClick: (event) => openPlus(date, event.currentTarget) }, icon('plus', 's14')),
                        );
                        put(plus, edge, y);
                        slot = 1;
                    }
                    const rowWarnings = warningsByDate.get(date);
                    if (rowWarnings) {
                        const mark = node(`warn:${date}`, seen, () => h('span', { class: 'cal-5b wr', 'data-day-warning': date, role: 'img' }, '!'));
                        mark.title = warningTip(rowWarnings);
                        mark.setAttribute('aria-label', mark.title);
                        put(mark, edge + dir * step * slot, y);
                        slot += 1;
                    }
                    const count = (comments.get(date) || []).length;
                    if (count) {
                        const bubble = node(`com:${date}`, seen, () => h('button', { type: 'button', class: 'cal-5b cm', 'data-day-comments': date, onClick: (event) => openComments(date, event.currentTarget) }));
                        bubble.textContent = String(count);
                        bubble.setAttribute('aria-label', `Komentář — ${dayLabel(date)} (${count})`);
                        put(bubble, edge + dir * step * slot, y);
                    }
                }
            }
        } else {
            overlayRoot.classList.remove('compact');
        }

        for (const [key, el] of [...nodes]) {
            if (seen.has(key)) continue;
            el.remove();
            nodes.delete(key);
        }
    }

    return {
        update,
        outerSides: (geometry) => outerSides(geometry, design().page.width),
        isCompact: (geometry, scale) => Boolean(geometry) && geometry.frames.some((frame) => frame.rowHeight * scale < COMPACT_ROW),
        destroy() {
            closePopover();
            controls.remove();
            surface.remove();
            for (const el of nodes.values()) el.remove();
            nodes.clear();
        },
    };
}
