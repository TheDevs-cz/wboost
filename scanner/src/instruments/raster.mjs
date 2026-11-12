// Pixel helpers shared by every instrument. A raster is {data: RGB Uint8 (Buffer), width, height};
// a box is {x, y, w, h} in pixels of that raster, origin top-left.
import sharp from 'sharp';

export const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
export const hex = (c) => '#' + [c[0], c[1], c[2]].map((v) => clamp(Math.round(v), 0, 255).toString(16).padStart(2, '0')).join('');
export function parseHex(s) {
    let h = String(s ?? '').replace('#', '');
    if (h.length === 3) h = [...h].map((c) => c + c).join('');
    const n = parseInt(h, 16);
    return Number.isFinite(n) && h.length === 6 ? [(n >> 16) & 255, (n >> 8) & 255, n & 255] : [0, 0, 0];
}
export const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]);
export function median(values) {
    if (!values.length) return NaN;
    const s = Float64Array.from(values).sort();
    return s.length % 2 ? s[(s.length - 1) / 2] : (s[s.length / 2 - 1] + s[s.length / 2]) / 2;
}

/** Decodes anything sharp reads into an sRGB raster; alpha is flattened on white; optional exact resize. */
export async function loadRgb(input, width = null, height = null) {
    let img = sharp(input, { limitInputPixels: 40_000_000 }).flatten({ background: '#ffffff' }).removeAlpha().toColourspace('srgb');
    if (width && height) img = img.resize(width, height, { fit: 'fill' });
    const { data, info } = await img.raw().toBuffer({ resolveWithObject: true });
    return { data, width: info.width, height: info.height };
}

/** A box clipped to the raster, at least 1 × 1. */
export function clipBox(img, b) {
    const x0 = clamp(Math.round(b.x), 0, img.width - 1), y0 = clamp(Math.round(b.y), 0, img.height - 1);
    const x1 = clamp(Math.round(b.x + b.w), x0 + 1, img.width), y1 = clamp(Math.round(b.y + b.h), y0 + 1, img.height);
    return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
}
export const grow = (b, d, dy = d) => ({ x: b.x - d, y: b.y - dy, w: b.w + 2 * d, h: b.h + 2 * dy });
export function union(boxes) {
    const x0 = Math.min(...boxes.map((b) => b.x)), y0 = Math.min(...boxes.map((b) => b.y));
    const x1 = Math.max(...boxes.map((b) => b.x + b.w)), y1 = Math.max(...boxes.map((b) => b.y + b.h));
    return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
}
export const intersects = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
export function iou(a, b) {
    const i = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x)) * Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
    const u = a.w * a.h + b.w * b.h - i;
    return u > 0 ? i / u : 0;
}
/** Share of box `a` that lies inside box `b`. */
export function coverage(a, b) {
    const i = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x)) * Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
    return a.w * a.h > 0 ? i / (a.w * a.h) : 0;
}
export const offset = (b, o) => (b ? { x: b.x + o.x, y: b.y + o.y, w: b.w, h: b.h } : null);

export function crop(img, box) {
    const b = clipBox(img, box);
    const out = Buffer.alloc(b.w * b.h * 3);
    for (let row = 0; row < b.h; row++) {
        const src = ((b.y + row) * img.width + b.x) * 3;
        img.data.copy(out, row * b.w * 3, src, src + b.w * 3);
    }
    return { data: out, width: b.w, height: b.h, box: b };
}

export function toPng(img, { scale = 1, kernel = 'lanczos3' } = {}) {
    let s = sharp(img.data, { raw: { width: img.width, height: img.height, channels: 3 } });
    if (scale !== 1) s = s.resize(Math.max(1, Math.round(img.width * scale)), Math.max(1, Math.round(img.height * scale)), { kernel });
    return s.png().toBuffer();
}

const px = (img, x, y) => { const i = (y * img.width + x) * 3; return [img.data[i], img.data[i + 1], img.data[i + 2]]; };

/** Median colour of the 1 px border — the "background" of a region. */
export function borderColour(img) {
    const rs = [], gs = [], bs = [];
    const push = (x, y) => { const p = px(img, x, y); rs.push(p[0]); gs.push(p[1]); bs.push(p[2]); };
    for (let x = 0; x < img.width; x++) { push(x, 0); push(x, img.height - 1); }
    for (let y = 0; y < img.height; y++) { push(0, y); push(img.width - 1, y); }
    return [median(rs), median(gs), median(bs)];
}

