// The calendar adapter: one page of a weekly desk calendar → a WBoost calendar LAYOUT (boxes in mm,
// type in pt, colours, fills, rules, what is shown). Same runner, caps and image rule as the template
// adapter; the instruments work in PAGE MILLIMETRES (origin = the top-left trim corner) — the unit
// the calendar's own model takes — through the page box (where the page is inside the picture).
//
// A candidate is a short list of the calendar model's commands (preset → switches → one layout
// patch); the app runs them on the calendar's document in memory and draws one sample week page.
// Nothing is saved, no picture is ever cut out of the source. No model call happens here.
//
// A 14-day calendar (`context.calendar.type === 'fortnight'`, the one preset `twoSided`) has a SECOND
// frame for three elements — `cal2`, `month2`, `wk2`: the second week column, drawn mirrored. The
// build then measures one row ladder per page half, mirrors every second frame the model does not
// send from its first one, and gives the feedback per column.
import sharp from 'sharp';
import * as im from '../instruments/raster.mjs';
import { lineInk } from '../instruments/textink.mjs';
import { rankFaces, verifyFaces } from '../instruments/fonts.mjs';
import { runOcr, mergeLines } from '../instruments/ocr.mjs';
import { compositeMasked, maskShare } from './mask.mjs';
import { pickLinesToRank } from './template.mjs';
import { ToolUserError } from '../tools/server.mjs';

export const MM_PER_PT = 25.4 / 72;
export const ELEMENTS = ['photo', 'cap', 'cal', 'month', 'wk', 'head', 'logo', 'qr', 'bg'];
export const FEATURES = ['captions', 'weekNumbers', 'moon', 'timeChange', 'seasons', 'waste', 'events', 'shifts', 'specialPages', 'qr'];
const PRESETS = ['photoLeft', 'photoRight'];
/** Elements with one frame per week column on a 14-day page: first frame → second frame. */
export const SECOND_FRAMES = Object.freeze({ cal: 'cal2', month: 'month2', wk: 'wk2' });
const FIRST_FRAME = Object.freeze(Object.fromEntries(Object.entries(SECOND_FRAMES).map(([a, b]) => [b, a])));
/** The element a box key belongs to (`cal2` → `cal`). */
export const elementOf = (key) => FIRST_FRAME[key] ?? key;
/** The long edge the OCR reads a small page at (never above 3 ×). */
const OCR_LONG_EDGE_PX = 2000;
const r1 = (v) => Math.round(v * 10) / 10;
const r2 = (v) => Math.round(v * 100) / 100;
const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

/** Deep merge of a patch over a base (objects merge, everything else replaces; `null` is a value). */
export function mergeDeep(base, patch) {
    if (!isObject(base) || !isObject(patch)) return patch === undefined ? base : structuredClone(patch);
    const out = { ...base };
    for (const [k, v] of Object.entries(patch)) out[k] = isObject(v) && isObject(base[k]) ? mergeDeep(base[k], v) : structuredClone(v);
    return out;
}

// ------------------------------------------------------------------------------- measurements

/**
 * Where the page is inside the picture, when the picture shows a margin around it (a screenshot, an
 * export with a frame): the box of everything that is not the border colour — taken only when its
 * ratio is the page's and the whole picture's is not. → {x, y, w, h} px | null.
 */
export function detectPageBox(img, ratio, { tolerance = 0.03 } = {}) {
    const { width: W, height: H } = img;
    const border = im.borderColour(img);
    const off = (x, y) => { const i = (y * W + x) * 3; return Math.abs(img.data[i] - border[0]) + Math.abs(img.data[i + 1] - border[1]) + Math.abs(img.data[i + 2] - border[2]) > 14; };
    const rowShare = (y) => { let n = 0; for (let x = 0; x < W; x += 2) if (off(x, y)) n++; return n / Math.ceil(W / 2); };
    const colShare = (x) => { let n = 0; for (let y = 0; y < H; y += 2) if (off(x, y)) n++; return n / Math.ceil(H / 2); };
    let top = 0, bottom = H - 1, left = 0, right = W - 1;
    while (top < H / 3 && rowShare(top) < 0.5) top++;
    while (bottom > (2 * H) / 3 && rowShare(bottom) < 0.5) bottom--;
    while (left < W / 3 && colShare(left) < 0.5) left++;
    while (right > (2 * W) / 3 && colShare(right) < 0.5) right--;
    const box = { x: left, y: top, w: right - left + 1, h: bottom - top + 1 };
    const inset = Math.max(left, top, W - 1 - right, H - 1 - bottom);
    if (inset < Math.max(4, Math.min(W, H) * 0.01)) return null;
    if (top >= H / 3 || left >= W / 3 || bottom <= (2 * H) / 3 || right <= (2 * W) / 3) return null;
    const err = (w, h) => Math.abs(w / h / ratio - 1);
    return err(box.w, box.h) <= tolerance && err(W, H) > err(box.w, box.h) ? box : null;
}

/**
 * Thin horizontal rules of a raster: rows where a long run of pixels differs from the rows 3 px above
 * and below in the same direction. → [{y, thickness, x0, x1}] (px, x1 exclusive), top to bottom.
 */
export function detectRules(img, { minShare = 0.15, contrast = 9 } = {}) {
    const { width: W, height: H } = img;
    const g = im.gray(img);
    const hits = [];
    for (let y = 3; y < H - 3; y++) {
        let best = null, start = -1, gap = 0, last = -1;
        const close = () => { if (start >= 0 && (!best || last - start > best.x1 - best.x0)) best = { x0: start, x1: last + 1 }; start = -1; gap = 0; };
        for (let x = 0; x < W; x++) {
            const p = y * W + x, a = g[p] - g[p - 3 * W], b = g[p] - g[p + 3 * W];
            const hit = (a > contrast && b > contrast) || (a < -contrast && b < -contrast);
            if (hit) { if (start < 0) start = x; last = x; gap = 0; } else if (start >= 0 && ++gap > 3) close();
        }
        close();
        if (best && best.x1 - best.x0 >= minShare * W) hits.push({ y, ...best });
    }
    const rules = [];
    for (const h of hits) {
        const r = rules.at(-1);
        if (r && h.y - r.y1 <= 1 && Math.min(r.x1, h.x1) - Math.max(r.x0, h.x0) > 0.6 * Math.min(r.x1 - r.x0, h.x1 - h.x0)) { r.y1 = h.y; r.rows.push(h); } else rules.push({ y0: h.y, y1: h.y, rows: [h] });
    }
    return rules.map((r) => ({ y: (r.y0 + r.y1) / 2, thickness: r.y1 - r.y0 + 1, x0: Math.round(im.median(r.rows.map((h) => h.x0))), x1: Math.round(im.median(r.rows.map((h) => h.x1))) }));
}

