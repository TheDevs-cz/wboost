#!/usr/bin/env node
// AI scan — scoring tool.
//
//   node score.mjs image  <A input picture> <B reconstruction render>
//   node score.mjs canvas <truth canvas.json> <candidate canvas.json> [--fonts project-fonts.json]
//   node score.mjs all    <A> <B> <truth canvas.json> <candidate canvas.json> [--fonts …]
//
// Prints one JSON document on stdout. Only dependency: sharp (decode + resize).
// Everything else (SSIM, Sobel, k-means, CIEDE2000, Levenshtein, Hungarian) is
// implemented here so the numbers are reproducible and inspectable.
//
// Conventions: similarities are 0…1 (1 = identical), MAE is 0…1 (0 = identical),
// ΔE is CIEDE2000 (0 = identical, ≈2.3 = just noticeable, > 10 = another colour).
import { readFileSync } from 'node:fs';
import sharp from 'sharp';

// ───────────────────────────── colour ─────────────────────────────

const srgbToLinear = (v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);

export function rgbToLab([r, g, b]) {
    const [R, G, B] = [r, g, b].map((v) => srgbToLinear(v / 255));
    const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047;
    const Y = 0.2126729 * R + 0.7151522 * G + 0.072175 * B;
    const Z = (0.0193339 * R + 0.119192 * G + 0.9503041 * B) / 1.08883;
    const f = (t) => (t > 216 / 24389 ? Math.cbrt(t) : (24389 / 27 * t + 16) / 116);
    const [fx, fy, fz] = [f(X), f(Y), f(Z)];
    return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
}

export function deltaE2000(lab1, lab2) {
    const [L1, a1, b1] = lab1;
    const [L2, a2, b2] = lab2;
    const rad = Math.PI / 180;
    const C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2);
    const Cb = (C1 + C2) / 2;
    const G = 0.5 * (1 - Math.sqrt(Cb ** 7 / (Cb ** 7 + 25 ** 7)));
    const a1p = (1 + G) * a1, a2p = (1 + G) * a2;
    const C1p = Math.hypot(a1p, b1), C2p = Math.hypot(a2p, b2);
    const h = (x, y) => (x === 0 && y === 0 ? 0 : ((Math.atan2(y, x) / rad) + 360) % 360);
    const h1p = h(a1p, b1), h2p = h(a2p, b2);
    const dLp = L2 - L1, dCp = C2p - C1p;
    let dhp = 0;
    if (C1p * C2p !== 0) {
        dhp = h2p - h1p;
        if (dhp > 180) dhp -= 360; else if (dhp < -180) dhp += 360;
    }
    const dHp = 2 * Math.sqrt(C1p * C2p) * Math.sin((dhp / 2) * rad);
    const Lbp = (L1 + L2) / 2, Cbp = (C1p + C2p) / 2;
    let hbp = h1p + h2p;
    if (C1p * C2p !== 0) {
        if (Math.abs(h1p - h2p) <= 180) hbp = (h1p + h2p) / 2;
        else hbp = (h1p + h2p + (h1p + h2p < 360 ? 360 : -360)) / 2;
    }
    const T = 1 - 0.17 * Math.cos((hbp - 30) * rad) + 0.24 * Math.cos(2 * hbp * rad)
        + 0.32 * Math.cos((3 * hbp + 6) * rad) - 0.2 * Math.cos((4 * hbp - 63) * rad);
    const dTheta = 30 * Math.exp(-(((hbp - 275) / 25) ** 2));
    const Rc = 2 * Math.sqrt(Cbp ** 7 / (Cbp ** 7 + 25 ** 7));
    const Sl = 1 + (0.015 * (Lbp - 50) ** 2) / Math.sqrt(20 + (Lbp - 50) ** 2);
    const Sc = 1 + 0.045 * Cbp, Sh = 1 + 0.015 * Cbp * T;
    const Rt = -Math.sin(2 * dTheta * rad) * Rc;
    return Math.sqrt((dLp / Sl) ** 2 + (dCp / Sc) ** 2 + (dHp / Sh) ** 2 + Rt * (dCp / Sc) * (dHp / Sh));
}

