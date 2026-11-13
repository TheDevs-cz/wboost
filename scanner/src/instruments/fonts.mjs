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
 * Tracked-out type. A line that is clearly WIDER than the face sets it at the size at which the face is as
 * tall as the source was set with extra space between its letters: the size is then the one read from the
 * height, and the rest of the width is letter spacing — in the DSL's unit, thousandths of an em after every
 * character. Reported only from 0.1 em — the size is read from the ink height, a pixel of which is 3 % of a
 * 30 px line and with it 0.03–0.05 em of apparent spacing: below 0.1 em it is the fit's own noise — and for
 * lines of ≥ 4 characters.
 * → {spaced: {size, letterSpacing}} | {}
 */
export function spacedFit(line, r, fontSize, byH) {
    const chars = [...String(line.text).trim()].length;
    const size = fontSize * byH;
    if (chars < 4 || !(size > 0)) return {};
    const perChar = (line.ink.w - r.w * byH) / (chars - 1);
    const letterSpacing = Math.round(perChar / size * 1000 / 5) * 5;
    return letterSpacing >= 100 && letterSpacing <= 1000 ? { spaced: { size: Math.round(size * 2) / 2, letterSpacing } } : {};
}

/**
 * Ranks faces for each line. `lines`: [{text, ink}] with `ink` from lineInk().
 * `renderFaces({lines: [text], faces, size})` is the app's "faces" call: ONE picture of the lines in
 * each face → {raster | image: {data}, cells: [{face, line, x, y, width, height, fontSize}]}.
 * → {results: per line {candidates: [{face, score, size, sizeByHeight, aspectError, spaced?, wrapped?}], best, runnerUp, available}, sheets}
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
            per[li].push({ face: c.face, score: +score.toFixed(3), size: Math.round(fontSize * k * 2) / 2, sizeByHeight: Math.round(fontSize * byH * 2) / 2, aspectError: +Math.abs(r.h * byW / line.ink.h - 1).toFixed(3), ...(wrapped ? {} : spacedFit(line, r, fontSize, byH)), ...(wrapped ? { wrapped: true } : {}) });
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

/** An n × coverage map box-averaged down to 1 ×; the 1 × grid starts `px`, `py` fine pixels before the map's origin. */
export function downsample(m, n, px = 0, py = 0) {
    if (n === 1) return m;
    const w = Math.ceil((m.w + px) / n), h = Math.ceil((m.h + py) / n);
    const acc = new Uint32Array(w * h);
    for (let y = 0; y < m.h; y++) { const row = Math.floor((y + py) / n) * w, src = y * m.w; for (let x = 0; x < m.w; x++) acc[row + Math.floor((x + px) / n)] += m.a[src + x]; }
    const a = new Uint8Array(w * h), d = n * n;
    let sum = 0;
    for (let i = 0; i < a.length; i++) { const v = Math.round(acc[i] / d); a[i] = v; sum += v; }
    return { w, h, a, sum };
}

const cutColumns = (m, s) => {
    const w = s.x1 - s.x0, out = new Uint8Array(w * m.h);
    let sum = 0;
    for (let y = 0; y < m.h; y++) for (let x = 0; x < w; x++) { const v = m.a[y * m.w + s.x0 + x]; out[y * w + x] = v; sum += v; }
    return { w, h: m.h, a: out, sum };
};

/**
 * wordIou with a render that is `n` × as large as the source line: every word of the render is laid over the
 * same word of the source at every SUB-PIXEL position — the fine render is box-averaged down to the source's
 * pixel grid at each of the n × n grid phases (the row phase is one for the line, the column phase is each
 * word's own), then shifted by whole pixels as wordIou does.
 *
 * Why: a 1 × render sits on the pixel grid wherever its renderer put it; the source's glyphs sit wherever
 * ITS renderer put them. A stem three pixels wide that lies half a pixel off its own copy overlaps it by
 * 0.71 — as little as another face does. That is what kept the true face of thin type (Arial regular at
 * 32–40 px) at 0.68–0.79 while a near twin reaches 0.76. At the right phase the true face reproduces the
 * source's anti-aliasing; another face has no phase at which its strokes are the source's.
 */
