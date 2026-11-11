// Default layouts, styles and mirroring (architecture §B.2–B.5, §E.8).

import { COLORS, DEFAULT_PAGE, roundMm } from './defaults.js';
import { DEFAULT_ORDER, HIDEABLE_BOXES } from './elements.js';
import { fontNames, nearestFace } from './fonts.js';

export const FALLBACK_ACCENT = '#2E5A8C';

const PRESETS = { week: ['photoLeft', 'photoRight'], fortnight: ['twoSided'] };

export function presetsForType(type) {
    return (PRESETS[type] || PRESETS.week).slice();
}

function clone(v) {
    return JSON.parse(JSON.stringify(v));
}

export function fill(o = {}) {
    const { stops, ...rest } = o;
    const [a, b] = stops || ['#FFFFFF', '#FFFFFF'];
    return {
        colorOn: false,
        color: '#FFFFFF',
        colorOpacity: 100,
        gradient: {
            on: false,
            type: 'linear',
            angle: 90,
            stops: [
                { color: a, opacity: 100, pos: 0 },
                { color: b, opacity: 100, pos: 100 },
            ],
        },
        imageOn: false,
        asset: null,
        fit: 'cover',
        imageOpacity: 100,
        ...rest,
    };
}

export function defaultStyles(env = {}) {
    const fonts = env.fonts || [];
    const fontName = fontNames(fonts)[0] || null;
    const accent = env.accent || FALLBACK_ACCENT;
    const { ink, mut, sat, red, line } = COLORS;
    const text = (weight, size, color) => {
        const face = fontName ? nearestFace(fonts, fontName, weight) : null;
        return { family: face ? face.family : null, weight, size, color };
    };

    return {
        photo: {
            source: 'client',
            radius: 0,
            border: { on: false, width: 0.7, color: '#FFFFFF' },
            shadow: { on: false, blur: 3, opacity: 35, dx: 1.5, dy: 2, color: '#000000' },
        },
        cap: { ...text(400, 9, mut), align: 'start', valign: 'middle', pad: 3, bg: fill({ colorOn: true, color: COLORS.ph2 }) },
        logo: { asset: null, fit: 'contain' },
        head: { bg: fill({ colorOn: true, color: COLORS.band, stops: [COLORS.band, COLORS.paper] }), text: text(800, 15, ink) },
        month: { ...text(800, 15, ink), align: 'end', upper: true, pad: 3, bg: fill({ colorOn: false, color: '#FFFFFF', stops: ['#FFFFFF', COLORS.band] }) },
        wk: { ...text(400, 9.5, mut), align: 'end', form: 'a' },
        qr: { perPage: false, asset: null },
        bg: fill({ colorOn: true, color: COLORS.paper, stops: [COLORS.paper, COLORS.band] }),
        cal: {
            bg: { color: null, opacity: 100 },
            satColor: sat,
            sunColor: red,
            numberSide: 'end',
            numAlign: null,
            dayFormat: 'full',
            dayPosition: 'side',
            dayAlign: null,
            iconSize: 6,
            iconGrid: '4',
            iconCellW: null,
            iconCells: {},
            placeLabels: false,
            emptyIconSlot: true,
        },
        lines: { on: true, width: 1, style: 'solid', color: line },
        num: text(800, 28, ink),
        numSat: text(800, 28, sat),
        numSun: text(800, 28, red),
        numHol: text(800, 28, red),
        day: text(600, 11, mut),
        name: text(400, 9.5, ink),
        hol: { ...text(400, 8, red), wrapWidth: null },
        ev: { ...text(600, 8, accent), align: null, valign: 'top', box: null },
        time: { ...text(400, 8, mut), position: 'text', align: null, valign: 'bottom' },
        shift: { ...text(600, 8, mut), bgMode: 'alt', bg: null },
        placeText: text(600, 7.5, mut),
        moon: { color: ink, size: 5, valign: 'middle', phases: [true, true, true, true], icons: [null, null, null, null] },
        icons: {
            order: ['waste', 'moon'],
            ev: { name: 'Akce', asset: null, perEvent: false },
            time: { asset: null, color: ink },
            season: { mode: 'four', asset: null, assets: [null, null, null, null], color: ink },
        },
        waste: { kinds: {} },
        offsets: {},
    };
}

/** Preset-dependent style values (§B.3). */
const PRESET_STYLES = {
    photoLeft: { numberSide: 'end', month: 'end', wk: 'end', cap: 'start', iconGrid: '4' },
    photoRight: { numberSide: 'start', month: 'start', wk: 'start', cap: 'end', iconGrid: '4' },
    twoSided: { numberSide: 'start', month: 'start', wk: 'end', cap: 'middle', iconGrid: '2x2' },
};

function applyPresetStyles(styles, preset) {
    const p = PRESET_STYLES[preset];
    return {
        ...styles,
        cal: { ...styles.cal, numberSide: p.numberSide, iconGrid: p.iconGrid },
        month: { ...styles.month, align: p.month },
        wk: { ...styles.wk, align: p.wk },
        cap: { ...styles.cap, align: p.cap },
    };
}

