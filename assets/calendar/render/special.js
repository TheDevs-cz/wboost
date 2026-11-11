// Cover, back and special pages. Phase P2 ships titled placeholders in the calendar's own header
// style; phase P6a implements the real pages (map §4.5).

import { COLORS, bleedOf, ptToMm } from '../model/defaults.js';
import { fillLayers, fontSpec, rect, slotPlaceholder, textEl } from './svg.js';

function styles(doc) {
    return doc.design.layout ? doc.design.layout.styles : null;
}

function headText(doc, opts, scale = 1) {
    const s = styles(doc);
    const style = s ? s.head.text : { family: null, weight: 800, size: 15, color: COLORS.ink };
    return { font: fontSpec(style, (opts.env && opts.env.fonts) || [], ptToMm(style.size) * scale), color: style.color };
}

function headBand(doc, page, opts, title, id) {
    const { width, height, bandHeight } = doc.design.page;
    const B = bleedOf(doc.design.print);
    const s = styles(doc);
    const band = { x: -B, y: -B, w: width + 2 * B, h: bandHeight + height * 0.1 + B };
    let out = s ? fillLayers(band, s.head.bg, null, { ...opts, placeholders: 'none' }, `${opts.idPrefix}-${id}`) : rect(band, { fill: COLORS.band });
    const t = headText(doc, opts);
    out += textEl(width * 0.03, bandHeight + height * 0.05 + 0.36 * t.font.sizeMm, title, t.font, { fill: t.color });
    return out;
}

function body(doc) {
    const { width, height, bandHeight } = doc.design.page;
    const m = width * 0.03;
    const top = bandHeight + height * 0.1 + m;
    return { x: m, y: top, w: width - 2 * m, h: height - top - m };
}

export function renderCover(doc, page, opts) {
    const { width, height, bandHeight } = doc.design.page;
    const B = bleedOf(doc.design.print);
    const photo = { x: -B, y: bandHeight, w: width + 2 * B, h: height - bandHeight + B };
    let out = '<g data-el="cover">';
    if (opts.placeholders !== 'none') out += slotPlaceholder(photo, 'TITULNÍ FOTOGRAFIE');
    const t = headText(doc, opts, 1.6);
    const sub = headText(doc, opts, 0.75);
    const card = { x: width * 0.27, y: height * 0.6, w: width * 0.46, h: height * 0.27 };
    out += rect(card, { rx: 3, fill: COLORS.paper, 'fill-opacity': 0.94 });
    out += textEl(width / 2, card.y + card.h * 0.48, `KALENDÁŘ ${doc.design.year}`, t.font, { 'text-anchor': 'middle', fill: t.color });
    out += textEl(width / 2, card.y + card.h * 0.8, (opts.env && opts.env.projectName) || 'Název klienta', sub.font, { 'text-anchor': 'middle', fill: t.color });
    return `${out}</g>`;
}

export function renderBack(doc, page, opts) {
    const title = page.backVariant === 'contacts' ? 'Kontakty, web a logo klienta' : `PŘEHLED ROKU ${doc.design.year}`;
    let out = `<g data-el="back">${headBand(doc, page, opts, title, 'back')}`;
    if (opts.placeholders !== 'none') out += slotPlaceholder(body(doc), page.title.toUpperCase());
    return `${out}</g>`;
}

export function renderSpecial(doc, page, opts) {
    const item = page.special ? page.special.item : null;
    const assets = (opts.env && opts.env.assets) || {};
    let out = '<g data-el="special">';
    if (item && item.kind === 'image' && item.asset && assets[item.asset]) {
        const { width, height } = doc.design.page;
        const B = bleedOf(doc.design.print);
        out += fillLayers({ x: -B, y: -B, w: width + 2 * B, h: height + 2 * B }, { imageOn: true, fit: 'cover', imageOpacity: 100 }, assets[item.asset], opts, `${opts.idPrefix}-sp`);
        return `${out}</g>`;
    }
    out += headBand(doc, page, opts, page.title.toUpperCase(), 'sp');
    if (opts.placeholders !== 'none') out += slotPlaceholder(body(doc), page.subtitle.toUpperCase());
    return `${out}</g>`;
}
