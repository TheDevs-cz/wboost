// Week page markup (architecture §D.5). Returns inner SVG; `render/page.js` wraps it.

import { COLORS, K, ptToMm } from '../model/defaults.js';
import { isElementVisible } from '../model/elements.js';
import { attrs, esc, fillLayers, fontSpec, num, photoIn, imageIn, rect, slotPlaceholder, textEl } from './svg.js';
import { iconFragment } from './icons.js';
import { wrapText } from './measure.js';
import { fillIsOn, monthTextSpec } from './week-layout.js';

const SAMPLE_CAPTION = 'Popisek fotografie, autor';

function assetOf(opts, id) {
    const assets = (opts.env && opts.env.assets) || {};
    return id && assets[id] ? assets[id] : null;
}

function renderItem(item, frame, date, opts) {
    const data = ` data-part="${item.part}"`;
    switch (item.type) {
        case 'text':
            return textEl(item.x, item.y, item.text, item.font, { 'text-anchor': item.anchor === 'start' ? null : item.anchor, fill: item.fill, 'data-part': item.part });
        case 'rect':
            return rect(item.box, { fill: item.fill, 'fill-opacity': item.opacity < 1 ? item.opacity : null, 'data-part': item.part });
        case 'line': {
            const lw = item.width;
            const dash = item.style === 'dash' ? `${num(lw * 4)} ${num(lw * 3)}` : item.style === 'dot' ? `0.01 ${num(lw * 2.6)}` : null;
            return `<line x1="${num(item.x1)}" y1="${num(item.y)}" x2="${num(item.x2)}" y2="${num(item.y)}"${attrs({ stroke: item.color, 'stroke-width': lw, 'stroke-dasharray': dash, 'stroke-linecap': item.style === 'dot' ? 'round' : null })}${data}/>`;
        }
        case 'icon':
            return `<g${data}>${iconFragment(item.ref, item.box, opts)}</g>`;
        case 'slot':
            if (opts.placeholders === 'none') return '';
            return rect(item.box, { rx: 0.8, fill: 'none', stroke: COLORS.ph2, 'stroke-width': 0.25, 'stroke-dasharray': '1 0.8', 'data-part': item.part });
        default:
            return '';
    }
}

function renderCal(doc, geometry, opts) {
    let out = '';
    for (const frame of geometry.frames) {
        const bg = frame.styles.cal.bg;
        out += `<g data-el="cal" data-frame="${frame.index}">`;
        if (bg.color) out += rect(frame.box, { fill: bg.color, 'fill-opacity': bg.opacity < 100 ? bg.opacity / 100 : null });
        for (const row of frame.rows) {
            out += `<g data-date="${row.date}">`;
            for (const item of row.items) out += renderItem(item, frame, row.date, opts);
            out += '</g>';
        }
        out += '</g>';
    }
    return out;
}

function renderPhoto(doc, page, opts) {
    const box = doc.design.layout.boxes.photo;
    const s = doc.design.layout.styles.photo;
    const id = `${opts.idPrefix}-photo`;
    const override = doc.content.pagePhotos[page.key];
    const asset = override ? assetOf(opts, override.asset) : null;
    let out = '<g data-el="photo">';
    if (s.shadow.on) {
        out += `<defs><filter id="${esc(id)}-s" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="${num(s.shadow.blur / 2)}"/></filter></defs>`;
        out += rect({ x: box.x + s.shadow.dx, y: box.y + s.shadow.dy, w: box.w, h: box.h }, { rx: s.radius || null, fill: s.shadow.color, 'fill-opacity': s.shadow.opacity / 100, filter: `url(#${id}-s)` });
    }
    if (asset) {
        out += photoIn(box, asset, override, s.radius, opts, id);
    } else if (opts.placeholders !== 'none') {
        out += slotPlaceholder(box, s.source === 'client' ? 'FOTKU DOPLNÍ KLIENT' : 'FOTOGRAFIE', { radius: s.radius, minW: 50, minH: 19 });
    }
    if (s.border.on && s.border.width > 0) {
        const bw = s.border.width;
        out += rect({ x: box.x + bw / 2, y: box.y + bw / 2, w: box.w - bw, h: box.h - bw }, { rx: Math.max(0, s.radius - bw / 2) || null, fill: 'none', stroke: s.border.color, 'stroke-width': bw });
    }
    return `${out}</g>`;
}

function renderHead(doc, page, opts) {
    const layout = doc.design.layout;
    const images = doc.content.pageImages[page.key];
    const asset = assetOf(opts, (images && images.head) || layout.styles.head.bg.asset);
    return `<g data-el="head">${fillLayers(layout.boxes.head, layout.styles.head.bg, asset, { ...opts, slotLabel: 'OBRÁZEK HLAVIČKY' }, `${opts.idPrefix}-head`)}</g>`;
}

function singleLine(box, value, font, align, pad, fill) {
    const x = align === 'start' ? box.x + pad : align === 'end' ? box.x + box.w - pad : box.x + box.w / 2;
    return textEl(x, box.y + box.h / 2 + 0.36 * font.sizeMm, value, font, { 'text-anchor': align === 'start' ? null : align, fill });
}

function renderMonth(doc, page, opts) {
    const layout = doc.design.layout;
    const box = layout.boxes.month;
    const s = layout.styles.month;
    const spec = monthTextSpec(layout.styles, opts.env.fonts, page.week.monthText);
    let out = '<g data-el="month">';
    out += fillLayers(box, s.bg, assetOf(opts, s.bg.asset), opts, `${opts.idPrefix}-month`);
    out += singleLine(box, spec.text, spec.font, s.align, spec.pad, s.color);
    return `${out}</g>`;
}