/**
 * The row ladder: the longest set of rules with the same ends and one pitch — the day rows of a
 * Kalendárium. → {rules: [...], pitch, x0, x1, thickness} (px) | null (fewer than 4 found).
 */
export function rowLadder(rules, { height, minPitchShare = 0.04 } = {}) {
    let best = null;
    const same = (a, b) => Math.abs(a.x0 - b.x0) <= Math.max(4, 0.02 * (a.x1 - a.x0)) && Math.abs(a.x1 - b.x1) <= Math.max(4, 0.02 * (a.x1 - a.x0));
    for (let i = 0; i < rules.length; i++) for (let j = i + 1; j < rules.length; j++) {
        if (!same(rules[i], rules[j])) continue;
        const pitch = rules[j].y - rules[i].y;
        if (pitch < minPitchShare * height) continue;
        const set = [rules[i], rules[j]];
        for (;;) {
            const want = set.at(-1).y + pitch;
            const next = rules.find((r) => Math.abs(r.y - want) <= Math.max(2, 0.04 * pitch) && same(r, set[0]));
            if (!next) break;
            set.push(next);
        }
        if (!best || set.length > best.length) best = set;
    }
    if (!best || best.length < 4) return null;
    const pitch = (best.at(-1).y - best[0].y) / (best.length - 1);
    return { rules: best, pitch, x0: Math.round(im.median(best.map((r) => r.x0))), x1: Math.round(im.median(best.map((r) => r.x1))), thickness: im.median(best.map((r) => r.thickness)) };
}

/**
 * The row ladders of a 14-day page: one per page half (the week columns stand left and right of the
 * photo; two rules on the same row would otherwise hide each other — `detectRules` keeps the longest
 * run of a row). → [left | null, right | null], x in px of the whole page.
 */
export function columnLadders(img) {
    const { width: W, height: H } = img;
    const half = Math.floor(W / 2);
    return [{ x: 0, y: 0, w: half, h: H }, { x: half, y: 0, w: W - half, h: H }].map((box) => {
        const rules = detectRules(im.crop(img, box), { minShare: 0.25 }).map((r) => ({ ...r, x0: r.x0 + box.x, x1: r.x1 + box.x }));
        return rowLadder(rules, { height: H });
    });
}

/** A crop (PNG) with rulers in page millimetres; optional grid across the crop. `scale` = output px per source px. */
export async function withMmRulers(png, boxMm, pxPerMm, { grid = true } = {}) {
    const meta = await sharp(png).metadata();
    const m = 30, W = meta.width + m, H = meta.height + m;
    const span = Math.max(boxMm.w, boxMm.h);
    const step = span > 160 ? 10 : span > 60 ? 5 : span > 25 ? 2 : span > 8 ? 1 : 0.5;
    const major = (v) => step >= 10 || Math.abs(v / (step * 5) - Math.round(v / (step * 5))) < 1e-6;
    let marks = '';
    for (let v = Math.ceil(boxMm.x / step - 1e-9) * step; v <= boxMm.x + boxMm.w + 1e-9; v += step) {
        const p = m + (v - boxMm.x) * pxPerMm.x;
        marks += `<line x1="${p}" y1="${m - (major(v) ? 9 : 4)}" x2="${p}" y2="${grid ? H : m}" stroke="#ff00ff" stroke-opacity="${major(v) ? 0.5 : 0.22}" stroke-width="1"/>`;
        if (major(v)) marks += `<text x="${p + 2}" y="12" font-size="11" font-family="Helvetica, Arial, sans-serif" fill="#000">${esc(r1(v))}</text>`;
    }
    for (let v = Math.ceil(boxMm.y / step - 1e-9) * step; v <= boxMm.y + boxMm.h + 1e-9; v += step) {
        const p = m + (v - boxMm.y) * pxPerMm.y;
        marks += `<line x1="${m - (major(v) ? 9 : 4)}" y1="${p}" x2="${grid ? W : m}" y2="${p}" stroke="#ff00ff" stroke-opacity="${major(v) ? 0.5 : 0.22}" stroke-width="1"/>`;
        if (major(v)) marks += `<text x="1" y="${p - 2}" font-size="11" font-family="Helvetica, Arial, sans-serif" fill="#000">${esc(r1(v))}</text>`;
    }
    const overlay = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">${marks}</svg>`);
    return { png: await sharp({ create: { width: W, height: H, channels: 3, background: '#ffffff' } }).composite([{ input: png, left: m, top: m }, { input: overlay, left: 0, top: 0 }]).png().toBuffer(), step };
}

/** Does a fill paint its box opaquely? (colour at full opacity, a gradient without transparent stops, or a picture) */
const opaqueFill = (f) => isObject(f) && ((f.colorOn && (f.colorOpacity ?? 100) >= 99) || (f.gradient?.on && (f.gradient.stops ?? []).every((s) => (s.opacity ?? 100) >= 99)) || (f.imageOn && (f.imageOpacity ?? 100) >= 99));

/**
 * Which page pixels show a picture the USER supplies — the page photo, the logo, the QR code — and
 * are therefore not the scan's to match. In stack order: an opaque element drawn above the photo
 * clears its box again.
 */
export function pictureMask(layout, features, toPx, W, H) {
    const mask = new Uint8Array(W * H);
    const boxes = layout?.boxes ?? {}, styles = layout?.styles ?? {}, order = layout?.order ?? [];
    const fill = (b, v) => { if (!b) return; const p = toPx(b); for (let y = p.y; y < p.y + p.h; y++) mask.fill(v, y * W + p.x, y * W + p.x + p.w); };
    const shown = (el) => {
        if (!boxes[el] || boxes[el].hidden) return false;
        if (el === 'cap') return features?.captions !== false;
        if (el === 'wk') return features?.weekNumbers !== false;
        if (el === 'qr') return features?.qr === true;
        return true;
    };
    const opaque = (el) => (el === 'head' ? opaqueFill(styles.head?.bg) : el === 'cal' ? !!styles.cal?.bg?.color && (styles.cal.bg.opacity ?? 100) >= 99 : el === 'cap' ? opaqueFill(styles.cap?.bg) : el === 'month' ? opaqueFill(styles.month?.bg) : false);
    // bottom layer first: `order[0]` is the top layer
    for (const el of [...order].reverse()) {
        if (!shown(el)) continue;
        if (el === 'photo' || el === 'logo' || el === 'qr') fill(boxes[el], 1);
        else if (opaque(el)) { fill(boxes[el], 0); if (SECOND_FRAMES[el] && boxes[SECOND_FRAMES[el]]) fill(boxes[SECOND_FRAMES[el]], 0); }
    }
    return mask;
}

