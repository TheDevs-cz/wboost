// Own layers of the layout (feedback K20, docs/calendar/custom-layers-plan.md): pictures and
// shapes the designer adds in step 3 › Vrstvy on top of the built-in elements. Pure.
//
// Where a layer lives (decision D1 — it is DESIGN, never a per-page override):
//
//   layout = {
//     v: 1,
//     boxes:  { …built-ins…, 'u:<id>': { x, y, w, h, hidden? } },   geometry — every box-generic
//                                                                  function (drag, snap, clamp,
//                                                                  bleed, mirror, layout files)
//                                                                  takes it for free
//     order:  [ 'qr', 'u:<id>', 'logo', … ],                       the z-position, index 0 = top,
//                                                                  one list with the built-ins
//     styles: { … },
//     layers: { '<id>': Layer },                                   everything else
//   }
//
// Invariant (`normalizeLayers` + `normalizeLayout` repair it, `CalendarDesignRules` refuses a
// layout that breaks it): every id of `layers` has exactly one `u:<id>` box and one `u:<id>`
// entry in `order`, and nothing else in `boxes` / `order` starts with `u:`. At most MAX_LAYERS.
//
// Layer = {
//   kind: 'image' | 'rect' | 'ellipse' | 'line',
//   name: string ≤ 60            '' = the numbered fallback („Obdélník 2“, numbered bottom = 1)
//   pages: 'weeks' | 'all' | [pageKey…]    which pages show it (P1: always 'weeks' from the UI;
//   except: [pageKey…]                     page sets come in P2 — D2: the default is every week page)
//   opacity: 0–100                         the whole layer, one <g opacity>
//   blend: one of the 16 PDF blend modes   (model/effects.js BLEND_VALUES)
//   image:   asset (id | null), fit 'contain' | 'cover', radius mm
//   rect:    fill { on, color, opacity }, stroke { on, color, width pt, style }, radius mm
//   ellipse: fill, stroke
//   line:    stroke { color, width pt, style, cap 'butt' | 'round' }
// }
//
// The PHP mirror is `Services/Calendar/CalendarLayerRules` (`CalendarLayerContractTest` compares
// the vocabularies through tests/Fixtures/calendar/dump-layers.mjs).

import { COLORS, K, clampLimit, roundMm } from './defaults.js';
import { BLEND_VALUES } from './effects.js';

