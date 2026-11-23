// „Upravit fotku“ — the one photo editor of the calendar (feedback K14, docs/calendar/photo-editor-plan.md).
// CSS: calendar-photo.css (`.cal-pe*`). Designer and client alike: the crop is page content.
//
// Used by every photo surface: step 5 (the page's photo toolbar), step 4 (the page panel's photo
// field), „Fotky a popisky“ (a row's pencil) and the photo field of the cover / intro / contacts
// pages (`ui/pagecontent.js`). The maths is `model/photo-crop.js` — the very functions the renderer
// places the picture with, so the editor cannot disagree with the page or the PDF.
//
// `mountPhotoEditor(root, options): { el, focus(), destroy() }` — the editor body, inline
//   options.frame     `{ x, y, w, h }` mm — the box the photo is cropped into (bleed part included)
//   options.trim      `{ x0, y0, x1, y1 }` mm — the trimmed page; where the frame runs past it a
//                     dashed line marks the cut (optional)
//   options.asset     the asset info (`{ id, url, previewUrl, width, height }`)
//   options.crop      the stored crop (`{ zoom, px, py, rotate?, flip? }`)
//   options.onPreview `(crop)` — every draft change (the caller may move the page's `<image>`)
//   options.onDone    `(item)` — „Hotovo“: the item to store (`cropItem` — optional keys only
//                     when not default); not called when nothing changed
//   options.onCancel  `()` — „Zrušit“ / Esc; the caller's preview has been reset to the stored crop
// `openPhotoEditor(options): { el, close() }` — the same body in a floating panel (`.cal-pop.wide
//   .cal-pe-pop`, draggable by its grip, a sheet docked to the bottom under 700 px); also
//   `options.anchor` (Element — the panel opens beside it) and `options.returnFocus`.
//   One panel at a time; `closePhotoEditor()` closes it (page change, step unmount).
//
// The draft lives here: nothing is written until „Hotovo“ (one content write = one History line);
// Esc / „Zrušit“ / ✕ discard. Keyboard (focus inside the editor): arrows pan 0.3 mm (Shift 3 mm),
// + / − zoom, [ / ] quarter turn, Enter = Hotovo, Esc = Zrušit.
//
// `weekPhotoEditing(ctx, page)` → `{ frame, trim, asset, crop, save(item) } | null` — the editor's
//   options for the photo of a week page (`pagePhotos[page.key]`), and its write; `null` while the
//   page has no photo with a known pixel size.
//
// DOM hooks: `[data-photo-editor]` (root), `[data-pe-stage]`, `[data-pe="zoom|straighten"]`
// (ranges), `[data-pe-action="rotate-left|rotate-right|mirror|centre|reset|done|cancel"]`,
// `[data-pe-info]` (pixels · dpi; `data-low` when under the print threshold).

import { makeDraggable } from '../../controllers/popover_drag.js';
import { K } from '../model/defaults.js';
import {
    STRAIGHTEN_MAX,
    ZOOM_MAX,
    ZOOM_MIN,
    centreCrop,
    cropItem,
    mirrorCrop,
    normalizeCrop,
    panBy,
    photoPlacement,
    resetCrop,
    rotateBy90,
    rotateTo,
    splitAngle,
    zoomTo,
} from '../model/photo-crop.js';
import { PHOTO_MIN_DPI } from '../render/warnings.js';
import { closePopover } from './popover.js';
import { h, icon } from './dom.js';

/** Pasteboard around the frame in the stage, as a share of the frame's size on each side. */
const PASTEBOARD = 0.16;
const DRAG_PX = 2;
const NBSP = ' ';

