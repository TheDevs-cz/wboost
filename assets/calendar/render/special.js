// Cover, back and special pages (map §4.5, handoff §10.10 and §10.13).
//
// These pages are generated structure: the real year grid, the real waste grid and the legend of
// clubs come from the calendar's data, everything a client would have to supply (cover photo,
// intro text, contact values) is a wireframe placeholder — exactly as far as the prototype goes.
//
// Geometry: the prototype drew on a 1000 × 468 canvas whose top 40 units were the binding band.
// Every coordinate below is stated in those design units and mapped onto the real page, so the
// pages stay proportional for any page size:
//   x  → width / 1000
//   y  → the band keeps its real height, the rest of the page scales by (height − band) / 428
//   type sizes and square things → the smaller of the two factors
// Pure: no DOM (architecture §D.1).

import { COLORS, bleedOf } from '../model/defaults.js';
import { FALLBACK_FAMILY, faceByFamily, nearestFace } from '../model/fonts.js';
import { sorted } from '../model/content.js';
import { MONTHS, WEEKDAY_INITIALS, holidayName } from '../generate/czech.js';
import { daysInMonth, iso, weekday } from '../generate/dates.js';
import { createDayContext, wasteKindColor } from '../generate/day.js';
import { wasteKindsOn } from '../generate/waste.js';
import { wrapText } from './measure.js';
import { esc, fillLayers, imageIn, num, rect, slotPlaceholder, textEl } from './svg.js';

const DESIGN_W = 1000;
const DESIGN_H = 468;
const DESIGN_BAND = 40;
const HEADER_BOTTOM = 102;

export const CONTACT_ROWS = Object.freeze([
    'Obecní úřad',
    'Starosta',
    'Matrika',
    'Pošta',
    'Praktický lékař',
    'Zubní lékař',
    'Lékárna',
    'Knihovna',
    'Hasiči 150',
    'Záchranná služba 155',
    'Policie 158',
    'Tísňová linka 112',
]);

const FALLBACK_STYLES = Object.freeze({
    head: { text: { family: null, weight: 800, size: 15, color: COLORS.ink }, bg: null },
    num: { family: null, weight: 800, size: 28, color: COLORS.ink },
    name: { family: null, weight: 400, size: 9.5, color: COLORS.ink },
    ev: { color: COLORS.ink },
    cal: { satColor: COLORS.sat, sunColor: COLORS.red },
    logo: { asset: null, fit: 'contain' },
});

const capitalise = (text) => text.charAt(0).toUpperCase() + text.slice(1);

/** Everything the drawing functions share for one page. */
function context(doc, opts) {
    const { width: W, height: H, bandHeight } = doc.design.page;
    const B = bleedOf(doc.design.print);
    const band = Math.min(Math.max(0, Number(bandHeight) || 0), H * 0.5);
    const ux = W / DESIGN_W;
    const uy = (H - band) / (DESIGN_H - DESIGN_BAND);
    const u = Math.min(ux, uy);
    const env = opts.env || {};
    const fonts = env.fonts || [];
    const styles = doc.design.layout ? doc.design.layout.styles : FALLBACK_STYLES;

    /** Font of a text style at a wanted weight: the nearest cut of the same project font, else the fallback. */
    const font = (style, weight, sizeMm, letterSpacingMm) => {
        const face = faceByFamily(fonts, style && style.family);
        const cut = face ? nearestFace(fonts, face.fontName, weight) || face : null;
        const spec = cut ? { family: `"${cut.family}"`, weight: 400, sizeMm } : { family: FALLBACK_FAMILY, weight: Math.min(700, weight), sizeMm };
        if (letterSpacingMm) spec.letterSpacingMm = letterSpacingMm;
        return spec;
    };

    const c = {
        doc,
        opts,
        W,
        H,
        B,
        band,
        ux,
        uy,
        u,
        styles,
        fonts,
        assets: env.assets || {},
        measurer: opts.measurer,
        project: env.projectName || 'Název klienta',
        placeholders: opts.placeholders !== 'none',
        headColor: (styles.head.text && styles.head.text.color) || COLORS.ink,
        sat: styles.cal.satColor || COLORS.sat,
        red: styles.cal.sunColor || COLORS.red,
        X: (v) => v * ux,
        Y: (v) => (v <= DESIGN_BAND ? (v / DESIGN_BAND) * band : band + (v - DESIGN_BAND) * uy),
        /** A design-unit rectangle on the page. */
        box: (x, y, w, h) => ({ x: x * ux, y: c.Y(y), w: w * ux, h: c.Y(y + h) - c.Y(y) }),
        headFont: (weight, size, spacing) => font(styles.head.text, weight, size * u, spacing ? spacing * u : 0),
        numFont: (weight, size) => font(styles.num, weight, size * u),
        plainFont: (weight, size, spacing) => font(null, weight, size * u, spacing ? spacing * u : 0),
        bodyFont: (sizeMm) => font(styles.name, (styles.name && styles.name.weight) || 400, sizeMm),
    };
    return c;
}

