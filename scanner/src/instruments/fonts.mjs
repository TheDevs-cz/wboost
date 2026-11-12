// Font ranking by rendering: the APP renders each text line in each project face (one or more "face
// sheets"), the scanner compares the glyph shapes with the source line and fits the size.
// Nothing here trusts a model: `available` comes from the measured score.
import sharp from 'sharp';
import { clipBox, ink as inkOf, crop } from './raster.mjs';

/** Soft IoU (Σmin / Σmax) of two coverage maps {w, h, a, sum}; anchored top and bottom, ±1 px search. */
export function softIou(a, b) {
    let best = 0;
    for (const anchor of a.h === b.h ? [0] : [0, a.h - b.h]) for (let dy = -1; dy <= 1; dy++) for (let dx = -1; dx <= 1; dx++) {
        const oy = anchor + dy;
        let inter = 0;
        const y0 = Math.max(0, oy), y1 = Math.min(a.h, b.h + oy), x0 = Math.max(0, dx), x1 = Math.min(a.w, b.w + dx);
        for (let y = y0; y < y1; y++) {
            const ra = y * a.w, rb = (y - oy) * b.w - dx;
            for (let x = x0; x < x1; x++) { const va = a.a[ra + x], vb = b.a[rb + x]; inter += va < vb ? va : vb; }
        }
        const v = inter / (a.sum + b.sum - inter || 1);
        if (v > best) best = v;
    }
    return best;
}

const glyphs = (t) => [...String(t).replace(/\s/g, '')].length;

async function resizeCoverage(cov, w, h, toW, toH) {
    if (w === toW && h === toH) return cov;
    const { data, info } = await sharp(Buffer.from(cov.buffer, cov.byteOffset, cov.length), { raw: { width: w, height: h, channels: 1 } })
        .resize(toW, toH, { fit: 'fill', kernel: 'cubic' }).raw().toBuffer({ resolveWithObject: true });
    if (info.channels === 1) return data;
    const out = Buffer.alloc(toW * toH);
    for (let i = 0; i < out.length; i++) out[i] = data[i * info.channels];
    return out;
}

/** Coverage (0..255, black glyphs on white) of one cell of a rendered sheet, trimmed to the ink. */
export function cellInk(sheet, cell) {
    const c = crop(sheet, clipBox(sheet, cell));
    const k = inkOf(c, { background: [255, 255, 255], threshold: 110 });
    if (!k.box) return null;
    const { x, y, w, h } = k.box;
    const a = new Uint8Array(w * h);
    let sum = 0;
    for (let yy = 0; yy < h; yy++) for (let xx = 0; xx < w; xx++) {
        const i = ((yy + y) * c.width + xx + x) * 3;
        const v = 255 - Math.round(0.299 * c.data[i] + 0.587 * c.data[i + 1] + 0.114 * c.data[i + 2]);
        a[yy * w + xx] = v; sum += v;
    }
    return { w, h, a, sum };
}

/**
 * The app renders ONE picture per `faces` call: a row 2 × size tall for every (face, line), as wide as the
 * longest line. At the sizes of a 300 dpi scan that picture would be tens of thousands of pixels tall and
 * wide (found on the first A3 scan through the app: a sheet above the raster loader's 40 Mpx limit failed
 * the build). So:
 *   - a RANKING sheet is rendered at ≤ 64 px (the app's own default): the ranking resamples the glyphs to
 *     the source line anyway, and the verification render is the one that uses the true size;
 *   - every sheet stays below SHEET_MAX_PIXELS and SHEET_MAX_HEIGHT by asking for fewer lines per call;
 *   - a VERIFICATION sheet (one face, a few lines, the fitted size) is rendered no wider than SHEET_MAX_WIDTH:
 *     a long line of very large type is set a little smaller and the rest is one resampling factor.
 */
export const RANK_MAX_SIZE = 64;
export const SHEET_MAX_HEIGHT = 16_000;
export const SHEET_MAX_PIXELS = 30_000_000;
export const SHEET_MAX_WIDTH = 6_000;
/** A generous estimate of a rendered line's width: left padding + glyphs (spaces included) × 0.7 em + one em. */
export const sheetWidth = (chars, size) => 24 + Math.ceil(chars * size * 0.7) + size;
/** How many rows — (face, line) pairs — one sheet may carry at a size, for lines of at most `chars` characters. */
export const sheetRows = (size, chars) => Math.max(1, Math.floor(Math.min(SHEET_MAX_HEIGHT / (2 * size), SHEET_MAX_PIXELS / (sheetWidth(chars, size) * 2 * size))));

