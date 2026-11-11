// Cover, back and special pages (map §4.5, handoff §10.10 and §10.13).
//
// What is generated stays generated — the year grid, the waste grid, the legends of clubs and waste
// kinds come from the calendar's data. Everything a person has to supply is CONTENT (2026-11-12):
//   `content.pageContent[<page key>]`  texts and the photo of a page (the client may edit them)
//   `content.pageGroups`               groups of lines of the contacts page and of the waste plan
//   `content.pageSetup[<page key>]`    the designer's options (cover layout, logo, alignment, …)
// read through `pageContentOf` / `pageGroupsOf` / `pageSetupOf` (model/content.js), so a missing
// item is simply "empty". An empty text draws nothing in print; with placeholders on (the editor,
// the approval PDF) an unfilled page shows grey bars and photo slots instead.
//
// Text is typeset here: wrapped with the injected measurer, shrunk within limits to fit, flowed
// into balanced columns — and when it still does not fit, the page says so (`specialPageIssues`,
// surfaced as a warning by render/warnings.js) and ends the text with an ellipsis. Nothing is cut
// silently.
//
// Geometry: the prototype drew on a 1000 × 468 canvas whose top 40 units were the binding band.
// Every coordinate below is stated in those design units and mapped onto the real page, so the
// pages stay proportional for any page size:
//   x  → width / 1000
//   y  → the band keeps its real height, the rest of the page scales by (height − band) / 428
//   type sizes and square things → the smaller of the two factors
// Pure: no DOM (architecture §D.1).

import { COLORS, bleedOf } from '../model/defaults.js';
import { FALLBACK_FAMILY, faceByFamily, facesOf, isUpright, nearestFace } from '../model/fonts.js';
import { pageContentOf, pageGroupsOf, pageSetupOf, parseRichText, sorted } from '../model/content.js';
import { MONTHS, WEEKDAY_INITIALS, holidayName } from '../generate/czech.js';
import { daysInMonth, iso, weekday } from '../generate/dates.js';
import { createDayContext, wasteKindColor } from '../generate/day.js';
import { wasteKindsOn } from '../generate/waste.js';
import { wrapText } from './measure.js';
import { attrs, esc, fillLayers, imageIn, num, photoIn, rect, slotPlaceholder, textEl } from './svg.js';

const DESIGN_W = 1000;
const DESIGN_H = 468;
const DESIGN_BAND = 40;
const HEADER_BOTTOM = 102;
const MARGIN = 32;
/** Bottom of the area texts may use. */
const TEXT_BOTTOM = 444;

const FALLBACK_STYLES = Object.freeze({
    head: { text: { family: null, weight: 800, size: 15, color: COLORS.ink }, bg: null },
    num: { family: null, weight: 800, size: 28, color: COLORS.ink },
    name: { family: null, weight: 400, size: 9.5, color: COLORS.ink },
    ev: { color: COLORS.ink },
    cal: { satColor: COLORS.sat, sunColor: COLORS.red },
    logo: { asset: null, fit: 'contain' },
});

/** Body sizes of the intro, largest first (design units); the first that fits is used. */
const INTRO_SIZES = Object.freeze([11.6, 10.8, 10, 9.3, 8.6, 8, 7.4]);
/** Sizes of the contact groups. */
const GROUP_SIZES = Object.freeze([10.4, 9.6, 8.9, 8.2, 7.6, 7, 6.6]);
/** A justified line is spread only while the gaps stay readable (× the type size per gap). */
const JUSTIFY_MAX = 0.5;
/** One-letter Czech prepositions and conjunctions never end a line. */
const ONE_LETTER = /^[aikosuvzAIKOSUVZ]$/;
const NBSP = ' ';

export const OVERFLOW = Object.freeze({
    intro: 'Text úvodního slova se na stránku nevejde — zkraťte ho, nebo zvolte jiné rozvržení',
    groups: 'Kontakty se na stránku nevejdou — uberte řádky, nebo zvolte rozvržení s více sloupci',
    side: 'Texty vedle mřížky se nevejdou — zkraťte je',
    line: (label) => `${label} je příliš dlouhý a nevejde se celý`,
    back: 'Kontakty na zadní straně se nevejdou — zkraťte je',
});

const capitalise = (text) => text.charAt(0).toUpperCase() + text.slice(1);
/** A one-line value: whitespace collapsed, trimmed. */
const one = (text) => String(text === null || text === undefined ? '' : text).replace(/\s+/g, ' ').trim();
const isWhite = (color) => /^#f{3}(f{3})?$/i.test(String(color || ''));

/** Every cut of the project fonts the special pages can draw with (regular, bold, italic of the three styles). */
export function specialFamilies(doc, fonts) {
    const styles = doc.design.layout ? doc.design.layout.styles : null;
    if (!styles) return [];
    const out = [];
    for (const style of [styles.head && styles.head.text, styles.num, styles.name]) {
        const face = faceByFamily(fonts, style && style.family);
        if (!face) continue;
        for (const cut of facesOf(fonts, face.fontName)) if (!out.includes(cut.family)) out.push(cut.family);
    }
    return out;
}

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

    /**
     * The body style in regular / bold / italic: a real cut of the project font when it has one,
     * otherwise the browser synthesises it (`font-weight` / `font-style` on the nearest cut).
     */
    const variant = (style, bold, italic, sizeMm) => {
        const face = faceByFamily(fonts, style && style.family);
        if (!face) {
            const base = Math.min(700, Number(style && style.weight) || 400);
            const spec = { family: FALLBACK_FAMILY, weight: bold ? 700 : Math.min(500, base), sizeMm };
            if (italic) spec.italic = true;
            return spec;
        }
        const wanted = bold ? Math.max(700, face.weight + 300) : face.weight;
        const pool = facesOf(fonts, face.fontName);
        const slanted = pool.filter((f) => !isUpright(f));
        let cut = null;
        if (italic && slanted.length) {
            for (const f of slanted) if (!cut || Math.abs(f.weight - wanted) < Math.abs(cut.weight - wanted)) cut = f;
        } else {
            cut = nearestFace(fonts, face.fontName, wanted) || face;
        }
        const spec = { family: `"${cut.family}"`, weight: 400, sizeMm };
        if (bold && cut.weight < 600) spec.weight = 700;
        if (italic && isUpright(cut)) spec.italic = true;
        return spec;
    };

    const headColor = (styles.head.text && styles.head.text.color) || COLORS.ink;
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
        content: doc.content,
        assets: env.assets || {},
        measurer: opts.measurer,
        placeholders: opts.placeholders !== 'none',
        headColor,
        /** Heading colour on paper: the Hlavička text colour unless it is white (made for a dark band). */
        paperHead: isWhite(headColor) ? COLORS.ink : headColor,
        ink: (styles.name && styles.name.color) || COLORS.ink,
        accent: (styles.ev && styles.ev.color) || COLORS.ink,
        sat: styles.cal.satColor || COLORS.sat,
        red: styles.cal.sunColor || COLORS.red,
        issues: [],
        X: (v) => v * ux,
        Y: (v) => (v <= DESIGN_BAND ? (v / DESIGN_BAND) * band : band + (v - DESIGN_BAND) * uy),
        /** A design-unit rectangle on the page. */
        box: (x, y, w, h) => ({ x: x * ux, y: c.Y(y), w: w * ux, h: c.Y(y + h) - c.Y(y) }),
        headFont: (weight, size, spacing) => font(styles.head.text, weight, size * u, spacing ? spacing * u : 0),
        numFont: (weight, size) => font(styles.num, weight, size * u),
        plainFont: (weight, size, spacing) => font(null, weight, size * u, spacing ? spacing * u : 0),
        bodyFont: (sizeMm) => font(styles.name, (styles.name && styles.name.weight) || 400, sizeMm),
        body: (sizeMm, bold = false, italic = false) => variant(styles.name, bold, italic, sizeMm),
        width: (text, f) => (opts.measurer ? opts.measurer.width(text, f) : String(text).length * f.sizeMm * 0.55),
        issue: (message) => {
            if (!c.issues.includes(message)) c.issues.push(message);
        },
    };
    return c;
}

