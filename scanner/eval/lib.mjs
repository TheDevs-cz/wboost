// Evaluation helpers: the prototypes' scoring, applied to a scan the scanner produced.
//   image    : eval/score.mjs (the shared scorer of the prototypes, copied unchanged) — unmasked and
//              masked (the source's pixels composited into the places for pictures first)
//   canvas   : the same scorer on the saved canvas against the truth canvas
//   pictures : box IoU of every picture layer of the truth against the candidate's (T1's measure)
//   texts    : the five-property "closely matches" measure of architecture §4.10
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { loadRgb } from '../src/instruments/raster.mjs';
import { placeholderMask, compositeMasked, maskShare } from '../src/adapters/mask.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
export const REPO = resolve(HERE, '..', '..');
const SCORE = join(HERE, 'score.mjs');
export const score = (...a) => JSON.parse(execFileSync('node', [SCORE, ...a], { encoding: 'utf8', maxBuffer: 64 << 20 }));
export const img = (s) => ({ lowResMae: s.lowResMae, ssim: s.ssim, edgeCorr: s.edge.correlation, edgeF1: s.edge.f1, paletteDeltaE: s.palette.deltaE });
export const composite = (i) => +(0.4 * i.ssim + 0.3 * i.edgeF1 + 0.2 * (1 - Math.min(1, i.lowResMae * 4)) + 0.1 * (1 - Math.min(1, i.paletteDeltaE / 20))).toFixed(4);

const iou = (a, b) => {
    const w = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x), h = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
    return w <= 0 || h <= 0 ? 0 : (w * h) / (a.w * a.h + b.w * b.h - w * h);
};
function pictureLayers(canvasPath, W, H) {
    const c = JSON.parse(readFileSync(canvasPath, 'utf8'));
    const clip = (o) => { const x = Math.max(0, o.x), y = Math.max(0, o.y); return { ...o, x, y, w: Math.max(0, Math.min(W, o.x + o.w) - x), h: Math.max(0, Math.min(H, o.y + o.h) - y) }; };
    return (c.objects ?? []).filter((o) => String(o.type).toLowerCase() === 'image').map((o) => clip({ x: o.left ?? 0, y: o.top ?? 0, w: (o.width ?? 0) * (o.scaleX ?? 1), h: (o.height ?? 0) * (o.scaleY ?? 1), placeholder: !!o.imagePlaceholder, background: !!o.isBackground })).filter((o) => o.w > 0 && o.h > 0);
}
/** Greedy best-IoU pairing of picture layers; a hit = IoU ≥ 0.5. */
export function pictureLayerIoU(truthPath, candidatePath, W, H) {
    const t = pictureLayers(truthPath, W, H), c = pictureLayers(candidatePath, W, H);
    const pairs = [];
    for (const [i, a] of t.entries()) for (const [j, b] of c.entries()) pairs.push({ i, j, v: iou(a, b) });
    pairs.sort((p, q) => q.v - p.v);
    const usedT = new Set(), usedC = new Set(), matched = [];
    for (const p of pairs) { if (p.v <= 0 || usedT.has(p.i) || usedC.has(p.j)) continue; usedT.add(p.i); usedC.add(p.j); matched.push(p); }
    const hits = matched.filter((p) => p.v >= 0.5).length;
    return { truth: t.length, candidate: c.length, hits, precision: c.length ? +(hits / c.length).toFixed(3) : null, recall: t.length ? +(hits / t.length).toFixed(3) : null, meanIoU: t.length ? +(matched.reduce((s, p) => s + p.v, 0) / t.length).toFixed(4) : null };
}

/**
 * "Closely matches" per truth text (architecture §4.10): right words; right face, or a reported
 * substitute; size within ± 5 %; colour ΔE ≤ 3; box IoU ≥ 0.7. `reported(candidateFace)` says whether
 * the scan's report marks that face as a substitute (matched = false).
 */
export function closeTexts(canvasScore, reported = () => false) {
    const pairs = canvasScore.text.pairs ?? [];
    const n = canvasScore.text.truth;
    const rows = pairs.map((p) => ({
        words: p.textNormalisedExact, face: p.fontFaceExact || reported(p.candidate.font), size: Math.abs((p.fontSizeRatio ?? 0) - 1) <= 0.05, colour: (p.fillDeltaE ?? 99) <= 3, box: p.boxIoU >= 0.7,
        faceExact: p.fontFaceExact, sizeRatio: p.fontSizeRatio,
    }));
    const share = (f) => (n ? +(rows.filter(f).length / n).toFixed(3) : null);
    return { texts: n, all: share((r) => r.words && r.face && r.size && r.colour && r.box), words: share((r) => r.words), face: share((r) => r.face), faceExact: share((r) => r.faceExact), size: share((r) => r.size), colour: share((r) => r.colour), box: share((r) => r.box),
        sizeRatioMedian: rows.length ? [...rows.map((r) => r.sizeRatio ?? 0)].sort((a, b) => a - b)[rows.length >> 1] : null };
}

/** source │ final │ final with the source's pixels in the places for pictures — the sheet a person looks at. */
export async function sideBySide(image, finalPng, maskedPng, outFile, width = 620) {
    const third = (f) => sharp(f).resize({ width }).png().toBuffer();
    const parts = [await third(image), await third(finalPng), await third(maskedPng)];
    const h = Math.max(...(await Promise.all(parts.map((x) => sharp(x).metadata()))).map((m) => m.height));
    await sharp({ create: { width: width * 3 + 20, height: h, channels: 3, background: '#ffffff' } }).composite(parts.map((input, i) => ({ input, left: i * (width + 10), top: 0 }))).jpeg({ quality: 82 }).toFile(outFile);
}

/** Scores one finished scan: writes final.masked.png and the sheet, returns the score record. */
export async function scoreScan({ image, truth, fonts, finalPng, finalCanvas, design, outDir, report }) {
    const source = await loadRgb(image);
    const W = source.width, H = source.height;
    const render = await loadRgb(finalPng, W, H);
    const mask = placeholderMask(design, W, H);
    const masked = compositeMasked(source, render, mask);
    const maskedPng = join(outDir, 'final.masked.png');
    await sharp(masked.data, { raw: { width: W, height: H, channels: 3 } }).png().toFile(maskedPng);
    const unmasked = img(score('image', image, finalPng, '--summary').image);
    const m = img(score('image', image, maskedPng, '--summary').image);
    const out = { image: { ...unmasked, composite: composite(unmasked) }, imageMasked: { ...m, composite: composite(m) }, maskShare: +maskShare(mask).toFixed(3), canvas: null, pictures: null, close: null };
    if (truth) {
        const full = score('canvas', truth, finalCanvas, '--fonts', fonts).canvas;
        const substitutes = new Set((report?.fonts ?? []).filter((f) => f.matched === false).map((f) => f.face));
        out.close = closeTexts(full, (face) => substitutes.has(face));
        delete full.text.pairs; for (const k of ['shapes', 'images', 'placeholders']) if (full[k]) delete full[k].pairs;
        out.canvas = full;
        out.pictures = pictureLayerIoU(truth, finalCanvas, W, H);
    }
    await sideBySide(image, finalPng, maskedPng, join(outDir, 'sheet.jpg'));
    writeFileSync(join(outDir, 'score.json'), JSON.stringify(out, null, 2));
    return out;
}
