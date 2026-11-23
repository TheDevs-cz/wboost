// Layer effects around a picture (model/effects.js; architecture "Image effects").
//
// `withEffects(effects, shape, content, id)` returns `content` (the picture's markup) with its
// effects: drop shadows and outer glows under it; gradient overlays, inner glows and inner shadows over it.
//
//   shape = { type: 'rect', box, radius }          the picture fills this (rounded) rectangle — a
//                                                   photo in its crop frame, a picture that covers
//                                                   its box; effects are drawn from the rectangle
//         | { type: 'alpha', box, copy }            the picture has its own outline (a logo, a PNG
//                                                   with transparency): `copy` is markup that paints
//                                                   the picture once more WITHOUT any <defs> (ids
//                                                   must stay unique) and effects follow its alpha
//
// PRINT RULES (measured 2026-11-23, Gotenberg 8 / Chromium → PDF → the CMYK pass):
//   - the picture itself NEVER gets a `filter` (Chromium would rasterise the whole picture at
//     300 ppi and drop the original JPEG) and NEVER a `mask` (Skia writes a soft mask with a
//     PostScript transfer function, which the CMYK pass refuses): every effect is an element of
//     its own under or over the untouched picture
//   - blending only through `mix-blend-mode` with a PDF-native mode (model `BLEND_VALUES`)
//   - the picture and its overlays are one `isolation: isolate` group, so an overlay blends with
//     the picture only; a shadow stays outside it and blends with the page
//   - what a filter draws (shadows, overlays clipped to an alpha) becomes a 300 ppi picture with a
//     soft mask in the PDF; a gradient over a rectangle stays vector
//
// Pure: strings only. Ids derive from `id`, which the caller derives from `opts.idPrefix`.

import { EFFECT_KINDS, isUnderKind, shadowOffset } from '../model/effects.js';
import { attrs, esc, num } from './svg.js';

const blendStyle = (blend) => (blend && blend !== 'normal' ? `mix-blend-mode:${blend}` : null);

/** `{x1,y1,x2,y2}` / `{cx,cy,r}` of a gradient effect over `box`, in the coordinates of `box` (mm). */
export function gradientGeometry(effect, box) {
    const cx = box.x + (box.w * effect.x) / 100;
    const cy = box.y + (box.h * effect.y) / 100;
    const scale = effect.scale / 100;
    if (effect.type === 'radial') return { cx, cy, r: Math.max(0.01, (Math.max(box.w, box.h) / 2) * scale) };
    const rad = (effect.angle * Math.PI) / 180;
    const ux = Math.cos(rad);
    const uy = Math.sin(rad);
    // half the extent of the box along the direction, so 100 % runs from edge to edge
    const half = Math.max(0.01, ((Math.abs(box.w * ux) + Math.abs(box.h * uy)) / 2) * scale);
    // a reflected gradient uses the same line, its stops mirrored about the centre (`drawnStops`)
    return { x1: cx - ux * half, y1: cy - uy * half, x2: cx + ux * half, y2: cy + uy * half };
}

/** The stops as drawn: sorted; a reflected gradient runs from the centre outwards both ways. */
function drawnStops(effect) {
    const sorted = effect.stops.slice().sort((a, b) => a.pos - b.pos);
    if (effect.type !== 'reflected') return sorted;
    const outer = sorted.map((s) => ({ ...s, pos: 50 + s.pos / 2 }));
    const inner = sorted
        .slice()
        .reverse()
        .map((s) => ({ ...s, pos: 50 - s.pos / 2 }));
    return [...inner, ...outer];
}