// ------------------------------------------------------------------- labels under a picture

/** The generated one-line labels (Měsíc, Číslo týdne) and the user's pictures that must not cover them. */
const LABEL_KEYS = ['month', 'month2', 'wk', 'wk2'];
const PICTURE_KEYS = ['logo', 'qr'];
/** The clear space kept between a label's frame and a picture (mm). */
export const LABEL_GAP_MM = 1.5;
/** The narrowest frame a label is cut down to: Měsíc ("PROSINEC 2026 / LEDEN 2027"), Číslo týdne ("53. týden"). */
const LABEL_MIN_MM = { month: 40, wk: 14 };
const isBox = (b) => isObject(b) && ['x', 'y', 'w', 'h'].every((k) => typeof b[k] === 'number' && Number.isFinite(b[k]));
const meets = (a, b, gap = 0) => a.x < b.x + b.w + gap && b.x < a.x + a.w + gap && a.y < b.y + b.h && b.y < a.y + a.h;
/** The alignment a frame's text really has: the second frame is drawn mirrored. */
const frameAlign = (align = 'start', key) => (key in FIRST_FRAME ? ({ start: 'end', end: 'start' }[align] ?? 'middle') : align);

/**
 * Which labels of a layout stand under a picture: `[{label, picture}]`. `boxes` are complete boxes (a
 * rendered layout, or a candidate laid over the default); a hidden element, a label whose switch is
 * off (`weekNumbers`) and a QR code that is not switched on are not on the page.
 */
export function labelOverlaps(boxes, features = {}, gap = 0) {
    const on = (key) => {
        const el = elementOf(key);
        if (!isBox(boxes?.[key]) || !isObject(boxes?.[el]) || boxes[el].hidden === true) return false;
        if (el === 'wk') return features?.weekNumbers !== false;
        if (el === 'qr') return features?.qr === true;
        return true;
    };
    const out = [];
    for (const label of LABEL_KEYS) for (const picture of PICTURE_KEYS) if (on(label) && on(picture) && meets(boxes[label], boxes[picture], gap)) out.push({ label, picture });
    return out;
}

/**
 * Moves a label's frame out from under a picture: the frame keeps its row and is cut to the part beside
 * the picture — the part its text grows AWAY from the picture in when it is wide enough (a frame of
 * Měsíc / Číslo týdne grows by itself, away from its anchor edge), else the other part. → the new box,
 * or null when neither part can hold the label.
 */
export function clearOfPicture(label, picture, align, minWidth, gap = LABEL_GAP_MM) {
    const left = { x: label.x, w: r1(picture.x - gap - label.x) };
    const right = { x: r1(picture.x + picture.w + gap), w: r1(label.x + label.w - (picture.x + picture.w + gap)) };
    // text anchored at the frame's right edge grows to the left: the part left of the picture is the safe one
    const order = align === 'end' ? [left, right] : align === 'start' ? [right, left] : (left.w >= right.w ? [left, right] : [right, left]);
    const part = order.find((p) => p.w >= minWidth);
    return part ? { x: part.x, y: label.y, w: part.w, h: label.h } : null;
}

// ------------------------------------------------------------------------------------- the build

export class CalendarBuild {
    /**
     * @param source   working copy as a raster
     * @param context  {fonts: [face strings], brandColours, calendar: the claim's calendar context}
     * @param app      {renderCommands(commands) → {rendered, issues, image, view, layout, features, preset, page}, faces(req)}
     */
    constructor({ source, sourcePath, context, app, caps, settings = {}, signal = null, onNote = () => {}, approximate = false }) {
        this.source = source; this.sourcePath = sourcePath; this.context = context; this.app = app; this.caps = caps; this.signal = signal; this.onNote = onNote; this.approximate = approximate;
        this.settings = { availableScore: 0.8, prepassLines: 6, faceSheets: 12, ocrThreads: 3, ...settings };
        this.calendar = context.calendar ?? {};
        this.mm = { w: Number(this.calendar.page?.width) || 310, h: Number(this.calendar.page?.height) || 145 };
        this.fortnight = this.calendar.type === 'fortnight';
        this.presets = Array.isArray(this.calendar.presets) && this.calendar.presets.length ? this.calendar.presets : PRESETS;
        this.pageBoxSet = null;         // 'detected' | 'model' | null (the whole picture)
        this.#setPage({ x: 0, y: 0, w: source.width, h: source.height });
        this.prepassResult = null;
        this.working = null;            // the last candidate sent: {preset, features, boxes, order, styles}
        this.candidates = [];           // rendered only: {n, commands, layout, features, preset, raster, mask, mae, textFit: null, feedback, bytes, mimeType}
        this.attempts = [];
        this.rankings = [];             // {text, box (page px), group, origin, line?, best, available, candidates}
        this.lines = [];
        this.ladders = [null, null];    // the row ladder per week column; a 7-day page has the first only
        this.sheetsUsed = 0;
        this.stats = { ocr: null, prepassMs: 0, faceSheets: 0 };
    }

