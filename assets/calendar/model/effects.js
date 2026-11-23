// Layer effects of the calendar's pictures (docs/calendar/effects-plan.md; architecture "Image effects").
//
// An effect list is an array of effect objects, stored in the layout styles (all pages):
//   styles.photo.effects        the week photo
//   styles.logo.effects         the logo (week pages, cover, back)
//   styles.head.bg.effects      the picture of the header fill (style or a page's own `pageImages.head`)
//   styles.bg.effects           the picture of the background fill (style or `pageImages.bg`)
//   styles.specialPhoto.effects the photos of the cover, the intro and the contacts page
//
// Effect shapes (all lengths mm, opacity / spread %, angles °):
//   { kind: 'dropShadow', on, blend, opacity, color, angle, distance, spread, size }
//       angle = Photoshop's LIGHT angle: 0° = light from the right, 90° = from the top, so the
//       shadow falls the other way (120° → down right)
//   { kind: 'gradient', on, blend, opacity, type: linear|radial|reflected, angle, scale, x, y, stops }
//       angle as the fills: 0° = left → right, 90° = top → bottom; x / y = the centre in % of the
//       picture; scale % of the picture's extent; stops = [{ color, opacity, pos }] (2–8)
//
// Stacking is by kind, as in Photoshop: drop shadows under the picture, gradient overlays over it.
// Within a kind the FIRST effect of the list is the TOP one (as `layout.order`).
//
// Blend modes: only the 16 that the PDF has natively (`/BM`). Photoshop's other 11 (Rozpustit,
// Lineárně ztmavit / zesvětlit, Tmavší / Světlejší barva, Jasné / Lineární / Bodové světlo,
// Tvrdé míchání, Odečíst, Rozdělit) cannot reach the print file without rasterising the picture.
//
// Pure: no DOM, no timers.

import { clampLimit } from './defaults.js';

/** The style paths that hold an effect list (dotted, inside `layout.styles`). */
export const EFFECT_PATHS = Object.freeze(['photo.effects', 'logo.effects', 'head.bg.effects', 'bg.effects', 'specialPhoto.effects']);

export const MAX_EFFECTS = 10;
export const MAX_STOPS = 8;

/** Kinds in Photoshop's panel order (top → bottom); `under` = painted below the picture. */
export const EFFECT_KINDS = Object.freeze([
    Object.freeze({ kind: 'gradient', label: 'Překrytí přechodem', under: false }),
    Object.freeze({ kind: 'dropShadow', label: 'Vržený stín', under: true }),
]);

/** The 16 blend modes, in Photoshop's order; `group` starts a new block of the menu. */
export const BLEND_MODES = Object.freeze([
    Object.freeze({ value: 'normal', label: 'Normální', group: 0 }),
    Object.freeze({ value: 'darken', label: 'Ztmavit', group: 1 }),
    Object.freeze({ value: 'multiply', label: 'Násobit', group: 1 }),
    Object.freeze({ value: 'color-burn', label: 'Ztmavit barvy', group: 1 }),
    Object.freeze({ value: 'lighten', label: 'Zesvětlit', group: 2 }),
    Object.freeze({ value: 'screen', label: 'Závoj', group: 2 }),
    Object.freeze({ value: 'color-dodge', label: 'Zesvětlit barvy', group: 2 }),
    Object.freeze({ value: 'overlay', label: 'Překrýt', group: 3 }),
    Object.freeze({ value: 'soft-light', label: 'Měkké světlo', group: 3 }),
    Object.freeze({ value: 'hard-light', label: 'Tvrdé světlo', group: 3 }),
    Object.freeze({ value: 'difference', label: 'Rozdíl', group: 4 }),
    Object.freeze({ value: 'exclusion', label: 'Vyloučit', group: 4 }),
    Object.freeze({ value: 'hue', label: 'Odstín', group: 5 }),
    Object.freeze({ value: 'saturation', label: 'Sytost', group: 5 }),
    Object.freeze({ value: 'color', label: 'Barva', group: 5 }),
    Object.freeze({ value: 'luminosity', label: 'Světlost', group: 5 }),
]);
export const BLEND_VALUES = Object.freeze(BLEND_MODES.map((m) => m.value));