/** `<text>` in mm; `font.italic` becomes `font-style`. */
function T(x, y, text, font, a = {}) {
    return textEl(x, y, text, font, { 'font-style': font.italic ? 'italic' : null, ...a });
}

/** Text at a design-unit point. `o`: `{ anchor, fill, field }`. */
function tx(c, x, y, text, font, o = {}) {
    return T(c.X(x), c.Y(y), text, font, { 'text-anchor': o.anchor && o.anchor !== 'start' ? o.anchor : null, fill: o.fill || COLORS.ink, 'fill-opacity': o.opacity || null, 'data-field': o.field || null });
}

/**
 * One line that must fit `maxMm`: the type shrinks down to 62 %, then the text is cut with an
 * ellipsis and the page reports it (`label` names the field in the warning).
 */
function fitLine(c, text, fontOf, size, maxMm, label) {
    let font = fontOf(size);
    if (!c.measurer || maxMm <= 0) return { text, font };
    let w = c.width(text, font);
    if (w <= maxMm) return { text, font };
    // letter spacing does not scale with the size, so the first estimate may be a little short
    for (let k = 0, at = size; k < 4 && at > size * 0.62; k++) {
        at = Math.max(size * 0.62, (at * maxMm * 0.995) / w);
        font = fontOf(at);
        w = c.width(text, font);
        if (w <= maxMm) return { text, font };
    }
    let cut = text;
    while (cut.length > 1 && c.width(`${cut}…`, font) > maxMm) cut = cut.slice(0, -1).trimEnd();
    if (label) c.issue(OVERFLOW.line(label));
    return { text: `${cut}…`, font };
}

const bar = (c, x, y, w, h, fill, opacity) => rect(c.box(x, y, w, h), { rx: Math.min(3 * c.u, (c.Y(y + h) - c.Y(y)) / 2), fill: fill || COLORS.ph, 'fill-opacity': opacity === undefined ? null : opacity });

/** Grey text-line placeholders; every fifth line is shorter, like the end of a paragraph. */
function lines(c, x, y, w, n, gap = 19) {
    let out = '';
    for (let k = 0; k < n; k++) out += bar(c, x, y + k * gap, k % 5 === 4 ? w * 0.62 : w, 8);
    return out;
}

/** The photo of a page in its box, or the grey slot while it is missing (nothing with `placeholders: 'none'`). */
function pagePhoto(c, box, photo, label, id, radius = 0) {
    const asset = photo ? c.assets[photo.asset] : null;
    if (asset) return `<g data-el="page-photo">${photoIn(box, asset, photo, radius, c.opts, `${c.opts.idPrefix}-${id}`)}</g>`;
    return c.placeholders ? `<g data-el="page-photo" data-empty="1">${slotPlaceholder(box, label, { radius })}</g>` : '';
}

/** The logo of the style in a square (mm), or a dashed box while the style has none. */
function logoIn(c, box) {
    const asset = c.styles.logo && c.styles.logo.asset ? c.assets[c.styles.logo.asset] : null;
    if (asset) return `<g data-el="page-logo">${imageIn(box, asset, 'contain', 100, c.opts)}</g>`;
    if (!c.placeholders) return '';
    const dash = `${num(5 * c.u)} ${num(4 * c.u)}`;
    const size = Math.min(12 * c.u, box.w * 0.22);
    return (
        '<g data-el="page-logo" data-empty="1">' +
        rect(box, { rx: 6 * c.u, fill: 'none', stroke: COLORS.mut, 'stroke-width': 0.3, 'stroke-dasharray': dash }) +
        textEl(box.x + box.w / 2, box.y + box.h / 2 + 0.36 * size, 'LOGO', { family: FALLBACK_FAMILY, weight: 700, sizeMm: size, letterSpacingMm: size * 0.12 }, { 'text-anchor': 'middle', fill: COLORS.mut }) +
        '</g>'
    );
}

/** A square of `size` design units centred on `cx`, its top at `top`. */
const square = (c, cx, top, size) => ({ x: c.X(cx) - (size * c.u) / 2, y: c.Y(top), w: size * c.u, h: size * c.u });

/**
 * Header band in the Hlavička style with the page heading. `o.right`: a second heading at
 * `o.right.x` (the notes column of the contacts page).
 */
function header(c, title, id, o = {}) {
    const band = { x: -c.B, y: -c.B, w: c.W + 2 * c.B, h: c.Y(HEADER_BOTTOM) + c.B };
    let out = '';
    if (!c.styles.head.bg) out += rect(band, { fill: COLORS.band });
    else out += fillLayers(band, c.styles.head.bg, c.assets[c.styles.head.bg.asset] || null, { ...c.opts, slotLabel: null }, `${c.opts.idPrefix}-${id}`);
    const fontOf = (size) => c.headFont(800, size, 0.5);
    const limit = o.right ? o.right.x - 24 : DESIGN_W - MARGIN;
    const main = fitLine(c, title, fontOf, 20, c.X(limit - MARGIN), 'Nadpis');
    out += T(c.X(MARGIN), c.Y(80), main.text, main.font, { fill: c.headColor, 'data-field': 'heading' });
    if (o.right) {
        const second = fitLine(c, o.right.text, fontOf, 20, c.X(DESIGN_W - MARGIN - o.right.x), 'Nadpis poznámek');
        out += T(c.X(o.right.x), c.Y(80), second.text, second.font, { fill: c.headColor, 'data-field': 'notes-heading' });
    }
    return out;
}

/** The heading of a page: what was typed, or the default in capitals. */
const headingOf = (typed, fallback) => one(typed) || String(fallback || '').toUpperCase();

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

/** The line under a year grid: a note on the left, an imprint on the right — each only when typed. */
function footLine(c, left, right) {
    const y = DESIGN_H - 18;
    const inner = DESIGN_W - 2 * MARGIN;
    let out = '';
    const a = one(left);
    const b = one(right);
    if (a) {
        const fit = fitLine(c, a, (size) => c.body(size * c.u), 10.5, c.X(b ? inner * 0.56 : inner), 'Poznámka pod mřížkou');
        out += T(c.X(MARGIN), c.Y(y), fit.text, fit.font, { fill: c.ink, 'data-field': 'note' });
    }
    if (b) {
        const fit = fitLine(c, b, (size) => c.body(size * c.u), 8.5, c.X(a ? inner * 0.4 : inner), 'Tiráž');
        out += T(c.X(DESIGN_W - MARGIN), c.Y(y), fit.text, fit.font, { 'text-anchor': 'end', fill: COLORS.mut, 'data-field': 'imprint' });
    }
    return out;
}

function sixByTwo(c, year) {
    let out = '';
    for (let m = 0; m < 12; m++) out += monthGrid(c, MARGIN + (m % 6) * 160, 130 + Math.floor(m / 6) * 168, year, m + 1, 20, 19, 9.5);
    return out;
}

// ---- rich text: wrapping, justification, columns ----------------------------------------

/**
 * A parsed line (runs) as unbreakable atoms: `[{ pieces: [{ text, bold, italic }], w }]`. A style
 * change inside a word keeps the word together; a one-letter preposition is glued to the next word.
 */