export function wordIouFine(a, b, n, { reach = 2 } = {}) {
    // the words: runs of ink split at gaps of a fifth of the line height. Two renderers do not leave the same gap
    // between words, so one threshold may split one line differently from the other: wider gaps are tried until
    // both lines fall into the same number of words (then the whole lines are compared)
    let sa = [], sb = [], words = false;
    for (const share of [0.2, 0.28, 0.4, 0.55]) {
        const gap = Math.max(3, Math.round(share * Math.min(a.h, b.h / n)));
        sa = inkSegments(a, gap); sb = inkSegments(b, gap * n);
        words = sa.length >= 2 && sa.length === sb.length;
        if (words || sa.length < 2 || sb.length < 2) break;
    }
    if (!words) { sa = [{ x0: 0, x1: a.w }]; sb = [{ x0: 0, x1: b.w }]; }
    const wa = sa.map((s) => cutColumns(a, s)), wbFine = sb.map((s) => cutColumns(b, s));
    let bestInter = 0, bestTotal = 1;
    for (let py = 0; py < n; py++) {
        let inter = 0, total = 0;
        for (let i = 0; i < wa.length; i++) {
            const A = wa[i];
            let best = 0, bestSum = 0;
            for (let px = 0; px < n; px++) {
                const B = downsample(wbFine[i], n, px, py);
                for (const anchor of A.h === B.h ? [0] : [0, A.h - B.h]) for (let dy = -1; dy <= 1; dy++) for (let dx = -reach; dx <= reach; dx++) {
                    const oy = anchor + dy;
                    let sum = 0;
                    const y0 = Math.max(0, oy), y1 = Math.min(A.h, B.h + oy), x0 = Math.max(0, dx), x1 = Math.min(A.w, B.w + dx);
                    for (let y = y0; y < y1; y++) { const ra = y * A.w, rb = (y - oy) * B.w - dx; for (let x = x0; x < x1; x++) { const va = A.a[ra + x], vb = B.a[rb + x]; sum += va < vb ? va : vb; } }
                    if (sum > best) { best = sum; bestSum = B.sum; }
                }
            }
            inter += best; total += A.sum + bestSum;
        }
        if (inter / (total - inter || 1) > bestInter / (bestTotal - bestInter || 1)) { bestInter = inter; bestTotal = total; }
    }
    return { score: bestInter / (bestTotal - bestInter || 1), words: wa.length, mode: words ? 'words' : 'line' };
}

/**
 * One source line against one rendered cell that is `n` × as large: what is left between the rendered and the
 * fitted size is taken out by one resampling factor (from the line width; from the height for < 4 glyphs),
 * then the two are compared word by word at sub-pixel positions. → {score, words, mode, scale}
 */
