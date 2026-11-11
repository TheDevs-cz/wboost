// Normalising of stored documents and layout files (architecture §B.8).

import { DEFAULT_FEATURES, DEFAULT_PAGE, DEFAULT_PRINT, K, SCHEMA_VERSION, bleedOf, clampLimit, roundMm } from './defaults.js';
import { BOX_KEYS, DEFAULT_ORDER, HIDEABLE_BOXES } from './elements.js';
import { COLLECTIONS } from './content.js';
import { defaultBoxes, defaultStyles, presetsForType } from './layouts.js';
import { adaptBoxesToBleed, clampBoxesToBounds } from '../editor/bleed.js';

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const clone = (v) => JSON.parse(JSON.stringify(v));
const finite = (v, fallback) => (typeof v === 'number' && Number.isFinite(v) ? v : fallback);

/** Schema migration; identity for version 1. */
export function migrate(raw) {
    return raw;
}

/** Style maps whose keys are data, not schema. */
const OPEN_MAPS = ['cal.iconCells', 'waste.kinds', 'offsets'];

function mergeDefaults(defaults, stored, path) {
    if (OPEN_MAPS.includes(path)) return isObject(stored) ? clone(stored) : clone(defaults);
    if (Array.isArray(defaults)) {
        if (!Array.isArray(stored)) return clone(defaults);
        // fixed-length tuples (phases, icons) keep their length; lists (order, stops) are free
        const fixed = path === 'moon.phases' || path === 'moon.icons' || path === 'icons.season.assets';
        return fixed ? defaults.map((d, i) => (stored[i] === undefined ? d : stored[i])) : clone(stored);
    }
    if (isObject(defaults)) {
        const out = {};
        for (const [key, value] of Object.entries(defaults)) {
            const next = path ? `${path}.${key}` : key;
            out[key] = isObject(stored) && stored[key] !== undefined ? mergeDefaults(value, stored[key], next) : clone(value);
        }
        return out;
    }
    if (stored === undefined) return defaults;
    if (defaults !== null && stored !== null && typeof defaults !== typeof stored) return defaults;
    return stored;
}

const TEXT_STYLES = ['num', 'numSat', 'numSun', 'numHol', 'day', 'name', 'hol', 'ev', 'time', 'shift', 'placeText', 'month', 'wk', 'cap'];
const ALIGNS = ['start', 'middle', 'end'];
const oneOf = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);

function clampFill(fill, fallback) {
    fill.colorOpacity = clampLimit('percent', fill.colorOpacity);
    fill.imageOpacity = clampLimit('percent', fill.imageOpacity);
    if (!Array.isArray(fill.gradient.stops) || fill.gradient.stops.length < 2) fill.gradient.stops = clone(fallback.gradient.stops);
    fill.gradient.stops = fill.gradient.stops.map((s) => ({
        color: typeof s.color === 'string' ? s.color : '#FFFFFF',
        opacity: clampLimit('percent', finite(s.opacity, 100)),
        pos: Math.min(100, Math.max(0, finite(s.pos, 0))),
    }));
    fill.gradient.angle = finite(fill.gradient.angle, 90);
}

