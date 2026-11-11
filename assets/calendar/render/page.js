// Complete page SVG (architecture §D.5).

import { COLORS, MARKS, bleedOf, ptToMm } from '../model/defaults.js';
import { esc, fillLayers, num, rect } from './svg.js';
import { layoutWeekPage } from './week-layout.js';
import { renderWeekPage } from './week.js';
import { renderBack, renderCover, renderSpecial } from './special.js';

const marksMargin = (B) => B + MARKS.gapMm + MARKS.lengthMm + 1;

export function pageViewBox(design, opts = {}) {
    const { width, height } = design.page;
    const B = bleedOf(design.print);
    if (opts.marks) {
        const M = marksMargin(B);
        return { x: 0 - M, y: 0 - M, w: width + 2 * M, h: height + 2 * M };
    }
    if (opts.bleed) return { x: 0 - B, y: 0 - B, w: width + 2 * B, h: height + 2 * B };
    return { x: 0, y: 0, w: width, h: height };
}

/** The row of binding holes; painted on top of every page. */
export function holesMarkup(page) {
    const { size, radius, pitch, top, margin } = page.holes;
    const count = Math.floor((page.width - 2 * margin - size) / pitch) + 1;
    if (!(count > 0) || !(pitch > 0)) return '';
    const x0 = (page.width - ((count - 1) * pitch + size)) / 2;
    let out = `<g data-el="holes" fill="${COLORS.paper}" pointer-events="none">`;
    for (let i = 0; i < count; i++) out += rect({ x: x0 + i * pitch, y: top, w: size, h: size }, { rx: radius });
    return `${out}</g>`;
}

/** Eight crop marks, each starting `B + gap` from its trim corner along the trim line. */
export function cropMarks(design) {
    const { width: W, height: H } = design.page;
    const a = bleedOf(design.print) + MARKS.gapMm;
    const b = a + MARKS.lengthMm;
    const lines = [
        [-b, 0, -a, 0], [0, -b, 0, -a],
        [W + a, 0, W + b, 0], [W, -b, W, -a],
        [-b, H, -a, H], [0, H + a, 0, H + b],
        [W + a, H, W + b, H], [W, H + a, W, H + b],
    ];
    const marks = lines.map(([x1, y1, x2, y2]) => `<line x1="${num(x1)}" y1="${num(y1)}" x2="${num(x2)}" y2="${num(y2)}"/>`).join('');
    return `<g data-el="marks" stroke="#000000" stroke-width="${num(ptToMm(MARKS.weightPt))}" fill="none">${marks}</g>`;
}

/**
 * @param doc   Doc
 * @param page  a page of `buildPages(doc)`
 * @param opts  RenderOpts `{ env, measurer, idPrefix, bleed?, marks?, drawMarks?, holes?, placeholders?, quality?, dayContext? }`
 *              — `drawMarks: false` keeps the band of the crop marks (`marks`) but leaves it empty: the CMYK export strokes
 *              the marks itself, in the registration colour
 * @returns a complete `<svg>` string with a viewBox in mm
 */
export function renderPage(doc, page, opts) {
    const o = { bleed: false, marks: false, drawMarks: true, holes: true, placeholders: 'frames', quality: 'preview', ...opts };
    o.env = { assets: {}, fonts: [], ...(opts.env || {}) };
    o.idPrefix = String(o.idPrefix || 'cal');
    const design = doc.design;
    const { width, height } = design.page;
    const B = bleedOf(design.print);
    const vb = pageViewBox(design, o);
    const bleedBox = { x: 0 - B, y: 0 - B, w: width + 2 * B, h: height + 2 * B };

    let body = rect(bleedBox, { fill: '#FFFFFF', 'data-el': 'paper' });
    const styles = design.layout ? design.layout.styles : null;
    if (styles) {
        const images = doc.content.pageImages[page.key];
        const bgAsset = o.env.assets[(images && images.bg) || styles.bg.asset] || null;
        body += `<g data-el="bg">${fillLayers(bleedBox, styles.bg, bgAsset, o, `${o.idPrefix}-bg`)}</g>`;
    } else {
        body += rect(bleedBox, { fill: COLORS.paper, 'data-el': 'bg' });
    }

    if (page.kind === 'week') {
        if (design.layout) body += renderWeekPage(doc, page, layoutWeekPage(doc, page, o), o);
    } else if (page.kind === 'cover') {
        body += renderCover(doc, page, o);
    } else if (page.kind === 'back') {
        body += renderBack(doc, page, o);
    } else {
        body += renderSpecial(doc, page, o);
    }
    if (o.holes) body += holesMarkup(design.page);

    let content = body;
    if (o.marks) {
        const clip = `${o.idPrefix}-page`;
        content = `${rect(vb, { fill: '#FFFFFF' })}<defs><clipPath id="${esc(clip)}">${rect(bleedBox)}</clipPath></defs><g clip-path="url(#${esc(clip)})">${body}</g>${o.drawMarks ? cropMarks(design) : ''}`;
    }
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${num(vb.x)} ${num(vb.y)} ${num(vb.w)} ${num(vb.h)}" data-page="${esc(page.key)}" role="img" aria-label="${esc(page.title)}" font-family="&quot;Outfit&quot;, Arial, sans-serif">${content}</svg>`;
}
