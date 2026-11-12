// Background fit: solid / linear / (elliptical) radial colour models fitted to the pixels no element covers.
import { median, hex } from './raster.mjs';

const round = (v, d = 0) => { const k = 10 ** d; return Math.round(v * k) / k; };

/**
 * `occupied(x, y)` says which pixels belong to texts or other known elements and must not vote.
 * → {kind: 'solid' | 'linear' | 'radial' | 'complex' | 'unknown', rms, coverage, candidates, …model}
 * `complex` = no single fill explains the free pixels (a photo, a pattern, several panels): rms ≥ `complexRms`.
 */
export function fitBackground(img, occupied = () => false, { complexRms = 9 } = {}) {
    const pts = [];
    const step = Math.max(2, Math.round(Math.max(img.width, img.height) / 270));
    for (let y = step >> 1; y < img.height; y += step) for (let x = step >> 1; x < img.width; x += step) {
        if (occupied(x, y)) continue;
        const i = (y * img.width + x) * 3; pts.push([x, y, img.data[i], img.data[i + 1], img.data[i + 2]]);
    }
    const n = pts.length;
    const coverage = n / (Math.ceil(img.width / step) * Math.ceil(img.height / step));
    if (n < 200) return { kind: 'unknown', coverage: round(coverage, 3) };
    const rms = (f) => { let s = 0; for (const p of pts) { const c = f(p[0], p[1]); s += (p[2] - c[0]) ** 2 + (p[3] - c[1]) ** 2 + (p[4] - c[2]) ** 2; } return Math.sqrt(s / (3 * n)); };
    // solid
    const med = [2, 3, 4].map((c) => median(pts.map((p) => p[c])));
    const solid = { kind: 'solid', colour: med, at: () => med };
    solid.rms = rms(solid.at);
    // linear: least-squares plane per channel
    const mx = pts.reduce((a, p) => a + p[0], 0) / n, my = pts.reduce((a, p) => a + p[1], 0) / n;
    let sxx = 0, sxy = 0, syy = 0; const sxc = [0, 0, 0], syc = [0, 0, 0];
    for (const p of pts) { const dx = p[0] - mx, dy = p[1] - my; sxx += dx * dx; sxy += dx * dy; syy += dy * dy; for (let c = 0; c < 3; c++) { sxc[c] += dx * p[2 + c]; syc[c] += dy * p[2 + c]; } }
    const det = sxx * syy - sxy * sxy || 1;
    const bx = sxc.map((v, c) => (v * syy - syc[c] * sxy) / det), by = syc.map((v, c) => (v * sxx - sxc[c] * sxy) / det);
    // one direction for all channels: the strongest channel's gradient, others sign-aligned
    const k = [0, 1, 2].sort((a, b) => Math.hypot(bx[b], by[b]) - Math.hypot(bx[a], by[a]))[0];
    let ux = 0, uy = 0;
    for (let c = 0; c < 3; c++) { const s = Math.sign(bx[c] * bx[k] + by[c] * by[k]) || 1; ux += s * bx[c]; uy += s * by[c]; }
    const norm = Math.hypot(ux, uy) || 1; ux /= norm; uy /= norm;
    let angle = (Math.atan2(uy, ux) * 180 / Math.PI + 360) % 360;
    const snapped = Math.round(angle / 90) * 90;
    if (Math.abs(angle - snapped) < 12) angle = snapped % 360;
    const a = angle * Math.PI / 180, vx = Math.cos(a), vy = Math.sin(a);
    const proj = (x, y) => x * vx + y * vy;
    const corners = [proj(0, 0), proj(img.width, 0), proj(0, img.height), proj(img.width, img.height)];
    const t0 = Math.min(...corners), t1 = Math.max(...corners);
    const fit1d = (tf) => {
        let st = 0, stt = 0; const sc = [0, 0, 0], stc = [0, 0, 0];
        for (const p of pts) { const t = tf(p[0], p[1]); st += t; stt += t * t; for (let c = 0; c < 3; c++) { sc[c] += p[2 + c]; stc[c] += t * p[2 + c]; } }
        const d = n * stt - st * st || 1;
        const slope = stc.map((v, c) => (n * v - st * sc[c]) / d), icpt = sc.map((v, c) => (v - slope[c] * st) / n);
        return { from: icpt, to: icpt.map((v, c) => v + slope[c]) };
    };
    const tl = (x, y) => (proj(x, y) - t0) / (t1 - t0);
    const lf = fit1d(tl);
    const linear = { kind: 'linear', angle: round(angle, 1), from: lf.from, to: lf.to, at: (x, y) => { const t = tl(x, y); return lf.from.map((v, c) => v + (lf.to[c] - v) * t); } };
    linear.rms = rms(linear.at);
    // radial (elliptical): colour = c0 + (c1 − c0)·min(1, d), d = normalised distance from (cx, cy) with radii (rx, ry)
    const radialOf = (cx, cy, rx, ry) => {
        const tr = (x, y) => Math.min(1, Math.hypot((x - cx) / rx, (y - cy) / ry));
        const f = fit1d(tr);
        const at = (x, y) => { const t = tr(x, y); return f.from.map((v, c) => v + (f.to[c] - v) * t); };
        return { kind: 'radial', cx, cy, rx, ry, from: f.from, to: f.to, at, rms: rms(at) };
    };
    let best = null;
    for (const fx of [0.2, 0.5, 0.8]) for (const fy of [0.2, 0.5, 0.8]) for (const fr of [0.7, 1.1, 1.6]) {
        const r = radialOf(fx * img.width, fy * img.height, fr * img.width, fr * img.height);
        if (!best || r.rms < best.rms) best = r;
    }
    let stepPx = Math.max(img.width, img.height) * 0.2;
    for (let iter = 0; iter < 40 && stepPx > 2; iter++) {
        let improved = false;
        for (const key of ['cx', 'cy', 'rx', 'ry']) for (const sgn of [-1, 1]) {
            const p = { cx: best.cx, cy: best.cy, rx: best.rx, ry: best.ry }; p[key] += sgn * stepPx;
            if (p.rx < 20 || p.ry < 20) continue;
            const r = radialOf(p.cx, p.cy, p.rx, p.ry);
            if (r.rms < best.rms - 1e-4) { best = r; improved = true; }
        }
        if (!improved) stepPx /= 2;
    }
    const radial = best;
    let pick;
    if (solid.rms < 2) pick = solid;
    else pick = linear.rms <= radial.rms * 1.25 + 0.3 ? linear : radial;
    const candidates = { solid: round(solid.rms, 2), linear: round(linear.rms, 2), radial: round(radial.rms, 2) };
    const out = { kind: pick.rms >= complexRms ? 'complex' : pick.kind, rms: round(pick.rms, 2), coverage: round(coverage, 3), candidates, n };
    if (pick.kind === 'solid') out.colour = hex(pick.colour);
    if (pick.kind === 'linear') Object.assign(out, { angle: linear.angle, from: hex(linear.from), to: hex(linear.to) });
    if (pick.kind === 'radial') Object.assign(out, { centre: { x: Math.round(radial.cx), y: Math.round(radial.cy) }, radius: { x: Math.round(radial.rx), y: Math.round(radial.ry) }, from: hex(radial.from), to: hex(radial.to) });
    if (out.kind === 'complex') out.bestSimpleModel = pick.kind;
    return out;
}

/** The DSL `fill` a background fit stands for (null when no single fill explains it). */
export function backgroundFill(fit) {
    if (fit.kind === 'solid') return fit.colour;
    if (fit.kind === 'linear') return { type: 'linear', angle: fit.angle, from: fit.from, to: fit.to };
    if (fit.kind === 'radial') return { type: 'radial', stops: [{ at: 0, color: fit.from }, { at: 1, color: fit.to }] };
    return null;
}
