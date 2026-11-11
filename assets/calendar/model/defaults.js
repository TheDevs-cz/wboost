// Calendar defaults and constants (docs/calendar/architecture.md §B.1, §B.7).
// This is the ONLY file under assets/calendar/ that may contain the page dimensions.

export const SCHEMA_VERSION = 1;

export const MM_PER_PT = 25.4 / 72;

export const DEFAULT_PAGE = Object.freeze({
    width: 310,
    height: 145,
    bandHeight: 12.5,
    holes: Object.freeze({ size: 4.3, radius: 0.6, pitch: 8, top: 4, margin: 4.5 }),
});

export const DEFAULT_PRINT = Object.freeze({ bleed: true, bleedMm: 5, marks: true });

export const DEFAULT_FEATURES = Object.freeze({
    captions: true,
    weekNumbers: true,
    moon: true,
    timeChange: false,
    seasons: false,
    waste: false,
    events: false,
    shifts: false,
    specialPages: false,
    qr: false,
});

export const MARKS = Object.freeze({ gapMm: 2, lengthMm: 5, weightPt: 0.25 });

/** All lengths in mm unless noted. */
export const K = Object.freeze({
    MM_PER_PT,
    GRID: 0.1,
    MIN_W: 6,
    MIN_H: 4,
    NUDGE: 0.3,
    NUDGE_BIG: 3,
    FIELD_STEP: 0.5,
    LINK_TOL: 0.15,
    SNAP_PX: 6,
    DRAG_PX: 3,
    ROW_PAD: 5,
    NUM_GAP: 3,
    ROW_SLACK: 2,
    COMPACT_BELOW: 11.8,
    COMPACT_BASE: 9.3,
    COMPACT_TEXT_MIN: 24,
    SHIFT_MIN_ROW: 10.5,
    SHIFT_COL_W: 5,
    SHIFT_COL_GAP: 0.3,
    SHIFT_END_GAP: 2,
    ICON_GAP: 1.2,
    ZONE_END_GAP: 2,
    EMPTY_SLOT: 6,
    EMPTY_SLOT_W: 7.5,
    EVBOX_MIN_W: 6,
    EVBOX_MIN_H: 1.5,
    EVBOX_GAP: 3,
    EVBOX_PAD_Y: 1,
    ICON_CELL_MIN: 2,
    HOL_WRAP_MIN: 6,
    PART_SNAP_BACK: 0.3,
    CAP_INSET_X: 2.5,
    CAP_INSET_Y: 1,
    TEXT_GROW_MARGIN: 0.6,
    UNDO_LIMIT: 100,
    ZOOM_STEPS: Object.freeze([0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4]),
    FIT_MIN: 1,
    FIT_MAX: 5.2,
});

export const LIMITS = Object.freeze({
    fontSize: { min: 3, max: 60, step: 0.5 },
    sliderNum: { min: 8, max: 40, step: 0.5 },
    sliderDay: { min: 3, max: 16, step: 0.5 },
    sliderText: { min: 3, max: 14, step: 0.5 },
    lineWidth: { min: 0.1, max: 5, step: 0.1 },
    iconSize: { min: 1.5, max: 12, step: 0.5 },
    iconCellSize: { min: 1, max: 30, step: 0.5 },
    moonSize: { min: 1.5, max: 9, step: 0.1 },
    photoBorder: { min: 0.1, max: 10, step: 0.1 },
    shadowOffset: { min: -10, max: 10, step: 0.5 },
    pad: { min: 0, max: 20, step: 0.5 },
    percent: { min: 0, max: 100, step: 5 },
    generic: { min: 0, max: 30, step: 0.5 },
    bleedMm: { min: 0, max: 10, step: 0.5 },
    photoZoom: { min: 1, max: 3, step: 0.01 },
});

/** Wireframe colours of the prototype (`CK`). */
export const COLORS = Object.freeze({
    band: '#CBC7D1',
    paper: '#F6F5F8',
    ph: '#E2DFE6',
    ph2: '#D2CED8',
    line: '#D7D3DC',
    ink: '#2B2830',
    mut: '#6E6977',
    sat: '#2F78B7',
    red: '#BE3A2E',
});

export const BUILTIN_WASTE = Object.freeze({
    kom: { name: 'Komunální odpad', color: '#3B3A3F' },
    bio: { name: 'Bio odpad', color: '#7A5230' },
    plast: { name: 'Plast', color: '#F2D23C' },
    papir: { name: 'Papír', color: '#2EA3D6' },
    sklo: { name: 'Sklo', color: '#3F8F5A' },
});

export const FALLBACK_WASTE_COLOR = '#8A8F98';

/** Effective bleed in mm. */
export function bleedOf(print) {
    return print && print.bleed ? Number(print.bleedMm) || 0 : 0;
}

function decimals(step) {
    const text = String(step);
    const dot = text.indexOf('.');
    return dot < 0 ? 0 : text.length - dot - 1;
}

export function clampLimit(key, value) {
    const limit = LIMITS[key];
    if (!limit) throw new Error(`Unknown limit "${key}"`);
    let v = Number(value);
    if (!Number.isFinite(v)) v = limit.min;
    v = Math.min(limit.max, Math.max(limit.min, v));
    v = Math.round(v / limit.step) * limit.step;
    v = Math.min(limit.max, Math.max(limit.min, v));
    return Number(v.toFixed(decimals(limit.step)));
}

/** Rounds to the 0.1 mm geometry grid. */
export function roundMm(v) {
    const r = Math.round(v * 10) / 10;
    return r === 0 ? 0 : r;
}

export function ptToMm(pt) {
    return pt * MM_PER_PT;
}