export function parseColor(value) {
    if (typeof value !== 'string') return null;
    let m = value.trim().match(/^#([0-9a-f]{3,8})$/i);
    if (m) {
        let hex = m[1];
        if (hex.length === 3 || hex.length === 4) hex = [...hex].map((c) => c + c).join('');
        return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));
    }
    m = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/i);
    if (m) return [1, 2, 3].map((i) => Math.round(Number(m[i])));
    return { black: [0, 0, 0], white: [255, 255, 255], transparent: null }[value.trim().toLowerCase()] ?? null;
}

const hex = (rgb) => '#' + rgb.map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
const round = (v, d = 4) => (v === null || v === undefined || Number.isNaN(v) ? null : Number(v.toFixed(d)));
const mean = (list) => (list.length ? list.reduce((a, b) => a + b, 0) / list.length : null);

// ───────────────────────────── image scores ─────────────────────────────

/** Decode, flatten onto white, force to w×h (B is stretched to A's aspect — a size mismatch is reported separately). */
async function load(path, width, height) {
    const { data } = await sharp(path).flatten({ background: '#ffffff' }).toColourspace('srgb').removeAlpha()
        .resize(width, height, { fit: 'fill', kernel: 'lanczos3' }).raw().toBuffer({ resolveWithObject: true });
    return data;
}

function gray(rgb, n) {
    const out = new Float64Array(n);
    for (let i = 0; i < n; i++) out[i] = 0.299 * rgb[3 * i] + 0.587 * rgb[3 * i + 1] + 0.114 * rgb[3 * i + 2];
    return out;
}

/** Mean SSIM over 8×8 windows, stride 4, on luma (Wang et al. constants). */
function ssim(a, b, w, h) {
    const C1 = (0.01 * 255) ** 2, C2 = (0.03 * 255) ** 2, N = 64;
    let total = 0, count = 0;
    for (let y = 0; y + 8 <= h; y += 4) {
        for (let x = 0; x + 8 <= w; x += 4) {
            let sa = 0, sb = 0, saa = 0, sbb = 0, sab = 0;
            for (let j = 0; j < 8; j++) {
                for (let i = 0; i < 8; i++) {
                    const p = a[(y + j) * w + x + i], q = b[(y + j) * w + x + i];
                    sa += p; sb += q; saa += p * p; sbb += q * q; sab += p * q;
                }
            }
            const ma = sa / N, mb = sb / N;
            const va = saa / N - ma * ma, vb = sbb / N - mb * mb, cov = sab / N - ma * mb;
            total += ((2 * ma * mb + C1) * (2 * cov + C2)) / ((ma * ma + mb * mb + C1) * (va + vb + C2));
            count++;
        }
    }
    return total / count;
}

function sobel(g, w, h) {
    const out = new Float64Array(w * h);
    for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
            const i = y * w + x;
            const gx = -g[i - w - 1] - 2 * g[i - 1] - g[i + w - 1] + g[i - w + 1] + 2 * g[i + 1] + g[i + w + 1];
            const gy = -g[i - w - 1] - 2 * g[i - w] - g[i - w + 1] + g[i + w - 1] + 2 * g[i + w] + g[i + w + 1];
            out[i] = Math.hypot(gx, gy);
        }
    }
    return out;
}

function pearson(a, b) {
    const n = a.length;
    let sa = 0, sb = 0;
    for (let i = 0; i < n; i++) { sa += a[i]; sb += b[i]; }
    const ma = sa / n, mb = sb / n;
    let num = 0, da = 0, db = 0;
    for (let i = 0; i < n; i++) { num += (a[i] - ma) * (b[i] - mb); da += (a[i] - ma) ** 2; db += (b[i] - mb) ** 2; }
    if (da === 0 && db === 0) return 1; // two edge-free pictures agree
    if (da === 0 || db === 0) return 0;
    return num / Math.sqrt(da * db);
}