/** A `<linearGradient>` / `<radialGradient>` in user space, shifted by `origin` (for a data-URI picture). */
export function gradientMarkup(id, effect, box, origin = { x: 0, y: 0 }) {
    const g = gradientGeometry(effect, box);
    const stops = drawnStops(effect)
        .map((s) => `<stop offset="${num(s.pos)}%" stop-color="${esc(s.color)}" stop-opacity="${num(s.opacity / 100)}"/>`)
        .join('');
    if (effect.type === 'radial') {
        return `<radialGradient id="${esc(id)}" gradientUnits="userSpaceOnUse" cx="${num(g.cx - origin.x)}" cy="${num(g.cy - origin.y)}" r="${num(g.r)}">${stops}</radialGradient>`;
    }
    return `<linearGradient id="${esc(id)}" gradientUnits="userSpaceOnUse" x1="${num(g.x1 - origin.x)}" y1="${num(g.y1 - origin.y)}" x2="${num(g.x2 - origin.x)}" y2="${num(g.y2 - origin.y)}">${stops}</linearGradient>`;
}

/** Filter region (user space) around `box`, grown by `margin` mm on every side. */
function region(box, margin) {
    return { x: box.x - margin, y: box.y - margin, w: box.w + 2 * margin, h: box.h + 2 * margin };
}

function filterOpen(id, r) {
    return `<filter id="${esc(id)}" filterUnits="userSpaceOnUse" primitiveUnits="userSpaceOnUse" x="${num(r.x)}" y="${num(r.y)}" width="${num(r.w)}" height="${num(r.h)}" color-interpolation-filters="sRGB">`;
}