/** Dominant colours: 4-bit / channel histogram, bins merged when closer than 18. → [{hex, share}] */
export function dominantColours(img, max = 6) {
    const bins = new Map();
    const n = img.width * img.height;
    const step = n > 4_000_000 ? 2 : 1; // a 16 Mpx working copy: every second pixel is plenty
    let counted = 0;
    for (let i = 0; i < n; i += step) {
        const r = img.data[i * 3], g = img.data[i * 3 + 1], b = img.data[i * 3 + 2];
        const k = (r >> 4) << 8 | (g >> 4) << 4 | (b >> 4);
        let e = bins.get(k);
        if (!e) bins.set(k, e = [0, 0, 0, 0]);
        e[0] += r; e[1] += g; e[2] += b; e[3]++; counted++;
    }
    const list = [...bins.values()].map((e) => ({ rgb: [e[0] / e[3], e[1] / e[3], e[2] / e[3]], count: e[3] })).sort((a, b) => b.count - a.count);
    const merged = [];
    for (const c of list) {
        const m = merged.find((o) => dist(o.rgb, c.rgb) < 18);
        if (m) { const t = m.count + c.count; m.rgb = m.rgb.map((v, i) => (v * m.count + c.rgb[i] * c.count) / t); m.count = t; } else merged.push({ ...c });
    }
    return merged.sort((a, b) => b.count - a.count).slice(0, max).map((c) => ({ hex: hex(c.rgb), share: +(c.count / counted).toFixed(3) })).filter((c) => c.share >= 0.004);
}

/** Mean colours at the four edges and the centre — enough to spot a gradient and its direction. */
export function edgeMeans(img) {
    const mean = (x0, y0, w, h) => {
        const s = [0, 0, 0]; let n = 0;
        for (let y = y0; y < y0 + h; y++) for (let x = x0; x < x0 + w; x++) { const p = px(img, x, y); s[0] += p[0]; s[1] += p[1]; s[2] += p[2]; n++; }
        return hex(s.map((v) => v / n));
    };
    const bw = Math.max(1, Math.round(img.width * 0.04)), bh = Math.max(1, Math.round(img.height * 0.04));
    return {
        left: mean(0, 0, bw, img.height), right: mean(img.width - bw, 0, bw, img.height),
        top: mean(0, 0, img.width, bh), bottom: mean(0, img.height - bh, img.width, bh),
        centre: mean(Math.floor(img.width / 2 - bw / 2), Math.floor(img.height / 2 - bh / 2), bw, bh),
    };
}

/**
 * "Ink" = pixels that differ from the region background (or, with `colour`, pixels close to that
 * colour). Returns the tight box (region-relative), the mask and the horizontal bands of inked rows.
 */