function atomsOf(c, runs, sizeMm) {
    const atoms = [];
    let current = null;
    for (const run of runs) {
        for (const part of run.text.split(/(\s+)/)) {
            if (part === '') continue;
            if (/^\s+$/.test(part)) {
                current = null;
                continue;
            }
            if (!current) {
                current = { pieces: [] };
                atoms.push(current);
            }
            const last = current.pieces[current.pieces.length - 1];
            if (last && last.bold === run.bold && last.italic === run.italic) last.text += part;
            else current.pieces.push({ text: part, bold: run.bold, italic: run.italic });
        }
        // a run that ends in the middle of a word continues it; one that ends with a space does not
        if (/\s$/.test(run.text)) current = null;
    }
    const glued = [];
    for (let i = 0; i < atoms.length; i++) {
        const atom = atoms[i];
        const next = atoms[i + 1];
        if (next && atom.pieces.length === 1 && ONE_LETTER.test(atom.pieces[0].text)) {
            const piece = atom.pieces[0];
            const first = next.pieces[0];
            if (first.bold === piece.bold && first.italic === piece.italic) first.text = `${piece.text}${NBSP}${first.text}`;
            else next.pieces.unshift({ ...piece, text: `${piece.text}${NBSP}` });
            continue;
        }
        glued.push(atom);
    }
    for (const atom of glued) atom.w = atom.pieces.reduce((sum, piece) => sum + c.width(piece.text, c.body(sizeMm, piece.bold, piece.italic)), 0);
    return glued;
}

/** Greedy wrap of atoms into lines of at most `maxW` mm: `[{ atoms, w }]`. */
function wrapAtoms(atoms, maxW, space) {
    const out = [];
    let line = null;
    for (const atom of atoms) {
        if (line && line.w + space + atom.w > maxW) {
            out.push(line);
            line = null;
        }
        if (!line) line = { atoms: [atom], w: atom.w };
        else {
            line.atoms.push(atom);
            line.w += space + atom.w;
        }
    }
    if (line) out.push(line);
    return out;
}

/** One typeset line as `<text>` with a `<tspan>` per style run; `spread` > 0 justifies it. */
function lineMarkup(c, line, x, y, sizeMm, o = {}) {
    const runs = [];
    line.atoms.forEach((atom, index) => {
        atom.pieces.forEach((piece, k) => {
            const text = piece.text + (k === atom.pieces.length - 1 && index < line.atoms.length - 1 ? ' ' : '');
            const last = runs[runs.length - 1];
            if (last && last.bold === piece.bold && last.italic === piece.italic) last.text += text;
            else runs.push({ text, bold: piece.bold, italic: piece.italic });
        });
    });
    if (o.suffix && runs.length) runs[runs.length - 1].text = `${runs[runs.length - 1].text.replace(/[\s.,;:]+$/, '')}${o.suffix}`;
    const base = c.body(sizeMm);
    const text = runs.map((run) => run.text).join('');
    const gaps = (text.match(/[  ]/g) || []).length;
    const spread = o.justifyTo && gaps > 0 ? (o.justifyTo - line.w) / gaps : 0;
    const a = {
        x,
        y,
        'font-family': base.family,
        'font-weight': base.weight,
        'font-size': base.sizeMm,
        fill: o.fill || c.ink,
        'text-anchor': o.anchor || null,
        'word-spacing': spread > 0.001 && spread <= sizeMm * JUSTIFY_MAX ? spread : null,
    };
    const plain = runs.length === 1 && !runs[0].bold && !runs[0].italic;
    const inner = plain
        ? esc(text)
        : runs
              .map((run) => {
                  if (!run.bold && !run.italic) return esc(run.text);
                  const f = c.body(sizeMm, run.bold, run.italic);
                  return `<tspan${attrs({ 'font-family': f.family === base.family ? null : f.family, 'font-weight': f.weight === base.weight ? null : f.weight, 'font-style': f.italic ? 'italic' : null })}>${esc(run.text)}</tspan>`;
              })
              .join('');
    return `<text${attrs(a)}>${inner}</text>`;
}

/**
 * Typesets the intro into `columns` columns of `area` at one size.
 * `parts`: `{ salutation, paragraphs (parsed), sign: { name, role } | null, signInFlow }`.
 * Columns are balanced: the lowest column height that holds everything is used.
 * @returns `{ fits, svg }`
 */
function flowRich(c, parts, sizeMm, columns, area, align) {
    const gap = columns > 1 ? Math.max(6 * c.u * 3, area.w * 0.04) : 0;
    const colW = (area.w - gap * (columns - 1)) / columns;
    const lineH = sizeMm * 1.5;
    const space = c.width(' ', c.body(sizeMm));
    const capacity = Math.max(1, (area.h - sizeMm) / lineH + 1);

    // rows in line units: lines (1), gaps (not at the top of a column), the signature block
    const rows = [];
    if (parts.salutation) {
        for (const line of wrapAtoms(atomsOf(c, [{ text: parts.salutation, bold: false, italic: false }], sizeMm), colW, space)) rows.push({ line, end: true });
        rows.push({ gap: 0.9 });
    }
    parts.paragraphs.forEach((paragraph, index) => {
        if (index > 0) rows.push({ gap: 0.55 });
        const first = rows.length;
        paragraph.lines.forEach((runs) => {
            const wrapped = wrapAtoms(atomsOf(c, runs, sizeMm), colW, space);
            wrapped.forEach((line, k) => rows.push({ line, end: k === wrapped.length - 1, para: index }));
        });
        // no widows or orphans: a paragraph of three or more lines never leaves one line alone
        if (rows.length - first >= 3) {
            rows[first].opens = true;
            rows[rows.length - 1].closes = true;
        }
    });
    const sign = parts.sign && parts.signInFlow ? { units: parts.sign.role ? 2 : 1 } : null;
    if (sign) {
        if (rows.length) rows.push({ gap: 0.9 });
        rows.push({ sign });
    }

    const place = (limit) => {
        const placed = [];
        let column = 0;
        let used = 0;
        for (const row of rows) {
            if (row.gap) {
                if (used > 0) used += row.gap;
                continue;
            }
            const units = row.sign ? row.sign.units : 1;
            // the first line of a paragraph takes the second one along
            const needed = row.opens ? 2 : units;
            if (used > 0 && used + needed > limit + 1e-6) {
                column += 1;
                used = 0;
                const previous = placed[placed.length - 1];
                if (row.closes && previous && previous.row.para === row.para && !previous.row.opens) {
                    // the last line of a paragraph takes the one before it along
                    previous.column = column;
                    previous.at = 0;
                    used = 1;
                }
            }
            placed.push({ row, column, at: used });
            used += units;
        }
        return { placed, columns: column + 1 };
    };

    const total = rows.reduce((sum, row) => sum + (row.gap || (row.sign ? row.sign.units : 1)), 0);
    let result = place(capacity);
    const fits = result.columns <= columns;
    if (fits && columns > 1) {
        // balance: the smallest limit that still needs no more columns
        for (let limit = Math.max(1, Math.ceil(total / columns) - 1); limit < capacity; limit += 0.5) {
            const attempt = place(limit);
            if (attempt.columns <= columns) {
                result = attempt;
                break;
            }
        }
    }

    const shown = result.placed.filter((p) => p.column < columns);
    let out = '';
    shown.forEach((p, index) => {
        const x = area.x + p.column * (colW + gap);
        const y = area.y + sizeMm * 0.8 + p.at * lineH;
        if (p.row.sign) {
            out += signature(c, parts.sign, x + colW, y, sizeMm, 'end');
            return;
        }
        const cutHere = !fits && index === shown.length - 1;
        out += lineMarkup(c, p.row.line, x, y, sizeMm, { justifyTo: align === 'justify' && !p.row.end && !cutHere ? colW : 0, suffix: cutHere ? '…' : '' });
    });
    return { fits, svg: out };
}

/** Name (bold) and role under each other; `anchor` end = right-aligned at `x`. */
function signature(c, sign, x, y, sizeMm, anchor) {
    const a = anchor === 'end' ? 'end' : null;
    let out = T(x, y, sign.name, c.body(sizeMm, true), { 'text-anchor': a, fill: c.ink, 'data-field': 'sign-name' });
    if (sign.role) out += T(x, y + sizeMm * 1.5, sign.role, c.body(sizeMm * 0.92, false, true), { 'text-anchor': a, fill: c.ink, 'fill-opacity': 0.78, 'data-field': 'sign-role' });
    return out;
}