export const LAYER_PREFIX = 'u:';
export const LAYER_KINDS = Object.freeze(['image', 'rect', 'ellipse', 'line']);
export const MAX_LAYERS = 50;
export const LAYER_NAME_MAX = 60;
export const MAX_LAYER_PAGES = 120;
export const LAYER_ID_PATTERN = /^[A-Za-z0-9_-]{1,40}$/;
/** The year/type-independent page keys of `generate/pages.js` (CalendarContentRules::PAGE_KEY_PATTERN). */
export const PAGE_KEY_PATTERN = /^(?:pre|post|w\d\d|cover|back|sp:[A-Za-z0-9_-]{1,40})$/;
export const PAGE_SETS = Object.freeze(['weeks', 'all']);
export const STROKE_STYLES = Object.freeze(['solid', 'dash', 'dot']);
export const LINE_CAPS = Object.freeze(['butt', 'round']);
export const IMAGE_FITS = Object.freeze(['contain', 'cover']);
/** Ids a plain object cannot hold (CalendarContentRules::RESERVED_IDS). */
const RESERVED_IDS = Object.freeze(['__proto__', 'constructor', 'prototype', 'hasOwnProperty', 'isPrototypeOf', 'propertyIsEnumerable', 'toLocaleString', 'toString', 'valueOf', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__']);

/** The keys of a normalised layer per kind, in the order `normalizeLayer` writes them. */
const COMMON_KEYS = ['kind', 'name', 'pages', 'except', 'opacity', 'blend'];
export const LAYER_KEYS = Object.freeze({
    image: Object.freeze([...COMMON_KEYS, 'asset', 'fit', 'radius']),
    rect: Object.freeze([...COMMON_KEYS, 'fill', 'stroke', 'radius']),
    ellipse: Object.freeze([...COMMON_KEYS, 'fill', 'stroke']),
    line: Object.freeze([...COMMON_KEYS, 'stroke']),
});
export const FILL_KEYS = Object.freeze(['on', 'color', 'opacity']);
export const STROKE_KEYS = Object.freeze(['on', 'color', 'width', 'style']);
export const LINE_STROKE_KEYS = Object.freeze(['color', 'width', 'style', 'cap']);

export const KIND_LABELS = Object.freeze({ image: 'Obrázek', rect: 'Obdélník', ellipse: 'Elipsa', line: 'Čára' });
export const KIND_ICONS = Object.freeze({ image: 'image', rect: 'square', ellipse: 'circle', line: 'minus' });

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const COLOR = /^#[0-9A-Fa-f]{6}$/;
const color = (value, fallback) => (typeof value === 'string' && COLOR.test(value) ? value.toUpperCase() : fallback);
const oneOf = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);
const finite = (v, fallback) => (typeof v === 'number' && Number.isFinite(v) ? v : fallback);

export const isLayerKey = (key) => typeof key === 'string' && key.startsWith(LAYER_PREFIX);
export const layerKey = (id) => `${LAYER_PREFIX}${id}`;
export const layerIdOf = (key) => (isLayerKey(key) ? key.slice(LAYER_PREFIX.length) : null);
export const isLayerId = (id) => typeof id === 'string' && LAYER_ID_PATTERN.test(id) && !RESERVED_IDS.includes(id);

/** A fresh layer of `kind` in `fillColor` (shapes) — the store / MCP add it with `addLayer`. */
export function newLayer(kind, fillColor = COLORS.band, extra = {}) {
    const base = { kind, name: '', pages: 'weeks', except: [], opacity: 100, blend: 'normal' };
    const c = color(fillColor, COLORS.band);
    if (kind === 'image') return normalizeLayer({ ...base, asset: null, fit: 'contain', radius: 0, ...extra });
    if (kind === 'line') return normalizeLayer({ ...base, stroke: { color: c, width: 1, style: 'solid', cap: 'butt' }, ...extra });
    const shape = { ...base, fill: { on: true, color: c, opacity: 100 }, stroke: { on: false, color: COLORS.ink, width: 1, style: 'solid' } };
    if (kind === 'rect') shape.radius = 0;
    return normalizeLayer({ ...shape, ...extra });
}

function pageList(value) {
    const out = [];
    for (const key of Array.isArray(value) ? value : []) {
        if (typeof key === 'string' && PAGE_KEY_PATTERN.test(key) && !out.includes(key)) out.push(key);
        if (out.length >= MAX_LAYER_PAGES) break;
    }
    return out;
}

function layerName(value) {
    if (typeof value !== 'string') return '';
    // one line, no control characters (the server refuses them), at most LAYER_NAME_MAX characters
    const text = value.replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim();
    return [...text].slice(0, LAYER_NAME_MAX).join('').trim();
}

/**
 * Defaults filled in, values clamped, unknown keys dropped. `null` for something that is not a
 * layer (no object, unknown kind). Pure and idempotent.
 */
export function normalizeLayer(raw) {
    if (!isObject(raw) || !LAYER_KINDS.includes(raw.kind)) return null;
    const kind = raw.kind;
    const pages = Array.isArray(raw.pages) ? pageList(raw.pages) : oneOf(raw.pages, PAGE_SETS, 'weeks');
    const out = {
        kind,
        name: layerName(raw.name),
        pages,
        // „Skrýt na této straně“ only makes sense on a set, not on a list of pages
        except: Array.isArray(pages) ? [] : pageList(raw.except),
        opacity: clampLimit('percent', finite(raw.opacity, 100)),
        blend: oneOf(raw.blend, BLEND_VALUES, 'normal'),
    };
    if (kind === 'image') {
        out.asset = typeof raw.asset === 'string' && raw.asset !== '' ? raw.asset : null;
        out.fit = oneOf(raw.fit, IMAGE_FITS, 'contain');
        out.radius = clampLimit('layerRadius', finite(raw.radius, 0));
        return out;
    }
    if (kind === 'line') {
        const s = isObject(raw.stroke) ? raw.stroke : {};
        out.stroke = {
            color: color(s.color, COLORS.ink),
            width: clampLimit('layerStroke', finite(s.width, 1)),
            style: oneOf(s.style, STROKE_STYLES, 'solid'),
            cap: oneOf(s.cap, LINE_CAPS, 'butt'),
        };
        return out;
    }
    const f = isObject(raw.fill) ? raw.fill : {};
    const s = isObject(raw.stroke) ? raw.stroke : {};
    out.fill = { on: typeof f.on === 'boolean' ? f.on : true, color: color(f.color, COLORS.band), opacity: clampLimit('percent', finite(f.opacity, 100)) };
    out.stroke = { on: s.on === true, color: color(s.color, COLORS.ink), width: clampLimit('layerStroke', finite(s.width, 1)), style: oneOf(s.style, STROKE_STYLES, 'solid') };
    if (kind === 'rect') out.radius = clampLimit('layerRadius', finite(raw.radius, 0));
    return out;
}

/** `layout.layers` normalised: valid ids only, at most MAX_LAYERS (the first ones), invalid items dropped. */
export function normalizeLayers(raw) {
    const out = {};
    if (!isObject(raw)) return out;
    let count = 0;
    for (const [id, item] of Object.entries(raw)) {
        if (count >= MAX_LAYERS) break;
        if (!isLayerId(id)) continue;
        const layer = normalizeLayer(item);
        if (!layer) continue;
        out[id] = layer;
        count++;
    }
    return out;
}

/** Is the layer drawn on `page` (a page of `buildPages`)? Its page set, minus `except`. */
export function layerOnPage(layer, page) {
    if (!layer || !page) return false;
    let on;
    if (Array.isArray(layer.pages)) on = layer.pages.includes(page.key);
    else if (layer.pages === 'all') on = true;
    else on = page.kind === 'week';
    return on && !(Array.isArray(layer.except) && layer.except.includes(page.key));
}

/**
 * The box a new layer gets: centred on the trim, sized relative to the page (the templates'
 * lesson) — a shape ¼ of the short trim edge, a line ½ of the width, a picture contain-fitted into
 * ⅓ of the page height with its own aspect ratio (`aspect` = width / height, when known).
 */
export function defaultLayerBox(kind, page, aspect) {
    const W = page.width;
    const H = page.height;
    let w;
    let h;
    if (kind === 'line') {
        w = W / 2;
        h = K.MIN_H;
    } else if (kind === 'image') {
        h = H / 3;
        w = aspect > 0 && Number.isFinite(aspect) ? h * aspect : h;
        if (w > W * 0.8) {
            w = W * 0.8;
            h = w / aspect;
        }
    } else {
        w = Math.min(W, H) / 4;
        h = w;
    }
    w = Math.max(K.MIN_W, w);
    h = Math.max(K.MIN_H, h);
    const x = roundMm((W - w) / 2);
    const y = roundMm((H - h) / 2);
    return { x, y, w: roundMm(roundMm(x + w) - x), h: roundMm(roundMm(y + h) - y) };
}

/** Display names: the stored name, else „<kind> n“ numbered per kind from the BOTTOM of the stack. */
export function layerLabels(layout) {
    const out = {};
    if (!layout || !isObject(layout.layers)) return out;
    const counts = {};
    const order = Array.isArray(layout.order) ? layout.order : [];
    for (let i = order.length - 1; i >= 0; i--) {
        const id = layerIdOf(order[i]);
        const layer = id !== null ? layout.layers[id] : null;
        if (!layer) continue;
        counts[layer.kind] = (counts[layer.kind] || 0) + 1;
        out[order[i]] = layer.name || `${KIND_LABELS[layer.kind]} ${counts[layer.kind]}`;
    }
    return out;
}

export function layerLabel(layout, key) {
    return layerLabels(layout)[key] || KIND_LABELS.rect;
}

/** Ids of the layers in stacking order, top first. */
export function layerIds(layout) {
    return (layout && Array.isArray(layout.order) ? layout.order : []).filter(isLayerKey).map(layerIdOf);
}

// ---- operations (pure; the UI runs them inside one `store.change`) -------------------------

/** `layout` with a new layer on top of the stack. */
export function addLayer(layout, id, layer, box) {
    const key = layerKey(id);
    return {
        ...layout,
        boxes: { ...layout.boxes, [key]: { ...box } },
        order: [key, ...layout.order.filter((k) => k !== key)],
        layers: { ...(layout.layers || {}), [id]: layer },
    };
}

export function removeLayer(layout, id) {
    const key = layerKey(id);
    if (!layout.layers || !layout.layers[id]) return layout;
    const boxes = { ...layout.boxes };
    delete boxes[key];
    const layers = { ...layout.layers };
    delete layers[id];
    return { ...layout, boxes, order: layout.order.filter((k) => k !== key), layers };
}

export function setLayer(layout, id, layer) {
    if (!layout.layers || !layout.layers[id]) return layout;
    return { ...layout, layers: { ...layout.layers, [id]: layer } };
}

/**
 * A copy of layer `id` as `newId`, directly ABOVE the original, shifted by `K.NUDGE_BIG` mm right
 * and down (back inside `bounds` `{ x0, y0, x1, y1 }` when that would leave the page).
 */
export function duplicateLayer(layout, id, newId, bounds) {
    const layer = layout.layers && layout.layers[id];
    const box = layout.boxes[layerKey(id)];
    if (!layer || !box) return layout;
    const step = K.NUDGE_BIG;
    let x = box.x + step;
    let y = box.y + step;
    if (bounds) {
        if (x + box.w > bounds.x1) x = Math.max(bounds.x0, box.x - step);
        if (y + box.h > bounds.y1) y = Math.max(bounds.y0, box.y - step);
    }
    const copyBox = { x: roundMm(x), y: roundMm(y), w: box.w, h: box.h };
    if (box.hidden) copyBox.hidden = true;
    const key = layerKey(id);
    const next = layerKey(newId);
    const order = [];
    for (const k of layout.order) {
        if (k === key) order.push(next);
        order.push(k);
    }
    return { ...layout, boxes: { ...layout.boxes, [next]: copyBox }, order, layers: { ...layout.layers, [newId]: JSON.parse(JSON.stringify(layer)) } };
}

/**
 * `target` (a layout that does not know the own layers — a preset's default, a scanned layout)
 * with the own layers of `source`: their items, boxes and their places in the stack (each at its
 * index of `source.order`). Layers `target` has of its own are replaced.
 */
export function carryLayers(target, source) {
    const ids = layerIds(source).filter((id) => source.layers && source.layers[id] && source.boxes[layerKey(id)]);
    const boxes = {};
    for (const [key, box] of Object.entries(target.boxes || {})) if (!isLayerKey(key)) boxes[key] = box;
    const order = (target.order || []).filter((k) => !isLayerKey(k));
    const layers = {};
    source.order.forEach((key, index) => {
        const id = layerIdOf(key);
        if (id === null || !ids.includes(id)) return;
        boxes[key] = { ...source.boxes[key] };
        layers[id] = source.layers[id];
        order.splice(Math.min(index, order.length), 0, key);
    });
    return { ...target, boxes, order, layers };
}
