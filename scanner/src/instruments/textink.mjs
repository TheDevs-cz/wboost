// Ink of one text line: which pixels are glyphs, their tight box, their colour and the backdrop.
// `core` is where the glyphs are known to be (an OCR box, or a box the model gave).
import { clipBox, grow, union, intersects, dist, median, dominantColour, maskOf, components, clamp, hex } from './raster.mjs';

/**
 * → {box, w, h, m (binary ink), a (coverage 0..255), n, sum, colour [r,g,b], bg [r,g,b]} or null when
 * nothing that looks like one line of glyphs on a backdrop is found there.
 */
export function lineInk(img, core, { growX = 1.6, growY = 0.3 } = {}) {
    const c = clipBox(img, core);
    if (c.w < 2 || c.h < 2) return null;
    const region = clipBox(img, grow(c, Math.round(c.h * growX), Math.round(c.h * growY)));
    // backdrop = what the frame around the line shows (the mode of the whole box can be the ink itself)
    const fr = clipBox(img, grow(c, 7));
    const bg = dominantColour(img, fr, (x, y) => x >= fr.x + 3 && x < fr.x + fr.w - 3 && y >= fr.y + 3 && y < fr.y + fr.h - 3).colour;
    const far = dominantColour(img, c, (x, y) => { const i = (y * img.width + x) * 3; return dist([img.data[i], img.data[i + 1], img.data[i + 2]], bg) < 60; });
    if (far.n < 12) return null;
    const inkColour = far.colour;
    const mask = maskOf(img, region, (r, g, b) => { const p = [r, g, b]; const db = dist(p, bg); return db > 30 && dist(p, inkColour) < db; });
    const { comps, labels } = components(mask);
    let keep = comps.filter((k) => intersects(k, c) && !(k.w > region.w * 0.98 && k.h > region.h * 0.98));
    if (!keep.length) return null;
    let bb = union(keep);
    // the OCR box may stop short of the line's end: walk outwards along the row
    for (let changed = true; changed;) {
        changed = false;
        for (const k of comps) {
            if (keep.includes(k) || k.h > 1.25 * bb.h || k.x <= region.x || k.y <= region.y || k.x + k.w >= region.x + region.w || k.y + k.h >= region.y + region.h) continue;
            const vOverlap = Math.min(k.y + k.h, bb.y + bb.h) - Math.max(k.y, bb.y);
            const gap = Math.max(k.x - (bb.x + bb.w), bb.x - (k.x + k.w));
            if (vOverlap > 0.5 * k.h && gap >= -1 && gap < 0.75 * bb.h) { keep.push(k); bb = union(keep); changed = true; }
        }
    }
    // accents / dots that float just outside the core
    const extra = comps.filter((k) => !keep.includes(k) && k.x >= bb.x - 2 && k.x + k.w <= bb.x + bb.w + 2 && (Math.abs(k.y + k.h - bb.y) < 0.25 * bb.h || Math.abs(k.y - bb.y - bb.h) < 0.25 * bb.h) && k.n < 0.05 * bb.w * bb.h);
    keep = keep.concat(extra); bb = union(keep);
    const ok = new Set(keep.map((k) => k.label));
    const m = new Uint8Array(bb.w * bb.h), a = new Uint8Array(bb.w * bb.h);
    const axis = [inkColour[0] - bg[0], inkColour[1] - bg[1], inkColour[2] - bg[2]], len2 = axis[0] ** 2 + axis[1] ** 2 + axis[2] ** 2 || 1;
    let n = 0, sum = 0; const interior = [[], [], []];
    for (let y = 0; y < bb.h; y++) for (let x = 0; x < bb.w; x++) {
        const mx = x + bb.x - mask.x, my = y + bb.y - mask.y, p = my * mask.w + mx;
        const i = ((y + bb.y) * img.width + x + bb.x) * 3;
        // coverage = position of the pixel between backdrop and ink colour
        const cov = Math.round(255 * clamp(((img.data[i] - bg[0]) * axis[0] + (img.data[i + 1] - bg[1]) * axis[1] + (img.data[i + 2] - bg[2]) * axis[2]) / len2, 0, 1));
        a[y * bb.w + x] = cov; sum += cov;
        if (!ok.has(labels[p])) continue;
        m[y * bb.w + x] = 1; n++;
        if (mx > 0 && my > 0 && mx < mask.w - 1 && my < mask.h - 1 && mask.m[p - 1] && mask.m[p + 1] && mask.m[p - mask.w] && mask.m[p + mask.w]) {
            interior[0].push(img.data[i]); interior[1].push(img.data[i + 1]); interior[2].push(img.data[i + 2]);
        }
    }
    const colour = interior[0].length >= 8 ? interior.map(median) : inkColour;
    return { box: bb, w: bb.w, h: bb.h, m, a, n, sum, colour, bg, colourHex: hex(colour), bgHex: hex(bg) };
}