// ---- groups of lines (contacts, waste side block) ---------------------------------------

/**
 * A group measured at one size for one column width: `{ id, chunks: [{ h, draw(x, y) }], h, solid }`.
 * A chunk is what must stay together — the heading with the first line, then each further line.
 * `solid` groups (the highlighted block) never split.
 */
function measureGroup(c, id, group, s, cw) {
    const lineH = s * 1.36;
    const regular = c.body(s);
    const bold = c.body(s, true);
    const highlight = group.highlight === true;
    const pad = highlight ? s * 0.95 : 0;
    const inner = cw - 2 * pad;
    const heading = one(group.heading);
    const rows = (Array.isArray(group.lines) ? group.lines : []).map((line) => ({ label: one(line && line.label), value: one(line && line.value), swatch: line && line.swatch ? line.swatch : null }));
    const gapLV = s * 0.9;
    const labelMax = rows.reduce((max, row) => (row.label ? Math.max(max, c.width(row.label, highlight ? bold : regular)) : max), 0);
    const labelW = Math.min(labelMax, inner * 0.5);

    const headFont = c.body(highlight ? s * 1.08 : s, true);
    const headLines = heading ? wrapText(heading, inner, headFont, c.measurer || { width: (t, f) => c.width(t, f) }) : [];
    const measurer = c.measurer || { width: (t, f) => c.width(t, f) };

    const parts = [];
    if (headLines.length) {
        parts.push({
            head: true,
            h: headLines.length * lineH * (highlight ? 1.08 : 1) + (highlight ? s * 0.35 : 0),
            draw: (x, y) => headLines.map((text, k) => T(x, y + s * 0.8 + k * lineH * (highlight ? 1.08 : 1), text, headFont, { fill: c.accent, 'data-field': 'group-heading' })).join(''),
        });
    }
    for (const row of rows) {
        if (!row.label && !row.value) {
            parts.push({ h: lineH * 0.5, draw: () => '' });
            continue;
        }
        if (row.swatch) {
            const side = s * 0.82;
            parts.push({
                h: lineH,
                draw: (x, y) => rect({ x, y: y + s * 0.8 - side * 0.86, w: side, h: side }, { rx: side * 0.18, fill: row.swatch }) + T(x + side + s * 0.55, y + s * 0.8, row.value, regular, { fill: c.ink }),
            });
            continue;
        }
        if (!row.label) {
            const wrapped = wrapText(row.value, inner, regular, measurer);
            parts.push({ h: wrapped.length * lineH, draw: (x, y) => wrapped.map((text, k) => T(x, y + s * 0.8 + k * lineH, text, regular, { fill: c.ink, 'data-field': 'line' })).join('') });
            continue;
        }
        const valueW = row.value ? c.width(row.value, regular) : 0;
        if (highlight && c.width(row.label, bold) + gapLV + valueW <= inner) {
            // the emergency-numbers look: label left, value right-aligned on the same line
            parts.push({
                h: lineH * 1.12,
                draw: (x, y) => T(x, y + s * 0.8, row.label, bold, { fill: c.accent, 'data-field': 'line-label' }) + (row.value ? T(x + inner, y + s * 0.8, row.value, regular, { 'text-anchor': 'end', fill: c.ink, 'data-field': 'line-value' }) : ''),
            });
            continue;
        }
        const labelFont = highlight ? bold : regular;
        const labelLines = wrapText(row.label, labelW, labelFont, measurer);
        const valueLines = row.value ? wrapText(row.value, Math.max(s * 4, inner - labelW - gapLV), regular, measurer) : [];
        parts.push({
            h: Math.max(labelLines.length, valueLines.length) * lineH,
            draw: (x, y) =>
                labelLines.map((text, k) => T(x, y + s * 0.8 + k * lineH, text, labelFont, { fill: highlight ? c.accent : c.ink, 'data-field': 'line-label' })).join('') +
                valueLines.map((text, k) => T(x + labelW + gapLV, y + s * 0.8 + k * lineH, text, regular, { fill: c.ink, 'data-field': 'line-value' })).join(''),
        });
    }

    // the heading never parts from its first line
    const chunks = [];
    parts.forEach((part, index) => {
        const previous = chunks[chunks.length - 1];
        if (previous && index === 1 && parts[0].head) {
            const first = previous;
            const offset = first.h;
            chunks[chunks.length - 1] = { h: first.h + part.h, draw: (x, y) => first.draw(x, y) + part.draw(x, y + offset) };
        } else chunks.push({ h: part.h, draw: part.draw });
    });
    const body = chunks.reduce((sum, chunk) => sum + chunk.h, 0);
    if (highlight) {
        const h = body + 2 * pad;
        const all = chunks;
        return {
            id,
            solid: true,
            h,
            legend: group.legend || null,
            chunks: [
                {
                    h,
                    draw: (x, y) => {
                        let out = rect({ x, y, w: cw, h }, { rx: 1.6 * c.u * 2, fill: c.accent, 'fill-opacity': 0.1, 'data-highlight': '1' });
                        let at = y + pad;
                        for (const chunk of all) {
                            out += chunk.draw(x + pad, at);
                            at += chunk.h;
                        }
                        return out;
                    },
                },
            ],
        };
    }
    return { id, solid: false, h: body, chunks, legend: group.legend || null };
}

/** Sequential fill of `cols` columns with at most `limit` mm each; `split` lets a group break between its chunks. */
function fillColumns(groups, cols, limit, gapMm, split) {
    const placed = [];
    let column = 0;
    let used = 0;
    const next = () => {
        column += 1;
        used = 0;
    };
    for (const group of groups) {
        if (!split || group.solid) {
            const lead = used > 0 ? gapMm : 0;
            if (used > 0 && used + lead + group.h > limit + 1e-6) next();
            const at = used > 0 ? used + gapMm : 0;
            placed.push({ group, chunks: group.chunks, column, at });
            used = at + group.h;
            continue;
        }
        let run = null;
        group.chunks.forEach((chunk, index) => {
            const lead = index === 0 && used > 0 ? gapMm : 0;
            if (used > 0 && used + lead + chunk.h > limit + 1e-6) {
                next();
                run = null;
            }
            if (!run) {
                const at = index === 0 && used > 0 ? used + gapMm : used;
                run = { group, chunks: [], column, at };
                placed.push(run);
                used = at;
            }
            run.chunks.push(chunk);
            used += chunk.h;
        });
    }
    // a single block higher than the limit still "fits" its column by the rule above — check it
    const heights = new Map();
    for (const p of placed) heights.set(p.column, Math.max(heights.get(p.column) || 0, p.at + p.chunks.reduce((sum, chunk) => sum + chunk.h, 0)));
    const overflow = [...heights.values()].some((h) => h > limit + 1e-6);
    return { placed, columns: column + 1, overflow };
}

/**
 * Whole groups spread evenly: every column takes groups up to its share of what is left (never
 * above `limit`), so nine equal groups in four columns come out 3 · 2 · 2 · 2 rather than
 * 3 · 3 · 3 · 0. `null` when the last column would overflow.
 */
function spreadColumns(groups, cols, limit, gapMm) {
    const placed = [];
    let index = 0;
    let rest = groups.reduce((sum, group) => sum + group.h + gapMm, 0);
    for (let column = 0; column < cols; column++) {
        const share = rest / (cols - column);
        let used = 0;
        while (index < groups.length) {
            const group = groups[index];
            const at = used > 0 ? used + gapMm : 0;
            const last = column === cols - 1;
            if (used > 0 && (at + group.h > limit + 1e-6 || (!last && at + group.h / 2 > share))) break;
            if (at + group.h > limit + 1e-6) return null;
            placed.push({ group, chunks: group.chunks, column, at });
            used = at + group.h;
            rest -= group.h + gapMm;
            index += 1;
        }
    }
    return index === groups.length ? { placed, columns: cols, overflow: false } : null;
}

