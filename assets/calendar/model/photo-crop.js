// The crop of a photo in its frame (feedback K14, docs/calendar/photo-editor-plan.md) — pure.
//
// One crop model for every photo of the calendar: `pagePhotos[pageKey]` and
// `pageContent.{cover|sp:intro|sp:contacts}.photo`:
//
//   { asset, zoom, px, py, rotate?, flip? }
//     zoom    LIMITS.photoZoom (1 … 5, `model/defaults.js`) × the cover scale — 1 = the picture just covers the frame
//     px, py  0 … 1, the share of the free travel along the picture's own axes (0.5 = centred)
//     rotate  −180 … 180 degrees (absent = 0)
//     flip    true = mirrored horizontally about the picture's own centre (absent = false)
//
// The two optional keys are written ONLY when they differ from the default (`cropItem`), so an
// unedited photo has exactly the item it had before K14 and renders the very same markup.
//
// Geometry (all in mm of the page; `box` = the frame, bleed part included):
//   E   = the frame's extents measured along the picture's (rotated) axes
//   k0  = max(Ex / width, Ey / height)    cover scale, mm per pixel — the rotated picture covers
//         the frame's bounding box in its own axes, so a frame is ALWAYS covered (no paper corner
//         in print), whatever the angle, zoom and pan
//   k   = k0 × zoom; drawn size dw × dh = width·k × height·k; dpi = 25.4 / k
//   o   = ((0.5 − px)(dw − Ex), (0.5 − py)(dh − Ey))  — the picture centre's offset from the frame
//         centre, in the picture's axes; on the page it is R(θ)·o
// For rotate = 0 this is exactly the pre-K14 formula (`x = box.x + (box.w − dw) · px`).
//
// The `<image>` stays a plain image inside the frame's clip: a transform keeps the uploaded JPEG
// byte for byte in the print PDF (measured, plan § 3); never a filter.

import { LIMITS } from './defaults.js';

const DEG = Math.PI / 180;

export const ZOOM_MIN = LIMITS.photoZoom.min;
export const ZOOM_MAX = LIMITS.photoZoom.max;
export const ROTATE_MAX = 180;
/** The straighten slider's range around the nearest quarter turn. */
export const STRAIGHTEN_MAX = 45;

const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const finite = (v, fallback) => (typeof v === 'number' && Number.isFinite(v) ? v : fallback);
const round = (v, digits) => {
    const f = 10 ** digits;
    const r = Math.round(v * f) / f;
    return Object.is(r, -0) ? 0 : r;
};

/** An angle in (−180, 180]. */
export function normalizeAngle(deg) {
    let a = finite(deg, 0) % 360;
    if (a > 180) a -= 360;
    if (a <= -180) a += 360;
    return round(a, 1);
}

/** The crop with every key filled and clamped (a missing / broken key reads as its default). */
export function normalizeCrop(crop) {
    const c = crop && typeof crop === 'object' ? crop : {};
    return {
        zoom: clamp(finite(Number(c.zoom), 1) || 1, ZOOM_MIN, ZOOM_MAX),
        px: clamp(finite(c.px, 0.5), 0, 1),
        py: clamp(finite(c.py, 0.5), 0, 1),
        rotate: normalizeAngle(c.rotate),
        flip: c.flip === true,
    };
}

/**
 * The item to store: `{ asset, zoom, px, py }` plus `rotate` / `flip` only when not default —
 * the exact shape `CalendarContentRules` accepts. Values are rounded (zoom 3, px / py 4, angle 1).
 */
export function cropItem(asset, crop) {
    const c = normalizeCrop(crop);
    const item = { asset, zoom: round(c.zoom, 3), px: round(c.px, 4), py: round(c.py, 4) };
    if (c.rotate !== 0) item.rotate = c.rotate;
    if (c.flip) item.flip = true;
    return item;
}

/** A crop at its defaults: centred, 100 %, not rotated, not mirrored. */
export function isDefaultCrop(crop) {
    const c = normalizeCrop(crop);
    return c.zoom === 1 && c.px === 0.5 && c.py === 0.5 && c.rotate === 0 && !c.flip;
}

const hasSize = (asset) => Boolean(asset && asset.width > 0 && asset.height > 0);

