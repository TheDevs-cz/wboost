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

/** Lines of similar size share one render (the app renders a call's lines at ONE size, ≤ `maxLines` lines). */
export function batchLines(lines, { maxLines = 8, minSize = 24, maxSize = 200 } = {}) {
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
    const batches = batchLines(usable);
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
            per[li].push({ face: c.face, score: +score.toFixed(3), size: Math.round(fontSize * k * 2) / 2, aspectError: +Math.abs(r.h * byW / line.ink.h - 1).toFixed(3), ...(wrapped ? { wrapped: true } : {}) });
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