/** Lines of similar size share one render (the app renders a call's lines at ONE size, ≤ `maxLines` lines). */
export function batchLines(lines, { maxLines = 8, minSize = 24, maxSize = RANK_MAX_SIZE } = {}) {
    const sized = lines.map((l, i) => ({ i, target: Math.max(minSize, Math.min(maxSize, Math.round((l.ink?.h ?? 40) / 0.72))) })).sort((a, b) => a.target - b.target);
    const batches = [];
    for (const it of sized) {
        const b = batches.at(-1);
        if (b && b.items.length < maxLines && it.target <= b.min * 1.8) b.items.push(it);
        else batches.push({ min: it.target, items: [it] });
    }
    return batches.map((b) => ({ size: b.items[b.items.length >> 1].target, indexes: b.items.map((it) => it.i) }));
}

/**
 * Ranks faces for each line. `lines`: [{text, ink}] with `ink` from lineInk().
 * `renderFaces({lines: [text], faces, size})` is the app's "faces" call: ONE picture of the lines in
 * each face → {raster | image: {data}, cells: [{face, line, x, y, width, height, fontSize}]}.
 * → {results: per line {candidates: [{face, score, size, aspectError, wrapped?}], best, runnerUp, available}, sheets}
 */
export async function rankFaces({ lines, faces, renderFaces, availableScore = 0.8, maxSheets = 8, signal = null, loadRaster = null }) {
    const usable = lines.map((l, i) => ({ ...l, index: i })).filter((l) => l.ink && l.ink.n >= 30 && String(l.text).trim());
    const out = lines.map(() => ({ candidates: [], best: null, runnerUp: null, available: false, note: 'no_ink' }));
    if (!usable.length || !faces.length) return { results: out, sheets: 0 };
    // a sheet is one picture: keep it inside the sheet bounds by asking for fewer lines per call
    const batches = batchLines(usable).flatMap((b) => {
        const chars = Math.max(...b.indexes.map((i) => String(usable[i].text).length));
        const lines = Math.max(1, Math.min(8, Math.floor(sheetRows(b.size, chars) / faces.length)));
        const out = [];
        for (let i = 0; i < b.indexes.length; i += lines) out.push({ size: b.size, indexes: b.indexes.slice(i, i + lines) });
        return out;
    });
    const per = usable.map(() => []);
    let sheets = 0, truncated = false;
    for (const batch of batches) {
        if (sheets >= maxSheets) { truncated = true; break; }
        if (signal?.aborted) throw Object.assign(new Error('aborted'), { code: 'aborted' });
        const sheet = await renderFaces({ lines: batch.indexes.map((i) => usable[i].text), faces, size: batch.size });
        sheets += sheet.sheets ?? 1;
        const raster = sheet.raster ?? await loadRaster(sheet.image.data);
        for (const c of sheet.cells) {
            const li = batch.indexes[c.line];
            const line = usable[li];
            if (!line) continue;
            const r = cellInk(raster, { x: c.x, y: c.y, w: c.width, h: c.height });
            if (!r) continue;
            const fontSize = c.fontSize ?? batch.size;
            const wrapped = r.h > fontSize * 1.45;
            const byW = line.ink.w / r.w, byH = line.ink.h / r.h;
            const k = glyphs(line.text) >= 4 ? byW : byH;
            let score = 0;
            if (!wrapped) {
                const toW = Math.max(1, Math.round(r.w * k)), toH = Math.max(1, Math.round(r.h * k));
                const a = await resizeCoverage(r.a, r.w, r.h, toW, toH);
                let sum = 0; for (let i = 0; i < a.length; i++) sum += a[i];
                score = softIou({ w: line.ink.w, h: line.ink.h, a: line.ink.a, sum: line.ink.sum }, { w: toW, h: toH, a, sum });
            }
            per[li].push({ face: c.face, score: +score.toFixed(3), size: Math.round(fontSize * k * 2) / 2, sizeByHeight: Math.round(fontSize * byH * 2) / 2, aspectError: +Math.abs(r.h * byW / line.ink.h - 1).toFixed(3), ...(wrapped ? { wrapped: true } : {}) });
        }
    }
    usable.forEach((line, i) => {
        const candidates = per[i].sort((p, q) => q.score - p.score);
        const best = candidates[0] ?? null;
        out[line.index] = {
            candidates, best, runnerUp: candidates[1] ?? null,
            available: !!best && best.score >= availableScore,
            ...(candidates.length < faces.length ? { note: truncated ? 'sheet_budget' : 'partial' } : {}),
        };
    });
    return { results: out, sheets, truncated };
}