/**
 * Flows groups into the balanced columns of `area` (mm): the largest size at which everything
 * fits, whole groups first, split between lines only when a group is higher than a column.
 * @returns `{ svg, fits, columnsUsed }`
 */
function flowGroups(c, groups, area, cols, o = {}) {
    if (!groups.length) return { svg: '', fits: true, columnsUsed: 0 };
    const gapX = cols > 1 ? Math.min(28 * c.ux, area.w * 0.05) : 0;
    const cw = (area.w - gapX * (cols - 1)) / cols;
    const sizes = o.sizes || GROUP_SIZES;
    // whole groups at a comfortable size first; a group is split between its lines only when
    // that saves the page from very small type (or when nothing else fits)
    const attempts = [...sizes.slice(0, -2).map((size) => [size, false]), ...sizes.map((size) => [size, true])];
    let best = null;
    let sizeMm = 0;
    let measured = [];
    let gapY = 0;
    for (const [size, split] of attempts) {
        sizeMm = size * c.u;
        measured = groups.map(([id, group]) => measureGroup(c, id, group, sizeMm, cw));
        gapY = sizeMm * 1.25;
        const full = fillColumns(measured, cols, area.h, gapY, split);
        if (full.columns > cols || full.overflow) continue;
        best = full;
        if (cols > 1) {
            // balance: the lowest limit that keeps everything in the columns
            let lo = 0;
            let hi = area.h;
            for (let i = 0; i < 18; i++) {
                const mid = (lo + hi) / 2;
                const attempt = fillColumns(measured, cols, mid, gapY, split);
                if (attempt.columns <= cols && !attempt.overflow) {
                    hi = mid;
                    best = attempt;
                } else lo = mid;
            }
            if (!split) best = spreadColumns(measured, cols, hi * 1.12 > area.h ? area.h : hi * 1.12, gapY) || best;
        }
        break;
    }
    if (!best) {
        // nothing fits even at the smallest size: draw what does and say so
        const rest = fillColumns(measured, cols, area.h, gapY, true);
        best = { placed: rest.placed.filter((p) => p.column < cols), columns: cols, cut: true };
    }
    let out = '';
    let used = 0;
    for (const p of best.placed) {
        const x = area.x + p.column * (cw + gapX);
        let y = area.y + p.at;
        let inner = '';
        for (const chunk of p.chunks) {
            if (y + chunk.h > area.y + area.h + 0.01 && best.cut) break;
            inner += chunk.draw(x, y);
            y += chunk.h;
        }
        used = Math.max(used, p.column + 1);
        out += `<g data-group="${esc(p.group.id)}"${p.group.legend ? ` data-legend="${esc(p.group.legend)}"` : ''} data-column="${p.column}">${inner}</g>`;
    }
    if (o.rules !== false) {
        // hairlines between the columns that are in use
        for (let k = 1; k < used; k++) {
            const x = area.x + k * (cw + gapX) - gapX / 2;
            out += `<line x1="${num(x)}" x2="${num(x)}" y1="${num(area.y)}" y2="${num(area.y + area.h)}" stroke="${COLORS.line}" stroke-width="0.2"/>`;
        }
    }
    return { svg: `<g data-el="groups" data-size="${num(sizeMm)}" data-columns="${cols}">${out}</g>`, fits: !best.cut, columnsUsed: used };
}

/** Grey groups of bars: what an unfilled contacts column looks like in the editor. */
function groupBars(c, area, cols) {
    const gapX = cols > 1 ? Math.min(28 * c.ux, area.w * 0.05) : 0;
    const cw = (area.w - gapX * (cols - 1)) / cols;
    const step = 9 * c.uy;
    let out = '';
    for (let col = 0; col < cols; col++) {
        const x = area.x + col * (cw + gapX);
        for (let g = 0, y = area.y; y + 5 * step < area.y + area.h; g++, y += 6.4 * step) {
            out += rect({ x, y, w: cw * (0.42 + ((g + col) % 3) * 0.08), h: 3.2 * c.uy }, { rx: 1.2 * c.u, fill: COLORS.ph2 });
            for (let k = 1; k <= 3; k++) out += rect({ x, y: y + k * 1.35 * step, w: cw * (k === 3 ? 0.55 : 0.86), h: 2.6 * c.uy }, { rx: 1.2 * c.u, fill: COLORS.ph });
        }
    }
    return `<g data-el="groups" data-empty="1">${out}</g>`;
}

/** Ruled lines for handwriting between `top` and `bottom` (design units). */
function ruled(c, x, w, top, bottom, pitch = 31) {
    let out = '<g data-el="ruled">';
    for (let y = top + pitch; y <= bottom + 0.01; y += pitch) {
        out += `<line x1="${num(c.X(x))}" x2="${num(c.X(x + w))}" y1="${num(c.Y(y))}" y2="${num(c.Y(y))}" stroke="${COLORS.mut}" stroke-opacity="0.55" stroke-width="0.2"/>`;
    }
    return `${out}</g>`;
}

// ---- generated kinds --------------------------------------------------------------------

/** Where the photo of a page sits (mm), `null` when the layout has none. Shared with the crop control. */
function photoBoxOf(c, page, layout) {
    const full = { x: -c.B, y: -c.B, w: c.W + 2 * c.B, h: c.H + 2 * c.B };
    if (page.kind === 'cover') {
        if (layout === 2) return null;
        return layout === 1 ? c.box(MARGIN, 58, DESIGN_W - 2 * MARGIN, 262) : full;
    }
    const kind = page.special && page.special.item ? page.special.item.kind : '';
    if (kind === 'intro') {
        if (layout === 0) return { x: -c.B, y: c.Y(DESIGN_BAND), w: c.X(430) + c.B, h: c.H - c.Y(DESIGN_BAND) + c.B };
        if (layout === 2) return c.box(MARGIN, 130, 170, 214);
        return null;
    }
    if (kind === 'contacts' && layout === 3) {
        const top = c.Y(HEADER_BOTTOM);
        return { x: c.X(600), y: top, w: c.W - c.X(600) + c.B, h: c.H - top + c.B };
    }
    return null;
}

