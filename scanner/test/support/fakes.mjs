// Test doubles: a tiny "app" that draws a design with rectangles (no fonts needed), a scripted engine.
import sharp from 'sharp';

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

/** Draws a DSL design: shapes as rectangles, a text as one bar per line, images as grey boxes. */
export async function drawDesign(design) {
    const { width: W, height: H } = design.canvas;
    const els = design.elements ?? [];
    const bg = els.find((e) => e.kind === 'background');
    let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><rect width="100%" height="100%" fill="${typeof bg?.fill === 'string' ? bg.fill : '#ffffff'}"/>`;
    for (const e of els) {
        if (e.kind === 'shape') svg += `<rect x="${e.x}" y="${e.y}" width="${e.width}" height="${e.height ?? e.width}" fill="${esc(typeof e.fill === 'string' ? e.fill : '#888888')}" opacity="${e.opacity ?? 1}"/>`;
        if (e.kind === 'image') svg += `<rect x="${e.x}" y="${e.y}" width="${e.width}" height="${e.height}" fill="#c9ccd1"/>`;
        if (e.kind === 'text') {
            const lines = String(e.text).split('\n');
            lines.forEach((line, i) => {
                const w = Math.min(e.width, Math.round(line.length * e.size * 0.5));
                svg += `<rect x="${e.x}" y="${Math.round(e.y + i * e.size * (e.lineHeight ?? 1.16) + e.size * 0.15)}" width="${w}" height="${Math.round(e.size * 0.7)}" fill="${e.color ?? '#000000'}"/>`;
            });
        }
    }
    return sharp(Buffer.from(svg + '</svg>')).png().toBuffer();
}

/** A fake app: validates a little, renders with drawDesign, serves face sheets from `faceInk`. */
export function fakeApp({ fonts = ['Sans (Regular)', 'Sans (Bold)'], reject = () => null, faceWeight = { 'Sans (Regular)': 0.35, 'Sans (Bold)': 0.7 } } = {}) {
    const app = {
        renders: 0, faceCalls: 0,
        async render(design) {
            app.renders++;
            const issues = reject(design) ?? (design.elements ?? []).filter((e) => e.kind === 'text' && !fonts.includes(e.font)).map((e) => ({ severity: 'error', stage: 'compile', code: 'unknown_font', path: `elements.${e.id}.font`, message: `no such font ${e.font}` }));
            if (issues.length) return { rendered: false, status: 'rejected', issues, image: null };
            return { rendered: true, status: 'ok', issues: [], image: { data: await drawDesign(design), mimeType: 'image/png' } };
        },
        /** Every line as a bar whose thickness depends on the face — so one face matches a source bar best. */
        async faces({ lines, faces, size }) {
            app.faceCalls++;
            const cellW = 600, rowH = Math.ceil(size * 1.5);
            const cells = []; let svg = '';
            lines.forEach((text, li) => faces.forEach((face, fi) => {
                const y = (li * faces.length + fi) * rowH;
                cells.push({ face, line: li, x: 0, y, width: cellW, height: rowH, fontSize: size });
                const w = Math.round(text.length * size * 0.5), h = Math.round(size * (faceWeight[face] ?? 0.5));
                svg += `<rect x="10" y="${y + Math.round((rowH - h) / 2)}" width="${w}" height="${h}" fill="#000"/>`;
            }));
            const height = cells.length * rowH;
            const data = await sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${cellW}" height="${height}"><rect width="100%" height="100%" fill="#fff"/>${svg}</svg>`)).png().toBuffer();
            return { image: { data, mimeType: 'image/png' }, cells, width: cellW, height };
        },
    };
    return app;
}

/** A source picture drawn from a design — the "truth" of a test scan. */
export async function sourceOf(design) {
    const png = await drawDesign(design);
    const { data, info } = await sharp(png).removeAlpha().raw().toBuffer({ resolveWithObject: true });
    return { data, width: info.width, height: info.height, png };
}

export const usage = (output = 100) => ({ byModel: { 'claude-opus-5-5': { input: 1000, output, cacheRead: 0, cacheWrite5m: 0, cacheWrite1h: 0 } }, costUsd: 0.01, costUsd5m: 0.01, priceVersion: 'test', engineCostUsd: null });

/**
 * A scripted engine: `script(ctx)` is an async function that plays the model — it may call
 * `ctx.tool(name, args)` and returns the finish object (or throws / returns {outcome}).
 */
export function scriptedEngine(script) {
    return {
        name: 'scripted',
        async run(o) {
            let turns = 0;
            const ctx = {
                options: o,
                async tool(name, args) { turns++; o.onEvent?.({ type: 'turn', turns, usage: usage(turns * 100) }); if (o.signal?.aborted) throw Object.assign(new Error('aborted'), { aborted: true }); return o.dispatch(name, args); },
            };
            const base = { text: '', usage: usage(), turns: 0, apiMs: 0, wallMs: 0, facts: { engine: 'scripted' }, finish: null, error: null };
            try {
                const r = await script(ctx);
                if (o.signal?.aborted) return { ...base, turns, outcome: 'aborted' };
                if (r && r.outcome) return { ...base, turns, ...r };
                return { ...base, turns, outcome: 'finished', finish: r };
            } catch (e) {
                if (e.aborted || o.signal?.aborted) return { ...base, turns, outcome: 'aborted' };
                throw e;
            }
        },
    };
}