/** Runs of columns that carry ink, split at gaps of ≥ `gap` empty columns: the words of a line. → [{x0, x1}] */
export function inkSegments(m, gap) {
    const col = new Uint32Array(m.w);
    for (let y = 0; y < m.h; y++) { const row = y * m.w; for (let x = 0; x < m.w; x++) col[x] += m.a[row + x]; }
    const floor = 24; // a column of faint anti-aliasing is not ink
    const out = [];
    let start = -1, empty = 0;
    for (let x = 0; x < m.w; x++) {
        if (col[x] > floor) { if (start < 0) start = x; empty = 0; } else if (start >= 0 && ++empty >= gap) { out.push({ x0: start, x1: x - empty + 1 }); start = -1; empty = 0; }
    }
    if (start >= 0) out.push({ x0: start, x1: m.w - empty });
    return out;
}

/**
 * Soft IoU of two lines of text compared WORD BY WORD: each word of the render is laid over the same word
 * of the source with its own small shift (± `reach` px), intersections and unions are summed over the line.
 *
 * Why not the whole line at once: two renderers that set the same face at the same size place the glyphs a
 * fraction of a pixel apart, and along a line that drift adds up — at the end of a 30-glyph line the right
 * face lies a pixel or two off its own source and the overlap of thin strokes collapses (measured: Arial 32 px,
 * 33 glyphs: 0.79 whole line). Inside one word the drift is below a pixel. Another face still fails: its
 * words are not as wide and its strokes are elsewhere.
 * When the two lines do not split into the same words, the whole lines are compared.
 */
export function wordIou(a, b, { reach = 2 } = {}) {
    const gap = Math.max(3, Math.round(0.2 * Math.min(a.h, b.h)));
    const sa = inkSegments(a, gap), sb = inkSegments(b, gap);
    if (sa.length < 2 || sa.length !== sb.length) return { score: softIou(a, b), words: 1, mode: 'line' };
    const cut = (m, s) => {
        const w = s.x1 - s.x0, out = new Uint8Array(w * m.h);
        let sum = 0;
        for (let y = 0; y < m.h; y++) for (let x = 0; x < w; x++) { const v = m.a[y * m.w + s.x0 + x]; out[y * w + x] = v; sum += v; }
        return { w, h: m.h, a: out, sum };
    };
    let inter = 0, total = 0;
    for (let i = 0; i < sa.length; i++) {
        const wa = cut(a, sa[i]), wb = cut(b, sb[i]);
        let best = 0;
        // the word's own shift: its left edges meet, ± reach; vertically the lines' tops or bottoms meet, ± 1
        for (const anchor of wa.h === wb.h ? [0] : [0, wa.h - wb.h]) for (let dy = -1; dy <= 1; dy++) for (let dx = -reach; dx <= reach; dx++) {
            const oy = anchor + dy;
            let sum = 0;
            const y0 = Math.max(0, oy), y1 = Math.min(wa.h, wb.h + oy), x0 = Math.max(0, dx), x1 = Math.min(wa.w, wb.w + dx);
            for (let y = y0; y < y1; y++) { const ra = y * wa.w, rb = (y - oy) * wb.w - dx; for (let x = x0; x < x1; x++) { const va = wa.a[ra + x], vb = wb.a[rb + x]; sum += va < vb ? va : vb; } }
            if (sum > best) best = sum;
        }
        inter += best; total += wa.sum + wb.sum;
    }
    return { score: inter / (total - inter || 1), words: sa.length, mode: 'words' };
}

/**
 * The verification render (the decision between `measured_match` and `nearest_available`).
 *
 * The ranking compares glyph shapes RESAMPLED from one sheet size to the size of the source line; a
 * resampled true face scores as low as a near twin at its best (0.72 … 0.83 overlap), so the ranking
 * alone cannot tell "this is the face" from "this is the nearest face". Here the app renders the text in
 * the chosen face AT THE FITTED SIZE — the pixels the template itself would produce — and the scanner
 * compares them with the source crop without resampling, word by word (wordIou): the same face reproduces
 * the source glyphs almost exactly, another face cannot.
 *
 * `items`: [{text, ink, face, size}] — `ink` from lineInk() of the source line, `size` the fitted size of
 * that face for that line. Items whose sizes lie within ±3 % share one render (≤ 8 lines); the app renders
 * whole pixels between 8 and 400. A render that comes out more than 1 % narrower or wider than the source
 * line is resampled by that factor (a size above 400 px, a half-pixel size); one that is more than 3 % off
 * was fitted wrongly and is rendered once more at the corrected size while the budget lasts.
 * → {results: [{score, size, scale, words, glyphs, wrapped} | null], renders}
 */