    #setPage(box) {
        this.pageBox = im.clipBox(this.source, box);
        this.page = im.crop(this.source, this.pageBox);
        this.pageGray = im.gray(this.page);
        this.W = this.page.width; this.H = this.page.height;
        this.sx = this.W / this.mm.w; this.sy = this.H / this.mm.h;
    }

    // mm (page) ↔ px (page raster)
    toPx(b) { const x0 = im.clamp(Math.round(b.x * this.sx), 0, this.W - 1), y0 = im.clamp(Math.round(b.y * this.sy), 0, this.H - 1); const x1 = im.clamp(Math.round((b.x + b.w) * this.sx), x0 + 1, this.W), y1 = im.clamp(Math.round((b.y + b.h) * this.sy), y0 + 1, this.H); return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 }; }
    toMm(b) { return b ? { x: r1(b.x / this.sx), y: r1(b.y / this.sy), w: r1(b.w / this.sx), h: r1(b.h / this.sy) } : null; }
    arrMm(b) { const m = this.toMm(b); return m ? [m.x, m.y, m.w, m.h] : null; }
    /** A box of the page raster in pixels of the working copy (what the report carries). */
    toSourcePx(b) { return b ? [b.x + this.pageBox.x, b.y + this.pageBox.y, b.w, b.h] : null; }
    /** How far the source page's ratio is from the calendar's (0 = the same). */
    ratioError() { return Math.abs((this.W / this.H) / (this.mm.w / this.mm.h) - 1); }

    // ---------------------------------------------------------------- pre-pass

    async prepass() {
        const started = Date.now();
        this.onNote('read');
        const detected = detectPageBox(this.source, this.mm.w / this.mm.h);
        if (detected) { this.#setPage(detected); this.pageBoxSet = 'detected'; }
        // A small page (a 977 × 457 px export is common) is enlarged for the OCR only: at a 2000 px long
        // edge the recogniser reads more of the 7–9 pt lines (eval/RESULTS.md §9.5). Boxes come back in
        // source px; nothing else sees the enlarged copy.
        const ocrScale = Math.min(3, Math.max(1, OCR_LONG_EDGE_PX / Math.max(this.source.width, this.source.height)));
        const ocr = await runOcr(this.sourcePath, { threads: this.settings.ocrThreads, scale: +ocrScale.toFixed(3), signal: this.signal, onSpawn: this.settings.onOcrSpawn });
        this.stats.ocr = { scale: +ocrScale.toFixed(3), seconds: ocr.seconds, loadSeconds: ocr.loadSeconds, peakRssMb: ocr.peakRssMb, cpuSeconds: ocr.cpuSeconds, wallMs: ocr.wallMs, raw: ocr.lines.length };
        this.ocrSource = mergeLines(ocr.lines).map((l) => ({ ...l, ink: lineInk(this.source, l.box) }));
        this.onNote(this.ocrSource.length ? 'texts_found' : 'no_texts', this.ocrSource.length ? { n: this.ocrSource.length } : null, this.ocrSource.length ? { textBoxes: this.ocrSource.map((l) => l.ink?.box ?? l.box) } : null);
        this.#measurePage();
        this.onNote('rows_found', { n: this.rowsFound() });
        if (this.context.fonts.length) {
            this.onNote('fonts');
            const toRank = pickLinesToRank(this.ocrSource.filter((l) => this.#onPage(l.box)), this.settings.prepassLines);
            if (toRank.length) {
                const { results } = await this.#rank(toRank.map((l) => ({ text: l.text, ink: l.ink })));
                toRank.forEach((l, i) => { l.font = results[i]; this.rankings.push({ text: l.text, sourceBox: l.ink.box, ink: l.ink, group: null, origin: 'prepass', line: l.id, ...results[i] }); });
            }
        }
        this.stats.prepassMs = Date.now() - started;
        return this.#describe();
    }

    #onPage(b) { const cx = b.x + b.w / 2, cy = b.y + b.h / 2, p = this.pageBox; return cx >= p.x && cx < p.x + p.w && cy >= p.y && cy < p.y + p.h; }
    /** A source-px box → page px. */
    #local(b) { return b ? { x: b.x - this.pageBox.x, y: b.y - this.pageBox.y, w: b.w, h: b.h } : null; }

    /** Everything measured on the page raster: recomputed when the page box moves. */
    #measurePage() {
        this.lines = this.ocrSource.filter((l) => this.#onPage(l.box)).map((l) => ({ ...l, pageBox: this.#local(l.box), pageInk: l.ink ? this.#local(l.ink.box) : null }));
        this.ladders = this.#laddersOf(this.page);
        this.palette = im.dominantColours(this.page, 8);
    }

    /** The row ladder of the page — of its first week column. */
    get ladder() { return this.ladders[0]; }
    set ladder(ladder) { this.ladders = [ladder, this.ladders[1]]; }

    /** → [ladder of the first column, ladder of the second column | null] */
    #laddersOf(raster) {
        return this.fortnight ? columnLadders(raster) : [rowLadder(detectRules(raster), { height: this.H }), null];
    }

    /** How many day rows the reading found (both columns of a 14-day page). */
    rowsFound() { return this.ladders.reduce((n, l) => n + (l ? l.rules.length : 0), 0); }

    /** The mirror image of a box across the page's vertical centre line — where a second frame stands by default. */
    mirror(b) { return { x: r1(this.mm.w - b.x - b.w), y: b.y, w: b.w, h: b.h }; }

    #rulesMm(ladder, frame = 'cal') {
        if (!ladder) return null;
        const n = ladder.rules.length, pitch = ladder.pitch / this.sy;
        const row = ladder.rules[Math.floor(n / 2)];
        const colour = (() => { const y = Math.round(row.y), xs = []; for (let x = row.x0 + 4; x < row.x1 - 4; x += 3) { const i = (y * this.W + x) * 3; xs.push([this.page.data[i], this.page.data[i + 1], this.page.data[i + 2]]); } return xs.length ? im.hex([0, 1, 2].map((k) => im.median(xs.map((c) => c[k])))) : null; })();
        const out = { count: n, yMm: ladder.rules.map((r) => r1(r.y / this.sy)), pitchMm: r2(pitch), x0Mm: r1(ladder.x0 / this.sx), x1Mm: r1(ladder.x1 / this.sx), weightPt: r1(Math.max(0.25, ladder.thickness / this.sy / MM_PER_PT)), colour };
        // the Kalendárium frame these rules belong to: 7 equal rows, a rule UNDER each row, inset 5 mm from both frame edges
        if (frame !== 'cal') out.frame = frame;
        if (n >= 6 && n <= 7) out.calBox = { x: r1(out.x0Mm - 5), y: r1(out.yMm[0] - pitch), w: r1(out.x1Mm - out.x0Mm + 10), h: r1(7 * pitch + 2), assumes: n === 7 ? 'a rule under each of the 7 rows' : 'rules under rows 1–6, none under the last row' };
        return out;
    }

    #describe() {
        const c = this.calendar;
        const ptOfInk = (h) => r1(h / this.sy / 0.253 / 1); // capital / digit height in mm ÷ 0.253 ≈ type size in pt
        this.prepassResult = {
            unit: 'page mm — origin top-left trim corner, x right, y down; boxes are [x, y, w, h]',
            page: {
                widthMm: this.mm.w, heightMm: this.mm.h, bleedMm: c.bleedMm ?? 0,
                sourcePx: [this.source.width, this.source.height], pageBoxPx: [this.pageBox.x, this.pageBox.y, this.pageBox.w, this.pageBox.h],
                pageBox: this.pageBoxSet ?? 'whole picture', pxPerMm: [r2(this.sx), r2(this.sy)],
                ...(this.ratioError() > 0.02 ? { ratioNote: `the source page is ${r2(this.W / this.H)} : 1, the calendar ${r2(this.mm.w / this.mm.h)} : 1 — the picture is stretched onto the page; if the picture shows a margin or two pages, call set_page_box first` } : {}),
            },
            calendar: { type: c.type ?? 'week', year: c.year ?? null, samplePage: c.samplePage ?? null, features: c.features ?? {}, hasLayout: !!c.hasLayout, presets: this.presets },
            fonts: this.context.fonts,
            brandColours: this.context.brandColours ?? [],
            rules: this.#rulesMm(this.ladders[0]),
            ...(this.fortnight ? { rules2: this.#rulesMm(this.ladders[1], 'cal2') } : {}),
            palette: this.palette,
            ocrLines: this.lines.map((l) => ({
                id: l.id, text: l.text, conf: l.conf, box: this.arrMm(l.pageBox),
                ...(l.pageInk ? { ink: this.arrMm(l.pageInk), inkHeightMm: r2(l.pageInk.h / this.sy), sizeIfCapitalsPt: ptOfInk(l.pageInk.h), colour: l.ink.colourHex, backdrop: l.ink.bgHex } : { ink: null }),
                ...(l.font?.best ? { font: { face: l.font.best.face, sizePt: r1(l.font.best.size / this.sy / MM_PER_PT), score: l.font.best.score, available: l.font.available, next: l.font.candidates.slice(1, 3).map((x) => ({ face: x.face, score: x.score })) } } : {}),
            })),
            defaultLayout: c.defaultLayout ?? null,
            notes: [
                '"ink" is the measured tight box of the glyphs; sizeIfCapitalsPt = the type size if the line is capitals or digits (ink height ÷ 0.253 mm per pt) — lower-case lines read smaller than they are',
                'the texts of the page are sample data (that week\'s days) or generated by the calendar — match where they are, their size, face and colour, never their wording',
                ...(this.context.fonts.length ? ['font.sizePt is the size at which that face sets the line exactly as wide as the source; available=false: no project face draws these glyphs'] : ['the project has no font: leave every "family" null (the built-in face)']),
                ...(this.fortnight ? ['the calendar is a 14-day one: `rules` is the row ladder of the LEFT week column (its calBox → boxes.cal), `rules2` of the RIGHT one (its calBox → boxes.cal2); a second frame you do not send (cal2, month2, wk2) is the mirror image of its first frame'] : []),
                ...(this.pageBoxSet === 'detected' ? ['the picture has a margin: the page box was detected by the scanner — check it, and correct it with set_page_box if it is off'] : []),
            ],
        };
        return this.prepassResult;
    }

    async #rank(lines) {
        if (!this.context.fonts.length) throw new ToolUserError('the project has no font — leave every "family" null');
        const left = this.settings.faceSheets - this.sheetsUsed;
        if (left < 1) throw new ToolUserError('the font ranking budget of this scan is used — reuse the rankings you have');
        const r = await rankFaces({ lines, faces: this.context.fonts, availableScore: this.settings.availableScore, maxSheets: left, signal: this.signal, loadRaster: (data) => im.loadRgb(data), renderFaces: (req) => this.app.faces(req) });
        this.sheetsUsed += r.sheets; this.stats.faceSheets += r.sheets;
        return r;
    }

    /** Ranks more source lines after the run (the finish stage ranks text styles the run left unranked). → the rankings pushed */
    async rank(lines, origin = 'finish') {
        const { results } = await this.#rank(lines.map((l) => ({ text: l.text, ink: l.ink })));
        return lines.map((l, i) => { const r = { text: l.text, sourceBox: l.ink.box, ink: l.ink, group: l.group ?? null, origin, ...results[i] }; this.rankings.push(r); return r; });
    }

    /** The verification render of the finish stage: [{text, ink, face, size}] → [{score, …} | null] (instruments/fonts.mjs verifyFaces). */
    async verify(items) {
        const r = await verifyFaces({ items, signal: this.signal, maxRenders: this.settings.verifyRenders ?? 6, supersample: this.settings.verifySupersample ?? 3, loadRaster: (data) => im.loadRgb(data), renderFaces: (req) => this.app.faces(req) });
        this.stats.verifyRenders = (this.stats.verifyRenders ?? 0) + r.renders;
        return r.results;
    }

    // ---------------------------------------------------------------- measured feedback

    /** What differs between a rendered candidate (page raster) and the source page — numbers in mm. */
    feedback(raw, layout, features) {
        const { page, W, H } = this;
        const mask = pictureMask(layout, features, (b) => this.toPx(b), W, H);
        const render = compositeMasked(page, raw, mask);
        const renderGray = im.gray(render);
        const cells = [];
        const cw = Math.round(W / 8), ch = Math.round(H / 6);
        for (let cy = 0; cy < H; cy += ch) for (let cx = 0; cx < W; cx += cw) { const box = im.clipBox(page, { x: cx, y: cy, w: cw, h: ch }); cells.push({ box, mae: +im.mae(page, render, box, mask).toFixed(4) }); }
        const worstCells = cells.filter((c) => c.mae > 0.012).sort((p, q) => q.mae - p.mae).slice(0, 6).map((c) => ({ box: this.arrMm(c.box), mae: c.mae }));
        const boxes = layout?.boxes ?? {};
        const elements = [];
        // a second frame is shown with its element: the flag and the switch are those of the first frame
        const shown = (key) => { const el = elementOf(key); return boxes[key] && boxes[el] && !boxes[el].hidden && !(el === 'cap' && features?.captions === false) && !(el === 'wk' && features?.weekNumbers === false); };
        for (const key of this.fortnight ? ['head', 'cal', 'cal2', 'month', 'month2', 'wk', 'wk2', 'cap'] : ['head', 'cal', 'month', 'wk', 'cap']) {
            if (!shown(key)) continue;
            const el = elementOf(key);
            const pad = el === 'cal' || el === 'head' ? 0 : 1.5;
            const box = this.toPx({ x: boxes[key].x - pad, y: boxes[key].y - pad, w: boxes[key].w + 2 * pad, h: boxes[key].h + 2 * pad });
            const row = { element: key, mae: +im.mae(page, render, box, mask).toFixed(4) };
            if (el !== 'head' && row.mae > 0.004) {
                const sh = im.bestShift(this.pageGray, renderGray, W, H, box, Math.min(24, Math.round(4 * this.sy)));
                if (sh.errorAfter < sh.errorBefore * 0.7 && (sh.dx || sh.dy)) row.move = { dxMm: r1(sh.dx / this.sx), dyMm: r1(sh.dy / this.sy) };
            }
            // single generated lines: where their ink is and how tall — the WORDING differs from the source's, so only height and anchor compare
            if (el === 'month' || el === 'wk') {
                const s = im.ink(im.crop(page, box)), r = im.ink(im.crop(raw, box));
                if (s.box && r.box && s.count > 12 && r.count > 12) {
                    row.sourceInk = this.arrMm(im.offset(s.box, box)); row.renderInk = this.arrMm(im.offset(r.box, box));
                    row.inkHeightRatio = r2(s.box.h / r.box.h);
                    row.hint = 'inkHeightRatio = source ÷ render ink height: multiply the size by it when both lines are capitals / digits; widths are not comparable (other wording)';
                }
            }
            elements.push(row);
        }
        const renderLadders = this.#laddersOf(raw);
        const compare = (sourceLadder, renderLadder, frame) => {
            const source = this.#rulesMm(sourceLadder, frame), rendered = this.#rulesMm(renderLadder, frame);
            return source && rendered ? {
                source: { count: source.count, firstYMm: source.yMm[0], pitchMm: source.pitchMm, x0Mm: source.x0Mm, x1Mm: source.x1Mm, weightPt: source.weightPt, colour: source.colour },
                render: { count: rendered.count, firstYMm: rendered.yMm[0], pitchMm: rendered.pitchMm, x0Mm: rendered.x0Mm, x1Mm: rendered.x1Mm, weightPt: rendered.weightPt, colour: rendered.colour },
                delta: { firstYMm: r1(source.yMm[0] - rendered.yMm[0]), pitchMm: r2(source.pitchMm - rendered.pitchMm), x0Mm: r1(source.x0Mm - rendered.x0Mm), x1Mm: r1(source.x1Mm - rendered.x1Mm) },
                hint: `delta = source − render: ${frame}.y += firstY delta; ${frame}.h += 7 × pitch delta; ${frame}.x += x0 delta; ${frame}.w += x1 delta − x0 delta`,
            } : (source ? { source: { count: source.count }, render: null, hint: `the render shows no row rules where the source has them in ${frame} (styles.lines.on?)` } : null);
        };
        const rules = compare(this.ladders[0], renderLadders[0], 'cal');
        const rules2 = this.fortnight ? compare(this.ladders[1], renderLadders[1], 'cal2') : undefined;
        return { mae: +im.mae(page, render, null, mask).toFixed(4), pictureShare: +maskShare(mask).toFixed(3), worstCells, elements, rules, ...(this.fortnight ? { rules2 } : {}), mask, masked: render };
    }

    // ---------------------------------------------------------------- candidates

    #candidateOf(a) {
        const base = a.patch === true ? this.working : null;
        if (a.patch === true && !base) throw new ToolUserError('there is no candidate to patch yet — send the complete candidate first (without "patch")');
        // a 14-day calendar has the one preset: it need not be named
        const preset = a.preset ?? base?.preset ?? (this.presets.length === 1 ? this.presets[0] : undefined);
        if (!this.presets.includes(preset)) throw new ToolUserError(this.presets.length === 1 ? `"preset" must be ${this.presets[0]} — the only layout of this calendar` : `"preset" must be one of ${this.presets.join(', ')} — where the photo is`);
        const features = [...new Set([...(base?.features ?? []), ...(Array.isArray(a.features) ? a.features : [])])];
        for (const f of features) if (!FEATURES.includes(f)) throw new ToolUserError(`"${f}" is not a feature switch — switches: ${FEATURES.join(', ')}`);
        for (const key of ['boxes', 'styles']) if (a[key] !== undefined && a[key] !== null && !isObject(a[key])) throw new ToolUserError(`"${key}" must be an object`);
        if (a.order !== undefined && a.order !== null && !Array.isArray(a.order)) throw new ToolUserError('"order" must be a list of the eight element keys, top layer first');
        return {
            preset, features,
            boxes: mergeDeep(base?.boxes ?? {}, a.boxes ?? {}),
            order: a.order ?? base?.order ?? null,
            styles: mergeDeep(base?.styles ?? {}, a.styles ?? {}),
        };
    }

    /**
     * The boxes a 14-day candidate sends: the model's own, plus every second frame — the mirror image of
     * its first frame (the first frame's missing sides taken from the default layout), with whatever the
     * model said about the second frame itself laid over it. The element's `hidden` lives on the first frame.
     */
    #withSecondFrames(boxes) {
        if (!this.fortnight) return boxes;
        const defaults = this.calendar.defaultLayout?.layout?.boxes ?? {};
        const out = { ...boxes };
        for (const [first, second] of Object.entries(SECOND_FRAMES)) {
            const own = isObject(boxes[second]) ? Object.fromEntries(Object.entries(boxes[second]).filter(([k]) => k !== 'hidden')) : {};
            const full = { ...(isObject(defaults[first]) ? defaults[first] : {}), ...(isObject(boxes[first]) ? boxes[first] : {}) };
            if (!isObject(boxes[first]) || boxes[first].hidden === true || !['x', 'y', 'w', 'h'].every((k) => typeof full[k] === 'number')) { if (Object.keys(own).length) out[second] = own; else delete out[second]; continue; }
            out[second] = { ...this.mirror(full), ...own };
        }
        return out;
    }

    /**
     * A 14-day candidate's labels are kept clear of the pictures: a second frame is placed as the mirror
     * image of its first one, and a logo that stands in the top right corner of the source would then sit
     * ON the second week's month line (the proposal read "LEDE[logo]7"). A label frame that meets the
     * logo or the QR code is cut to the part beside the picture. → {boxes, adjusted: [{box, picture, to}]}
     */
    #clearLabels(boxes, c) {
        if (!this.fortnight) return { boxes, adjusted: [] };
        const defaults = this.calendar.defaultLayout?.layout ?? {};
        const styles = mergeDeep(defaults.styles ?? {}, c.styles ?? {});
        const features = { ...(this.calendar.features ?? {}), ...Object.fromEntries((c.features ?? []).map((f) => [f, true])) };
        const full = (key) => ({ ...(isObject(defaults.boxes?.[key]) ? defaults.boxes[key] : {}), ...(isObject(boxes[key]) ? boxes[key] : {}) });
        const out = { ...boxes }, adjusted = [];
        // every pass moves one frame away from one picture; a frame cut twice (logo and QR code) converges
        for (let pass = 0; pass < 4; pass++) {
            const all = Object.fromEntries([...LABEL_KEYS, ...PICTURE_KEYS].map((k) => [k, { ...full(k), ...(isObject(out[k]) ? out[k] : {}) }]));
            const hit = labelOverlaps(all, features, LABEL_GAP_MM).find((o) => !adjusted.some((x) => x.box === o.label && x.picture === o.picture));
            if (!hit) break;
            const el = elementOf(hit.label);
            const to = clearOfPicture(all[hit.label], all[hit.picture], frameAlign(styles[el]?.align, hit.label), LABEL_MIN_MM[el]);
            adjusted.push({ box: hit.label, picture: hit.picture, to: to ? [to.x, to.y, to.w, to.h] : null });
            if (to) out[hit.label] = { ...(isObject(out[hit.label]) ? out[hit.label] : {}), ...to };
        }
        return { boxes: out, adjusted };
    }

    /** The commands of a candidate and what the scanner moved in it. → {commands, adjusted} */
    planOf(c) {
        const { boxes, adjusted } = this.#clearLabels(this.#withSecondFrames(c.boxes), c);
        return { commands: CalendarBuild.commandsOf({ ...c, boxes }), adjusted };
    }

    commandsOf(c) { return this.planOf(c).commands; }

    static commandsOf(c) {
        const layout = { op: 'layout' };
        if (Object.keys(c.boxes).length) layout.boxes = c.boxes;
        if (c.order) layout.order = c.order;
        if (Object.keys(c.styles).length) layout.styles = c.styles;
        return [
            { op: 'preset', preset: c.preset },
            ...(c.features.length ? [{ op: 'configure', features: Object.fromEntries(c.features.map((f) => [f, true])) }] : []),
            ...(Object.keys(layout).length > 1 ? [layout] : []),
        ];
    }

    async #preview(a) {
        if (this.caps.candidatesLeft() < 1) throw new ToolUserError(`the candidate budget is used (${this.caps.limits.candidates} of ${this.caps.limits.candidates} rendered) — return the final report now; the scanner keeps the best candidate`);
        if (this.caps.rejectionsLeft() < 1) throw new ToolUserError('the validation budget is used — return the final report now');
        const candidate = this.#candidateOf(a);
        const { commands, adjusted } = this.planOf(candidate);
        this.onNote(this.candidates.length ? 'refine' : 'build');
        const res = await this.app.renderCommands(commands);
        const issues = (res.issues ?? []).map(({ severity, stage, code, message }) => ({ severity, stage, code, message }));
        if (!res.rendered || !res.image) {
            this.caps.takeRejection();
            this.attempts.push({ rendered: false, issues: issues.length });
            return { json: { rendered: false, issues, validationAttemptsLeft: this.caps.rejectionsLeft(), note: 'nothing changed: the previous candidate (if any) is still the one "patch": true builds on' }, isError: true };
        }
        this.working = candidate;
        this.caps.takeCandidate();
        const n = this.candidates.length + 1;
        const raster = await im.loadRgb(res.image.data, this.W, this.H);
        const fb = this.feedback(raster, res.layout, res.features);
        this.candidates.push({ n, commands, candidate, layout: res.layout, features: res.features, preset: res.preset ?? candidate.preset, raster, mask: fb.mask, masked: fb.masked, mae: fb.mae, textFit: null, pictureShare: fb.pictureShare, issues, bytes: res.image.data, mimeType: res.image.mimeType,
            feedback: { mae: fb.mae, pictureShare: fb.pictureShare, worstCells: fb.worstCells, elements: fb.elements, rules: fb.rules, ...(this.fortnight ? { rules2: fb.rules2 } : {}) } });
        this.attempts.push({ rendered: true, candidate: n });
        const boxes = Object.fromEntries(Object.entries(res.layout?.boxes ?? {}).map(([k, b]) => [k, b.hidden ? { hidden: true } : [b.x, b.y, b.w, b.h]]));
        // a label under a picture, in the layout AS RENDERED (frames of Měsíc / Číslo týdne grow to their text)
        const overlaps = labelOverlaps(res.layout?.boxes ?? {}, res.features ?? {});
        this.candidates.at(-1).overlaps = overlaps;
        const moved = adjusted.filter((x) => x.to);
        return {
            json: {
                candidate: n, rendered: true, mae: fb.mae, pictureShare: fb.pictureShare, history: this.candidates.map((c) => ({ candidate: c.n, mae: c.mae })),
                notes: issues.filter((i) => i.severity !== 'error').map((i) => i.message),
                ...(moved.length ? { adjusted: moved, adjustedNote: 'the scanner cut these label frames to the part beside the picture — the picture would have covered the label (a second frame is the mirror image of its first frame unless you send it). Send the frame yourself, clear of the picture, to place it otherwise.' } : {}),
                ...(overlaps.length ? { overlaps, overlapsNote: 'these labels stand UNDER a picture in this candidate (the logo / QR code covers the text) — the user would see a cut-off month or week label. Correct it in the next candidate: move or narrow the label frame (its text is anchored at the frame edge `align` names; mirrored in a second frame), or move the picture.' } : {}),
                boxes, rules: fb.rules, ...(this.fortnight ? { rules2: fb.rules2 } : {}), elements: fb.elements, worstCells: fb.worstCells,
                hint: 'the photo, the logo and the QR code are the user\'s pictures — their boxes are left out of every number. Corrections: calendar_preview with "patch": true and only what changes.',
            },
            images: [res.image.data],
        };
    }

    // ---------------------------------------------------------------- tools

    #box(a, { whole = false } = {}) {
        const n = (v, name, d) => { if (v === undefined && d !== undefined) return d; if (typeof v !== 'number' || !Number.isFinite(v)) throw new ToolUserError(`"${name}" must be a number (page mm)`); return v; };
        const x = n(a.x, 'x', whole ? 0 : undefined), y = n(a.y, 'y', whole ? 0 : undefined);
        const b = { x, y, w: n(a.w, 'w', whole ? this.mm.w - x : undefined), h: n(a.h, 'h', whole ? this.mm.h - y : undefined) };
        if (b.w <= 0 || b.h <= 0) throw new ToolUserError('w and h must be > 0 (page mm)');
        return this.toPx(b);
    }

    tools() {
        const self = this;
        return {
            async scan_source() {
                return { json: { ...self.prepassResult, note: 'The picture is the source. Coordinates of every tool are page mm.' }, images: [self.page] };
            },
            async scan_crop(a) {
                const b = self.#box(a, { whole: true });
                self.onNote('zooming');
                const scale = typeof a.zoom === 'number' ? im.clamp(a.zoom, 0.25, 8) : Math.max(0.5, Math.min(6, 1100 / Math.max(b.w, b.h)));
                const c = im.crop(self.page, b);
                const mm = self.toMm(b);
                const out = await withMmRulers(await im.toPng(c, { scale, kernel: scale >= 3 ? 'cubic' : 'lanczos3' }), mm, { x: self.sx * scale, y: self.sy * scale }, { grid: a.grid !== false });
                return { json: { region: [mm.x, mm.y, mm.w, mm.h], gridStepMm: out.step, pxPerMm: r2(self.sx * scale), pxPerPt: r2(self.sy * scale * MM_PER_PT), note: 'Ruler labels are page mm.' }, images: [out.png] };
            },
            async scan_measure(a) {
                const b = self.#box(a);
                self.onNote('measuring');
                const c = im.crop(self.page, b);
                const k = im.ink(c);
                const bands = k.bands.filter((d) => d.h >= 2).slice(0, 24).map((d) => ({ box: self.arrMm(im.offset(d, b)), heightMm: r2(d.h / self.sy), sizeIfCapitalsPt: r1(d.h / self.sy / 0.253) }));
                const rules = detectRules(c, { minShare: 0.3 }).slice(0, 12).map((r) => ({ yMm: r1((r.y + b.y) / self.sy), x0Mm: r1((r.x0 + b.x) / self.sx), x1Mm: r1((r.x1 + b.x) / self.sx), weightPt: r1(Math.max(0.25, r.thickness / self.sy / MM_PER_PT)) }));
                return { json: {
                    region: self.arrMm(b), background: k.background, colours: im.dominantColours(c, 6), edges: im.edgeMeans(c),
                    ink: self.arrMm(im.offset(k.box, b)), inkShare: +(k.count / (b.w * b.h)).toFixed(3), bands, rules,
                    note: 'boxes are page mm; sizeIfCapitalsPt = band height ÷ 0.253 (capitals / digits); edges left≠right or top≠bottom = a gradient',
                } };
            },
            async font_rank(a) {
                const line = String(a.text ?? '').replace(/\n/g, ' ').trim();
                if (!line) throw new ToolUserError('"text" is empty — give one line exactly as written in the picture');
                const pb = self.#box(a);
                // the ink instruments work on the working copy: page px → source px
                const k = lineInk(self.source, { x: pb.x + self.pageBox.x, y: pb.y + self.pageBox.y, w: pb.w, h: pb.h }, { growX: 0.3, growY: 0.15 });
                if (!k || k.n < 30) throw new ToolUserError('no text found in that region (one flat colour, or not a single line) — check the region');
                self.onNote('fonts');
                const { results } = await self.#rank([{ text: line, ink: k }]);
                const r = results[0];
                const group = typeof a.group === 'string' ? a.group.slice(0, 40) : null;
                self.rankings.push({ text: line, sourceBox: k.box, ink: k, group, origin: 'tool', ...r });
                const pt = (px) => r1(px / self.sy / MM_PER_PT);
                return { json: { text: line, ink: self.arrMm(self.#local(k.box)), colour: k.colourHex, backdrop: k.bgHex, available: r.available,
                    candidates: r.candidates.slice(0, 6).map((c) => ({ face: c.face, score: c.score, sizePt: pt(c.size), sizeByHeightPt: pt(c.sizeByHeight) })),
                    note: 'sizePt = the size at which the face sets THIS text as wide as the source — use it only for text the calendar prints with the same wording (day numbers); for generated lines (month, weekday, name day, week label) take the face from here and the size from the ink height. score compares glyph shapes (1 = identical); available=false: no project face draws these glyphs — use the top face as a substitute.' } };
            },
            async calendar_preview(a) { return self.#preview(a ?? {}); },
            async set_page_box(a) {
                if (self.candidates.length) throw new ToolUserError('the page box cannot move after a candidate was rendered');
                for (const k of ['x', 'y', 'w', 'h']) if (typeof a[k] !== 'number' || !Number.isFinite(a[k])) throw new ToolUserError(`"${k}" must be a number (pixels of the source picture)`);
                if (a.w < 100 || a.h < 50) throw new ToolUserError('the page box is too small');
                self.#setPage({ x: a.x, y: a.y, w: a.w, h: a.h });
                self.pageBoxSet = 'model';
                self.#measurePage();
                self.onNote('rows_found', { n: self.rowsFound() });
                self.#describe();
                const { ocrLines, rules, rules2, page, palette } = self.prepassResult;
                return { json: { ok: true, page, rules, ...(self.fortnight ? { rules2 } : {}), palette, ocrLines, note: 'every mm coordinate now refers to this page box; the measurements above replace those of the first message' } };
            },
            async compare_region(a) {
                const p = a.candidate ? self.candidates.find((x) => x.n === a.candidate) : self.candidates.at(-1);
                if (!p) throw new ToolUserError('no rendered candidate to compare — call calendar_preview first');
                const b = self.#box(a, { whole: true });
                const scale = Math.max(0.5, Math.min(5, 560 / Math.max(b.w, b.h * 1.6)));
                const s = im.crop(self.page, b), r = im.crop(p.masked, b);
                const kernel = scale >= 3 ? 'cubic' : 'lanczos3';
                const mm = self.toMm(b);
                const ruled = async (raster) => (await withMmRulers(await im.toPng(raster, { scale, kernel }), mm, { x: self.sx * scale, y: self.sy * scale }, { grid: a.grid !== false })).png;
                const png = await stack([
                    { label: 'SOURCE', png: await ruled(s) },
                    { label: `CANDIDATE ${p.n}`, png: await ruled(r) },
                    { label: 'DIFFERENCE (white = identical)', png: await im.toPng(im.diffImage(s, r), { scale, kernel: 'nearest' }) },
                ]);
                const sh = im.bestShift(self.pageGray, im.gray(p.masked), self.W, self.H, b, Math.min(32, Math.round(6 * self.sy)));
                const ks = im.ink(s), kr = im.ink(r);
                return { json: { candidate: p.n, region: [mm.x, mm.y, mm.w, mm.h], mae: +im.mae(self.page, p.masked, b, p.mask).toFixed(4),
                    shift: { dxMm: r1(sh.dx / self.sx), dyMm: r1(sh.dy / self.sy), errorBefore: sh.errorBefore, errorAfter: sh.errorAfter },
                    sourceInk: self.arrMm(im.offset(ks.box, b)), renderInk: self.arrMm(im.offset(kr.box, b)), sourceBackground: ks.background, renderBackground: kr.background,
                    note: 'shift = how far the render content must move to match the source (+x right, +y down, mm); trust it when errorAfter is clearly below errorBefore. Picture boxes (photo, logo, QR) show the source in both panels.' }, images: [png] };
            },
        };
    }
}

/** Panels one under the other with captions (same scale) — a calendar page is wide. */
async function stack(panels, gap = 10) {
    const metas = await Promise.all(panels.map((p) => sharp(p.png).metadata()));
    const cap = 18;
    const W = Math.max(...metas.map((m) => m.width));
    const H = metas.reduce((s, m) => s + m.height + cap, 0) + gap * (panels.length - 1);
    const comps = []; let y = 0;
    panels.forEach((p, i) => {
        comps.push({ input: Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${cap}"><text x="2" y="13" font-size="12" font-family="Helvetica, Arial, sans-serif" fill="#000">${esc(p.label)}</text></svg>`), left: 0, top: y });
        comps.push({ input: p.png, left: 0, top: y + cap });
        y += metas[i].height + cap + gap;
    });
    return sharp({ create: { width: W, height: H, channels: 3, background: '#ffffff' } }).composite(comps).png().toBuffer();
}

export const CALENDAR_APP_TOOLS = ['font_rank', 'calendar_preview'];