/** F1 of binary edge maps with a 1 px tolerance (an edge counts as found if the other map has one in its 3×3 neighbourhood). */
function edgeF1(ea, eb, w, h, threshold = 80) {
    const bin = (e) => { const o = new Uint8Array(w * h); for (let i = 0; i < o.length; i++) o[i] = e[i] > threshold ? 1 : 0; return o; };
    const dilate = (m) => {
        const o = new Uint8Array(w * h);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            let v = 0;
            for (let j = -1; j <= 1 && !v; j++) for (let i = -1; i <= 1 && !v; i++) {
                const xx = x + i, yy = y + j;
                if (xx >= 0 && yy >= 0 && xx < w && yy < h && m[yy * w + xx]) v = 1;
            }
            o[y * w + x] = v;
        }
        return o;
    };
    const A = bin(ea), B = bin(eb), Ad = dilate(A), Bd = dilate(B);
    let nA = 0, nB = 0, hitA = 0, hitB = 0;
    for (let i = 0; i < A.length; i++) { nA += A[i]; nB += B[i]; hitA += A[i] & Bd[i]; hitB += B[i] & Ad[i]; }
    if (nA === 0 && nB === 0) return { f1: 1, precision: 1, recall: 1, edgePixelsA: 0, edgePixelsB: 0 };
    const recall = nA ? hitA / nA : 0, precision = nB ? hitB / nB : 0;
    return { f1: precision + recall ? (2 * precision * recall) / (precision + recall) : 0, precision, recall, edgePixelsA: nA, edgePixelsB: nB };
}

/** Deterministic k-means in Lab (seeded from the most populated 4-bit RGB bins). Returns clusters sorted by share. */
function palette(rgb, n, k = 6) {
    const labs = new Array(n);
    const bins = new Map();
    for (let i = 0; i < n; i++) {
        const p = [rgb[3 * i], rgb[3 * i + 1], rgb[3 * i + 2]];
        labs[i] = rgbToLab(p);
        const key = ((p[0] >> 4) << 8) | ((p[1] >> 4) << 4) | (p[2] >> 4);
        const bin = bins.get(key) ?? { count: 0, sum: [0, 0, 0] };
        bin.count++; bin.sum[0] += p[0]; bin.sum[1] += p[1]; bin.sum[2] += p[2];
        bins.set(key, bin);
    }
    // Seeds: the biggest bin, then repeatedly the bin maximising count × distance to the chosen seeds.
    const candidates = [...bins.values()].map((b) => ({ count: b.count, lab: rgbToLab(b.sum.map((s) => s / b.count)) }));
    candidates.sort((a, b) => b.count - a.count);
    const centres = [candidates[0].lab];
    while (centres.length < Math.min(k, candidates.length)) {
        let best = null, bestScore = -1;
        for (const c of candidates) {
            const d = Math.min(...centres.map((s) => deltaE2000(s, c.lab)));
            const score = Math.sqrt(c.count) * d;
            if (score > bestScore) { bestScore = score; best = c; }
        }
        if (bestScore <= 0) break;
        centres.push(best.lab);
    }
    const assign = new Int32Array(n);
    const dist2 = (p, q) => (p[0] - q[0]) ** 2 + (p[1] - q[1]) ** 2 + (p[2] - q[2]) ** 2;
    for (let iter = 0; iter < 20; iter++) {
        let changed = 0;
        for (let i = 0; i < n; i++) {
            let best = 0, bd = Infinity;
            for (let c = 0; c < centres.length; c++) { const d = dist2(labs[i], centres[c]); if (d < bd) { bd = d; best = c; } }
            if (assign[i] !== best) { assign[i] = best; changed++; }
        }
        const sums = centres.map(() => [0, 0, 0, 0]);
        for (let i = 0; i < n; i++) { const s = sums[assign[i]]; s[0] += labs[i][0]; s[1] += labs[i][1]; s[2] += labs[i][2]; s[3]++; }
        sums.forEach((s, c) => { if (s[3]) centres[c] = [s[0] / s[3], s[1] / s[3], s[2] / s[3]]; });
        if (!changed && iter) break;
    }
    const rgbSums = centres.map(() => [0, 0, 0, 0]);
    for (let i = 0; i < n; i++) { const s = rgbSums[assign[i]]; s[0] += rgb[3 * i]; s[1] += rgb[3 * i + 1]; s[2] += rgb[3 * i + 2]; s[3]++; }
    return rgbSums.map((s, c) => ({ share: s[3] / n, lab: centres[c], hex: s[3] ? hex([s[0] / s[3], s[1] / s[3], s[2] / s[3]]) : null }))
        .filter((c) => c.share > 0).sort((a, b) => b.share - a.share);
}