function intro(c, page, layout) {
    const item = pageContentOf(c.content, 'sp:intro');
    const setup = pageSetupOf(c.content, 'sp:intro');
    const heading = headingOf(item.heading, page.title);
    const paragraphs = parseRichText(item.body);
    const salutation = one(item.salutation);
    const sign = one(item.signName) ? { name: one(item.signName), role: one(item.signRole) } : null;
    const empty = !paragraphs.length && !salutation;
    const photoBox = photoBoxOf(c, page, layout);
    let out = '';

    // [columns, sizes, area in design units]
    let variants;
    if (layout === 0) {
        const fit = fitLine(c, heading, (size) => c.headFont(800, size, 0.5), 22, c.X(498), 'Nadpis');
        out += pagePhoto(c, photoBox, item.photo, 'FOTOGRAFIE', 'intro');
        out += T(c.X(470), c.Y(100), fit.text, fit.font, { fill: c.paperHead, 'data-field': 'heading' });
        variants = [
            [1, INTRO_SIZES, [470, 126, 498]],
            [2, INTRO_SIZES.slice(-3), [470, 126, 498]],
        ];
    } else if (layout === 1) {
        out += header(c, heading, 'intro');
        variants = [
            [1, INTRO_SIZES.slice(0, 3), [170, 132, 660]],
            [2, INTRO_SIZES, [60, 132, 880]],
            [3, INTRO_SIZES.slice(-2), [MARGIN, 132, DESIGN_W - 2 * MARGIN]],
        ];
    } else {
        out += header(c, heading, 'intro');
        out += pagePhoto(c, photoBox, item.photo, 'PORTRÉT', 'intro', 2 * c.u);
        if (sign) out += signature(c, sign, c.X(MARGIN), c.Y(370), 10.5 * c.u, 'start');
        variants = [
            [2, INTRO_SIZES, [236, 132, 732]],
            [3, INTRO_SIZES.slice(-3), [236, 132, 732]],
        ];
    }

    if (empty) {
        if (c.placeholders) {
            if (layout === 0) out += lines(c, 470, 130, 498, 12);
            else if (layout === 1) out += lines(c, 170, 140, 660, 11);
            else out += lines(c, 236, 136, 350, 13) + lines(c, 618, 136, 350, 11);
        }
        if (sign && layout !== 2) out += signature(c, sign, c.X(layout === 0 ? 968 : 830), c.Y(400), 10.5 * c.u, 'end');
        return out;
    }
    if (!c.measurer) return out;

    const parts = { salutation, paragraphs, sign, signInFlow: layout !== 2 };
    let flow = null;
    search: for (const [columns, sizes, [x, y, w]] of variants) {
        const area = { x: c.X(x), y: c.Y(y), w: c.X(w), h: c.Y(TEXT_BOTTOM) - c.Y(y) };
        for (const size of sizes) {
            flow = flowRich(c, parts, size * c.u, columns, area, setup.align);
            flow.size = size * c.u;
            flow.columns = columns;
            if (flow.fits) break search;
        }
    }
    if (!flow.fits) c.issue(OVERFLOW.intro);
    return `${out}<g data-field="body" data-size="${num(flow.size)}" data-columns="${flow.columns}"${flow.fits ? '' : ' data-overflow="1"'}>${flow.svg}</g>`;
}

/** Clubs for the legend of the contacts page: only while events are on, at most eight. */
function legendClubs(doc) {
    if (!doc.design.features.events) return [];
    return sorted(doc.content.clubs || {}, 'pos')
        .map(([, club]) => club)
        .filter((club) => String(club.code || '').trim() !== '')
        .slice(0, 8);
}

/** The waste kinds that are switched on, for a legend. */
function legendWaste(c) {
    if (!c.doc.design.features.waste) return [];
    const dayContext = c.opts.dayContext || createDayContext(c.doc);
    return dayContext.wasteKinds.filter(([, kind]) => kind.on).map(([id, kind]) => ({ swatch: wasteKindColor(c.styles, id, kind), label: '', value: one(kind.name) || 'Odpad' }));
}

function contacts(c, page, layout) {
    const item = pageContentOf(c.content, 'sp:contacts');
    const setup = pageSetupOf(c.content, 'sp:contacts');
    const photoBox = photoBoxOf(c, page, layout);
    const right = photoBox ? 568 : DESIGN_W - MARGIN;
    const cols = layout === 1 ? 3 : layout === 2 ? 4 : 2;
    const inner = right - MARGIN;
    // the notes take the right part of the page, the way the client's calendars have them
    const notesW = setup.notes ? Math.round(inner * (cols >= 4 ? 0.24 : 0.3)) : 0;
    const notesX = right - notesW;
    const groupsRight = setup.notes ? notesX - 30 : right;

    let out = header(c, headingOf(item.heading, page.title), 'contacts', setup.notes ? { right: { x: notesX, text: headingOf(item.notesHeading, 'Poznámky') } } : {});
    if (photoBox) out += pagePhoto(c, photoBox, item.photo, 'FOTOGRAFIE', 'contacts');
    if (setup.notes) out += ruled(c, notesX, notesW, 108, TEXT_BOTTOM - 6);

    const groups = pageGroupsOf(c.content, 'sp:contacts');
    const user = groups.length > 0;
    const all = [...groups];
    const clubs = setup.clubLegend ? legendClubs(c.doc) : [];
    if (clubs.length) all.push(['legend-clubs', { heading: 'Vysvětlivky', lines: clubs.map((club) => ({ label: one(club.code), value: one(club.name) })), highlight: false, legend: 'clubs' }]);
    const waste = setup.wasteLegend ? legendWaste(c) : [];
    if (waste.length) all.push(['legend-waste', { heading: 'Svoz odpadů', lines: waste, highlight: false, legend: 'waste' }]);

    const area = { x: c.X(MARGIN), y: c.Y(124), w: c.X(groupsRight - MARGIN), h: c.Y(TEXT_BOTTOM) - c.Y(124) };
    if (!user && c.placeholders) {
        // an unfilled page: bars where the contacts go, the legends (real data) in the last column
        const gapX = Math.min(28 * c.ux, area.w * 0.05);
        const cw = (area.w - gapX * (cols - 1)) / cols;
        if (!all.length) return out + groupBars(c, area, cols);
        out += groupBars(c, { ...area, w: area.w - cw - gapX }, cols - 1);
        return out + flowGroups(c, all, { ...area, x: area.x + area.w - cw, w: cw }, 1, { rules: false }).svg;
    }
    if (!c.measurer) return out;
    const flow = flowGroups(c, all, area, cols);
    if (!flow.fits) c.issue(OVERFLOW.groups);
    return out + flow.svg;
}

function notesPage(c, page) {
    const item = pageContentOf(c.content, 'sp:notes');
    return header(c, headingOf(item.heading, page.title), 'notes') + ruled(c, MARGIN, DESIGN_W - 2 * MARGIN, 104, TEXT_BOTTOM);
}

function wastePlan(c, page, layout) {
    const { doc } = c;
    const year = doc.design.year;
    const item = pageContentOf(c.content, 'sp:waste');
    const dayContext = c.opts.dayContext || createDayContext(doc);
    const kinds = doc.design.features.waste ? dayContext.wasteKinds.filter(([, kind]) => kind.on) : [];
    const gridRight = layout === 1 ? 640 : DESIGN_W - MARGIN;
    const x0 = 92;
    const cw = (gridRight - x0) / 31;
    const ch = 21;
    const y0 = 132;
    let out = header(c, one(item.heading) || `${String(page.title || '').toUpperCase()} ${year}`, 'waste');
    out += '<g data-waste-grid="1">';
    for (let d = 0; d < 31; d++) out += tx(c, x0 + d * cw + cw / 2, y0 - 8, String(d + 1), c.plainFont(700, cw > 22 ? 9 : 7.5), { anchor: 'middle', fill: COLORS.mut });
    for (let m = 1; m <= 12; m++) {
        const y = y0 + (m - 1) * ch;
        const last = daysInMonth(year, m);
        out += tx(c, MARGIN, y + 14.5, capitalise(MONTHS[m - 1]), c.headFont(700, 10));
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

    // the note under the grid (the client's „Přistavujte prosím odpady před dnem svozu.“)
    const note = one(item.note);
    if (note) {
        const fit = fitLine(c, note, (size) => c.body(size * c.u, false, true), 10.5, c.X(gridRight - MARGIN), 'Poznámka pod mřížkou');
        out += T(c.X(MARGIN), c.Y(y0 + 12 * ch + 20), fit.text, fit.font, { fill: c.ink, 'data-field': 'note' });
    }

    // legend of the active kinds, flowing left to right
    const legendFont = c.headFont(400, 10.5);
    let lx = MARGIN;
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
        // free texts beside the grid: bulky waste days, the collection yard's hours…
        const groups = pageGroupsOf(c.content, 'sp:waste');
        const area = { x: c.X(672), y: c.Y(y0 - 18), w: c.X(DESIGN_W - MARGIN - 672), h: c.Y(TEXT_BOTTOM) - c.Y(y0 - 18) };
        if (!groups.length) {
            if (c.placeholders) out += groupBars(c, area, 1);
        } else if (c.measurer) {
            const flow = flowGroups(c, groups, area, 1, { rules: false });
            if (!flow.fits) c.issue(OVERFLOW.side);
            out += `<g data-el="side">${flow.svg}</g>`;
        }
    }
    return out;
}