/** Text at a design-unit point. `o`: `{ anchor, fill, font }`. */
function tx(c, x, y, text, font, o = {}) {
    return textEl(c.X(x), c.Y(y), text, font, { 'text-anchor': o.anchor && o.anchor !== 'start' ? o.anchor : null, fill: o.fill || COLORS.ink });
}

const bar = (c, x, y, w, h, fill, opacity) => rect(c.box(x, y, w, h), { rx: Math.min(3 * c.u, (c.Y(y + h) - c.Y(y)) / 2), fill: fill || COLORS.ph, 'fill-opacity': opacity === undefined ? null : opacity });

/** Grey text-line placeholders; every fifth line is shorter, like the end of a paragraph. */
function lines(c, x, y, w, n, gap = 19) {
    let out = '';
    for (let k = 0; k < n; k++) out += bar(c, x, y + k * gap, k % 5 === 4 ? w * 0.62 : w, 8);
    return out;
}

/** Photo slot (grey, diagonals, label pill); nothing with `placeholders: 'none'`. */
function photoSlot(c, box, label) {
    return c.placeholders ? slotPlaceholder(box, label) : '';
}

/** Dashed square for the client's logo, or the logo of the style when one is set. */
function logoBox(c, cx, top, size) {
    const side = size * c.u;
    const box = { x: c.X(cx) - side / 2, y: c.Y(top), w: side, h: side };
    const asset = c.styles.logo && c.styles.logo.asset ? c.assets[c.styles.logo.asset] : null;
    if (asset) return imageIn(box, asset, 'contain', 100, c.opts);
    if (!c.placeholders) return '';
    const dash = `${num(5 * c.u)} ${num(4 * c.u)}`;
    return (
        rect(box, { rx: 6 * c.u, fill: 'none', stroke: COLORS.mut, 'stroke-width': 0.3, 'stroke-dasharray': dash }) +
        textEl(box.x + side / 2, box.y + side / 2 + 0.36 * 12 * c.u, 'LOGO', c.plainFont(700, 12, 1.5), { 'text-anchor': 'middle', fill: COLORS.mut })
    );
}

/** Header band in the Hlavička style with the page title and the client name. */
function header(c, title, id) {
    const band = { x: -c.B, y: -c.B, w: c.W + 2 * c.B, h: c.Y(HEADER_BOTTOM) + c.B };
    let out = '';
    if (!c.styles.head.bg) out += rect(band, { fill: COLORS.band });
    else out += fillLayers(band, c.styles.head.bg, c.assets[c.styles.head.bg.asset] || null, { ...c.opts, slotLabel: null }, `${c.opts.idPrefix}-${id}`);
    out += tx(c, 32, 80, title, c.headFont(800, 20, 0.5), { fill: c.headColor });
    out += tx(c, DESIGN_W - 32, 80, c.project, c.headFont(400, 12), { anchor: 'end', fill: c.headColor });
    return out;
}