/** Share-weighted ΔE from each colour of one palette to the nearest of the other, averaged both ways. */
function paletteDistance(pa, pb) {
    const oneWay = (from, to) => from.reduce((sum, c) => sum + c.share * Math.min(...to.map((t) => deltaE2000(c.lab, t.lab))), 0);
    return (oneWay(pa, pb) + oneWay(pb, pa)) / 2;
}

export async function scoreImages(pathA, pathB) {
    const [metaA, metaB] = await Promise.all([sharp(pathA).metadata(), sharp(pathB).metadata()]);
    const aspect = metaA.height / metaA.width;
    const dims = (w) => [w, Math.max(8, Math.round(w * aspect))];

    const [lw, lh] = dims(64);
    const [la, lb] = await Promise.all([load(pathA, lw, lh), load(pathB, lw, lh)]);
    let abs = 0;
    for (let i = 0; i < la.length; i++) abs += Math.abs(la[i] - lb[i]);

    const [w, h] = dims(256);
    const [ra, rb] = await Promise.all([load(pathA, w, h), load(pathB, w, h)]);
    const ga = gray(ra, w * h), gb = gray(rb, w * h);
    const ea = sobel(ga, w, h), eb = sobel(gb, w, h);
    const f1 = edgeF1(ea, eb, w, h);

    const [pw, ph] = dims(96);
    const [pa, pb] = await Promise.all([load(pathA, pw, ph), load(pathB, pw, ph)]);
    const palA = palette(pa, pw * ph), palB = palette(pb, pw * ph);
    const meanLab = (rgb, n) => { const s = [0, 0, 0]; for (let i = 0; i < n; i++) { s[0] += rgb[3 * i]; s[1] += rgb[3 * i + 1]; s[2] += rgb[3 * i + 2]; } return rgbToLab(s.map((v) => v / n)); };

    return {
        a: { path: pathA, width: metaA.width, height: metaA.height },
        b: { path: pathB, width: metaB.width, height: metaB.height },
        aspectRatioMismatch: round(Math.abs(metaA.width / metaA.height - metaB.width / metaB.height) / (metaA.width / metaA.height)),
        lowResMae: round(abs / la.length / 255, 5),          // 64 px wide, RGB, 0 = identical
        ssim: round(ssim(ga, gb, w, h)),                       // 256 px wide, luma, 8×8 windows
        edge: {
            correlation: round(pearson(ea, eb)),               // Pearson of Sobel magnitude maps, 256 px wide
            f1: round(f1.f1), precision: round(f1.precision), recall: round(f1.recall),
            edgePixelsA: f1.edgePixelsA, edgePixelsB: f1.edgePixelsB,
        },
        palette: {
            deltaE: round(paletteDistance(palA, palB), 3),     // share-weighted nearest-colour CIEDE2000, symmetric
            meanColourDeltaE: round(deltaE2000(meanLab(pa, pw * ph), meanLab(pb, pw * ph)), 3),
            a: palA.map((c) => ({ hex: c.hex, share: round(c.share, 3) })),
            b: palB.map((c) => ({ hex: c.hex, share: round(c.share, 3) })),
        },
    };
}

// ───────────────────────────── canvas (structural) scores ─────────────────────────────

const TEXT_TYPES = new Set(['textbox', 'itext', 'i-text', 'text']);
export function layerKind(o) {
    const type = String(o.type ?? '').toLowerCase();
    if (TEXT_TYPES.has(type)) return 'text';
    if (type === 'image') return o.imagePlaceholder ? 'placeholder' : 'image';
    return 'shape';
}

const normaliseText = (s) => String(s ?? '').normalize('NFC').toLowerCase().replace(/\s+/g, ' ').trim();

export function levenshtein(a, b) {
    const s = [...a], t = [...b];
    if (!s.length) return t.length;
    if (!t.length) return s.length;
    let prev = Array.from({ length: t.length + 1 }, (_, i) => i);
    for (let i = 1; i <= s.length; i++) {
        const cur = [i];
        for (let j = 1; j <= t.length; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (s[i - 1] === t[j - 1] ? 0 : 1));
        prev = cur;
    }
    return prev[t.length];
}
const editSimilarity = (a, b) => (a.length || b.length ? 1 - levenshtein(a, b) / Math.max([...a].length, [...b].length) : 1);