/** Extents of the frame along the picture's axes at angle `deg`. */
function extents(box, deg) {
    const c = Math.abs(Math.cos(deg * DEG));
    const s = Math.abs(Math.sin(deg * DEG));
    return { ex: box.w * c + box.h * s, ey: box.w * s + box.h * c };
}

/** Cover scale (mm per pixel) of a picture in a frame at an angle. */
export function coverScale(box, asset, deg = 0) {
    const { ex, ey } = extents(box, deg);
    return Math.max(ex / asset.width, ey / asset.height);
}

/**
 * Where the picture lies: `{ x, y, w, h, transform, dpi, k, ex, ey, ox, oy }`.
 * Without rotation and flip `transform` is null and x / y are the picture's top-left on the page
 * (the pre-K14 markup); otherwise the `<image>` is drawn at x / y = −w/2 / −h/2 under
 * `transform` (translate to its centre, rotate, mirror). `null` for a picture without a pixel size.
 */
export function photoPlacement(box, asset, crop) {
    if (!hasSize(asset)) return null;
    const c = normalizeCrop(crop);
    const { ex, ey } = extents(box, c.rotate);
    const k = Math.max(ex / asset.width, ey / asset.height) * c.zoom;
    const w = asset.width * k;
    const h = asset.height * k;
    const ox = (0.5 - c.px) * (w - ex);
    const oy = (0.5 - c.py) * (h - ey);
    const dpi = 25.4 / k;
    if (c.rotate === 0 && !c.flip) {
        // the pre-K14 expressions, kept literally so an unedited photo renders the very same numbers
        const ratio = asset.width / asset.height;
        const dh = Math.max(box.w / ratio, box.h) * c.zoom;
        const dw = dh * ratio;
        return { x: box.x + (box.w - dw) * c.px, y: box.y + (box.h - dh) * c.py, w: dw, h: dh, transform: null, dpi, k, ex, ey, ox, oy };
    }
    const cos = Math.cos(c.rotate * DEG);
    const sin = Math.sin(c.rotate * DEG);
    const cx = box.x + box.w / 2 + ox * cos - oy * sin;
    const cy = box.y + box.h / 2 + ox * sin + oy * cos;
    return { x: -w / 2, y: -h / 2, w, h, transform: { cx, cy, rotate: c.rotate, flip: c.flip }, dpi, k, ex, ey, ox, oy };
}

/** The SVG `transform` attribute value of a placement, `null` when it has none. `fmt` formats a number. */
export function transformAttr(placement, fmt = String) {
    const t = placement && placement.transform;
    if (!t) return null;
    let out = `translate(${fmt(t.cx)} ${fmt(t.cy)})`;
    if (t.rotate) out += ` rotate(${fmt(t.rotate)})`;
    if (t.flip) out += ' scale(-1 1)';
    return out;
}

/** Effective print resolution of a placed picture (rounded dpi). */
export function photoDpi(box, asset, crop) {
    const p = photoPlacement(box, asset, crop);
    return p ? Math.round(p.dpi) : null;
}

/** Whether the picture as turned reads as portrait (taller than wide). */
export function isPortraitAsPlaced(asset, crop) {
    if (!hasSize(asset)) return false;
    const a = normalizeCrop(crop).rotate * DEG;
    const sideways = Math.abs(Math.sin(a)) > Math.abs(Math.cos(a));
    return sideways ? asset.width > asset.height : asset.height > asset.width;
}

/** px / py from a centre offset (picture axes) under the crop's zoom and angle — clamped into the frame. */
function withOffset(box, asset, c, ox, oy) {
    const { ex, ey } = extents(box, c.rotate);
    const k = Math.max(ex / asset.width, ey / asset.height) * c.zoom;
    const freeX = asset.width * k - ex;
    const freeY = asset.height * k - ey;
    return {
        ...c,
        px: freeX > 1e-6 ? clamp(0.5 - ox / freeX, 0, 1) : 0.5,
        py: freeY > 1e-6 ? clamp(0.5 - oy / freeY, 0, 1) : 0.5,
    };
}

/** Moves the picture by a page delta (mm) — the drag: the picture follows the pointer at any angle. */
export function panBy(box, asset, crop, dxMm, dyMm) {
    const c = normalizeCrop(crop);
    if (!hasSize(asset)) return c;
    const p = photoPlacement(box, asset, c);
    const cos = Math.cos(c.rotate * DEG);
    const sin = Math.sin(c.rotate * DEG);
    // the page delta in the picture's axes = R(−θ)·d
    return withOffset(box, asset, c, p.ox + dxMm * cos + dyMm * sin, p.oy - dxMm * sin + dyMm * cos);
}