export async function scoreRender(src, ren, { n = 1, glyphs: count = 4 } = {}) {
    const k = count >= 4 ? src.w * n / ren.w : src.h * n / ren.h;
    let r = ren;
    if (Math.abs(k - 1) > (n > 1 ? 0.003 : 0.01)) {
        const toW = Math.max(1, Math.round(r.w * k)), toH = Math.max(1, Math.round(r.h * k));
        const a = await resizeCoverage(r.a, r.w, r.h, toW, toH);
        let sum = 0; for (let i = 0; i < a.length; i++) sum += a[i];
        r = { w: toW, h: toH, a, sum };
    }
    const v = wordIouFine({ w: src.w, h: src.h, a: src.a, sum: src.sum }, r, n);
    return { ...v, scale: k };
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
 * that face for that line. The render is asked for at `supersample` × that size (3 ×; 2 × or 1 × where 3 ×
 * would leave the app's 8 … 400 px or the sheet bound) and compared at sub-pixel positions (wordIouFine).
 * Items whose rendered sizes lie within ±3 % share one render (≤ 8 lines). What is left between the rendered
 * and the fitted size is one resampling factor (scoreRender); a render that is more than 3 % off was fitted
 * wrongly and is rendered once more at the corrected size while the budget lasts.
 * → {results: [{score, size, scale, words, glyphs, n, inkHeight, wrapped} | null], renders}
 */
export async function verifyFaces({ items, renderFaces, loadRaster, signal = null, maxRenders = 6, minSize = 8, maxSize = 400, supersample = 3 }) {
    const out = items.map(() => null);
    const clean = (t) => String(t).replace(/\s+/g, ' ').trim();
    // the size a sheet is rendered at: the wanted size, inside the app's range, and no wider than the sheet bound
    const sizeFor = (wanted, chars) => Math.max(minSize, Math.min(maxSize, Math.round(wanted), Math.floor((SHEET_MAX_WIDTH - 24) / (chars * 0.7 + 1))));
    // how many times the fitted size a line is rendered: the largest factor the app's range and the sheet width allow
    const factorFor = (size, chars) => { for (let n = Math.max(1, Math.floor(supersample)); n > 1; n--) if (sizeFor(size * n, chars) >= size * n * 0.97) return n; return 1; };
    let renders = 0;
    const round = async (list) => {
        const again = [];
        const usable = list.filter((it) => it.ink && it.ink.n >= 30 && clean(it.text) && it.face && it.size > 0)
            .map((it) => { const text = clean(it.text), n = factorFor(it.size, text.length); return { ...it, text, n, wanted: it.size * n }; })
            .sort((a, b) => a.n - b.n || a.wanted - b.wanted);
        const batches = [];
        for (const it of usable) {
            const b = batches.at(-1);
            // would this item still fit the sheet of the current batch? (rows = lines × faces, at the batch's size and longest line)
            const fits = (bb) => {
                const lines = bb.lines.includes(it.text) ? bb.lines.length : bb.lines.length + 1, fcs = bb.faces.includes(it.face) ? bb.faces.length : bb.faces.length + 1;
                const chars = Math.max(it.text.length, ...bb.lines.map((l) => l.length));
                return lines <= 8 && fcs <= 6 && lines * fcs <= sheetRows(sizeFor(Math.max(it.wanted, bb.min * 1.03), chars), chars);
            };
            if (b && b.n === it.n && it.wanted <= b.min * 1.03 && fits(b)) b.items.push(it);
            else batches.push({ n: it.n, min: it.wanted, items: [it], lines: [], faces: [] });
            const cur = batches.at(-1);
            if (!cur.lines.includes(it.text)) cur.lines.push(it.text);
            if (!cur.faces.includes(it.face)) cur.faces.push(it.face);
        }
        for (const batch of batches) {
            if (renders >= maxRenders) break;
            if (signal?.aborted) throw Object.assign(new Error('aborted'), { code: 'aborted' });
            const wanted = batch.items[batch.items.length >> 1].wanted;
            const size = sizeFor(wanted, Math.max(...batch.lines.map((l) => l.length)));
            const sheet = await renderFaces({ lines: batch.lines, faces: batch.faces, size });
            renders += sheet.sheets ?? 1;
            const raster = sheet.raster ?? await loadRaster(sheet.image.data);
            for (const it of batch.items) {
                const cell = sheet.cells.find((c) => c.face === it.face && c.line === batch.lines.indexOf(it.text));
                if (!cell) continue;
                const r = cellInk(raster, { x: cell.x, y: cell.y, w: cell.width, h: cell.height });
                if (!r) continue;
                const fontSize = cell.fontSize ?? size;
                if (r.h > fontSize * 1.45) { out[it.index] = { score: 0, size: fontSize / batch.n, scale: null, words: 0, wrapped: true }; continue; }
                const v = await scoreRender(it.ink, r, { n: batch.n, glyphs: glyphs(it.text) });
                // (a sheet that was set smaller on purpose — the width bound — is not a wrong fit)
                const fitted = fontSize * v.scale / batch.n;
                if (Math.abs(v.scale - 1) > 0.03 && !it.corrected && fitted >= minSize && sizeFor(fitted * batch.n, it.text.length) > fontSize * 1.03) again.push({ ...it, size: fitted, corrected: true });
                out[it.index] = { score: +v.score.toFixed(3), size: +(fontSize / batch.n).toFixed(2), scale: +v.scale.toFixed(4), words: v.words, glyphs: glyphs(it.text), n: batch.n, inkHeight: it.ink.h };
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
 * the true face overlaps a few bold glyphs almost as well as the face itself, and less and less of a longer
 * line. Measured with the sub-pixel comparison on the 223 groups of the evaluation (eval/RESULTS.md §10.1) —
 * the best score of ANOTHER face / the worst of the TRUE face, by the length of the line:
 *   < 12 glyphs   0.84 / 0.94   → 0.88
 *   12–19 glyphs  0.81 / 0.89   → 0.85
 *   ≥ 20 glyphs   0.73 / 0.82   → 0.78
 * SMALL type gets no allowance for its length: on calendar pages (eval/RESULTS.md §10.5) a caption of 29
 * glyphs whose ink is 15 px tall scored 0.78 in the NEIGHBOURING weight of its family and 0.74–0.81 in its own
 * — at that size a stroke differs by a third of a pixel between two weights. Under `verifiedSmallInkPx` of
 * ink height the short-line bar holds whatever the length.
 * Thresholds: definition.json `fonts` (`verifiedScore`, `verifiedScoreLong` from `verifiedLongGlyphs`,
 * `verifiedScoreLonger` from `verifiedLongerGlyphs`, `verifiedSmallInkPx`).
 */
export function verifiedMatch(v, { verifiedScore = 0.88, verifiedScoreLong = 0.85, verifiedLongGlyphs = 12, verifiedScoreLonger = null, verifiedLongerGlyphs = 20, verifiedSmallInkPx = 0 } = {}) {
    if (!v || v.wrapped) return false;
    const glyphCount = v.glyphs ?? 0;
    const small = (v.inkHeight ?? Infinity) < verifiedSmallInkPx;
    const bar = small ? verifiedScore : verifiedScoreLonger !== null && glyphCount >= verifiedLongerGlyphs ? verifiedScoreLonger : glyphCount >= verifiedLongGlyphs ? verifiedScoreLong : verifiedScore;
    return v.score >= bar;
}

/** The thresholds of verifiedMatch as a build's settings carry them (definition.json `fonts`). */
export const verificationThresholds = (settings = {}) => ({
    verifiedScore: settings.verifiedScore ?? 0.88, verifiedScoreLong: settings.verifiedScoreLong ?? 0.85, verifiedLongGlyphs: settings.verifiedLongGlyphs ?? 12,
    verifiedScoreLonger: settings.verifiedScoreLonger ?? 0.78, verifiedLongerGlyphs: settings.verifiedLongerGlyphs ?? 20,
    verifiedSmallInkPx: settings.verifiedSmallInkPx ?? 22,
});