export const GRADIENT_TYPES = Object.freeze(['linear', 'radial', 'reflected']);

const DEFAULTS = Object.freeze({
    dropShadow: Object.freeze({ kind: 'dropShadow', on: true, blend: 'multiply', opacity: 75, color: '#000000', angle: 120, distance: 1.5, spread: 0, size: 3 }),
    gradient: Object.freeze({
        kind: 'gradient',
        on: true,
        blend: 'normal',
        opacity: 100,
        type: 'linear',
        angle: 90,
        scale: 100,
        x: 50,
        y: 50,
        stops: Object.freeze([Object.freeze({ color: '#000000', opacity: 0, pos: 0 }), Object.freeze({ color: '#000000', opacity: 70, pos: 100 })]),
    }),
});

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const finite = (v, fallback) => (typeof v === 'number' && Number.isFinite(v) ? v : fallback);
const COLOR = /^#[0-9A-F]{6}$/;
const colorOf = (v, fallback) => (typeof v === 'string' && COLOR.test(v.toUpperCase()) ? v.toUpperCase() : fallback);
const clone = (v) => JSON.parse(JSON.stringify(v));

export const isEffectKind = (kind) => Object.prototype.hasOwnProperty.call(DEFAULTS, kind);
export const effectLabel = (kind) => (EFFECT_KINDS.find((k) => k.kind === kind) || { label: kind }).label;
export const isUnderKind = (kind) => (EFFECT_KINDS.find((k) => k.kind === kind) || {}).under === true;

/** The keys an effect of `kind` has (MCP validation, PHP mirror). */
export function effectKeys(kind) {
    return isEffectKind(kind) ? Object.keys(DEFAULTS[kind]) : [];
}

/** A fresh effect of `kind` with Photoshop's defaults (translated to mm). */
export function newEffect(kind) {
    if (!isEffectKind(kind)) throw new Error(`Unknown effect "${kind}"`);
    return clone(DEFAULTS[kind]);
}

/** Angle in (−180, 180]. */
function wrapAngle(a) {
    let v = finite(a, 0) % 360;
    if (v > 180) v -= 360;
    if (v <= -180) v += 360;
    return v;
}

function normalizeStops(stops, fallback) {
    if (!Array.isArray(stops) || stops.length < 2) return clone(fallback);
    return stops.slice(0, MAX_STOPS).map((s) => {
        const stop = isObject(s) ? s : {};
        return {
            color: colorOf(stop.color, '#000000'),
            opacity: clampLimit('percent', finite(stop.opacity, 100)),
            pos: Math.min(100, Math.max(0, Math.round(finite(stop.pos, 0)))),
        };
    });
}

/** One effect with every key present and every value in range, or `null` for an unknown kind. */
export function normalizeEffect(raw) {
    if (!isObject(raw) || !isEffectKind(raw.kind)) return null;
    const d = DEFAULTS[raw.kind];
    const common = {
        kind: raw.kind,
        on: typeof raw.on === 'boolean' ? raw.on : true,
        blend: BLEND_VALUES.includes(raw.blend) ? raw.blend : d.blend,
        opacity: clampLimit('percent', finite(raw.opacity, d.opacity)),
    };
    if (raw.kind === 'dropShadow') {
        return {
            ...common,
            color: colorOf(raw.color, d.color),
            angle: clampLimit('fxAngle', wrapAngle(finite(raw.angle, d.angle))),
            distance: clampLimit('fxDistance', finite(raw.distance, d.distance)),
            spread: clampLimit('percent', finite(raw.spread, d.spread)),
            size: clampLimit('fxSize', finite(raw.size, d.size)),
        };
    }
    return {
        ...common,
        type: GRADIENT_TYPES.includes(raw.type) ? raw.type : d.type,
        angle: Math.round(((finite(raw.angle, d.angle) % 360) + 360) % 360),
        scale: clampLimit('fxScale', finite(raw.scale, d.scale)),
        x: clampLimit('fxCentre', finite(raw.x, d.x)),
        y: clampLimit('fxCentre', finite(raw.y, d.y)),
        stops: normalizeStops(raw.stops, d.stops),
    };
}