const fmt = (n, digits = 0) => n.toLocaleString('cs-CZ', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const sameCrop = (a, b) => JSON.stringify(cropItem('', a)) === JSON.stringify(cropItem('', b));

export function mountPhotoEditor(root, options) {
    const frame = options.frame;
    const asset = options.asset;
    const stored = normalizeCrop(options.crop);
    let draft = { ...stored };
    let ended = false;

    // ---- stage: the frame as a window, the whole picture around it -----------------------------

    const img = h('img', { class: 'cal-pe-img', alt: '', draggable: 'false', src: asset.previewUrl || asset.url });
    const windowEl = h('div', { class: 'cal-pe-win' }, h('span', { class: 'cal-pe-thirds', 'aria-hidden': 'true' }));
    const trimEl = h('div', { class: 'cal-pe-trim', 'aria-hidden': 'true', title: 'Ořez stránky' });
    const stage = h(
        'div',
        {
            class: 'cal-pe-stage',
            'data-pe-stage': '',
            tabindex: '0',
            role: 'application',
            'aria-label': 'Výřez fotky — tažením posunete, kolečkem přiblížíte, šipkami posunete o kousek',
            // the ratio holds and the height stays within --cal-pe-h (CSS): narrower rather than cut
            style: `--cal-pe-r: ${(frame.w / frame.h).toFixed(4)}; aspect-ratio: ${(frame.w / frame.h).toFixed(4)}`,
        },
        img,
        windowEl,
        trimEl,
    );

    // ---- controls ---------------------------------------------------------------------------------

    const zoom = h('input', { type: 'range', min: String(ZOOM_MIN * 100), max: String(ZOOM_MAX * 100), step: '1', 'aria-label': 'Přiblížení fotky', 'data-pe': 'zoom' });
    const zoomValue = h('output', { class: 'cal-5zv' });
    const straighten = h('input', { type: 'range', min: String(-STRAIGHTEN_MAX), max: String(STRAIGHTEN_MAX), step: '0.1', 'aria-label': 'Narovnat fotku', 'data-pe': 'straighten' });
    const straightenValue = h('output', { class: 'cal-5zv' });
    const button = (action, label, iconName, extra = {}) =>
        h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-pe-action': action, title: label, ...extra }, icon(iconName, 's16'), h('span', null, label));
    const rotateLeft = h('button', { type: 'button', class: 'btn icon sm btn-tertiary cal-pe-ccw', 'data-pe-action': 'rotate-left', 'aria-label': 'Otočit doleva o 90°', title: 'Otočit doleva o 90° ( [ )' }, icon('rotate', 's16'));
    const rotateRight = h('button', { type: 'button', class: 'btn icon sm btn-tertiary', 'data-pe-action': 'rotate-right', 'aria-label': 'Otočit doprava o 90°', title: 'Otočit doprava o 90° ( ] )' }, icon('rotate', 's16'));
    const mirror = button('mirror', 'Zrcadlit', 'swap-horizontal', { 'aria-pressed': 'false' });
    mirror.title = 'Zrcadlit vodorovně';
    const centre = button('centre', 'Na střed', 'center');
    const reset = button('reset', 'Obnovit', 'refresh');
    reset.title = 'Zpět na 100 %, na střed, bez otočení a zrcadlení';
    const info = h('span', { class: 'cap cal-pe-info', 'data-pe-info': '', 'aria-live': 'polite' });
    const cancel = h('button', { type: 'button', class: 'btn sm btn-ghost', 'data-pe-action': 'cancel' }, 'Zrušit');
    const done = h('button', { type: 'button', class: 'btn sm btn-primary', 'data-pe-action': 'done' }, icon('check', 's16'), 'Hotovo');

    const el = h(
        'div',
        { class: 'cal-pe', 'data-photo-editor': '' },
        stage,
        h(
            'div',
            { class: 'cal-pe-ctl' },
            h('label', { class: 'cal-pe-row' }, h('span', { class: 'lbl' }, 'Přiblížení'), h('span', { class: 'cal-5z' }, icon('minus', 's14'), zoom, icon('plus', 's14')), zoomValue),
            h('label', { class: 'cal-pe-row' }, h('span', { class: 'lbl' }, 'Narovnat'), h('span', { class: 'cal-5z' }, straighten), straightenValue),
            h('div', { class: 'cal-pe-btns' }, rotateLeft, rotateRight, mirror, centre, reset),
            info,
        ),
        h('div', { class: 'cal-pe-foot' }, cancel, done),
    );
    root.append(el);

    // ---- painting ---------------------------------------------------------------------------------

    function paint() {
        const W = stage.clientWidth;
        if (!(W > 0)) return;
        const s = W / (frame.w * (1 + 2 * PASTEBOARD)); // px per mm
        const ox = frame.w * PASTEBOARD; // frame origin in the stage, mm
        const oy = frame.h * PASTEBOARD;
        const X = (mm) => (mm - frame.x + ox) * s;
        const Y = (mm) => (mm - frame.y + oy) * s;
        Object.assign(windowEl.style, { left: `${X(frame.x)}px`, top: `${Y(frame.y)}px`, width: `${frame.w * s}px`, height: `${frame.h * s}px` });

        const p = photoPlacement(frame, asset, draft);
        if (p) {
            const cx = p.transform ? p.transform.cx : p.x + p.w / 2;
            const cy = p.transform ? p.transform.cy : p.y + p.h / 2;
            const rotate = p.transform ? p.transform.rotate : 0;
            const flip = p.transform ? p.transform.flip : false;
            Object.assign(img.style, {
                width: `${p.w * s}px`,
                height: `${p.h * s}px`,
                left: `${X(cx) - (p.w * s) / 2}px`,
                top: `${Y(cy) - (p.h * s) / 2}px`,
                transform: `rotate(${rotate}deg)${flip ? ' scaleX(-1)' : ''}`,
            });
        }

        const t = options.trim;
        const inside = t && (t.x0 > frame.x + 0.01 || t.y0 > frame.y + 0.01 || t.x1 < frame.x + frame.w - 0.01 || t.y1 < frame.y + frame.h - 0.01);
        trimEl.hidden = !inside;
        if (inside) {
            const x0 = Math.max(t.x0, frame.x);
            const y0 = Math.max(t.y0, frame.y);
            const x1 = Math.min(t.x1, frame.x + frame.w);
            const y1 = Math.min(t.y1, frame.y + frame.h);
            Object.assign(trimEl.style, { left: `${X(x0)}px`, top: `${Y(y0)}px`, width: `${(x1 - x0) * s}px`, height: `${(y1 - y0) * s}px` });
        }

        const z = Math.round(draft.zoom * 100);
        if (document.activeElement !== zoom) zoom.value = String(z);
        zoomValue.textContent = `${z}${NBSP}%`;
        const { straighten: st } = splitAngle(draft.rotate);
        if (document.activeElement !== straighten) straighten.value = String(st);
        straightenValue.textContent = `${fmt(st, 1)}°`;
        mirror.setAttribute('aria-pressed', String(draft.flip));
        mirror.classList.toggle('on', draft.flip);
        const dpi = p ? Math.round(p.dpi) : null;
        const low = dpi !== null && dpi < PHOTO_MIN_DPI;
        info.textContent = `${fmt(asset.width)} × ${fmt(asset.height)}${NBSP}px${dpi !== null ? ` · ${dpi}${NBSP}dpi${low ? ' — pro tisk málo, zmenšete přiblížení' : ''}` : ''}`;
        info.toggleAttribute('data-low', low);
    }

    function set(next) {
        if (ended) return;
        draft = normalizeCrop(next);
        paint();
        if (options.onPreview) options.onPreview(draft);
    }

    // ---- controls → draft -------------------------------------------------------------------------

    zoom.addEventListener('input', () => set(zoomTo(frame, asset, draft, Number(zoom.value) / 100)));
    straighten.addEventListener('input', () => {
        const { quarter } = splitAngle(draft.rotate);
        set(rotateTo(frame, asset, draft, quarter + Number(straighten.value)));
    });
    // a range's own arrow keys change its value; the editor's shortcuts stay off while it has focus
    rotateLeft.addEventListener('click', () => set(rotateBy90(frame, asset, draft, -1)));
    rotateRight.addEventListener('click', () => set(rotateBy90(frame, asset, draft, 1)));
    mirror.addEventListener('click', () => set(mirrorCrop(draft)));
    centre.addEventListener('click', () => set(centreCrop(draft)));
    reset.addEventListener('click', () => set(resetCrop()));
    done.addEventListener('click', () => finish(true));
    cancel.addEventListener('click', () => finish(false));

    function finish(commit) {
        if (ended) return;
        ended = true;
        if (commit && !sameCrop(draft, stored)) {
            options.onDone(cropItem(asset.id, draft));
            return;
        }
        if (options.onPreview) options.onPreview(stored);
        if (commit) options.onDone(null);
        else if (options.onCancel) options.onCancel();
    }

    // ---- pointer: drag pans, wheel / pinch zoom about the pointer ---------------------------------

    const pointers = new Map();
    let gesture = null;
    const mmPerPx = () => (frame.w * (1 + 2 * PASTEBOARD)) / stage.clientWidth;
    const pointMm = (clientX, clientY) => {
        const r = stage.getBoundingClientRect();
        const k = mmPerPx();
        return { x: frame.x - frame.w * PASTEBOARD + (clientX - r.left) * k, y: frame.y - frame.h * PASTEBOARD + (clientY - r.top) * k };
    };

    stage.addEventListener('pointerdown', (event) => {
        if (event.button > 0) return;
        event.preventDefault();
        stage.focus({ preventScroll: true });
        stage.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 1) gesture = { kind: 'pan', x: event.clientX, y: event.clientY, crop: draft, moved: false };
        else if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            gesture = { kind: 'pinch', d: Math.hypot(a.x - b.x, a.y - b.y) || 1, crop: draft, at: pointMm((a.x + b.x) / 2, (a.y + b.y) / 2) };
        }
    });
    stage.addEventListener('pointermove', (event) => {
        if (!pointers.has(event.pointerId) || !gesture) return;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (gesture.kind === 'pan') {
            const dx = event.clientX - gesture.x;
            const dy = event.clientY - gesture.y;
            if (!gesture.moved && Math.hypot(dx, dy) < DRAG_PX) return;
            gesture.moved = true;
            stage.classList.add('drag');
            const k = mmPerPx();
            set(panBy(frame, asset, gesture.crop, dx * k, dy * k));
        } else if (pointers.size >= 2) {
            const [a, b] = [...pointers.values()];
            const d = Math.hypot(a.x - b.x, a.y - b.y) || 1;
            set(zoomTo(frame, asset, gesture.crop, gesture.crop.zoom * (d / gesture.d), gesture.at));
        }
    });
    const up = (event) => {
        pointers.delete(event.pointerId);
        if (!pointers.size) {
            gesture = null;
            stage.classList.remove('drag');
        } else if (pointers.size === 1) {
            const [p] = [...pointers.values()];
            gesture = { kind: 'pan', x: p.x, y: p.y, crop: draft, moved: true };
        }
    };
    stage.addEventListener('pointerup', up);
    stage.addEventListener('pointercancel', up);
    stage.addEventListener(
        'wheel',
        (event) => {
            event.preventDefault();
            const factor = Math.exp(-event.deltaY * (event.deltaMode === 1 ? 0.05 : 0.0015));
            set(zoomTo(frame, asset, draft, draft.zoom * factor, pointMm(event.clientX, event.clientY)));
        },
        { passive: false },
    );

    // ---- keyboard ---------------------------------------------------------------------------------

    el.addEventListener('keydown', (event) => {
        if (event.isComposing) return;
        const onRange = event.target instanceof HTMLInputElement && event.target.type === 'range';
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            finish(false);
            return;
        }
        if (event.key === 'Enter' && !(event.target instanceof HTMLButtonElement)) {
            event.preventDefault();
            event.stopPropagation();
            finish(true);
            return;
        }
        if (onRange || event.ctrlKey || event.metaKey || event.altKey) return;
        const step = event.shiftKey ? K.NUDGE_BIG : K.NUDGE;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        let next = null;
        if (moves[event.key]) next = panBy(frame, asset, draft, ...moves[event.key]);
        else if (event.key === '+' || event.key === '=') next = zoomTo(frame, asset, draft, draft.zoom * 1.1);
        else if (event.key === '-' || event.key === '_') next = zoomTo(frame, asset, draft, draft.zoom / 1.1);
        else if (event.key === '[') next = rotateBy90(frame, asset, draft, -1);
        else if (event.key === ']') next = rotateBy90(frame, asset, draft, 1);
        if (!next) return;
        event.preventDefault();
        event.stopPropagation();
        set(next);
    });

    const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(() => paint()) : null;
    if (resize) resize.observe(stage);
    if (img.complete) paint();
    img.addEventListener('load', paint);
    requestAnimationFrame(paint);

    return {
        el,
        focus() {
            stage.focus({ preventScroll: true });
        },
        /** Ends the editor without a write (like „Zrušit“) unless it already ended. */
        cancel() {
            finish(false);
        },
        destroy() {
            if (resize) resize.disconnect();
            ended = true;
            el.remove();
        },
    };
}