function renderWk(doc, page, opts) {
    const layout = doc.design.layout;
    const s = layout.styles.wk;
    return `<g data-el="wk">${singleLine(layout.boxes.wk, page.week.weekText, fontSpec(s, opts.env.fonts), s.align, 0, s.color)}</g>`;
}

function renderCap(doc, page, opts) {
    const layout = doc.design.layout;
    const box = layout.boxes.cap;
    const s = layout.styles.cap;
    const stored = doc.content.pageCaptions[page.key];
    const value = (stored && stored.text) || (opts.placeholders === 'all' ? SAMPLE_CAPTION : '');
    let out = '<g data-el="cap">';
    const bgOn = fillIsOn(s.bg);
    if (value || opts.placeholders !== 'none') out += fillLayers(box, s.bg, assetOf(opts, s.bg.asset), opts, `${opts.idPrefix}-cap`);
    if (value) {
        const padX = bgOn ? s.pad : K.CAP_INSET_X;
        const padY = bgOn ? Math.min(s.pad, Math.max(0, (box.h - ptToMm(s.size) * 1.2) / 2)) : K.CAP_INSET_Y;
        const font = fontSpec(s, opts.env.fonts);
        const lines = wrapText(value, Math.max(1, box.w - 2 * padX), font, opts.measurer);
        const lh = 1.2 * font.sizeMm;
        const blockH = lines.length * lh;
        const top = s.valign === 'top' ? box.y + padY : s.valign === 'bottom' ? box.y + box.h - padY - blockH : box.y + (box.h - blockH) / 2;
        const x = s.align === 'start' ? box.x + padX : s.align === 'end' ? box.x + box.w - padX : box.x + box.w / 2;
        lines.forEach((line, i) => {
            out += textEl(x, top + i * lh + lh / 2 + 0.36 * font.sizeMm, line, font, { 'text-anchor': s.align === 'start' ? null : s.align, fill: s.color });
        });
    }
    return `${out}</g>`;
}

function renderLogo(doc, page, opts) {
    const layout = doc.design.layout;
    const box = layout.boxes.logo;
    const s = layout.styles.logo;
    const asset = assetOf(opts, s.asset);
    let out = '<g data-el="logo">';
    if (asset) {
        out += imageIn(box, asset, s.fit, 100, opts);
    } else if (opts.placeholders !== 'none') {
        const size = Math.min(3.8, box.h * 0.3, box.w * 0.16);
        out += rect({ x: box.x + 0.2, y: box.y + 0.2, w: box.w - 0.4, h: box.h - 0.4 }, { rx: 1.2, fill: '#FFFFFF', 'fill-opacity': 0.6, stroke: COLORS.mut, 'stroke-width': 0.3, 'stroke-dasharray': '1.4 1' });
        out += textEl(box.x + box.w / 2, box.y + box.h / 2 + 0.36 * size, 'LOGO', { family: '"Outfit", Arial, sans-serif', weight: 700, sizeMm: size }, { 'text-anchor': 'middle', fill: COLORS.mut });
    }
    return `${out}</g>`;
}

function renderQr(doc, page, opts) {
    const layout = doc.design.layout;
    const b = layout.boxes.qr;
    const s = layout.styles.qr;
    const side = Math.min(b.w, b.h);
    const box = { x: b.x, y: b.y, w: side, h: side };
    const own = doc.content.pageQr[page.key];
    // a page's own code always wins; the shared code is the fallback only while the code is the
    // same on all pages (per-page mode: a page without a code shows none)
    const asset = assetOf(opts, own && own.asset) || (s.perPage ? null : assetOf(opts, s.asset));
    let out = '<g data-el="qr">';
    if (asset) {
        out += imageIn(box, asset, 'contain', 100, opts);
    } else if (opts.placeholders === 'all') {
        const size = Math.min(3.8, side * 0.3);
        out += rect({ x: box.x + 0.2, y: box.y + 0.2, w: side - 0.4, h: side - 0.4 }, { rx: 0.8, fill: '#FFFFFF', 'fill-opacity': 0.7, stroke: COLORS.mut, 'stroke-width': 0.3, 'stroke-dasharray': '1.4 1' });
        out += textEl(box.x + side / 2, box.y + side / 2 + 0.36 * size, 'QR', { family: '"Outfit", Arial, sans-serif', weight: 700, sizeMm: size }, { 'text-anchor': 'middle', fill: COLORS.mut });
    }
    return `${out}</g>`;
}

/** Inner markup of a week page: the visible elements in reverse `layout.order` (bottom layer first). */
export function renderWeekPage(doc, page, geometry, opts) {
    const design = doc.design;
    const order = design.layout.order.slice().reverse();
    let out = '';
    for (const el of order) {
        if (!isElementVisible(design, el)) continue;
        if (el === 'photo') out += renderPhoto(doc, page, opts);
        else if (el === 'head') out += renderHead(doc, page, opts);
        else if (el === 'month') out += renderMonth(doc, page, opts);
        else if (el === 'cal') out += renderCal(doc, geometry, opts);
        else if (el === 'cap') out += renderCap(doc, page, opts);
        else if (el === 'wk') out += renderWk(doc, page, opts);
        else if (el === 'logo') out += renderLogo(doc, page, opts);
        else if (el === 'qr') out += renderQr(doc, page, opts);
    }
    return out;
}