const WEIGHT_WORDS = [
    [/extra\s*-?black|ultra\s*-?black/i, 950], [/black|heavy/i, 900], [/extra\s*-?bold|ultra\s*-?bold/i, 800],
    [/semi\s*-?bold|demi\s*-?bold/i, 600], [/bold|gras|tučn/i, 700], [/medium/i, 500],
    [/extra\s*-?light|ultra\s*-?light/i, 200], [/thin|hairline/i, 100], [/light/i, 300],
];
const ITALIC = /ital|oblique|kurz/i;

/** `"Arial (Arial Gras)"` → {family, face, weight, italic}; weight from the project's font list when given, else from the face name. */
function describeFont(o, fonts) {
    const full = String(o.fontFamily ?? '');
    const m = full.match(/^(.*?)\s*\((.*)\)\s*$/);
    const family = (m ? m[1] : full).trim();
    const face = (m ? m[2] : full).trim();
    const known = fonts?.get(full);
    let weight = known?.weight ?? null;
    if (weight === null) {
        const explicit = Number(o.fontWeight);
        if (Number.isFinite(explicit) && explicit > 0 && explicit !== 400) weight = explicit;
        else if (o.fontWeight === 'bold') weight = 700;
        else weight = (WEIGHT_WORDS.find(([re]) => re.test(face)) ?? [null, 400])[1];
    }
    const italic = known ? ITALIC.test(`${known.style} ${face}`) : (ITALIC.test(face) || o.fontStyle === 'italic');
    return { full, family: family.toLowerCase(), face, weight, italic };
}

/**
 * Axis-aligned box of an unrotated object. A Textbox stores no height, so it is
 * ESTIMATED: wrapped line count (explicit breaks + a 0.52 em average glyph
 * advance, 0.58 em for weights ≥ 700) × fontSize × lineHeight × Fabric's 1.13.
 * Both canvases get the same estimator, so the IoU is comparable; it is not the
 * rendered ink box.
 */
function boxOf(o, font) {
    const sx = o.scaleX ?? 1, sy = o.scaleY ?? 1;
    let w = (o.width ?? (o.radius ? o.radius * 2 : 0)) * sx;
    let h = (o.height ?? (o.radius ? o.radius * 2 : 0)) * sy;
    if (layerKind(o) === 'text') {
        const size = (o.fontSize ?? 16) * sy;
        const advance = size * ((font?.weight ?? 400) >= 700 ? 0.58 : 0.52);
        const perLine = Math.max(1, Math.floor(w / advance));
        const text = o.uppercase ? String(o.text ?? '').toUpperCase() : String(o.text ?? '');
        const lines = text.split('\n').reduce((n, line) => n + Math.max(1, Math.ceil([...line].length / perLine)), 0);
        h = lines * size * (o.lineHeight ?? 1.16) * 1.13;
    }
    let x = o.left ?? 0, y = o.top ?? 0;
    if (o.originX === 'center') x -= w / 2; else if (o.originX === 'right') x -= w;
    if (o.originY === 'center') y -= h / 2; else if (o.originY === 'bottom') y -= h;
    return { x, y, w, h };
}

function iou(a, b) {
    const ix = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x));
    const iy = Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
    const inter = ix * iy, union = a.w * a.h + b.w * b.h - inter;
    return union > 0 ? inter / union : 0;
}

/** A fill as one representative colour: a solid, or the mean of a gradient's stops. */
function fillColour(fill) {
    if (typeof fill === 'string') return parseColor(fill);
    const stops = (fill?.colorStops ?? []).map((s) => parseColor(s.color)).filter(Boolean);
    if (!stops.length) return null;
    return [0, 1, 2].map((i) => stops.reduce((s, c) => s + c[i], 0) / stops.length);
}
const fillKind = (fill) => (typeof fill === 'string' ? 'solid' : fill?.type ? `gradient:${fill.type}` : 'none');
const colourDeltaE = (a, b) => (a && b ? deltaE2000(rgbToLab(a), rgbToLab(b)) : null);

