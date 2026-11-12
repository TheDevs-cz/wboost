// Placeholder mask: which canvas pixels show a picture the USER will supply (a fillable image slot or
// a picture background) rather than something the scan is responsible for. In stack order: a picture
// background marks the whole canvas, a slot marks its box, an OPAQUE shape drawn later clears its box.
const boxOf = (e, W, H) => {
    if (typeof e.x !== 'number' || typeof e.y !== 'number' || typeof e.width !== 'number') return null;
    const h = e.height ?? e.width;
    const x0 = Math.max(0, Math.round(e.x)), y0 = Math.max(0, Math.round(e.y));
    const x1 = Math.min(W, Math.round(e.x + e.width)), y1 = Math.min(H, Math.round(e.y + h));
    return x1 > x0 && y1 > y0 ? { x0, y0, x1, y1 } : null;
};
const opaqueFill = (f) => typeof f === 'string' || (f && typeof f === 'object' && (f.stops ? f.stops.every((s) => (s.opacity ?? 1) >= 0.99) : true));

export const isSlot = (e) => (e?.kind === 'image' && !!e.input) || (e?.kind === 'background' && !!e.asset);

export function placeholderMask(design, W, H) {
    const mask = new Uint8Array(W * H);
    const els = design.elements ?? [];
    const ordered = [...els.filter((e) => e.kind === 'background'), ...els.filter((e) => e.kind !== 'background')];
    for (const e of ordered) {
        if (e.kind === 'background') { if (e.asset) mask.fill(1); continue; }
        const b = boxOf(e, W, H);
        if (!b) continue;
        if (e.kind === 'image') { for (let y = b.y0; y < b.y1; y++) mask.fill(1, y * W + b.x0, y * W + b.x1); continue; }
        if (e.kind === 'shape' && (e.opacity ?? 1) >= 0.99 && opaqueFill(e.fill ?? '#000000')) {
            const round = e.shape === 'circle' || e.shape === 'ellipse';
            if (!round && !['rectangle', 'square', 'line'].includes(e.shape)) continue;
            const cx = e.x + e.width / 2, cy = e.y + (e.height ?? e.width) / 2, rx = e.width / 2, ry = (e.height ?? e.width) / 2;
            for (let y = b.y0; y < b.y1; y++) for (let x = b.x0; x < b.x1; x++) {
                if (round && ((x + 0.5 - cx) / rx) ** 2 + ((y + 0.5 - cy) / ry) ** 2 > 1) continue;
                mask[y * W + x] = 0;
            }
        }
    }
    return mask;
}

/** RGB raster = render, with the source's pixels wherever the mask is set. */
export function compositeMasked(source, render, mask) {
    const out = Buffer.from(render.data);
    for (let i = 0; i < mask.length; i++) if (mask[i]) { out[i * 3] = source.data[i * 3]; out[i * 3 + 1] = source.data[i * 3 + 1]; out[i * 3 + 2] = source.data[i * 3 + 2]; }
    return { data: out, width: render.width, height: render.height };
}
export const maskShare = (mask) => { let n = 0; for (let i = 0; i < mask.length; i++) n += mask[i]; return n / mask.length; };
export function unionMask(masks) {
    const out = new Uint8Array(masks[0].length);
    for (const m of masks) for (let i = 0; i < out.length; i++) if (m[i]) out[i] = 1;
    return out;
}