/**
 * A new zoom that keeps the picture point under `anchor` (page mm; default the frame centre)
 * where it is — the wheel zooms about the pointer, the slider about the frame centre.
 */
export function zoomTo(box, asset, crop, zoom, anchor = null) {
    const c = normalizeCrop(crop);
    const next = { ...c, zoom: clamp(finite(zoom, c.zoom), ZOOM_MIN, ZOOM_MAX) };
    if (!hasSize(asset)) return next;
    const p = photoPlacement(box, asset, c);
    const { ex, ey } = extents(box, c.rotate);
    const k1 = Math.max(ex / asset.width, ey / asset.height) * next.zoom;
    const ratio = k1 / p.k;
    // the anchor relative to the frame centre, in the picture's axes
    const cos = Math.cos(c.rotate * DEG);
    const sin = Math.sin(c.rotate * DEG);
    const ax0 = anchor ? anchor.x - (box.x + box.w / 2) : 0;
    const ay0 = anchor ? anchor.y - (box.y + box.h / 2) : 0;
    const ax = ax0 * cos + ay0 * sin;
    const ay = -ax0 * sin + ay0 * cos;
    return withOffset(box, asset, next, ax + (p.ox - ax) * ratio, ay + (p.oy - ay) * ratio);
}

/** A new angle that keeps the picture point at the frame centre there (as far as the frame allows). */
export function rotateTo(box, asset, crop, deg) {
    const c = normalizeCrop(crop);
    const next = { ...c, rotate: normalizeAngle(clamp(finite(deg, 0), -360, 360)) };
    if (!hasSize(asset)) return next;
    const p = photoPlacement(box, asset, c);
    const k1 = coverScale(box, asset, next.rotate) * c.zoom;
    const ratio = k1 / p.k;
    return withOffset(box, asset, next, p.ox * ratio, p.oy * ratio);
}

/** A quarter turn: `dir` = 1 clockwise, −1 counter-clockwise. */
export function rotateBy90(box, asset, crop, dir) {
    const c = normalizeCrop(crop);
    return rotateTo(box, asset, c, c.rotate + (dir < 0 ? -90 : 90));
}

/** The nearest quarter turn of an angle, and the straightening around it (−45 … 45). */
export function splitAngle(deg) {
    const a = normalizeAngle(deg);
    let quarter = Math.round(a / 90) * 90 + 0;
    if (quarter === -180) quarter = 180;
    return { quarter, straighten: round(normalizeAngle(a - quarter), 1) };
}

/**
 * Mirrors what the frame shows, in place: the whole picture mirrored about the frame's vertical
 * centre line (flip toggles, the angle and the horizontal pan change sign).
 */
export function mirrorCrop(crop) {
    const c = normalizeCrop(crop);
    return { ...c, flip: !c.flip, rotate: normalizeAngle(-c.rotate), px: round(1 - c.px, 4) };
}

/** Centred, at the current zoom and angle. */
export function centreCrop(crop) {
    return { ...normalizeCrop(crop), px: 0.5, py: 0.5 };
}

/** Everything back: centred, 100 %, not rotated, not mirrored. */
export function resetCrop() {
    return { zoom: 1, px: 0.5, py: 0.5, rotate: 0, flip: false };
}

/**
 * The four corners of the drawn picture on the page (mm) — for the editor's whole-picture view
 * and for the coverage test.
 */
export function pictureCorners(box, asset, crop) {
    const p = photoPlacement(box, asset, crop);
    if (!p) return null;
    if (!p.transform) return [[p.x, p.y], [p.x + p.w, p.y], [p.x + p.w, p.y + p.h], [p.x, p.y + p.h]];
    const { cx, cy, rotate } = p.transform;
    const cos = Math.cos(rotate * DEG);
    const sin = Math.sin(rotate * DEG);
    return [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([sx, sy]) => {
        const lx = (sx * p.w) / 2;
        const ly = (sy * p.h) / 2;
        return [cx + lx * cos - ly * sin, cy + lx * sin + ly * cos];
    });
}