/** One month of a year grid: name, weekday initials, day numbers with weekends and holidays coloured. */
function monthGrid(c, cx, cy, year, month, dx, dy, size) {
    let out = tx(c, cx, cy, MONTHS[month - 1].toUpperCase(), c.headFont(700, size + 2.5, 1));
    WEEKDAY_INITIALS.forEach((initial, i) => {
        out += tx(c, cx + dx / 2 + i * dx, cy + dy + 1, initial, c.plainFont(700, size - 1), { anchor: 'middle', fill: i === 6 ? c.red : i === 5 ? c.sat : COLORS.mut });
    });
    const offset = weekday(iso(year, month, 1));
    for (let d = 1, last = daysInMonth(year, month); d <= last; d++) {
        const cell = offset + d - 1;
        const wd = cell % 7;
        const holiday = holidayName(iso(year, month, d)) !== '';
        out += tx(c, cx + dx / 2 + wd * dx, cy + dy * 2 + Math.floor(cell / 7) * dy, String(d), c.numFont(holiday ? 700 : 400, size), {
            anchor: 'middle',
            fill: holiday || wd === 6 ? c.red : wd === 5 ? c.sat : COLORS.ink,
        });
    }
    return `<g data-month="${year}-${String(month).padStart(2, '0')}">${out}</g>`;
}

function yearFooter(c) {
    // below the sixth week row of the lowest months (the prototype let them collide)
    return bar(c, 32, DESIGN_H - 27, 300, 11) + tx(c, DESIGN_W - 32, DESIGN_H - 18, 'Kontakty, web a logo klienta', c.plainFont(400, 10.5), { anchor: 'end', fill: COLORS.mut });
}

function sixByTwo(c, year) {
    let out = '';
    for (let m = 0; m < 12; m++) out += monthGrid(c, 32 + (m % 6) * 160, 130 + Math.floor(m / 6) * 168, year, m + 1, 20, 19, 9.5);
    return out;
}

// ---- generated kinds --------------------------------------------------------------------

function intro(c, layout, title) {
    const sign = (x, y, anchor) => tx(c, x, y, 'Jméno Příjmení', c.headFont(700, 12), { anchor }) + tx(c, x, y + 18, 'starosta obce', c.plainFont(400, 10.5), { anchor, fill: COLORS.mut });
    if (layout === 0) {
        const photo = { x: -c.B, y: c.Y(DESIGN_BAND), w: c.X(430) + c.B, h: c.H - c.Y(DESIGN_BAND) + c.B };
        const color = c.headColor.toUpperCase() === '#FFFFFF' ? COLORS.ink : c.headColor;
        return photoSlot(c, photo, 'FOTOGRAFIE') + tx(c, 470, 100, title, c.headFont(800, 22, 0.5), { fill: color }) + lines(c, 470, 130, 498, 12) + sign(968, 395, 'end');
    }
    if (layout === 1) return header(c, title, 'intro') + lines(c, 170, 140, 660, 11) + sign(830, 380, 'end');
    const portrait = c.box(32, 130, 170, 210);
    let out = header(c, title, 'intro');
    if (c.placeholders) {
        out += rect(portrait, { rx: 6 * c.u, fill: COLORS.ph });
        out += textEl(portrait.x + portrait.w / 2, portrait.y + portrait.h / 2 + 0.36 * 10 * c.u, 'PORTRÉT', c.plainFont(700, 10, 1.5), { 'text-anchor': 'middle', fill: COLORS.mut });
    }
    return out + sign(32, 370, 'start') + lines(c, 236, 130, 350, 13) + lines(c, 618, 130, 350, 11);
}

/** Clubs for the legend of the contacts page: only while events are on, at most eight. */
function legendClubs(doc) {
    if (!doc.design.features.events) return [];
    return sorted(doc.content.clubs || {}, 'pos')
        .map(([, club]) => club)
        .filter((club) => String(club.code || '').trim() !== '')
        .slice(0, 8);
}

function dashed(c, x1, x2, y, color, pattern) {
    return `<line x1="${num(c.X(x1))}" x2="${num(c.X(x2))}" y1="${num(c.Y(y))}" y2="${num(c.Y(y))}" stroke="${esc(color)}" stroke-width="0.25" stroke-dasharray="${pattern.map((v) => num(v * c.u)).join(' ')}"/>`;
}