/** Hungarian algorithm (maximise). `score[i][j]` ≥ 0; returns pairs [i, j] with score > minScore. */
export function assign(score, minScore = 0) {
    const n = score.length, m = n ? score[0].length : 0;
    if (!n || !m) return [];
    const size = Math.max(n, m);
    const big = 1 + Math.max(...score.flat());
    const cost = Array.from({ length: size }, (_, i) => Array.from({ length: size }, (_, j) => (i < n && j < m ? big - score[i][j] : big)));
    const u = new Array(size + 1).fill(0), v = new Array(size + 1).fill(0), p = new Array(size + 1).fill(0), way = new Array(size + 1).fill(0);
    for (let i = 1; i <= size; i++) {
        p[0] = i;
        let j0 = 0;
        const minv = new Array(size + 1).fill(Infinity), used = new Array(size + 1).fill(false);
        do {
            used[j0] = true;
            const i0 = p[j0];
            let delta = Infinity, j1 = 0;
            for (let j = 1; j <= size; j++) if (!used[j]) {
                const cur = cost[i0 - 1][j - 1] - u[i0] - v[j];
                if (cur < minv[j]) { minv[j] = cur; way[j] = j0; }
                if (minv[j] < delta) { delta = minv[j]; j1 = j; }
            }
            for (let j = 0; j <= size; j++) if (used[j]) { u[p[j]] += delta; v[j] -= delta; } else minv[j] -= delta;
            j0 = j1;
        } while (p[j0] !== 0);
        do { const j1 = way[j0]; p[j0] = p[j1]; j0 = j1; } while (j0);
    }
    const pairs = [];
    for (let j = 1; j <= size; j++) {
        const i = p[j] - 1;
        if (i < n && j - 1 < m && score[i][j - 1] > minScore) pairs.push([i, j - 1]);
    }
    return pairs.sort((a, b) => a[0] - b[0]);
}

function loadFonts(path) {
    if (!path) return null;
    const map = new Map();
    for (const family of JSON.parse(readFileSync(path, 'utf8'))) {
        for (const face of family.faces ?? []) map.set(face.canvasFontFamily ?? `${family.family} (${face.name})`, { weight: face.weight, style: face.style ?? '' });
    }
    return map;
}

const readCanvas = (path) => {
    const doc = JSON.parse(readFileSync(path, 'utf8'));
    return typeof doc.canvas === 'string' ? JSON.parse(doc.canvas) : (doc.canvas?.objects ? doc.canvas : doc);
};

