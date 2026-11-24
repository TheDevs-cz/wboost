// Own layers of a page (model/layers.js, feedback K20): pictures, rectangles, ellipses, lines.
//
// `renderLayer(doc, page, key, opts)` → `<g data-el="layer" data-layer="<id>">…</g>` or ''.
//
// PRINT RULES (the K2 measurements, architecture § "Image effects" and § "CMYK variant"):
//   - shapes are plain vector elements (rect / ellipse / line): `re` and Béziers in the PDF, the
//     colours go through the CMYK colour rules — a project palette colour prints with the
//     palette's CMYK to the digit, a neutral one as black ink only
//   - a picture is one `<image>` with its file's own bytes, clipped by a clipPath — never a
//     `filter` or a `mask` on it
//   - the layer's opacity is one `<g opacity>`, its blend one of the 16 PDF modes
//     (`mix-blend-mode`, model/effects.js BLEND_VALUES)
//   - strokes sit INSIDE the box (a frame drawn round the photo ends where its box ends); a line is
//     drawn through the middle of its box (horizontal when the box is at least as wide as high)
//
// Pure: strings only. Ids derive from `opts.idPrefix` and the layer id.

import { ptToMm } from '../model/defaults.js';
import { layerIdOf } from '../model/layers.js';
import { drawnRect } from './effects.js';
import { attrs, esc, imageIn, num, rect, slotPlaceholder } from './svg.js';

/** `stroke-dasharray` / linecap of a stroke style at width `w` mm — the Kalendárium lines' patterns. */
function dashOf(style, w) {
    if (style === 'dash') return { dash: `${num(w * 4)} ${num(w * 3)}`, cap: null };
    if (style === 'dot') return { dash: `0.01 ${num(w * 2.6)}`, cap: 'round' };
    return { dash: null, cap: null };
}

function strokeAttrs(stroke, w) {
    const { dash, cap } = dashOf(stroke.style, w);
    return { fill: 'none', stroke: stroke.color, 'stroke-width': w, 'stroke-dasharray': dash, 'stroke-linecap': cap };
}

function shapeMarkup(layer, box) {
    const fill = layer.fill;
    const stroke = layer.stroke;
    const sw = stroke.on ? Math.min(ptToMm(stroke.width), box.w / 2, box.h / 2) : 0;
    const fillA = fill.on ? { fill: fill.color, 'fill-opacity': fill.opacity < 100 ? fill.opacity / 100 : null } : null;
    let out = '';
    if (layer.kind === 'ellipse') {
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        if (fillA) out += `<ellipse cx="${num(cx)}" cy="${num(cy)}" rx="${num(box.w / 2)}" ry="${num(box.h / 2)}"${attrs(fillA)}/>`;
        if (sw > 0) out += `<ellipse cx="${num(cx)}" cy="${num(cy)}" rx="${num(box.w / 2 - sw / 2)}" ry="${num(box.h / 2 - sw / 2)}"${attrs(strokeAttrs(stroke, sw))}/>`;
        return out;
    }
    const r = Math.min(layer.radius || 0, box.w / 2, box.h / 2);
    if (fillA) out += rect(box, { rx: r > 0 ? r : null, ...fillA });
    if (sw > 0) {
        const inner = { x: box.x + sw / 2, y: box.y + sw / 2, w: box.w - sw, h: box.h - sw };
        const ri = Math.max(0, r - sw / 2);
        out += rect(inner, { rx: ri > 0 ? ri : null, ...strokeAttrs(stroke, sw) });
    }
    return out;
}

function lineMarkup(layer, box) {
    const s = layer.stroke;
    const w = ptToMm(s.width);
    const { dash, cap } = dashOf(s.style, w);
    const horizontal = box.w >= box.h;
    const [x1, y1, x2, y2] = horizontal ? [box.x, box.y + box.h / 2, box.x + box.w, box.y + box.h / 2] : [box.x + box.w / 2, box.y, box.x + box.w / 2, box.y + box.h];
    const a = { stroke: s.color, 'stroke-width': w, 'stroke-dasharray': dash, 'stroke-linecap': cap || (s.cap === 'round' ? 'round' : null) };
    return `<line x1="${num(x1)}" y1="${num(y1)}" x2="${num(x2)}" y2="${num(y2)}"${attrs(a)}/>`;
}

function imageMarkup(layer, box, opts, id) {
    const assets = (opts.env && opts.env.assets) || {};
    const asset = layer.asset && assets[layer.asset] ? assets[layer.asset] : null;
    if (!asset) {
        // the editor shows the empty place; print and the other surfaces show nothing
        return opts.placeholders === 'all' ? slotPlaceholder(box, 'OBRÁZEK', { radius: layer.radius, minW: 30, minH: 10 }) : '';
    }
    const picture = imageIn(box, asset, layer.fit, 100, opts);
    if (!picture) return '';
    // a covering picture overflows its box (slice) and is clipped to it; a contained one only
    // needs a clip for its rounded corners — on the rectangle it really covers
    const clipBox = layer.fit === 'cover' ? box : drawnRect(box, asset, layer.fit);
    const r = Math.min(layer.radius || 0, clipBox.w / 2, clipBox.h / 2);
    if (layer.fit !== 'cover' && !(r > 0)) return picture;
    const clip = `${id}-c`;
    return `<defs><clipPath id="${esc(clip)}">${rect(clipBox, { rx: r > 0 ? r : null })}</clipPath></defs><g clip-path="url(#${esc(clip)})">${picture}</g>`;
}

/**
 * One own layer `key` (`u:<id>`) of the layout — the caller decides whether it is shown on the
 * page (`isElementVisible` + `layerOnPage`).
 */
export function renderLayer(doc, page, key, opts) {
    const layout = doc.design.layout;
    const id = layerIdOf(key);
    const layer = id !== null && layout.layers ? layout.layers[id] : null;
    const box = layout.boxes[key];
    if (!layer || !box) return '';
    let body = '';
    if (layer.kind === 'image') body = imageMarkup(layer, box, opts, `${opts.idPrefix}-L${id}`);
    else if (layer.kind === 'line') body = lineMarkup(layer, box);
    else body = shapeMarkup(layer, box);
    if (!body) return '';
    const a = {
        'data-el': 'layer',
        'data-layer': id,
        opacity: layer.opacity < 100 ? layer.opacity / 100 : null,
        style: layer.blend && layer.blend !== 'normal' ? `mix-blend-mode:${layer.blend}` : null,
    };
    return `<g${attrs(a)}>${body}</g>`;
}