function contacts(c, layout, title) {
    let out = header(c, title, 'contacts');
    if (layout === 1) {
        const cols = [
            ['INSTITUCE', 32],
            ['TELEFON', 400],
            ['E-MAIL', 580],
            ['ÚŘEDNÍ HODINY', 790],
        ];
        for (const [title, x] of cols) out += tx(c, x + 8, 128, title, c.plainFont(800, 9.5, 1), { fill: COLORS.mut });
        CONTACT_ROWS.slice(0, 11).forEach((row, i) => {
            const y = 140 + i * 27;
            if (i % 2 === 0) out += rect(c.box(32, y, DESIGN_W - 64, 27), { fill: COLORS.ph, 'fill-opacity': 0.45 });
            out += tx(c, 40, y + 18, row, c.headFont(700, 11.5));
            cols.slice(1).forEach(([, x], k) => {
                out += bar(c, x + 8, y + 10, [110, 150, 140][k], 8);
            });
        });
        return out;
    }
    if (layout === 2) {
        const top = c.Y(HEADER_BOTTOM);
        out += photoSlot(c, { x: c.X(560), y: top, w: c.W - c.X(560) + c.B, h: c.H - top + c.B }, 'FOTOGRAFIE');
        CONTACT_ROWS.slice(0, 9).forEach((row, i) => {
            const y = 136 + i * 36;
            out += tx(c, 32, y, row, c.headFont(700, 12.5)) + dashed(c, 32, 520, y + 11, COLORS.line, [2, 3]) + bar(c, 410, y - 11, 110, 12);
        });
        return out;
    }
    const clubs = legendClubs(c.doc);
    const legend = clubs.length > 0;
    const colX = legend ? [32, 352] : [32, 520];
    const colW = legend ? 290 : 448;
    CONTACT_ROWS.forEach((row, i) => {
        const x = colX[i < 6 ? 0 : 1];
        const y = 140 + (i % 6) * 50;
        out += tx(c, x, y, row, c.headFont(700, 13)) + dashed(c, x, x + colW, y + 12, COLORS.line, [2, 3]) + bar(c, x + colW - 110, y - 12, 110, 13);
    });
    if (legend) {
        const x = 690;
        const nameFont = c.headFont(400, 11.5);
        out += '<g data-legend="clubs">';
        out += tx(c, x, 140, 'VYSVĚTLIVKY', c.headFont(800, 16, 0.5), { fill: (c.styles.ev && c.styles.ev.color) || COLORS.ink });
        clubs.forEach((club, i) => {
            const y = 178 + i * 32;
            const name = String(club.name || '').trim();
            // the dotted leader ends where the right-aligned name begins
            const nameW = name && c.measurer ? c.measurer.width(name, nameFont) / c.ux : 118;
            const leaderEnd = Math.max(x + 60, DESIGN_W - 32 - nameW - 8);
            out += tx(c, x, y, club.code, c.headFont(800, 12));
            if (leaderEnd > x + 56) out += dashed(c, x + 52, leaderEnd, y, COLORS.mut, [1, 3]);
            if (name) out += tx(c, DESIGN_W - 32, y, name, nameFont, { anchor: 'end' });
        });
        out += '</g>';
    }
    return out;
}