export function scoreCanvases(truthPath, candidatePath, fontsPath = null) {
    const fonts = loadFonts(fontsPath);
    const [truth, cand] = [readCanvas(truthPath), readCanvas(candidatePath)];
    const prepare = (canvas) => (canvas.objects ?? []).map((o, index) => {
        const kind = layerKind(o);
        const font = kind === 'text' ? describeFont(o, fonts) : null;
        return { o, index, kind, font, box: boxOf(o, font), background: o.isBackground === true };
    });
    const T = prepare(truth), C = prepare(cand);
    const count = (list, canvas) => {
        const c = { text: 0, image: 0, placeholder: 0, shape: 0 };
        for (const l of list) c[l.kind]++;
        return { ...c, container: (canvas.containers ?? []).length, objects: list.length };
    };
    const countsT = count(T, truth), countsC = count(C, cand);
    const kinds = ['text', 'image', 'placeholder', 'shape', 'container'];
    const countSimilarity = mean(kinds.map((k) => (countsT[k] || countsC[k] ? Math.min(countsT[k], countsC[k]) / Math.max(countsT[k], countsC[k]) : 1)));

    // Texts: one-to-one assignment on ½ content similarity + ½ box IoU.
    const tt = T.filter((l) => l.kind === 'text'), ct = C.filter((l) => l.kind === 'text');
    const norm = (l) => normaliseText(l.o.uppercase ? String(l.o.text ?? '').toUpperCase() : l.o.text);
    const pairScore = tt.map((a) => ct.map((b) => 0.5 * editSimilarity(norm(a), norm(b)) + 0.5 * iou(a.box, b.box)));
    const textPairs = assign(pairScore, 0.1).map(([i, j]) => {
        const a = tt[i], b = ct[j];
        const sizeA = (a.o.fontSize ?? 0) * (a.o.scaleY ?? 1), sizeB = (b.o.fontSize ?? 0) * (b.o.scaleY ?? 1);
        return {
            truth: { index: a.index, name: a.o.name ?? null, text: a.o.text, font: a.font.full, size: sizeA, fill: a.o.fill, box: boxRounded(a.box) },
            candidate: { index: b.index, name: b.o.name ?? null, text: b.o.text, font: b.font.full, size: sizeB, fill: b.o.fill, box: boxRounded(b.box) },
            textExact: a.o.text === b.o.text,
            textNormalisedExact: norm(a) === norm(b),
            textEditSimilarity: round(editSimilarity(norm(a), norm(b))),
            boxIoU: round(iou(a.box, b.box)),
            leftTopWidthError: { left: round((b.box.x - a.box.x), 1), top: round((b.box.y - a.box.y), 1), width: round(b.box.w - a.box.w, 1) },
            fontFaceExact: a.font.full === b.font.full,
            fontFamilyMatch: a.font.family === b.font.family,
            fontWeightMatch: a.font.weight === b.font.weight,
            fontWeightDiff: b.font.weight - a.font.weight,
            fontItalicMatch: a.font.italic === b.font.italic,
            fontSizeRatio: round(sizeA ? sizeB / sizeA : null),
            fontSizeAgreement: round(sizeA ? Math.max(0, 1 - Math.abs(sizeB - sizeA) / sizeA) : null),
            alignMatch: (a.o.textAlign ?? 'left') === (b.o.textAlign ?? 'left'),
            fillDeltaE: round(colourDeltaE(fillColour(a.o.fill), fillColour(b.o.fill)), 3),
        };
    });
    // Unmatched truth texts count as zeros in every per-text mean (a missing text is a miss, not a skip).
    const perTruth = (pick) => (tt.length ? textPairs.reduce((s, p) => s + pick(p), 0) / tt.length : null);
    const matchedMean = (pick) => mean(textPairs.map(pick).filter((v) => v !== null));

    // Shapes / images / placeholders: one-to-one assignment on IoU within the kind (backgrounds pair with backgrounds).
    const geometry = {};
    for (const kind of ['shape', 'image', 'placeholder']) {
        const a = T.filter((l) => l.kind === kind), b = C.filter((l) => l.kind === kind);
        const pairs = assign(a.map((x) => b.map((y) => (x.background === y.background ? iou(x.box, y.box) : 0))), 0.05).map(([i, j]) => ({
            truthIndex: a[i].index, candidateIndex: b[j].index, name: a[i].o.name ?? null,
            boxIoU: round(iou(a[i].box, b[j].box)),
            ...(kind === 'shape' ? {
                typeMatch: String(a[i].o.type).toLowerCase() === String(b[j].o.type).toLowerCase(),
                fillKindMatch: fillKind(a[i].o.fill) === fillKind(b[j].o.fill),
                fillDeltaE: round(colourDeltaE(fillColour(a[i].o.fill), fillColour(b[j].o.fill)), 3),
                opacityDiff: round((b[j].o.opacity ?? 1) - (a[i].o.opacity ?? 1), 3),
                cornerRadiusDiff: round((b[j].o.rx ?? 0) - (a[i].o.rx ?? 0), 1),
            } : {}),
        }));
        geometry[kind] = {
            truth: a.length, candidate: b.length, matched: pairs.length,
            meanIoU: round(a.length ? pairs.reduce((s, p) => s + p.boxIoU, 0) / a.length : null),   // unmatched truth = 0
            ...(kind === 'shape' ? { meanFillDeltaE: round(mean(pairs.map((p) => p.fillDeltaE).filter((v) => v !== null)), 3) } : {}),
            pairs,
        };
    }

    const bgT = T.find((l) => l.background), bgC = C.find((l) => l.background);
    const background = {
        truthKind: bgT ? `${bgT.kind}${bgT.kind === 'shape' ? ':' + fillKind(bgT.o.fill) : ''}` : 'none',
        candidateKind: bgC ? `${bgC.kind}${bgC.kind === 'shape' ? ':' + fillKind(bgC.o.fill) : ''}` : 'none',
        fillDeltaE: bgT?.kind === 'shape' && bgC?.kind === 'shape' ? round(colourDeltaE(fillColour(bgT.o.fill), fillColour(bgC.o.fill)), 3) : null,
        canvasColourDeltaE: round(colourDeltaE(parseColor(truth.background), parseColor(cand.background)), 3),
    };
    background.kindMatch = background.truthKind === background.candidateKind;

    return {
        truth: truthPath, candidate: candidatePath,
        layers: { truth: countsT, candidate: countsC, countSimilarity: round(countSimilarity) },
        text: {
            truth: tt.length, candidate: ct.length, matched: textPairs.length,
            exactRate: round(perTruth((p) => (p.textExact ? 1 : 0))),
            normalisedExactRate: round(perTruth((p) => (p.textNormalisedExact ? 1 : 0))),
            editSimilarity: round(perTruth((p) => p.textEditSimilarity)),
            meanBoxIoU: round(perTruth((p) => p.boxIoU)),
            fontFaceExactRate: round(perTruth((p) => (p.fontFaceExact ? 1 : 0))),
            fontFamilyRate: round(perTruth((p) => (p.fontFamilyMatch ? 1 : 0))),
            fontWeightRate: round(perTruth((p) => (p.fontWeightMatch ? 1 : 0))),
            fontSizeAgreement: round(perTruth((p) => p.fontSizeAgreement ?? 0)),
            alignRate: round(perTruth((p) => (p.alignMatch ? 1 : 0))),
            meanFillDeltaE: round(matchedMean((p) => p.fillDeltaE), 3),          // over MATCHED texts only
            maxFillDeltaE: round(textPairs.length ? Math.max(...textPairs.map((p) => p.fillDeltaE ?? 0)) : null, 3),
            unmatchedTruth: tt.filter((_, i) => !textPairs.some((p) => p.truth.index === tt[i].index)).map((l) => ({ index: l.index, text: l.o.text })),
            unmatchedCandidate: ct.filter((l) => !textPairs.some((p) => p.candidate.index === l.index)).map((l) => ({ index: l.index, text: l.o.text })),
            pairs: textPairs,
        },
        shapes: geometry.shape, images: geometry.image, placeholders: geometry.placeholder,
        background,
    };
}