function clampStyles(styles, defaults) {
    for (const key of TEXT_STYLES) styles[key].size = clampLimit('fontSize', styles[key].size);
    styles.head.text.size = clampLimit('fontSize', styles.head.text.size);
    styles.lines.width = clampLimit('lineWidth', styles.lines.width);
    styles.cal.iconSize = clampLimit('iconSize', styles.cal.iconSize);
    if (styles.cal.iconCellW !== null) styles.cal.iconCellW = Math.max(K.ICON_CELL_MIN, clampLimit('iconCellSize', styles.cal.iconCellW));
    styles.cal.bg.opacity = clampLimit('percent', styles.cal.bg.opacity);
    styles.cal.numberSide = oneOf(styles.cal.numberSide, ALIGNS, defaults.cal.numberSide);
    styles.cal.iconGrid = oneOf(styles.cal.iconGrid, ['4', '2x2', '1+2', '2+1'], defaults.cal.iconGrid);
    styles.moon.size = clampLimit('moonSize', styles.moon.size);
    styles.photo.border.width = clampLimit('photoBorder', styles.photo.border.width);
    styles.photo.radius = clampLimit('generic', styles.photo.radius);
    styles.photo.shadow.blur = clampLimit('generic', styles.photo.shadow.blur);
    styles.photo.shadow.dx = clampLimit('shadowOffset', styles.photo.shadow.dx);
    styles.photo.shadow.dy = clampLimit('shadowOffset', styles.photo.shadow.dy);
    styles.photo.shadow.opacity = clampLimit('percent', styles.photo.shadow.opacity);
    styles.month.pad = clampLimit('pad', styles.month.pad);
    styles.cap.pad = clampLimit('pad', styles.cap.pad);
    if (styles.hol.wrapWidth !== null) styles.hol.wrapWidth = Math.max(K.HOL_WRAP_MIN, roundMm(finite(styles.hol.wrapWidth, K.HOL_WRAP_MIN)));
    if (styles.ev.box !== null) {
        const b = isObject(styles.ev.box) ? styles.ev.box : {};
        styles.ev.box = {
            x: roundMm(finite(b.x, 0)),
            y: roundMm(finite(b.y, 0)),
            w: Math.max(K.EVBOX_MIN_W, roundMm(finite(b.w, K.EVBOX_MIN_W))),
            h: Math.max(K.EVBOX_MIN_H, roundMm(finite(b.h, K.EVBOX_MIN_H))),
        };
    }
    for (const key of ['bg']) clampFill(styles[key], defaults[key]);
    clampFill(styles.head.bg, defaults.head.bg);
    clampFill(styles.month.bg, defaults.month.bg);
    clampFill(styles.cap.bg, defaults.cap.bg);
    const order = [];
    for (const k of styles.icons.order) {
        if (['waste', 'moon', 'ev', 'time', 'season'].includes(k) && !order.includes(k)) order.push(k);
    }
    styles.icons.order = order;
    const offsets = {};
    for (const [part, o] of Object.entries(styles.offsets)) {
        if (!isObject(o)) continue;
        const x = roundMm(finite(o.x, 0));
        const y = roundMm(finite(o.y, 0));
        if (x !== 0 || y !== 0) offsets[part] = { x, y };
    }
    styles.offsets = offsets;
    return styles;
}

function normalizeBox(stored, fallback, hideable) {
    const b = isObject(stored) ? stored : {};
    const out = {
        x: finite(b.x, fallback.x),
        y: finite(b.y, fallback.y),
        w: Math.max(0.1, finite(b.w, fallback.w)),
        h: Math.max(0.1, finite(b.h, fallback.h)),
    };
    if (hideable && b.hidden === true) out.hidden = true;
    return out;
}

export function normalizeLayout(layout, type, page, env) {
    const raw = isObject(layout) ? layout : {};
    const defaults = defaultStyles(env || {});
    const preset = type === 'fortnight' ? 'twoSided' : 'photoLeft';
    const fallbackBoxes = defaultBoxes(preset, page, 0);
    const storedBoxes = isObject(raw.boxes) ? raw.boxes : {};
    const boxes = {};
    for (const key of BOX_KEYS) {
        if (key === 'cal2' && type !== 'fortnight') continue;
        boxes[key] = normalizeBox(storedBoxes[key], fallbackBoxes[key] || fallbackBoxes.cal, HIDEABLE_BOXES.includes(key));
    }
    const order = [];
    for (const key of Array.isArray(raw.order) ? raw.order : []) {
        if (DEFAULT_ORDER.includes(key) && !order.includes(key)) order.push(key);
    }
    // missing element keys go on top, in the default stacking order
    const missing = DEFAULT_ORDER.filter((key) => !order.includes(key));
    const styles = clampStyles(mergeDefaults(defaults, isObject(raw.styles) ? raw.styles : {}, ''), defaults);
    return { v: SCHEMA_VERSION, boxes, order: [...missing, ...order], styles };
}

export function normalizeContent(content) {
    const raw = isObject(content) ? content : {};
    const out = { v: SCHEMA_VERSION };
    for (const name of Object.keys(COLLECTIONS)) {
        out[name] = isObject(raw[name]) ? clone(raw[name]) : {};
    }
    return out;
}

function normalizePage(page) {
    const raw = isObject(page) ? page : {};
    const holes = isObject(raw.holes) ? raw.holes : {};
    const out = {
        width: finite(raw.width, DEFAULT_PAGE.width),
        height: finite(raw.height, DEFAULT_PAGE.height),
        bandHeight: finite(raw.bandHeight, DEFAULT_PAGE.bandHeight),
        holes: {},
    };
    for (const key of Object.keys(DEFAULT_PAGE.holes)) out.holes[key] = finite(holes[key], DEFAULT_PAGE.holes[key]);
    return out;
}