function wastePlan(c, layout, title) {
    const { doc } = c;
    const year = doc.design.year;
    const dayContext = c.opts.dayContext || createDayContext(doc);
    const kinds = doc.design.features.waste ? dayContext.wasteKinds.filter(([, kind]) => kind.on) : [];
    const gridRight = layout === 1 ? 640 : DESIGN_W - 32;
    const x0 = 92;
    const cw = (gridRight - x0) / 31;
    const ch = 21;
    const y0 = 132;
    let out = header(c, `${title} ${year}`, 'waste');
    out += '<g data-waste-grid="1">';
    for (let d = 0; d < 31; d++) out += tx(c, x0 + d * cw + cw / 2, y0 - 8, String(d + 1), c.plainFont(700, cw > 22 ? 9 : 7.5), { anchor: 'middle', fill: COLORS.mut });
    for (let m = 1; m <= 12; m++) {
        const y = y0 + (m - 1) * ch;
        const last = daysInMonth(year, m);
        out += tx(c, 32, y + 14.5, capitalise(MONTHS[m - 1]), c.headFont(700, 10));
        for (let d = 1; d <= 31; d++) {
            const x = x0 + (d - 1) * cw;
            const cell = c.box(x, y, cw, ch);
            if (d > last) {
                out += rect(cell, { fill: COLORS.ph2, 'fill-opacity': 0.5 });
                continue;
            }
            const date = iso(year, m, d);
            const wd = weekday(date);
            const holiday = holidayName(date) !== '';
            if (wd >= 5 || holiday) out += rect(cell, { fill: holiday || wd === 6 ? c.red : c.sat, 'fill-opacity': 0.12 });
            const on = wasteKindsOn(doc, date, dayContext);
            on.forEach(({ id, kind }, k) => {
                const part = c.box(x + 1.5, y + 1.5 + (k * (ch - 3)) / on.length, cw - 3, (ch - 3) / on.length);
                out += rect(part, { fill: wasteKindColor(c.styles, id, kind), 'data-waste': `${date}:${id}` });
            });
            out += rect(cell, { fill: 'none', stroke: COLORS.line, 'stroke-width': 0.25 });
        }
    }
    out += '</g>';

    // legend of the active kinds, flowing left to right
    const legendFont = c.headFont(400, 10.5);
    let lx = 32;
    out += '<g data-legend="waste">';
    for (const [id, kind] of kinds) {
        const name = String(kind.name || '').trim() || 'Odpad';
        const side = 14 * c.u;
        const top = c.Y(DESIGN_H - 30);
        out += rect({ x: c.X(lx), y: top, w: side, h: side }, { rx: 2 * c.u, fill: wasteKindColor(c.styles, id, kind) });
        out += textEl(c.X(lx) + side + 8 * c.u, top + side / 2 + 0.36 * legendFont.sizeMm, name, legendFont, { fill: COLORS.ink });
        const width = c.measurer ? c.measurer.width(name, legendFont) / c.ux : name.length * 6;
        lx += Math.max(120, 14 + 8 + width + 28);
    }
    out += '</g>';

    if (layout === 1) {
        const tables = [
            ['VELKOOBJEMOVÝ ODPAD', 4, 0],
            ['NEBEZPEČNÝ ODPAD', 3, 104],
            ['SBĚRNÝ DVŮR — PROVOZNÍ DOBA', 3, 190],
        ];
        for (const [title, rows, dy] of tables) {
            const y = 128 + dy;
            out += tx(c, 672, y, title, c.headFont(800, 10.5, 0.5));
            for (let r = 0; r < rows; r++) {
                out += rect(c.box(672, y + 10 + r * 20, DESIGN_W - 704, 17), { fill: COLORS.ph, 'fill-opacity': r % 2 ? 0.25 : 0.55 });
                out += bar(c, 680, y + 15 + r * 20, 120, 7, COLORS.ph2);
            }
        }
    }
    return out;
}

function yearPage(c, item, title) {
    const year = c.doc.design.year + (item.nextYear ? 1 : 0);
    const layout = item.layout || 0;
    if (layout === 2) {
        let out = header(c, `${title} ${year} / ${year + 1}`, 'year');
        [year, year + 1].forEach((y, half) => {
            out += tx(c, 32 + half * 484, 124, String(y), c.headFont(800, 13));
            for (let m = 0; m < 12; m++) out += monthGrid(c, 32 + half * 484 + (m % 6) * 78, 146 + Math.floor(m / 6) * 150, y, m + 1, 10.6, 15.5, 6.6);
        });
        return out + yearFooter(c);
    }
    let out = header(c, `${title} ${year}`, 'year');
    if (layout === 1) {
        for (let m = 0; m < 12; m++) out += monthGrid(c, 32 + (m % 4) * 240, 122 + Math.floor(m / 4) * 112, year, m + 1, 26, 12.4, 8);
    } else {
        out += sixByTwo(c, year);
    }
    return out + yearFooter(c);
}