const boxRounded = (b) => ({ x: round(b.x, 1), y: round(b.y, 1), w: round(b.w, 1), h: round(b.h, 1) });

// ───────────────────────────── CLI ─────────────────────────────

async function main() {
    const argv = process.argv.slice(2);
    const fontsAt = argv.indexOf('--fonts');
    const fonts = fontsAt >= 0 ? argv.splice(fontsAt, 2)[1] : null;
    const compact = argv.includes('--summary') && argv.splice(argv.indexOf('--summary'), 1);
    const [mode, ...paths] = argv;
    let out;
    if (mode === 'image' && paths.length === 2) out = { image: await scoreImages(paths[0], paths[1]) };
    else if (mode === 'canvas' && paths.length === 2) out = { canvas: scoreCanvases(paths[0], paths[1], fonts) };
    else if (mode === 'all' && paths.length === 4) out = { image: await scoreImages(paths[0], paths[1]), canvas: scoreCanvases(paths[2], paths[3], fonts) };
    else {
        console.error('usage:\n  score.mjs image <A> <B>\n  score.mjs canvas <truth.json> <candidate.json> [--fonts project-fonts.json]\n  score.mjs all <A> <B> <truth.json> <candidate.json> [--fonts …]\n  add --summary to drop the per-pair lists and palettes');
        process.exit(2);
    }
    if (compact) {
        if (out.image) { delete out.image.palette.a; delete out.image.palette.b; }
        if (out.canvas) { delete out.canvas.text.pairs; for (const k of ['shapes', 'images', 'placeholders']) delete out.canvas[k].pairs; }
    }
    console.log(JSON.stringify(out, null, 2));
}

if (import.meta.url === `file://${process.argv[1]}`) await main();