function dropShadow(effect, shape, id) {
    const { dx, dy } = shadowOffset(effect);
    const grow = (effect.spread / 100) * effect.size;
    const sigma = (effect.size * (1 - effect.spread / 100)) / 2;
    const style = blendStyle(effect.blend);
    if (shape.type === 'rect') {
        const b = shape.box;
        const box = { x: b.x - grow + dx, y: b.y - grow + dy, w: b.w + 2 * grow, h: b.h + 2 * grow };
        const rx = shape.radius > 0 ? shape.radius + grow : null;
        let defs = '';
        let filter = null;
        if (sigma > 0) {
            defs = `<defs>${filterOpen(id, region(box, 3 * sigma + 0.5))}<feGaussianBlur stdDeviation="${num(sigma)}"/></filter></defs>`;
            filter = `url(#${id})`;
        }
        const rectAttrs = { x: box.x, y: box.y, width: Math.max(0, box.w), height: Math.max(0, box.h), rx, fill: effect.color, 'fill-opacity': effect.opacity < 100 ? effect.opacity / 100 : null, filter, style };
        return `${defs}<rect${attrs(rectAttrs)} data-fx="dropShadow"/>`;
    }
    const r = region({ x: shape.box.x + Math.min(0, dx), y: shape.box.y + Math.min(0, dy), w: shape.box.w + Math.abs(dx), h: shape.box.h + Math.abs(dy) }, grow + 3 * sigma + 0.5);
    let chain = '';
    let input = 'SourceAlpha';
    if (grow > 0) {
        chain += `<feMorphology in="${input}" operator="dilate" radius="${num(grow)}" result="s"/>`;
        input = 's';
    }
    if (sigma > 0) {
        chain += `<feGaussianBlur in="${input}" stdDeviation="${num(sigma)}" result="b"/>`;
        input = 'b';
    }
    chain += `<feOffset in="${input}" dx="${num(dx)}" dy="${num(dy)}" result="o"/>`;
    chain += `<feFlood flood-color="${esc(effect.color)}" flood-opacity="${num(effect.opacity / 100)}"/><feComposite in2="o" operator="in"/>`;
    return `<defs>${filterOpen(id, r)}${chain}</filter></defs><g${attrs({ filter: `url(#${id})`, style })} data-fx="dropShadow">${shape.copy}</g>`;
}

function gradientOverlay(effect, shape, id) {
    const style = blendStyle(effect.blend);
    const opacity = effect.opacity < 100 ? effect.opacity / 100 : null;
    const b = shape.box;
    if (shape.type === 'rect') {
        const defs = `<defs>${gradientMarkup(`${id}-g`, effect, b)}</defs>`;
        return `${defs}<rect${attrs({ x: b.x, y: b.y, width: Math.max(0, b.w), height: Math.max(0, b.h), rx: shape.radius > 0 ? shape.radius : null, fill: `url(#${id}-g)`, opacity, style })} data-fx="gradient"/>`;
    }
    // over an outline: the gradient as a picture inside a filter, kept where the picture is opaque
    // (`feImage` with a data URI — an `href="#element"` is misplaced by Chromium's print)
    const w = Math.max(0.01, b.w);
    const h = Math.max(0.01, b.h);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${num(w)}mm" height="${num(h)}mm" viewBox="0 0 ${num(w)} ${num(h)}" preserveAspectRatio="none"><defs>${gradientMarkup('g', effect, b, b)}</defs><rect width="${num(w)}" height="${num(h)}" fill="url(#g)"/></svg>`;
    const href = `data:image/svg+xml,${encodeURIComponent(svg)}`;
    const filter = `${filterOpen(id, region(b, 0.5))}<feImage href="${esc(href)}" x="${num(b.x)}" y="${num(b.y)}" width="${num(w)}" height="${num(h)}" preserveAspectRatio="none" result="g"/><feComposite in="g" in2="SourceAlpha" operator="in"/></filter>`;
    return `<defs>${filter}</defs><g${attrs({ filter: `url(#${id})`, opacity, style })} data-fx="gradient">${shape.copy}</g>`;
}

/** An outer glow is a drop shadow without an offset. */
function outerGlow(effect, shape, id) {
    return dropShadow({ ...effect, angle: 0, distance: 0 }, shape, id).replace('data-fx="dropShadow"', 'data-fx="outerGlow"');
}

/** Path of a rounded rectangle (clockwise). */
function roundedRect(b, r) {
    const rr = Math.max(0, Math.min(r || 0, b.w / 2, b.h / 2));
    if (!(rr > 0)) return `M${num(b.x)} ${num(b.y)}H${num(b.x + b.w)}V${num(b.y + b.h)}H${num(b.x)}Z`;
    return `M${num(b.x + rr)} ${num(b.y)}H${num(b.x + b.w - rr)}A${num(rr)} ${num(rr)} 0 0 1 ${num(b.x + b.w)} ${num(b.y + rr)}V${num(b.y + b.h - rr)}A${num(rr)} ${num(rr)} 0 0 1 ${num(b.x + b.w - rr)} ${num(b.y + b.h)}H${num(b.x + rr)}A${num(rr)} ${num(rr)} 0 0 1 ${num(b.x)} ${num(b.y + b.h - rr)}V${num(b.y + rr)}A${num(rr)} ${num(rr)} 0 0 1 ${num(b.x + rr)} ${num(b.y)}Z`;
}

/**
 * Inner shadow / inner glow: the area OUTSIDE the (offset, choked) shape, blurred, kept inside the
 * shape. A rectangle: an even-odd frame clipped by a vector clip path; an outline: the inverted
 * alpha of a copy inside a filter.
 */
function inner(effect, shape, id, kind) {
    const { dx, dy } = kind === 'innerShadow' ? shadowOffset(effect) : { dx: 0, dy: 0 };
    const choke = (effect.spread / 100) * effect.size;
    const sigma = (effect.size * (1 - effect.spread / 100)) / 2;
    const style = blendStyle(effect.blend);
    const b = shape.box;
    if (shape.type === 'rect') {
        const reach = 3 * sigma + Math.abs(dx) + Math.abs(dy) + choke + 1;
        const outer = region(b, reach);
        const hole = { x: b.x + dx + choke, y: b.y + dy + choke, w: Math.max(0, b.w - 2 * choke), h: Math.max(0, b.h - 2 * choke) };
        const d = `${roundedRect(outer, 0)}${roundedRect(hole, Math.max(0, (shape.radius || 0) - choke))}`;
        let defs = `<clipPath id="${esc(id)}-c"><path d="${roundedRect(b, shape.radius)}"/></clipPath>`;
        let filter = null;
        if (sigma > 0) {
            defs += `${filterOpen(id, region(b, 3 * sigma + 0.5))}<feGaussianBlur stdDeviation="${num(sigma)}"/></filter>`;
            filter = `url(#${id})`;
        }
        const path = `<path${attrs({ d, 'fill-rule': 'evenodd', fill: effect.color, 'fill-opacity': effect.opacity < 100 ? effect.opacity / 100 : null, filter })}/>`;
        return `<defs>${defs}</defs><g${attrs({ 'clip-path': `url(#${id}-c)`, style })} data-fx="${kind}">${path}</g>`;
    }
    const r = region(b, 3 * sigma + Math.abs(dx) + Math.abs(dy) + choke + 0.5);
    let chain = '<feComponentTransfer in="SourceAlpha" result="inv"><feFuncA type="table" tableValues="1 0"/></feComponentTransfer>';
    let input = 'inv';
    if (choke > 0) {
        chain += `<feMorphology in="${input}" operator="dilate" radius="${num(choke)}" result="k"/>`;
        input = 'k';
    }
    if (sigma > 0) {
        chain += `<feGaussianBlur in="${input}" stdDeviation="${num(sigma)}" result="b"/>`;
        input = 'b';
    }
    if (dx || dy) {
        chain += `<feOffset in="${input}" dx="${num(dx)}" dy="${num(dy)}" result="o"/>`;
        input = 'o';
    }
    chain += `<feComposite in="${input}" in2="SourceAlpha" operator="in" result="e"/><feFlood flood-color="${esc(effect.color)}" flood-opacity="${num(effect.opacity / 100)}"/><feComposite in2="e" operator="in"/>`;
    return `<defs>${filterOpen(id, r)}${chain}</filter></defs><g${attrs({ filter: `url(#${id})`, style })} data-fx="${kind}">${shape.copy}</g>`;
}

const DRAW = {
    dropShadow,
    outerGlow,
    gradient: gradientOverlay,
    innerGlow: (effect, shape, id) => inner(effect, shape, id, 'innerGlow'),
    innerShadow: (effect, shape, id) => inner(effect, shape, id, 'innerShadow'),
};

/** Bottom-to-top rank of a kind (Photoshop's panel lists the top first). */
const rank = (kind) => EFFECT_KINDS.length - EFFECT_KINDS.findIndex((k) => k.kind === kind);

/**
 * @param effects  the effect list (normalised); switched-off effects are skipped
 * @param shape    see the header
 * @param content  markup of the picture
 * @param id       unique id stem
 */
export function withEffects(effects, shape, content, id) {
    const active = (Array.isArray(effects) ? effects : []).map((e, i) => ({ e, i })).filter(({ e }) => e.on && DRAW[e.kind]);
    if (!active.length || !shape || !content) return content;
    // bottom to top: by kind (Photoshop's order), and inside a kind from its last effect up (the first is the top one)
    const paint = (list) =>
        list
            .slice()
            .sort((a, b) => rank(a.e.kind) - rank(b.e.kind) || b.i - a.i)
            .map(({ e, i }) => DRAW[e.kind](e, shape, `${id}${i}`))
            .join('');
    const under = paint(active.filter(({ e }) => isUnderKind(e.kind)));
    const over = paint(active.filter(({ e }) => !isUnderKind(e.kind)));
    return `${under}${over ? `<g style="isolation:isolate">${content}${over}</g>` : content}`;
}

/** The rectangle a picture actually covers inside `box` (`contain` with known pixels shrinks it). */
export function drawnRect(box, asset, fit) {
    if (fit !== 'contain' || !asset || !(asset.width > 0) || !(asset.height > 0)) return box;
    const scale = Math.min(box.w / asset.width, box.h / asset.height);
    const w = asset.width * scale;
    const h = asset.height * scale;
    return { x: box.x + (box.w - w) / 2, y: box.y + (box.h - h) / 2, w, h };
}

/** A picture whose own pixels may be transparent (effects then follow its outline). */
export function mayBeTransparent(asset) {
    if (!asset) return false;
    const mime = String(asset.mime || '').toLowerCase();
    if (mime) return !/jpe?g/.test(mime);
    const url = String(asset.url || '');
    return !(/^data:image\/jpe?g/i.test(url) || /\.jpe?g(\?|$)/i.test(url));
}