// ---- uploaded pages ---------------------------------------------------------------------

function imagePage(c, item) {
    const asset = item.asset ? c.assets[item.asset] : null;
    const full = { x: -c.B, y: -c.B, w: c.W + 2 * c.B, h: c.H + 2 * c.B };
    // a finished page covers the whole sheet including the bleed (handoff §10.10)
    if (asset) return imageIn(full, asset, 'cover', 100, c.opts);
    if (!c.placeholders) return '';
    const file = String(item.fileName || '').trim();
    if (/\.pdf$/i.test(file)) {
        const box = { x: c.W / 2 - c.X(140), y: c.H / 2 - 40 * c.uy, w: c.X(280), h: 80 * c.uy };
        return rect(box, { rx: 10 * c.u, fill: COLORS.ph }) + textEl(c.W / 2, c.H / 2 + 0.36 * 13 * c.u, `PDF: ${file}`, c.plainFont(700, 13), { 'text-anchor': 'middle', fill: COLORS.mut });
    }
    return slotPlaceholder({ x: 0, y: c.Y(DESIGN_BAND), w: c.W, h: c.H - c.Y(DESIGN_BAND) }, 'VLASTNÍ STRANA');
}

/** Body sizes tried for a text page, largest first (design units); the first that fits is used. */
const TEXT_SIZES = Object.freeze([13, 11.6, 10.3, 9.4, 8.4, 7.4]);
/** Below this size one line across the whole page is too long to read: two columns. */
const TEXT_ONE_COLUMN_MIN = 10.3;

/** Wraps the paragraphs into `columns` columns; `null` when they do not fit the height. */
function flowText(c, paragraphs, sizeMm, columns, area) {
    const font = c.bodyFont(sizeMm);
    const gap = columns > 1 ? Math.max(6, area.w * 0.035) : 0;
    const colW = (area.w - gap * (columns - 1)) / columns;
    const lineH = sizeMm * 1.5;
    const perColumn = Math.max(1, Math.floor((area.h + lineH - sizeMm) / lineH));
    const rows = [];
    paragraphs.forEach((paragraph, index) => {
        if (index > 0) rows.push(null);
        for (const line of wrapText(paragraph, colW, font, c.measurer)) rows.push(line);
    });
    const placed = [];
    let column = 0;
    let row = 0;
    for (const line of rows) {
        if (row >= perColumn) {
            column += 1;
            row = 0;
        }
        if (line === null) {
            // paragraph gap: half a line, never at the top of a column
            if (row > 0) row += 0.5;
            continue;
        }
        placed.push({ column, row, line });
        row += 1;
    }
    const fits = column < columns && placed.every((p) => p.column < columns);
    return { fits, font, colW, gap, lineH, placed: placed.filter((p) => p.column < columns) };
}

function textPage(c, page, item) {
    let out = header(c, String(page.title || 'Vlastní strana').toUpperCase(), 'text');
    const file = String(item.fileName || '').trim();
    const source = String(item.text || '').trim() || (file ? `Text z dokumentu „${file}“ se vysází do písma a barev kalendáře.` : '');
    const paragraphs = source
        .split(/\n+/)
        .map((p) => p.replace(/\s+/g, ' ').trim())
        .filter((p) => p !== '');
    if (!paragraphs.length || !c.measurer) return out;
    const top = c.Y(HEADER_BOTTOM) + 26 * c.uy;
    const area = { x: c.X(32), y: top, w: c.W - 2 * c.X(32), h: c.H - top - 22 * c.uy };
    let flow = null;
    for (const columns of [1, 2, 3]) {
        for (const size of TEXT_SIZES) {
            // one column only while the type stays comfortable to read across the page
            if (columns === 1 && size < TEXT_ONE_COLUMN_MIN) continue;
            // a third column is the last resort for the longest documents
            if (columns === 3 && size > TEXT_SIZES[TEXT_SIZES.length - 2]) continue;
            flow = flowText(c, paragraphs, size * c.u, columns, area);
            if (flow.fits) break;
        }
        if (flow.fits) break;
    }
    const color = (c.styles.name && c.styles.name.color) || COLORS.ink;
    out += '<g data-el="special-text">';
    flow.placed.forEach((p, index) => {
        const last = index === flow.placed.length - 1 && !flow.fits;
        out += textEl(area.x + p.column * (flow.colW + flow.gap), area.y + flow.font.sizeMm * 0.8 + p.row * flow.lineH, last ? `${p.line.replace(/[\s.,;:]+$/, '')}…` : p.line, flow.font, { fill: color });
    });
    return `${out}</g>`;
}