/** The editor's options for the photo of a week page, and its write (see the header). */
export function weekPhotoEditing(ctx, page) {
    const { store, env } = ctx;
    const doc = store.getState().doc;
    const item = page ? doc.content.pagePhotos[page.key] : null;
    const asset = item ? env.assets[item.asset] : null;
    if (!item || !asset || !(asset.width > 0) || !(asset.height > 0) || !doc.design.layout) return null;
    return {
        frame: doc.design.layout.boxes.photo,
        trim: { x0: 0, y0: 0, x1: doc.design.page.width, y1: doc.design.page.height },
        asset: { ...asset, id: item.asset },
        crop: item,
        save(next) {
            // the photo may have been replaced meanwhile (another tab, the client): never crop a different one
            const now = store.getState().doc.content.pagePhotos[page.key];
            if (next && now && now.asset === next.asset) store.putItem('pagePhotos', page.key, next, page.title);
        },
    };
}

// ---- the floating panel -----------------------------------------------------------------------------

const EDGE = 12;
let current = null;

/** Closes the open photo editor without saving (page change, step unmount). */
export function closePhotoEditor() {
    if (current) current.close();
}

export function openPhotoEditor(options) {
    closePhotoEditor();
    closePopover();
    let closed = false;
    const grip = h('span', { class: 'cal-pop-grip', 'data-popover-grip': true, title: 'Přetažením přesunete', 'aria-hidden': 'true' }, icon('grip', 's14'));
    const x = h('button', { type: 'button', class: 'btn icon xs btn-ghost cal-pop-x', 'aria-label': 'Zavřít bez uložení', title: 'Zavřít bez uložení', onClick: () => editor.cancel() }, icon('x', 's16'));
    const body = h('div', { class: 'cal-pop-b' });
    const el = h(
        'div',
        { class: 'cal-pop wide cal-pe-pop', role: 'dialog', 'aria-label': options.title || 'Upravit fotku', 'data-cal-popover': 'photo' },
        h('div', { class: 'cal-pop-h' }, grip, h('b', { class: 'cal-pop-t' }, options.title || 'Upravit fotku'), h('span', { class: 'cal-pop-sp' }), x),
        body,
    );

    const end = (fn) => (arg) => {
        api.close();
        if (fn) fn(arg);
    };
    const editor = mountPhotoEditor(body, { ...options, onDone: end(options.onDone), onCancel: end(options.onCancel) });

    function clamp() {
        const r = el.getBoundingClientRect();
        el.style.left = `${Math.round(Math.max(EDGE, Math.min(window.innerWidth - r.width - EDGE, r.left)))}px`;
        el.style.top = `${Math.round(Math.max(EDGE, Math.min(window.innerHeight - r.height - EDGE, r.top)))}px`;
    }
    function place() {
        const a = options.anchor && typeof options.anchor.getBoundingClientRect === 'function' ? options.anchor.getBoundingClientRect() : null;
        const r = el.getBoundingClientRect();
        // beside the anchor when there is room on its right, else under it; always inside the window
        const left = a ? (a.right + 10 + r.width <= window.innerWidth - EDGE ? a.right + 10 : a.left + a.width / 2 - r.width / 2) : window.innerWidth - r.width - EDGE;
        const top = a ? (a.right + 10 + r.width <= window.innerWidth - EDGE ? a.top : a.bottom + 10) : 80;
        el.style.left = `${left}px`;
        el.style.top = `${top}px`;
        clamp();
    }
    const onResize = () => clamp();
    const onModal = () => editor.cancel();
    const onGripDown = () => document.addEventListener('pointerup', () => !closed && clamp(), { once: true });

    const api = {
        el,
        close() {
            if (closed) return;
            closed = true;
            const hadFocus = el.contains(document.activeElement);
            window.removeEventListener('resize', onResize);
            document.removeEventListener('show.bs.modal', onModal);
            editor.destroy();
            el.remove();
            if (current && current.el === el) current = null;
            const back = hadFocus && options.returnFocus ? options.returnFocus() : null;
            if (back && document.contains(back)) back.focus({ preventScroll: true });
        },
    };
    // „close“ from outside = discard: the page preview goes back to the stored crop first
    const discard = () => editor.cancel();
    current = { close: discard, el };

    document.body.append(el);
    place();
    makeDraggable(grip, el);
    grip.addEventListener('pointerdown', onGripDown);
    window.addEventListener('resize', onResize);
    document.addEventListener('show.bs.modal', onModal);
    editor.focus();
    return { el, close: discard };
}