export async function verifyFaces({ items, renderFaces, loadRaster, signal = null, maxRenders = 6, minSize = 8, maxSize = 400 }) {
    const out = items.map(() => null);
    const clean = (t) => String(t).replace(/\s+/g, ' ').trim();
    // the size a sheet is rendered at: the fitted size, inside the app's range, and no wider than the sheet bound
    const sizeFor = (wanted, chars) => Math.max(minSize, Math.min(maxSize, Math.round(wanted), Math.floor((SHEET_MAX_WIDTH - 24) / (chars * 0.7 + 1))));
    let renders = 0;
    const round = async (list) => {
        const again = [];
        const usable = list.filter((it) => it.ink && it.ink.n >= 30 && clean(it.text) && it.face && it.size > 0).sort((a, b) => a.size - b.size);
        const batches = [];
        for (const it of usable) {
            const text = clean(it.text);
            const b = batches.at(-1);
            // would this item still fit the sheet of the current batch? (rows = lines × faces, at the batch's size and longest line)
            const fits = (bb) => {
                const lines = bb.lines.includes(text) ? bb.lines.length : bb.lines.length + 1, fcs = bb.faces.includes(it.face) ? bb.faces.length : bb.faces.length + 1;
                const chars = Math.max(text.length, ...bb.lines.map((l) => l.length));
                return lines <= 8 && fcs <= 6 && lines * fcs <= sheetRows(sizeFor(Math.max(it.size, bb.min * 1.03), chars), chars);
            };
            if (b && it.size <= b.min * 1.03 && fits(b)) b.items.push({ ...it, text });
            else batches.push({ min: it.size, items: [{ ...it, text }], lines: [], faces: [] });
            const cur = batches.at(-1);
            if (!cur.lines.includes(text)) cur.lines.push(text);
            if (!cur.faces.includes(it.face)) cur.faces.push(it.face);
        }
        for (const batch of batches) {
            if (renders >= maxRenders) break;
            if (signal?.aborted) throw Object.assign(new Error('aborted'), { code: 'aborted' });
            const wanted = batch.items[batch.items.length >> 1].size;
            const size = sizeFor(wanted, Math.max(...batch.lines.map((l) => l.length)));
            const sheet = await renderFaces({ lines: batch.lines, faces: batch.faces, size });
            renders += sheet.sheets ?? 1;
            const raster = sheet.raster ?? await loadRaster(sheet.image.data);
            for (const it of batch.items) {
                const cell = sheet.cells.find((c) => c.face === it.face && c.line === batch.lines.indexOf(it.text));
                if (!cell) continue;
                let r = cellInk(raster, { x: cell.x, y: cell.y, w: cell.width, h: cell.height });
                if (!r) continue;
                const fontSize = cell.fontSize ?? size;
                if (r.h > fontSize * 1.45) { out[it.index] = { score: 0, size: fontSize, scale: null, words: 0, wrapped: true }; continue; }
                // what is left between the rendered and the fitted size: one factor for both axes, from the line width
                const k = glyphs(it.text) >= 4 ? it.ink.w / r.w : it.ink.h / r.h;
                // (a sheet that was set smaller on purpose — the width bound — is not a wrong fit)
                if (Math.abs(k - 1) > 0.03 && !it.corrected && fontSize * k >= minSize && sizeFor(fontSize * k, it.text.length) > fontSize * 1.03) again.push({ ...it, size: fontSize * k, corrected: true });
                if (Math.abs(k - 1) > 0.01) {
                    const toW = Math.max(1, Math.round(r.w * k)), toH = Math.max(1, Math.round(r.h * k));
                    const a = await resizeCoverage(r.a, r.w, r.h, toW, toH);
                    let sum = 0; for (let i = 0; i < a.length; i++) sum += a[i];
                    r = { w: toW, h: toH, a, sum };
                }
                const v = wordIou({ w: it.ink.w, h: it.ink.h, a: it.ink.a, sum: it.ink.sum }, r);
                out[it.index] = { score: +v.score.toFixed(3), size: fontSize, scale: +k.toFixed(4), words: v.words, glyphs: glyphs(it.text) };
            }
        }
        return again;
    };
    const again = await round(items.map((it, index) => ({ ...it, index })));
    if (again.length) await round(again);
    return { results: out, renders };
}

/**
 * Does a verification score say "this IS the face"? A longer line is stronger evidence: a near twin of
 * the true face overlaps a few bold glyphs almost as well as the face itself (measured: up to 0.82 on
 * 2–5 glyphs, never above 0.78 on 12 or more), the true face stays above 0.82 at any length once the words
 * are aligned one by one. Thresholds: definition.json `fonts` (eval/RESULTS.md §2).
 */
export function verifiedMatch(v, { verifiedScore = 0.85, verifiedScoreLong = 0.8, verifiedLongGlyphs = 12 } = {}) {
    if (!v || v.wrapped) return false;
    return v.score >= ((v.glyphs ?? 0) >= verifiedLongGlyphs ? verifiedScoreLong : verifiedScore);
}