// ---- entry points -----------------------------------------------------------------------

export function renderCover(doc, page, opts) {
    const c = context(doc, opts);
    const photo = { x: -c.B, y: c.Y(DESIGN_BAND), w: c.W + 2 * c.B, h: c.H - c.Y(DESIGN_BAND) + c.B };
    const head = c.styles.head.bg;
    const card = c.box(DESIGN_W / 2 - 230, 300, 460, 120);
    let out = '<g data-el="cover">';
    out += photoSlot(c, photo, 'TITULNÍ FOTOGRAFIE');
    out += rect(card, { rx: 10 * c.u, fill: head && head.colorOn && head.color ? head.color : COLORS.paper, 'fill-opacity': 0.94 });
    out += tx(c, DESIGN_W / 2, 352, `KALENDÁŘ ${doc.design.year}`, c.headFont(800, 34, 1), { anchor: 'middle', fill: c.headColor });
    out += tx(c, DESIGN_W / 2, 388, c.project, c.headFont(400, 15), { anchor: 'middle', fill: c.headColor });
    out += logoBox(c, DESIGN_W - 72, 58, 80);
    return `${out}</g>`;
}

export function renderBack(doc, page, opts) {
    const c = context(doc, opts);
    let out = `<g data-el="back" data-variant="${page.backVariant === 'contacts' ? 'contacts' : 'year'}">`;
    if (page.backVariant === 'contacts') {
        // Roční kalendárium is a page of its own, so the back page carries the client's contacts
        out += logoBox(c, DESIGN_W / 2, 150, 120);
        out += tx(c, DESIGN_W / 2, 320, c.project, c.headFont(700, 15), { anchor: 'middle' });
        out += bar(c, DESIGN_W / 2 - 180, 340, 360, 12) + bar(c, DESIGN_W / 2 - 120, 362, 240, 12);
    } else {
        out += header(c, `PŘEHLED ROKU ${doc.design.year}`, 'back') + sixByTwo(c, doc.design.year) + yearFooter(c);
    }
    return `${out}</g>`;
}

export function renderSpecial(doc, page, opts) {
    const c = context(doc, opts);
    const item = page.special ? page.special.item : null;
    const kind = item ? item.kind : '';
    let out = `<g data-el="special" data-kind="${esc(kind)}">`;
    if (!item) return `${out}</g>`;
    const layout = Number(item.layout) || 0;
    // the page name in capitals: `ÚVODNÍ SLOVO`, `DŮLEŽITÉ KONTAKTY`, … unless the page was renamed
    const title = String(page.title || '').toUpperCase();
    if (kind === 'image') out += imagePage(c, item);
    else if (kind === 'text') out += textPage(c, page, item);
    else if (kind === 'intro') out += intro(c, layout, title);
    else if (kind === 'contacts') out += contacts(c, layout, title);
    else if (kind === 'waste') out += wastePlan(c, layout, title);
    else if (kind === 'year') out += yearPage(c, item, title);
    return `${out}</g>`;
}

/** Layout names of the generated kinds (map §8.2), in the order of `specialPages[*].layout`. */
export const SPECIAL_LAYOUTS = Object.freeze({
    intro: Object.freeze(['Fotka + text', 'Jen text', 'Portrét + dva sloupce']),
    contacts: Object.freeze(['Dva sloupce', 'Tabulka', 'Seznam + fotka']),
    year: Object.freeze(['6 × 2 měsíce', '4 × 3 měsíce', 'Dva roky vedle sebe']),
    waste: Object.freeze(['Roční mřížka', 'Mřížka + tabulky']),
});
