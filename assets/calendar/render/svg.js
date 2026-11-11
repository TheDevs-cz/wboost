// SVG string helpers. All coordinates are mm.

import { COLORS, ptToMm } from '../model/defaults.js';
import { cssFont } from '../model/fonts.js';

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function esc(text) {
    return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

/** Number with at most 3 decimals and no trailing zeros. */
export function num(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '0';
    const s = n.toFixed(3).replace(/\.?0+$/, '');
    return s === '-0' ? '0' : s;
}

export function attrs(map) {
    let out = '';
    for (const [key, value] of Object.entries(map || {})) {
        if (value === null || value === undefined || value === false) continue;
        out += ` ${key}="${typeof value === 'number' ? num(value) : esc(value)}"`;
    }
    return out;
}

export function rect(box, a) {
    return `<rect x="${num(box.x)}" y="${num(box.y)}" width="${num(Math.max(0, box.w))}" height="${num(Math.max(0, box.h))}"${attrs(a)}/>`;
}

/** FontSpec of a text style at a size in mm (default: the style's own pt size). */
export function fontSpec(style, fonts, sizeMm, letterSpacingMm) {
    const css = cssFont(style, fonts);
    const spec = { family: css.family, weight: css.weight, sizeMm: sizeMm === undefined ? ptToMm(style.size) : sizeMm };
    if (letterSpacingMm) spec.letterSpacingMm = letterSpacingMm;
    return spec;
}

export function textEl(x, y, text, font, a) {
    const base = {
        x,
        y,
        'font-family': font.family,
        'font-weight': font.weight,
        'font-size': font.sizeMm,
        'letter-spacing': font.letterSpacingMm ? font.letterSpacingMm : null,
    };
    return `<text${attrs({ ...base, ...a })}>${esc(text)}</text>`;
}

export function assetUrl(asset, quality) {
    if (!asset) return '';
    return quality === 'full' ? asset.url : asset.previewUrl || asset.url;
}

export function gradientDef(id, gradient, box) {
    const stops = gradient.stops
        .slice()
        .sort((a, b) => a.pos - b.pos)
        .map((s) => `<stop offset="${num(s.pos)}%" stop-color="${esc(s.color)}" stop-opacity="${num(s.opacity / 100)}"/>`)
        .join('');
    if (gradient.type === 'radial') {
        return `<radialGradient id="${esc(id)}" cx="50%" cy="50%" r="50%">${stops}</radialGradient>`;
    }
    // angle on the unit square of the box: 0° = left → right, 90° = top → bottom
    const rad = ((Number(gradient.angle) || 0) * Math.PI) / 180;
    const dx = Math.cos(rad) / 2;
    const dy = Math.sin(rad) / 2;
    void box;
    return `<linearGradient id="${esc(id)}" x1="${num(0.5 - dx)}" y1="${num(0.5 - dy)}" x2="${num(0.5 + dx)}" y2="${num(0.5 + dy)}">${stops}</linearGradient>`;
}

export function imageIn(box, asset, fit, opacity, opts) {
    const href = assetUrl(asset, opts && opts.quality);
    if (!href) return '';
    const par = fit === 'contain' ? 'xMidYMid meet' : 'xMidYMid slice';
    const op = opacity === undefined || opacity === null || opacity >= 100 ? null : opacity / 100;
    return `<image href="${esc(href)}" x="${num(box.x)}" y="${num(box.y)}" width="${num(box.w)}" height="${num(box.h)}" preserveAspectRatio="${par}"${attrs({ opacity: op })}/>`;
}

/** Neutral slot placeholder: grey rect, diagonals and an optional label pill. */
export function slotPlaceholder(box, label, opts = {}) {
    const r = opts.radius || 0;
    let out = rect(box, { rx: r || null, fill: COLORS.ph });
    out += `<path d="M${num(box.x)} ${num(box.y)}L${num(box.x + box.w)} ${num(box.y + box.h)}M${num(box.x + box.w)} ${num(box.y)}L${num(box.x)} ${num(box.y + box.h)}" stroke="${COLORS.ph2}" stroke-width="0.35" fill="none"/>`;
    if (label && box.w > (opts.minW || 30) && box.h > (opts.minH || 8)) {
        const size = Math.min(3.4, box.h * 0.3);
        const w = Math.min(box.w - 4, label.length * size * 0.72 + 6);
        const h = size * 2.1;
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        out += rect({ x: cx - w / 2, y: cy - h / 2, w, h }, { rx: h / 2, fill: '#FFFFFF', 'fill-opacity': 0.82 });
        out += textEl(cx, cy + size * 0.36, label, { family: '"Outfit", Arial, sans-serif', weight: 600, sizeMm: size, letterSpacingMm: size * 0.06 }, { 'text-anchor': 'middle', fill: COLORS.mut });
    }
    return out;
}

/**
 * Background layers of a box: colour → gradient → picture (architecture §E.6.9).
 * `opts.slotLabel` draws a placeholder when the picture is switched on but missing.
 */
export function fillLayers(box, fill, imageAsset, opts, idPrefix) {
    if (!fill) return '';
    let out = '';
    if (fill.colorOn && fill.color) {
        out += rect(box, { fill: fill.color, 'fill-opacity': fill.colorOpacity < 100 ? fill.colorOpacity / 100 : null });
    }
    if (fill.gradient && fill.gradient.on) {
        const id = `${idPrefix}-g`;
        out += `<defs>${gradientDef(id, fill.gradient, box)}</defs>${rect(box, { fill: `url(#${id})` })}`;
    }
    if (fill.imageOn) {
        if (imageAsset) {
            const id = `${idPrefix}-c`;
            out += `<defs><clipPath id="${esc(id)}">${rect(box)}</clipPath></defs><g clip-path="url(#${esc(id)})">${imageIn(box, imageAsset, fill.fit, fill.imageOpacity, opts)}</g>`;
        } else if (opts && opts.slotLabel && opts.placeholders !== 'none') {
            out += slotPlaceholder(box, opts.slotLabel);
        }
    }
    return out;
}

/** A photo cropped into a frame: cover scale × zoom, positioned by px / py (0–1), clipped to the radius. */
export function photoIn(box, asset, crop, radius, opts, idPrefix) {
    const href = assetUrl(asset, opts && opts.quality);
    if (!href) return '';
    const id = `${idPrefix}-p`;
    const zoom = Math.max(1, Number(crop && crop.zoom) || 1);
    const px = crop && Number.isFinite(crop.px) ? crop.px : 0.5;
    const py = crop && Number.isFinite(crop.py) ? crop.py : 0.5;
    let image;
    if (asset.width > 0 && asset.height > 0) {
        const ratio = asset.width / asset.height;
        const dh = Math.max(box.w / ratio, box.h) * zoom;
        const dw = dh * ratio;
        image = `<image href="${esc(href)}" x="${num(box.x + (box.w - dw) * px)}" y="${num(box.y + (box.h - dh) * py)}" width="${num(dw)}" height="${num(dh)}" preserveAspectRatio="none"/>`;
    } else {
        image = `<image href="${esc(href)}" x="${num(box.x)}" y="${num(box.y)}" width="${num(box.w)}" height="${num(box.h)}" preserveAspectRatio="xMidYMid slice"/>`;
    }
    return `<defs><clipPath id="${esc(id)}">${rect(box, { rx: radius || null })}</clipPath></defs><g clip-path="url(#${esc(id)})">${image}</g>`;
}
