/**
 * "Styl vrstvy" › Vržený stín / Vnější záře — Photoshop's controls mapped
 * onto Fabric's NATIVE `shadow` (`{color, blur, offsetX, offsetY,
 * nonScaling}`), which Fabric serializes itself and every render surface
 * paints with no further teaching.
 *
 * The UI speaks Photoshop (and the calendar's effects, docs/calendar/
 * effects-plan.md §3): colour, Krytí (%), Úhel (the LIGHT angle, 120° = shadow
 * falls bottom-right), Vzdálenost and Velikost (canvas px). A glow is a
 * shadow at distance 0 — so the kind is derived from the offsets, nothing
 * extra is stored. Fabric draws ONE shadow per object, so an element has a
 * drop shadow OR an outer glow, never both; there is no spread / choke.
 *
 * `nonScaling: true`: blur and offsets are canvas px regardless of the
 * object's own scale — a fill-page pick re-scales the client's picture, and
 * its shadow must not change size with it. The viewport (editor zoom, export
 * pixel ratio, print passes) still scales it, as it does everything else.
 *
 * Mirrored by `src/Value/CanvasShadow.php` (the DSL, the projector).
 */

export const SHADOW_NONE = 'none';
export const SHADOW_DROP = 'dropShadow';
export const SHADOW_GLOW = 'outerGlow';

/** Photoshop-like starting values, sized to the design (canvas px). */
export function defaultEffect(kind, designWidth) {
    const unit = Math.max(1, Math.round((Number(designWidth) || 1080) / 100));
    if (kind === SHADOW_GLOW) {
        return { kind, color: '#ffffff', opacity: 75, angle: 120, distance: 0, size: unit * 2 };
    }
    return { kind: SHADOW_DROP, color: '#000000', opacity: 50, angle: 120, distance: unit, size: unit * 2 };
}

/** The UI model of an object's shadow (`kind: 'none'` when it has none). */
export function shadowToEffect(shadow) {
    const plain = shadow && typeof shadow.toObject === 'function' ? shadow.toObject() : shadow;
    if (!plain || typeof plain !== 'object' || !plain.color) {
        return { kind: SHADOW_NONE, color: '#000000', opacity: 50, angle: 120, distance: 0, size: 0 };
    }
    const { hex, alpha } = parseColor(plain.color);
    const dx = Number(plain.offsetX) || 0;
    const dy = Number(plain.offsetY) || 0;
    const distance = Math.round(Math.hypot(dx, dy) * 10) / 10;
    // Light angle: the shadow falls AWAY from the light → offset = −cos, +sin.
    const angle = distance > 0 ? Math.round(Math.atan2(dy, -dx) * 180 / Math.PI) : 120;
    return {
        kind: distance > 0 ? SHADOW_DROP : SHADOW_GLOW,
        color: hex,
        opacity: Math.round(alpha * 100),
        angle,
        distance,
        size: Math.round((Number(plain.blur) || 0) * 10) / 10,
    };
}

/** The Fabric `shadow` options for a UI model (null = no shadow). */
export function effectToShadow(effect) {
    if (!effect || effect.kind === SHADOW_NONE) return null;
    const distance = effect.kind === SHADOW_GLOW ? 0 : Math.max(0, Number(effect.distance) || 0);
    const radians = (Number(effect.angle) || 0) * Math.PI / 180;
    return {
        color: rgba(effect.color, clamp((Number(effect.opacity) || 0) / 100, 0, 1)),
        blur: Math.max(0, Number(effect.size) || 0),
        offsetX: round2(-Math.cos(radians) * distance),
        offsetY: round2(Math.sin(radians) * distance),
        affectStroke: false,
        nonScaling: true,
    };
}

/** A plain copy of a shadow with its px values projected by `ratio` (group
 *  editor: a shadow is the same visual share of every dimension). */
export function scaleShadow(shadow, ratio) {
    const plain = shadow && typeof shadow.toObject === 'function' ? shadow.toObject() : shadow;
    if (!plain || typeof plain !== 'object') return null;
    const r = Number(ratio) || 1;
    return {
        ...plain,
        blur: round2((Number(plain.blur) || 0) * r),
        offsetX: round2((Number(plain.offsetX) || 0) * r),
        offsetY: round2((Number(plain.offsetY) || 0) * r),
    };
}

/** `#rrggbb` + alpha from `#rgb`, `#rrggbb`, `rgb(…)` or `rgba(…)`. */
export function parseColor(color) {
    const value = String(color || '').trim().toLowerCase();
    let match = value.match(/^#([0-9a-f]{3})$/);
    if (match) {
        const [r, g, b] = match[1].split('').map((c) => c + c);
        return { hex: `#${r}${g}${b}`, alpha: 1 };
    }
    match = value.match(/^#([0-9a-f]{6})$/);
    if (match) return { hex: `#${match[1]}`, alpha: 1 };
    match = value.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/);
    if (match) {
        const hex = '#' + [match[1], match[2], match[3]]
            .map((n) => clamp(Math.round(Number(n)), 0, 255).toString(16).padStart(2, '0')).join('');
        return { hex, alpha: match[4] === undefined ? 1 : clamp(Number(match[4]), 0, 1) };
    }
    return { hex: '#000000', alpha: 1 };
}

function rgba(hex, alpha) {
    const { hex: h } = parseColor(hex);
    const n = parseInt(h.slice(1), 16);
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${round2(alpha)})`;
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function round2(value) {
    return Math.round(value * 100) / 100;
}