export function ink(img, { colour = null, background = null, threshold = null } = {}) {
    const bg = background ?? borderColour(img);
    const n = img.width * img.height;
    const mask = new Uint8Array(n);
    let limit = threshold;
    if (colour === null && limit === null) {
        let maxD = 0;
        for (let i = 0; i < n; i++) { const d = dist([img.data[i * 3], img.data[i * 3 + 1], img.data[i * 3 + 2]], bg); if (d > maxD) maxD = d; }
        limit = Math.max(24, maxD * 0.45);
    }
    let x0 = img.width, y0 = img.height, x1 = -1, y1 = -1, count = 0;
    const rows = new Uint32Array(img.height);
    for (let y = 0; y < img.height; y++) for (let x = 0; x < img.width; x++) {
        const i = y * img.width + x;
        const p = [img.data[i * 3], img.data[i * 3 + 1], img.data[i * 3 + 2]];
        const on = colour ? dist(p, colour) < (limit ?? 70) && dist(p, colour) < dist(p, bg) : dist(p, bg) > limit;
        if (on) { mask[i] = 1; count++; rows[y]++; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
    if (count === 0) return { box: null, count: 0, mask, bands: [], background: hex(bg) };
    const bands = [];
    let start = -1;
    for (let y = 0; y <= img.height; y++) {
        const on = y < img.height && rows[y] >= 1;
        if (on && start < 0) start = y;
        if (!on && start >= 0) {
            let bx0 = img.width, bx1 = -1;
            for (let yy = start; yy < y; yy++) for (let x = 0; x < img.width; x++) if (mask[yy * img.width + x]) { if (x < bx0) bx0 = x; if (x > bx1) bx1 = x; }
            bands.push({ x: bx0, y: start, w: bx1 - bx0 + 1, h: y - start });
            start = -1;
        }
    }
    return { box: { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1 }, count, mask, bands, background: hex(bg) };
}

export function gray(img) {
    const n = img.width * img.height, g = new Float32Array(n);
    for (let i = 0; i < n; i++) g[i] = 0.299 * img.data[i * 3] + 0.587 * img.data[i * 3 + 1] + 0.114 * img.data[i * 3 + 2];
    return g;
}

/** Mean absolute RGB difference 0…1 of two same-size rasters, optionally inside a box and outside a mask. */
export function mae(a, b, box = null, mask = null) {
    const r = box ? clipBox(a, box) : { x: 0, y: 0, w: a.width, h: a.height };
    let s = 0, n = 0;
    for (let y = r.y; y < r.y + r.h; y++) {
        for (let x = r.x; x < r.x + r.w; x++) {
            const p = y * a.width + x;
            if (mask && mask[p]) continue;
            const i = p * 3;
            s += Math.abs(a.data[i] - b.data[i]) + Math.abs(a.data[i + 1] - b.data[i + 1]) + Math.abs(a.data[i + 2] - b.data[i + 2]);
            n++;
        }
    }
    return n ? s / (n * 3 * 255) : 0;
}

/**
 * Best integer shift of the RENDER that makes it match the SOURCE inside a box: {dx, dy} = how far
 * the render's content must MOVE (+x right, +y down), with the error before / after.
 */
export function bestShift(srcGray, renGray, width, height, box, search = 32) {
    const b = clipBox({ width, height }, box);
    const step = b.w * b.h > 400_000 ? 4 : b.w * b.h > 60_000 ? 2 : 1;
    const err = (dx, dy) => {
        let s = 0, n = 0;
        for (let y = b.y; y < b.y + b.h; y += step) {
            const yy = y - dy; if (yy < 0 || yy >= height) continue;
            for (let x = b.x; x < b.x + b.w; x += step) {
                const xx = x - dx; if (xx < 0 || xx >= width) continue;
                s += Math.abs(srcGray[y * width + x] - renGray[yy * width + xx]); n++;
            }
        }
        return n ? s / n / 255 : 1;
    };
    const base = err(0, 0);
    let best = { dx: 0, dy: 0, e: base };
    const coarse = step >= 2 ? 2 : 1;
    for (let dy = -search; dy <= search; dy += coarse) for (let dx = -search; dx <= search; dx += coarse) {
        const e = err(dx, dy); if (e < best.e - 1e-9) best = { dx, dy, e };
    }
    if (coarse > 1) {
        const c = { ...best };
        for (let dy = c.dy - 1; dy <= c.dy + 1; dy++) for (let dx = c.dx - 1; dx <= c.dx + 1; dx++) { const e = err(dx, dy); if (e < best.e - 1e-9) best = { dx, dy, e }; }
    }
    return { dx: best.dx, dy: best.dy, errorBefore: +base.toFixed(4), errorAfter: +best.e.toFixed(4) };
}

/** Absolute difference image (amplified ×3): white = identical. */
export function diffImage(a, b) {
    const out = Buffer.alloc(a.data.length);
    for (let i = 0; i < out.length; i++) out[i] = 255 - Math.min(255, Math.abs(a.data[i] - b.data[i]) * 3);
    return { data: out, width: a.width, height: a.height };
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

/** A crop (PNG) with pixel rulers in SOURCE coordinates; optional grid lines across the crop. */
export async function withRulers(png, box, scale, { grid = true } = {}) {
    const meta = await sharp(png).metadata();
    const m = 30, W = meta.width + m, H = meta.height + m;
    const span = Math.max(box.w, box.h);
    const tick = span <= 80 ? 10 : span <= 200 ? 20 : span <= 500 ? 50 : span <= 1400 ? 100 : span <= 3000 ? 250 : 500;
    let marks = '';
    for (let v = Math.ceil(box.x / tick) * tick; v <= box.x + box.w; v += tick) {
        const p = m + (v - box.x) * scale;
        marks += `<line x1="${p}" y1="${m - 8}" x2="${p}" y2="${grid ? H : m}" stroke="#ff00ff" stroke-opacity="0.35" stroke-width="1"/><text x="${p + 2}" y="12" font-size="11" font-family="Helvetica, Arial, sans-serif" fill="#000">${v}</text>`;
    }
    for (let v = Math.ceil(box.y / tick) * tick; v <= box.y + box.h; v += tick) {
        const p = m + (v - box.y) * scale;
        marks += `<line x1="${m - 8}" y1="${p}" x2="${grid ? W : m}" y2="${p}" stroke="#ff00ff" stroke-opacity="0.35" stroke-width="1"/><text x="1" y="${p - 2}" font-size="11" font-family="Helvetica, Arial, sans-serif" fill="#000">${v}</text>`;
    }
    const overlay = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">${marks}</svg>`);
    return sharp({ create: { width: W, height: H, channels: 3, background: '#ffffff' } }).composite([{ input: png, left: m, top: m }, { input: overlay, left: 0, top: 0 }]).png().toBuffer();
}

/** Panels side by side with captions (same scale). */
export async function sideBySide(panels, gap = 12) {
    const metas = await Promise.all(panels.map((p) => sharp(p.png).metadata()));
    const cap = 22;
    const W = metas.reduce((s, m) => s + m.width, 0) + gap * (panels.length - 1);
    const H = Math.max(...metas.map((m) => m.height)) + cap;
    const comps = [];
    let x = 0, labels = '';
    panels.forEach((p, i) => {
        comps.push({ input: p.png, left: x, top: cap });
        labels += `<text x="${x + 2}" y="15" font-size="13" font-family="Helvetica, Arial, sans-serif" fill="#000">${esc(p.label)}</text>`;
        x += metas[i].width + gap;
    });
    comps.push({ input: Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${cap}">${labels}</svg>`), left: 0, top: 0 });
    return sharp({ create: { width: W, height: H, channels: 3, background: '#ffffff' } }).composite(comps).png().toBuffer();
}

/** Most frequent colour (5-bit bins, mean of the winning bin) inside a box, optionally skipping pixels. */
export function dominantColour(img, box, skip = null) {
    const b = clipBox(img, box);
    const bins = new Map();
    for (let y = b.y; y < b.y + b.h; y++) for (let x = b.x; x < b.x + b.w; x++) {
        if (skip && skip(x, y)) continue;
        const i = (y * img.width + x) * 3;
        const r = img.data[i], g = img.data[i + 1], bl = img.data[i + 2];
        const key = ((r >> 3) << 10) | ((g >> 3) << 5) | (bl >> 3);
        let e = bins.get(key);
        if (!e) bins.set(key, e = [0, 0, 0, 0]);
        e[0]++; e[1] += r; e[2] += g; e[3] += bl;
    }
    let best = null, total = 0;
    for (const e of bins.values()) { total += e[0]; if (!best || e[0] > best[0]) best = e; }
    if (!best) return { colour: [0, 0, 0], share: 0, n: 0, total: 0 };
    return { colour: [best[1] / best[0], best[2] / best[0], best[3] / best[0]], share: best[0] / total, n: best[0], total };
}

/** Binary mask {x, y, w, h, m} of a box from a predicate (r, g, b, x, y). */
export function maskOf(img, box, pred) {
    const b = clipBox(img, box);
    const m = new Uint8Array(b.w * b.h);
    for (let y = 0; y < b.h; y++) for (let x = 0; x < b.w; x++) {
        const i = ((y + b.y) * img.width + x + b.x) * 3;
        if (pred(img.data[i], img.data[i + 1], img.data[i + 2], x + b.x, y + b.y)) m[y * b.w + x] = 1;
    }
    return { ...b, m };
}

/** Connected components (4-neighbour) of a mask: [{x, y, w, h, n, label}] in image coordinates + label map. */
export function components(mask) {
    const { w, h, m } = mask;
    const labels = new Int32Array(w * h);
    const out = [];
    const stack = [];
    for (let s = 0; s < w * h; s++) {
        if (!m[s] || labels[s]) continue;
        const label = out.length + 1;
        let x0 = w, y0 = h, x1 = -1, y1 = -1, n = 0;
        stack.push(s); labels[s] = label;
        while (stack.length) {
            const p = stack.pop();
            const x = p % w, y = (p - x) / w;
            n++; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
            if (x > 0 && m[p - 1] && !labels[p - 1]) { labels[p - 1] = label; stack.push(p - 1); }
            if (x < w - 1 && m[p + 1] && !labels[p + 1]) { labels[p + 1] = label; stack.push(p + 1); }
            if (y > 0 && m[p - w] && !labels[p - w]) { labels[p - w] = label; stack.push(p - w); }
            if (y < h - 1 && m[p + w] && !labels[p + w]) { labels[p + w] = label; stack.push(p + w); }
        }
        out.push({ x: mask.x + x0, y: mask.y + y0, w: x1 - x0 + 1, h: y1 - y0 + 1, n, label });
    }
    return { comps: out, labels };
}

// ---- colour difference (CIEDE2000)
export function rgb2lab([r, g, b]) {
    const f = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    const R = f(r), G = f(g), B = f(b);
    const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047, Y = 0.2126729 * R + 0.7151522 * G + 0.072175 * B, Z = (0.0193339 * R + 0.119192 * G + 0.9503041 * B) / 1.08883;
    const g3 = (t) => (t > 0.008856 ? Math.cbrt(t) : 7.787 * t + 16 / 116);
    return [116 * g3(Y) - 16, 500 * (g3(X) - g3(Y)), 200 * (g3(Y) - g3(Z))];
}
export function deltaE(c1, c2) {
    const [L1, a1, b1] = rgb2lab(c1), [L2, a2, b2] = rgb2lab(c2);
    const C1 = Math.hypot(a1, b1), C2 = Math.hypot(a2, b2), Cb = (C1 + C2) / 2;
    const G = 0.5 * (1 - Math.sqrt(Cb ** 7 / (Cb ** 7 + 25 ** 7)));
    const a1p = a1 * (1 + G), a2p = a2 * (1 + G);
    const C1p = Math.hypot(a1p, b1), C2p = Math.hypot(a2p, b2);
    const h = (y, x) => { const v = Math.atan2(y, x) * 180 / Math.PI; return v < 0 ? v + 360 : v; };
    const h1p = h(b1, a1p), h2p = h(b2, a2p);
    const dL = L2 - L1, dC = C2p - C1p;
    let dh = h2p - h1p; if (C1p * C2p === 0) dh = 0; else if (dh > 180) dh -= 360; else if (dh < -180) dh += 360;
    const dH = 2 * Math.sqrt(C1p * C2p) * Math.sin(dh * Math.PI / 360);
    const Lb = (L1 + L2) / 2, Cbp = (C1p + C2p) / 2;
    let hb = h1p + h2p; if (C1p * C2p !== 0) hb = Math.abs(h1p - h2p) <= 180 ? hb / 2 : (hb < 360 ? (hb + 360) / 2 : (hb - 360) / 2);
    const T = 1 - 0.17 * Math.cos((hb - 30) * Math.PI / 180) + 0.24 * Math.cos(2 * hb * Math.PI / 180) + 0.32 * Math.cos((3 * hb + 6) * Math.PI / 180) - 0.2 * Math.cos((4 * hb - 63) * Math.PI / 180);
    const SL = 1 + 0.015 * (Lb - 50) ** 2 / Math.sqrt(20 + (Lb - 50) ** 2), SC = 1 + 0.045 * Cbp, SH = 1 + 0.015 * Cbp * T;
    const RT = -2 * Math.sqrt(Cbp ** 7 / (Cbp ** 7 + 25 ** 7)) * Math.sin(60 * Math.exp(-(((hb - 275) / 25) ** 2)) * Math.PI / 180);
    return Math.sqrt((dL / SL) ** 2 + (dC / SC) ** 2 + (dH / SH) ** 2 + RT * (dC / SC) * (dH / SH));
}