function yearPage(c, page, item) {
    const year = c.doc.design.year + (item.nextYear ? 1 : 0);
    const layout = item.layout || 0;
    const content = pageContentOf(c.content, 'sp:year');
    const title = String(page.title || '').toUpperCase();
    const typed = one(content.heading);
    if (layout === 2) {
        let out = header(c, typed || `${title} ${year} / ${year + 1}`, 'year');
        [year, year + 1].forEach((y, half) => {
            out += tx(c, MARGIN + half * 484, 124, String(y), c.headFont(800, 13));
            for (let m = 0; m < 12; m++) out += monthGrid(c, MARGIN + half * 484 + (m % 6) * 78, 146 + Math.floor(m / 6) * 150, y, m + 1, 10.6, 15.5, 6.6);
        });
        return out + footLine(c, content.note, '');
    }
    let out = header(c, typed || `${title} ${year}`, 'year');
    if (layout === 1) {
        for (let m = 0; m < 12; m++) out += monthGrid(c, MARGIN + (m % 4) * 240, 122 + Math.floor(m / 4) * 112, year, m + 1, 26, 12.4, 8);
    } else {
        out += sixByTwo(c, year);
    }
    return out + footLine(c, content.note, '');
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
    const area = { x: c.X(MARGIN), y: top, w: c.W - 2 * c.X(MARGIN), h: c.H - top - 22 * c.uy };
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

// ---- cover and back ---------------------------------------------------------------------

const LOGO_SIZES = Object.freeze({ s: 56, m: 80, l: 110 });

function cover(c, page) {
    const { doc } = c;
    const setup = pageSetupOf(c.content, 'cover');
    const item = pageContentOf(c.content, 'cover');
    const layout = Math.min(3, Math.max(0, Number(setup.layout) || 0));
    const title = one(item.title) || `Kalendář ${doc.design.year}`;
    const subtitle = one(item.subtitle);
    const note = one(item.note);
    const size = LOGO_SIZES[setup.logoSize] || LOGO_SIZES.m;
    const photoBox = photoBoxOf(c, page, layout);
    const head = c.styles.head.bg;
    const titleFont = (s) => c.headFont(800, s, 0.4);
    const subFont = (s) => c.headFont(400, s);
    const noteFont = (s) => c.headFont(400, s);
    let out = `<g data-el="cover" data-layout="${layout}">`;
    let logo = null;

    if (photoBox) out += pagePhoto(c, photoBox, item.photo, 'TITULNÍ FOTOGRAFIE', 'cover', layout === 1 ? 2 * c.u : 0);

    if (layout === 0) {
        // the title band across the bottom of the photo
        const top = 362;
        const bandBox = { x: -c.B, y: c.Y(top), w: c.W + 2 * c.B, h: c.H - c.Y(top) + c.B };
        out += rect(bandBox, { fill: head && head.colorOn && head.color ? head.color : COLORS.paper, 'fill-opacity': 0.94, 'data-el': 'cover-band' });
        let rightEdge = DESIGN_W - MARGIN - 8;
        if (setup.logo === 'title') {
            const side = Math.min(size, 82);
            logo = square(c, rightEdge - side / 2, top + (DESIGN_H - top - side) / 2, side);
            rightEdge -= side + 22;
        }
        const left = MARGIN + 8;
        const noteFit = note ? fitLine(c, note, noteFont, 10.5, c.X((rightEdge - left) * 0.45), 'Doplňkový řádek') : null;
        const noteW = noteFit ? c.width(noteFit.text, noteFit.font) / c.ux : 0;
        const titleY = subtitle ? top + 50 : top + 66;
        const subY = top + 82;
        const noteY = subtitle ? subY : titleY;
        const fit = fitLine(c, title, titleFont, 34, c.X(rightEdge - left - (subtitle || !note ? 0 : noteW + 30)), 'Titulek');
        out += T(c.X(left), c.Y(titleY), fit.text, fit.font, { fill: c.headColor, 'data-field': 'title' });
        if (subtitle) {
            const sub = fitLine(c, subtitle, subFont, 14.5, c.X(rightEdge - left - (note ? noteW + 30 : 0)), 'Podtitul');
            out += T(c.X(left), c.Y(subY), sub.text, sub.font, { fill: c.headColor, 'data-field': 'subtitle' });
        }
        if (noteFit) out += T(c.X(rightEdge), c.Y(noteY), noteFit.text, noteFit.font, { 'text-anchor': 'end', fill: c.headColor, 'fill-opacity': 0.85, 'data-field': 'note' });
    } else if (layout === 1) {
        // the photo in a frame, the title under it
        let room = DESIGN_W - 2 * MARGIN;
        if (setup.logo === 'title') {
            const side = Math.min(size, 96);
            logo = square(c, MARGIN + side / 2, 334 + (TEXT_BOTTOM + 6 - 334 - side) / 2, side);
            room -= 2 * (side + 24);
        }
        const titleY = subtitle ? (note ? 368 : 376) : note ? 380 : 394;
        const fit = fitLine(c, title, titleFont, 30, c.X(room), 'Titulek');
        out += T(c.X(DESIGN_W / 2), c.Y(titleY), fit.text, fit.font, { 'text-anchor': 'middle', fill: c.paperHead, 'data-field': 'title' });
        if (subtitle) {
            const sub = fitLine(c, subtitle, subFont, 14, c.X(room), 'Podtitul');
            out += T(c.X(DESIGN_W / 2), c.Y(titleY + 30), sub.text, sub.font, { 'text-anchor': 'middle', fill: c.paperHead, 'data-field': 'subtitle' });
        }
        if (note) {
            const fitNote = fitLine(c, note, noteFont, 9.5, c.X(room), 'Doplňkový řádek');
            out += T(c.X(DESIGN_W / 2), c.Y(TEXT_BOTTOM - 4), fitNote.text, fitNote.font, { 'text-anchor': 'middle', fill: COLORS.mut, 'data-field': 'note' });
        }
    } else if (layout === 2) {
        // no photo: a typographic cover
        let titleY = 236;
        if (setup.logo === 'title') {
            logo = square(c, DESIGN_W / 2, 78, Math.max(size, 64));
            titleY = 78 + Math.max(size, 64) + 74;
        }
        const fit = fitLine(c, title, titleFont, 52, c.X(DESIGN_W - 2 * MARGIN - 60), 'Titulek');
        out += T(c.X(DESIGN_W / 2), c.Y(titleY), fit.text, fit.font, { 'text-anchor': 'middle', fill: c.paperHead, 'data-field': 'title' });
        out += rect({ x: c.X(DESIGN_W / 2) - 11 * c.u * 3, y: c.Y(titleY + 20), w: 66 * c.u, h: 2.6 * c.u }, { rx: 1.3 * c.u, fill: c.accent === COLORS.ink ? c.paperHead : c.accent, 'data-el': 'cover-rule' });
        if (subtitle) {
            const sub = fitLine(c, subtitle, subFont, 20, c.X(DESIGN_W - 2 * MARGIN - 60), 'Podtitul');
            out += T(c.X(DESIGN_W / 2), c.Y(titleY + 62), sub.text, sub.font, { 'text-anchor': 'middle', fill: c.paperHead, 'data-field': 'subtitle' });
        }
        if (note) {
            const fitNote = fitLine(c, note, noteFont, 10.5, c.X(DESIGN_W - 2 * MARGIN), 'Doplňkový řádek');
            out += T(c.X(DESIGN_W / 2), c.Y(TEXT_BOTTOM - 4), fitNote.text, fitNote.font, { 'text-anchor': 'middle', fill: COLORS.mut, 'data-field': 'note' });
        }
    } else if (setup.logo === 'title') {
        // a finished cover graphic carries its own title: the logo goes to the bottom corner
        logo = square(c, DESIGN_W - MARGIN - size / 2, DESIGN_H - MARGIN - size, size);
    }

    // the logo lies over the photo (the crest on the client's covers)
    if (setup.logo === 'tl') logo = square(c, MARGIN + 8 + size / 2, 58, size);
    else if (setup.logo === 'tc') logo = square(c, DESIGN_W / 2, 58, size);
    else if (setup.logo === 'tr') logo = square(c, DESIGN_W - MARGIN - 8 - size / 2, 58, size);
    if (logo) out += logoIn(c, logo);
    return `${out}</g>`;
}

function backContacts(c) {
    const setup = pageSetupOf(c.content, 'back');
    const item = pageContentOf(c.content, 'back');
    const name = one(item.name);
    const address = String(item.address || '')
        .split('\n')
        .map(one)
        .filter((line) => line !== '')
        .slice(0, 6);
    const reach = [one(item.phone), one(item.email), one(item.web)].filter((v) => v !== '');
    const imprint = one(item.imprint);
    const empty = !name && !address.length && !reach.length;
    const cx = DESIGN_W / 2;
    const maxW = c.X(DESIGN_W - 2 * MARGIN - 120);
    let out = '';

    if (imprint) {
        const fit = fitLine(c, imprint, (size) => c.body(size * c.u), 8.5, c.X(DESIGN_W - 2 * MARGIN), 'Tiráž');
        out += T(c.X(cx), c.Y(DESIGN_H - 18), fit.text, fit.font, { 'text-anchor': 'middle', fill: COLORS.mut, 'data-field': 'imprint' });
    }
    if (empty) {
        // unfilled: the wireframe of the block
        if (setup.logo) out += logoIn(c, square(c, cx, 150, 120));
        if (c.placeholders) out += bar(c, cx - 110, 300, 220, 14, COLORS.ph2) + bar(c, cx - 180, 334, 360, 10) + bar(c, cx - 120, 354, 240, 10);
        return out;
    }

    // a centred stack: logo · name · address · phone, e-mail, web
    const u = c.u;
    const logoSide = setup.logo && ((c.styles.logo && c.styles.logo.asset && c.assets[c.styles.logo.asset]) || c.placeholders) ? 104 * u : 0;
    const rows = [];
    if (name) {
        const fit = fitLine(c, name, (size) => c.headFont(700, size, 0.3), 19, maxW, 'Název');
        rows.push({ ...fit, field: 'name', fill: c.paperHead, lead: logoSide ? 26 * u : 0 });
    }
    address.forEach((line, index) => {
        const fit = fitLine(c, line, (size) => c.body(size * u), 11.5, maxW, 'Adresa');
        rows.push({ ...fit, field: 'address', fill: c.ink, lead: index === 0 ? (name ? 20 * u : logoSide ? 26 * u : 0) : 15.5 * u });
    });
    if (reach.length) {
        const font = c.body(11.5 * u, true);
        const joined = reach.join('  ·  ');
        const list = c.width(joined, font) <= maxW ? [joined] : reach;
        list.forEach((line, index) => {
            const fit = fitLine(c, line, (size) => c.body(size * u, true), 11.5, maxW, 'Kontakt');
            rows.push({ ...fit, field: 'reach', fill: c.ink, lead: index === 0 ? (rows.length ? 24 * u : logoSide ? 26 * u : 0) : 15.5 * u });
        });
    }
    const textH = rows.reduce((sum, row, index) => sum + row.lead + (index === 0 ? row.font.sizeMm * 0.74 : 0), 0);
    const total = logoSide + textH;
    const top = c.Y(DESIGN_BAND + 14);
    const bottom = c.Y(DESIGN_H - (imprint ? 44 : 26));
    if (total > bottom - top) c.issue(OVERFLOW.back);
    let y = Math.max(top, (top + bottom) / 2 - total / 2);
    if (logoSide) {
        out += logoIn(c, { x: c.X(cx) - logoSide / 2, y, w: logoSide, h: logoSide });
        y += logoSide;
    }
    rows.forEach((row, index) => {
        y += row.lead + (index === 0 ? row.font.sizeMm * 0.74 : 0);
        out += T(c.X(cx), y, row.text, row.font, { 'text-anchor': 'middle', fill: row.fill, 'data-field': row.field });
    });
    return out;
}

function draw(doc, page, opts) {
    const c = context(doc, opts);
    if (page.kind === 'cover') return { svg: cover(c, page), issues: c.issues };
    if (page.kind === 'back') {
        const variant = page.backVariant === 'contacts' ? 'contacts' : 'year';
        let out = `<g data-el="back" data-variant="${variant}">`;
        if (variant === 'contacts') {
            // Roční kalendárium is a page of its own, so the back page carries the client's contacts
            out += backContacts(c);
        } else {
            const item = pageContentOf(c.content, 'back');
            out += header(c, one(item.heading) || `PŘEHLED ROKU ${doc.design.year}`, 'back') + sixByTwo(c, doc.design.year) + footLine(c, item.footer, item.imprint);
        }
        return { svg: `${out}</g>`, issues: c.issues };
    }
    const item = page.special ? page.special.item : null;
    const kind = item ? item.kind : '';
    let out = `<g data-el="special" data-kind="${esc(kind)}"${item ? ` data-layout="${Number(item.layout) || 0}"` : ''}>`;
    if (!item) return { svg: `${out}</g>`, issues: c.issues };
    const layout = Number(item.layout) || 0;
    if (kind === 'image') out += imagePage(c, item);
    else if (kind === 'text') out += textPage(c, page, item);
    else if (kind === 'intro') out += intro(c, page, layout);
    else if (kind === 'contacts') out += contacts(c, page, layout);
    else if (kind === 'waste') out += wastePlan(c, page, layout);
    else if (kind === 'year') out += yearPage(c, page, item);
    else if (kind === 'notes') out += notesPage(c, page);
    return { svg: `${out}</g>`, issues: c.issues };
}

// ---- entry points -----------------------------------------------------------------------

export function renderCover(doc, page, opts) {
    return draw(doc, page, opts).svg;
}

export function renderBack(doc, page, opts) {
    return draw(doc, page, opts).svg;
}

export function renderSpecial(doc, page, opts) {
    return draw(doc, page, opts).svg;
}

/**
 * What does not fit on a cover / back / special page with its current content: Czech sentences,
 * empty when all is well. Runs the very layout of the renderer, so it can never disagree with it.
 */
export function specialPageIssues(doc, page, opts) {
    if (!page || page.kind === 'week') return [];
    return draw(doc, page, { ...opts, idPrefix: 'chk' }).issues;
}

/**
 * The frame the photo of a page is cropped into (mm; the part in the bleed included), `null` when
 * the page — in its current layout — shows no photo. For the crop control of the editing UI.
 */
export function specialPhotoBox(doc, page) {
    if (!page || page.kind === 'week' || page.kind === 'back') return null;
    const c = context(doc, { env: {} });
    const layout = page.kind === 'cover' ? pageSetupOf(doc.content, 'cover').layout : page.special && page.special.item ? Number(page.special.item.layout) || 0 : 0;
    return photoBoxOf(c, page, layout);
}

/** Layout names of the generated kinds (map §8.2), in the order of `specialPages[*].layout`. */
export const SPECIAL_LAYOUTS = Object.freeze({
    intro: Object.freeze(['Fotka + text', 'Jen text', 'Portrét + dva sloupce']),
    contacts: Object.freeze(['Dva sloupce', 'Tři sloupce', 'Čtyři sloupce', 'Dva sloupce + fotka']),
    year: Object.freeze(['6 × 2 měsíce', '4 × 3 měsíce', 'Dva roky vedle sebe']),
    waste: Object.freeze(['Roční mřížka', 'Mřížka + texty']),
});

/** Layout names of the cover, in the order of `pageSetup.cover.layout`. */
export const COVER_LAYOUTS = Object.freeze(['Fotka s pruhem', 'Fotka v rámečku', 'Bez fotky', 'Jen fotografie']);