// Edge tables in mm of the default page: [x0, y0, x1, y1, edges in the bleed]; `W` / `H` = page edge,
// `band` = lower edge of the binding band. Squares: [x, y, side].
const W = 'W';
const H = 'H';
const BAND = 'band';
const EDGE_TABLES = {
    photoLeft: {
        head: [0, 0, W, 27, 'ltr'],
        photo: [0, 27, 197, H, 'lb'],
        cal: [197, 27, W, 139, 'r'],
        month: [203, BAND, -6, 27, ''],
        wk: [203, 139, -6, 143.5, ''],
        cap: [5, 135, 98, 140.5, ''],
        logo: [168.5, 31, 23.5],
        qr: [170.5, 116.5, 21],
    },
    twoSided: {
        head: [0, 0, W, 27, 'ltr'],
        cal: [0, 27, 84, 139, 'l'],
        cal2: [-84, 27, W, 139, 'r'],
        photo: [84, 27, -84, 139, ''],
        month: [6, BAND, 126, 27, ''],
        wk: [-78, 139, -6, 143.5, ''],
        cap: [89, 139.5, -89, 144, ''],
        logo: [143, 15, 24],
        qr: [199.5, 113, 21],
    },
};

function mirrorBoxes(boxes, page) {
    const out = {};
    for (const [k, b] of Object.entries(boxes)) {
        out[k] = { ...b, x: roundMm(page.width - b.x - b.w) };
    }
    return out;
}

export function defaultBoxes(preset, page, bleedMm) {
    if (preset === 'photoRight') return mirrorBoxes(defaultBoxes('photoLeft', page, bleedMm), page);
    const table = EDGE_TABLES[preset];
    if (!table) throw new Error(`Unknown preset "${preset}"`);
    const B = Number(bleedMm) || 0;
    const fx = page.width / DEFAULT_PAGE.width;
    const fy = page.height / DEFAULT_PAGE.height;
    // a negative x is measured from the right page edge of the default page
    const X = (v) => (v === W ? page.width : (v < 0 ? DEFAULT_PAGE.width + v : v) * fx);
    const Y = (v) => (v === H ? page.height : v === BAND ? page.bandHeight : v * fy);
    const boxes = {};
    for (const [key, row] of Object.entries(table)) {
        if (row.length === 3) {
            const side = roundMm(row[2] * fx);
            boxes[key] = { x: roundMm(X(row[0])), y: roundMm(Y(row[1])), w: side, h: side };
            continue;
        }
        const [a, b, c, d, bleedEdges] = row;
        const x0 = roundMm(X(a) - (bleedEdges.includes('l') ? B : 0));
        const y0 = roundMm(Y(b) - (bleedEdges.includes('t') ? B : 0));
        const x1 = roundMm(X(c) + (bleedEdges.includes('r') ? B : 0));
        const y1 = roundMm(Y(d) + (bleedEdges.includes('b') ? B : 0));
        boxes[key] = { x: x0, y: y0, w: roundMm(x1 - x0), h: roundMm(y1 - y0) };
    }
    return boxes;
}

export function defaultLayout(preset, page, bleedMm, env) {
    if (preset === 'photoRight') return mirrorLayout(defaultLayout('photoLeft', page, bleedMm, env), page);
    return {
        v: 1,
        boxes: defaultBoxes(preset, page, bleedMm),
        order: DEFAULT_ORDER.slice(),
        styles: applyPresetStyles(defaultStyles(env), preset),
    };
}

const flipAlign = (a) => (a === 'start' ? 'end' : a === 'end' ? 'start' : a);

/** Row-internal flips of the left column; used for the second 14-day frame and by `mirrorLayout`. */
export function flipRowStyles(styles) {
    const iconCells = {};
    for (const [grid, cells] of Object.entries(styles.cal.iconCells || {})) {
        iconCells[grid] = {};
        for (const [cell, v] of Object.entries(cells || {})) {
            iconCells[grid][cell] = { ...v, align: flipAlign(v.align) };
        }
    }
    const offsets = {};
    for (const [part, o] of Object.entries(styles.offsets || {})) {
        offsets[part] = { x: o.x === 0 ? 0 : -o.x, y: o.y };
    }
    return {
        ...styles,
        cal: {
            ...styles.cal,
            numberSide: flipAlign(styles.cal.numberSide),
            numAlign: flipAlign(styles.cal.numAlign),
            dayAlign: flipAlign(styles.cal.dayAlign),
            iconCells,
        },
        ev: { ...styles.ev, align: flipAlign(styles.ev.align) },
        time: { ...styles.time, align: flipAlign(styles.time.align) },
        offsets,
    };
}

export function mirrorLayout(layout, page) {
    const styles = flipRowStyles(layout.styles);
    return {
        ...layout,
        boxes: mirrorBoxes(layout.boxes, page),
        order: layout.order.slice(),
        styles: {
            ...styles,
            month: { ...styles.month, align: flipAlign(styles.month.align) },
            wk: { ...styles.wk, align: flipAlign(styles.wk.align) },
            cap: { ...styles.cap, align: flipAlign(styles.cap.align) },
        },
    };
}

/** Type change (§E.8): positions reset to the default of the new type, styles kept. */
export function rebaseLayoutForType(layout, newType, page, bleedMm) {
    const preset = newType === 'fortnight' ? 'twoSided' : 'photoLeft';
    const boxes = defaultBoxes(preset, page, bleedMm);
    for (const key of HIDEABLE_BOXES) {
        if (layout.boxes[key] && layout.boxes[key].hidden && boxes[key]) boxes[key].hidden = true;
    }
    const kept = clone(layout.styles);
    const styles = applyPresetStyles(kept, preset);
    styles.ev = { ...styles.ev, box: null };
    styles.hol = { ...styles.hol, wrapWidth: null };
    styles.cal = { ...styles.cal, iconCellW: null };
    styles.offsets = {};
    return { layout: { v: 1, boxes, order: layout.order.slice(), styles }, preset };
}