function normalizePrint(print) {
    const raw = isObject(print) ? print : {};
    return {
        bleed: typeof raw.bleed === 'boolean' ? raw.bleed : DEFAULT_PRINT.bleed,
        bleedMm: clampLimit('bleedMm', finite(raw.bleedMm, DEFAULT_PRINT.bleedMm)),
        marks: typeof raw.marks === 'boolean' ? raw.marks : DEFAULT_PRINT.marks,
    };
}

function normalizeFeatures(features) {
    const raw = isObject(features) ? features : {};
    const out = {};
    for (const key of Object.keys(DEFAULT_FEATURES)) out[key] = typeof raw[key] === 'boolean' ? raw[key] : DEFAULT_FEATURES[key];
    return out;
}

/**
 * The single entry point for a stored calendar: accepts the bootstrap `calendar` object (flat) or an
 * already normalised `Doc`, returns a fully materialised `Doc`. Pure and idempotent.
 */
export function normalizeDocument(rawCalendar, env) {
    const raw = migrate(isObject(rawCalendar) ? rawCalendar : {});
    const d = isObject(raw.design) ? raw.design : raw;
    const type = d.type === 'fortnight' ? 'fortnight' : 'week';
    const page = normalizePage(d.page);
    const hasLayout = isObject(d.layout);
    let preset = presetsForType(type).includes(d.preset) ? d.preset : null;
    if (hasLayout && preset === null) preset = presetsForType(type)[0];
    if (!hasLayout) preset = null;
    const currentYear = finite(env && env.currentYear, 0);
    const year = Number.isInteger(d.year) ? d.year : currentYear ? currentYear + 1 : new Date().getUTCFullYear() + 1;
    const step = [1, 2, 3, 4, 5].includes(d.step) ? d.step : 1;
    return {
        id: typeof raw.id === 'string' ? raw.id : '',
        revision: finite(raw.revision, 0),
        design: {
            name: typeof d.name === 'string' ? d.name : '',
            year,
            type,
            page,
            print: normalizePrint(d.print),
            features: normalizeFeatures(d.features),
            preset,
            layout: hasLayout ? normalizeLayout(d.layout, type, page, env) : null,
            linkElements: typeof d.linkElements === 'boolean' ? d.linkElements : true,
            step,
            finished: d.finished === true,
        },
        content: normalizeContent(raw.content),
    };
}

/** The object written by „Uložit layout do souboru“. */
export function exportLayoutFile(doc) {
    const { design } = doc;
    return {
        wboostCalendarLayout: 1,
        type: design.type,
        preset: design.preset,
        page: clone(design.page),
        bleedMm: bleedOf(design.print),
        sourceCalendar: doc.id,
        layout: clone(design.layout),
    };
}

/**
 * Parses a layout file for the given design. A file made for another page size is scaled box-wise,
 * bleed edges follow the current bleed. Styles missing in the file get neutral defaults.
 */
export function parseLayoutFile(text, design) {
    let file;
    try {
        file = JSON.parse(text);
    } catch (e) {
        return { ok: false };
    }
    if (!isObject(file) || file.wboostCalendarLayout !== 1 || file.type !== design.type) return { ok: false };
    if (!isObject(file.layout) || !isObject(file.layout.boxes) || !isObject(file.layout.styles)) return { ok: false };
    const layout = normalizeLayout(file.layout, design.type, design.page, { fonts: [], accent: null });
    const source = isObject(file.page) ? file.page : {};
    const sourcePage = { ...design.page, width: finite(source.width, design.page.width), height: finite(source.height, design.page.height) };
    const fx = design.page.width / sourcePage.width;
    const fy = design.page.height / sourcePage.height;
    const bleed = bleedOf(design.print);
    // bleed edges go back to the trim of the source page, are scaled, then follow the current bleed
    let boxes = adaptBoxesToBleed(layout.boxes, sourcePage, Math.max(0, finite(file.bleedMm, 0)), 0);
    for (const [key, b] of Object.entries(boxes)) {
        const x0 = roundMm(b.x * fx);
        const y0 = roundMm(b.y * fy);
        boxes[key] = { ...b, x: x0, y: y0, w: roundMm((b.x + b.w) * fx - x0), h: roundMm((b.y + b.h) * fy - y0) };
    }
    boxes = clampBoxesToBounds(adaptBoxesToBleed(boxes, design.page, 0, bleed), design.page, bleed);
    return { ok: true, layout: { ...layout, boxes }, sourceCalendar: typeof file.sourceCalendar === 'string' ? file.sourceCalendar : null };
}