/** A clean effect list: unknown kinds dropped, values clamped, at most `MAX_EFFECTS`. */
export function normalizeEffects(list) {
    if (!Array.isArray(list)) return [];
    const out = [];
    for (const raw of list) {
        const effect = normalizeEffect(raw);
        if (effect) out.push(effect);
        if (out.length >= MAX_EFFECTS) break;
    }
    return out;
}

/**
 * The photo's „Stín“ of before 2026-11-24 (`{ on, blur, opacity, dx, dy, color }`) as an effect list:
 * a drop shadow that draws exactly what the old one drew (normal blend, no spread, σ = blur / 2).
 */
export function shadowToEffects(shadow) {
    if (!isObject(shadow) || shadow.on !== true) return [];
    const dx = finite(shadow.dx, 1.5);
    const dy = finite(shadow.dy, 2);
    const distance = Math.hypot(dx, dy);
    // shadow offset = (−cos a · d, sin a · d) in page coordinates (y down)
    const angle = distance > 0 ? (Math.atan2(dy, -dx) * 180) / Math.PI : 120;
    return normalizeEffects([
        {
            kind: 'dropShadow',
            on: true,
            blend: 'normal',
            opacity: finite(shadow.opacity, 35),
            color: shadow.color,
            angle,
            distance,
            spread: 0,
            size: finite(shadow.blur, 3),
        },
    ]);
}

/** The effects mirrored left ↔ right (Fotka vlevo ↔ vpravo): angles and centres flip horizontally. */
export function mirrorEffects(list) {
    return (Array.isArray(list) ? list : []).map((e) => {
        if (e.kind === 'dropShadow') return { ...e, angle: clampLimit('fxAngle', wrapAngle(180 - e.angle)) };
        if (e.kind === 'gradient') return { ...e, angle: (((180 - e.angle) % 360) + 360) % 360, x: 100 - e.x };
        return { ...e };
    });
}

/** Shadow offset in mm of a drop shadow (Photoshop's light angle). */
export function shadowOffset(effect) {
    const rad = (effect.angle * Math.PI) / 180;
    const dx = -Math.cos(rad) * effect.distance;
    const dy = Math.sin(rad) * effect.distance;
    return { dx: Math.abs(dx) < 1e-9 ? 0 : dx, dy: Math.abs(dy) < 1e-9 ? 0 : dy };
}

/**
 * Display order of a list in the panel: kinds in Photoshop's order, the list order within a kind.
 * Returns the list indices.
 */
export function displayOrder(list) {
    const rank = (kind) => EFFECT_KINDS.findIndex((k) => k.kind === kind);
    return list.map((e, i) => i).sort((a, b) => rank(list[a].kind) - rank(list[b].kind) || a - b);
}

/** The list with effect `index` moved one place up (−1) or down (+1) among the effects of its kind. */
export function moveEffect(list, index, direction) {
    const kind = list[index] && list[index].kind;
    if (!kind) return list;
    const same = list.map((e, i) => (e.kind === kind ? i : -1)).filter((i) => i >= 0);
    const at = same.indexOf(index);
    const other = same[at + direction];
    if (other === undefined) return list;
    const out = list.slice();
    out[index] = list[other];
    out[other] = list[index];
    return out;
}

/** Reads the effect list at a dotted style path (`EFFECT_PATHS`); `[]` when missing. */
export function effectsAt(styles, path) {
    let node = styles;
    for (const key of path.split('.')) {
        if (!isObject(node)) return [];
        node = node[key];
    }
    return Array.isArray(node) ? node : [];
}
