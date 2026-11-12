// The template adapter: the measured pre-pass handed to the builder, the model-facing tools of a
// template scan, and the measured feedback every rendered candidate gets. No model call happens here.
import * as im from '../instruments/raster.mjs';
import { lineInk } from '../instruments/textink.mjs';
import { fitBackground, backgroundFill } from '../instruments/background.mjs';
import { rankFaces } from '../instruments/fonts.mjs';
import { runOcr, mergeLines } from '../instruments/ocr.mjs';
import { placeholderMask, compositeMasked, maskShare } from './mask.mjs';
import { ToolUserError } from '../tools/server.mjs';

const arr = (b) => (b ? [b.x, b.y, b.w, b.h] : null);
const lineCount = (t) => String(t ?? '').split('\n').length;

/** The box a DSL element occupies, {x, y, w, h}; a text's height is estimated from its lines. */
export function elementBox(e) {
    if (typeof e?.x !== 'number' || typeof e?.y !== 'number' || typeof e?.width !== 'number') return null;
    if (e.kind === 'text') return { x: e.x, y: e.y, w: e.width, h: Math.max(1, lineCount(e.text)) * (e.size ?? 20) * (e.lineHeight ?? 1.16) };
    if (e.kind === 'shape' || e.kind === 'image') return { x: e.x, y: e.y, w: e.width, h: e.height ?? e.width };
    return null;
}

/**
 * Which lines get a font ranking in the pre-pass: ONE per distinct text style (ink height and colour),
 * the longest line of each — more glyphs, more signal — largest styles first. Ranking a line per
 * style instead of every line keeps the faces of one style from flipping between lines.
 */
export function pickLinesToRank(lines, max) {
    const usable = lines.filter((l) => l.ink && [...l.text.replace(/\s/g, '')].length >= 3);
    const same = (a, b) => Math.abs(a.ink.h - b.ink.h) / Math.max(a.ink.h, b.ink.h) <= 0.14 && im.dist(a.ink.colour, b.ink.colour) <= 60;
    const styles = [];
    for (const l of [...usable].sort((a, b) => b.ink.h - a.ink.h)) {
        const s = styles.find((st) => same(st.lines[0], l));
        if (s) s.lines.push(l); else styles.push({ lines: [l] });
    }
    return styles
        .map((s) => ({ area: s.lines.reduce((a, l) => a + l.ink.w * l.ink.h, 0), line: [...s.lines].sort((a, b) => b.text.length - a.text.length)[0] }))
        .sort((a, b) => b.area - a.area).slice(0, max).map((s) => s.line);
}

export class TemplateBuild {
    /**
     * @param source   working copy as a raster (its pixels are canvas pixels)
     * @param context  {fonts: [face strings], brandColours: [hex], standInAssetId}
     * @param app      {render(design) → {rendered, issues, status, image: {data, mimeType} | null}}
     */
    constructor({ source, sourcePath, context, app, caps, settings = {}, signal = null, onNote = () => {} }) {
        this.source = source; this.sourcePath = sourcePath; this.context = context; this.app = app; this.caps = caps; this.signal = signal; this.onNote = onNote;
        this.settings = { availableScore: 0.8, prepassLines: 6, faceSheets: 12, ocrThreads: 3, snapDeltaE: 2, ...settings };
        this.sourceGray = im.gray(source);
        this.W = source.width; this.H = source.height;
        this.prepassResult = null;
        this.working = null;
        this.candidates = [];   // rendered only: {n, design, raster, mask, mae, textFit, feedback, issues, bytes, mimeType}
        this.attempts = [];     // every preview call: {via, rendered, candidate?}
        this.rankings = [];     // {text, box, group, candidates, best, available, origin}
        this.sheetsUsed = 0;
        this.paletteSnaps = [];
        this.stats = { ocr: null, prepassMs: 0, faceSheets: 0 };
    }

    // ---------------------------------------------------------------- pre-pass

    async prepass() {
        const started = Date.now();
        this.onNote('read');
        const ocr = await runOcr(this.sourcePath, { threads: this.settings.ocrThreads, signal: this.signal, onSpawn: this.settings.onOcrSpawn });
        this.stats.ocr = { seconds: ocr.seconds, loadSeconds: ocr.loadSeconds, peakRssMb: ocr.peakRssMb, cpuSeconds: ocr.cpuSeconds, wallMs: ocr.wallMs, raw: ocr.lines.length };
        const lines = mergeLines(ocr.lines).map((l) => {
            const k = lineInk(this.source, l.box);
            return { ...l, ink: k };
        });
        this.onNote('texts_found', { n: lines.length });
        const occupied = (() => {
            const boxes = lines.map((l) => im.grow(l.ink?.box ?? l.box, 6));
            return (x, y) => boxes.some((b) => x >= b.x && x < b.x + b.w && y >= b.y && y < b.y + b.h);
        })();
        const background = fitBackground(this.source, occupied);
        const palette = im.dominantColours(this.source, 8);
        this.onNote('fonts');
        const toRank = pickLinesToRank(lines, this.settings.prepassLines);
        if (toRank.length && this.context.fonts.length) {
            const { results } = await this.#rank(toRank.map((l) => ({ text: l.text, ink: l.ink })));
            toRank.forEach((l, i) => { l.font = results[i]; this.rankings.push({ text: l.text, box: l.ink.box, group: null, origin: 'prepass', line: l.id, ...results[i] }); });
        }
        this.lines = lines;
        this.stats.prepassMs = Date.now() - started;
        this.prepassResult = {
            canvas: { width: this.W, height: this.H },
            fonts: this.context.fonts,
            brandColours: this.context.brandColours ?? [],
            standInAsset: this.context.standInAssetId,
            background: { ...background, fill: backgroundFill(background) },
            palette,
            ocrLines: lines.map((l) => ({
                id: l.id, text: l.text, conf: l.conf, box: arr(l.box),
                ...(l.ink ? { ink: arr(l.ink.box), colour: l.ink.colourHex, backdrop: l.ink.bgHex } : { ink: null }),
                ...(l.font?.best ? { font: { face: l.font.best.face, size: l.font.best.size, score: l.font.best.score, available: l.font.available, next: l.font.candidates.slice(1, 3).map((c) => ({ face: c.face, score: c.score })) } } : {}),
            })),
            notes: [
                'boxes are [x, y, w, h] in canvas px; "box" is what OCR reported, "ink" the measured tight box of the glyphs',
                'font.size is the size at which that face sets the line exactly as wide as the source; font.available=false means no project face draws these glyphs — the typeface is a substitute',
                ...(background.kind === 'complex' ? ['background: no single fill explains the free pixels (rms ' + background.rms + ') — a photo, a pattern or several panels'] : []),
            ],
        };
        return this.prepassResult;
    }

    async #rank(lines) {
        const left = this.settings.faceSheets - this.sheetsUsed;
        if (left < 1) throw new ToolUserError('the font ranking budget of this scan is used — reuse the rankings you have');
        const r = await rankFaces({
            lines, faces: this.context.fonts, availableScore: this.settings.availableScore, maxSheets: left, signal: this.signal,
            loadRaster: (data) => im.loadRgb(data),
            renderFaces: (req) => this.app.faces(req),
        });
        this.sheetsUsed += r.sheets; this.stats.faceSheets += r.sheets;
        return r;
    }

    /** Ranks more lines after the run (the finish stage ranks text groups the run left unranked). */
    async rank(lines) { return (await this.#rank(lines)).results; }

    // ---------------------------------------------------------------- measured feedback

    feedback(rawRender, design) {
        const { source, W, H } = this;
        const mask = placeholderMask(design, W, H);
        const render = compositeMasked(source, rawRender, mask);
        const renderGray = im.gray(render);
        const cells = [];
        const cw = Math.round(W / 8), ch = Math.round(H / 10);
        for (let cy = 0; cy < H; cy += ch) for (let cx = 0; cx < W; cx += cw) {
            const box = im.clipBox(source, { x: cx, y: cy, w: cw, h: ch });
            cells.push({ ...box, mae: +im.mae(source, render, box).toFixed(4) });
        }
        const worstCells = cells.filter((c) => c.mae > 0.012).sort((p, q) => q.mae - p.mae).slice(0, 6).map((c) => ({ box: arr(c), mae: c.mae }));
        const elements = [];
        let fitSum = 0, fitN = 0;
        for (const e of design.elements ?? []) {
            const eb = elementBox(e);
            if (!eb || e.kind === 'image') continue;
            const pad = e.kind === 'text' ? 18 : 10;
            const box = im.clipBox(source, im.grow(eb, pad));
            const row = { id: e.id, kind: e.kind, mae: +im.mae(source, e.kind === 'text' ? rawRender : render, box).toFixed(4) };
            if (e.kind === 'text') {
                const colour = im.parseHex(e.color ?? '#000000');
                const s = im.ink(im.crop(source, box), { colour });
                // the RAW render: a text lying over a placeholder must still be measured
                const r = im.ink(im.crop(rawRender, box), { colour });
                row.sourceInk = arr(im.offset(s.box, box)); row.renderInk = arr(im.offset(r.box, box));
                if (s.box && r.box && s.count > 20 && r.count > 20) {
                    const align = e.align ?? 'left';
                    const dx = align === 'center' ? Math.round(s.box.x + s.box.w / 2 - r.box.x - r.box.w / 2) : align === 'right' ? (s.box.x + s.box.w) - (r.box.x + r.box.w) : s.box.x - r.box.x;
                    row.move = { dx, dy: s.box.y - r.box.y };
                    row.widthRatio = +(s.box.w / r.box.w).toFixed(3);
                    row.heightRatio = +(s.box.h / r.box.h).toFixed(3);
                    const size = e.size ?? 20;
                    fitSum += Math.min(1, (Math.abs(dx) + Math.abs(row.move.dy)) / (2 * size) + Math.abs(1 - row.widthRatio) + Math.abs(1 - row.heightRatio) / 2); fitN++;
                } else {
                    if (!s.box || s.count <= 20) row.note = 'no pixels of this text colour in the source here — colour or position is off';
                    fitSum += 1; fitN++;
                }
            } else if (row.mae > 0.006) {
                const sh = im.bestShift(this.sourceGray, renderGray, W, H, box, 24);
                if (sh.errorAfter < sh.errorBefore * 0.65 && (sh.dx || sh.dy)) row.move = { dx: sh.dx, dy: sh.dy };
            }
            elements.push(row);
        }
        elements.sort((p, q) => q.mae - p.mae);
        return { mae: +im.mae(source, render).toFixed(4), placeholderShare: +maskShare(mask).toFixed(3), textFit: fitN ? +(fitSum / fitN).toFixed(4) : null, worstCells, elements, mask, masked: render };
    }

    // ---------------------------------------------------------------- previews

    #snapPalette(design) {
        const brand = (this.context.brandColours ?? []).map((h) => ({ hex: h.toLowerCase(), rgb: im.parseHex(h) }));
        if (!brand.length) return design;
        const snap = (h) => {
            if (typeof h !== 'string' || !/^#[0-9a-f]{6}$/i.test(h)) return h;
            const rgb = im.parseHex(h);
            const near = brand.map((b) => ({ b, d: im.deltaE(rgb, b.rgb) })).sort((p, q) => p.d - q.d)[0];
            if (near && near.d > 0 && near.d <= this.settings.snapDeltaE && near.b.hex !== h.toLowerCase()) {
                if (!this.paletteSnaps.some((s) => s.from === h.toLowerCase())) this.paletteSnaps.push({ from: h.toLowerCase(), to: near.b.hex });
                return near.b.hex;
            }
            return h;
        };
        const d = structuredClone(design);
        for (const e of d.elements ?? []) {
            if (e.color) e.color = snap(e.color);
            if (typeof e.fill === 'string') e.fill = snap(e.fill);
            if (e.stroke) e.stroke = snap(e.stroke);
        }
        return d;
    }

    async #preview(designIn, via) {
        if (this.caps.candidatesLeft() < 1) throw new ToolUserError(`the candidate budget is used (${this.caps.limits.candidates} of ${this.caps.limits.candidates} rendered) — return the final report now; the scanner keeps the best candidate`);
        if (this.caps.rejectionsLeft() < 1) throw new ToolUserError('the validation budget is used — return the final report now');
        const design = this.#snapPalette(designIn);
        this.working = design;
        this.onNote(this.candidates.length ? 'refine' : 'build');
        const res = await this.app.render(design, { purpose: 'candidate' });
        const issues = (res.issues ?? []).map(({ severity, stage, code, path, message }) => ({ severity, stage, code, path, message }));
        if (!res.rendered || !res.image) {
            this.caps.takeRejection();
            this.attempts.push({ via, rendered: false, issues: issues.length });
            return { json: { rendered: false, status: res.status ?? null, issues, validationAttemptsLeft: this.caps.rejectionsLeft() }, isError: true };
        }
        this.caps.takeCandidate();
        const n = this.candidates.length + 1;
        const raster = await im.loadRgb(res.image.data, this.W, this.H);
        const fb = this.feedback(raster, design);
        const cand = { n, via, design, raster, mask: fb.mask, mae: fb.mae, textFit: fb.textFit, placeholderShare: fb.placeholderShare, issues, bytes: res.image.data, mimeType: res.image.mimeType,
            feedback: { mae: fb.mae, placeholderShare: fb.placeholderShare, textFit: fb.textFit, worstCells: fb.worstCells, elements: fb.elements }, masked: fb.masked };
        this.candidates.push(cand);
        this.attempts.push({ via, rendered: true, candidate: n });
        const history = this.candidates.map((c) => ({ candidate: c.n, mae: c.mae }));
        return {
            json: { candidate: n, rendered: true, mae: fb.mae, placeholderShare: fb.placeholderShare, history,
                warnings: issues.filter((i) => i.severity !== 'error'), worstCells: fb.worstCells, elements: fb.elements },
            images: [res.image.data],
        };
    }

    // ---------------------------------------------------------------- tools

    #box(a) {
        const n = (v, name) => { if (typeof v !== 'number' || !Number.isFinite(v)) throw new ToolUserError(`"${name}" must be a number`); return v; };
        const b = { x: n(a.x, 'x'), y: n(a.y, 'y'), w: n(a.w, 'w'), h: n(a.h, 'h') };
        if (b.w < 1 || b.h < 1) throw new ToolUserError('w and h must be ≥ 1');
        return im.clipBox(this.source, b);
    }

    tools() {
        const self = this;
        return {
            async scan_source() {
                return { json: { ...self.prepassResult, note: 'The picture is the source; its pixel coordinates are canvas coordinates (multiply by 1/imageScale when it is shown smaller).' }, images: [self.source] };
            },
            async scan_crop(a) {
                const b = self.#box(a);
                self.onNote('zooming');
                const auto = Math.max(1, Math.min(4, Math.floor(900 / Math.max(b.w, b.h))));
                const scale = typeof a.zoom === 'number' ? im.clamp(a.zoom, 0.25, 6) : auto;
                const c = im.crop(self.source, b);
                const png = await im.withRulers(await im.toPng(c, { scale, kernel: scale >= 3 ? 'cubic' : 'lanczos3' }), b, scale, { grid: a.grid !== false });
                return { json: { region: arr(b), zoom: scale, note: 'Ruler labels are canvas px.' }, images: [png] };
            },
            async scan_measure(a) {
                const b = self.#box(a);
                self.onNote('measuring');
                const c = im.crop(self.source, b);
                const k = im.ink(c);
                return { json: {
                    region: arr(b), background: k.background, colours: im.dominantColours(c, 6), edges: im.edgeMeans(c),
                    ink: arr(im.offset(k.box, b)), inkShare: +(k.count / (b.w * b.h)).toFixed(3),
                    bands: k.bands.filter((d) => d.h >= 2).slice(0, 24).map((d) => arr(im.offset(d, b))),
                } };
            },
            async font_rank(a) {
                const line = String(a.text ?? '').replace(/\n/g, ' ').trim();
                if (!line) throw new ToolUserError('"text" is empty — give one line exactly as written in the picture');
                const b = self.#box(a);
                const k = lineInk(self.source, b, { growX: 0.3, growY: 0.15 });
                if (!k || k.n < 30) throw new ToolUserError('no text found in that region (one flat colour, or not a single line) — check the region');
                self.onNote('fonts');
                const { results } = await self.#rank([{ text: line, ink: k }]);
                const r = results[0];
                self.rankings.push({ text: line, box: k.box, group: typeof a.group === 'string' ? a.group.slice(0, 40) : null, origin: 'tool', ...r });
                return { json: { text: line, ink: arr(k.box), colour: k.colourHex, backdrop: k.bgHex, available: r.available, candidates: r.candidates.slice(0, 6),
                    note: 'size = the size at which the face sets this line exactly as wide as the source; score compares glyph shapes (1 = identical). available=false: no project face draws these glyphs — use the top face as a substitute.' } };
            },
            async design_preview(a) {
                if (!a.design || typeof a.design !== 'object' || !Array.isArray(a.design.elements)) throw new ToolUserError('"design" must be an object with "canvas" and "elements"');
                return self.#preview(a.design, 'design_preview');
            },
            async design_patch(a) {
                if (!self.working) throw new ToolUserError('there is no working copy yet — send the complete design with design_preview first');
                const d = structuredClone(self.working);
                const find = (id) => d.elements.findIndex((e) => e.id === id);
                const missing = [];
                for (const s of a.set ?? []) {
                    const i = find(s.id);
                    if (i < 0) { missing.push(s.id); continue; }
                    for (const [k, v] of Object.entries(s.props ?? {})) {
                        if (v === null) delete d.elements[i][k];
                        else if (k === 'input' && v && typeof v === 'object' && d.elements[i][k] && typeof d.elements[i][k] === 'object') d.elements[i][k] = { ...d.elements[i][k], ...v };
                        else d.elements[i][k] = v;
                    }
                }
                for (const id of a.remove ?? []) { const i = find(id); if (i < 0) missing.push(id); else d.elements.splice(i, 1); }
                for (const add of a.add ?? []) {
                    if (!add.element || typeof add.element !== 'object') throw new ToolUserError('add[].element must be an element object');
                    const i = add.before ? find(add.before) : -1;
                    if (add.before && i < 0) { missing.push(add.before); continue; }
                    if (i < 0) d.elements.push(add.element); else d.elements.splice(i, 0, add.element);
                }
                if (missing.length) throw new ToolUserError(`unknown element id(s): ${missing.join(', ')} — existing ids: ${self.working.elements.map((e) => e.id).join(', ')}`);
                return self.#preview(d, 'design_patch');
            },
            async compare_region(a) {
                const p = a.candidate ? self.candidates.find((x) => x.n === a.candidate) : self.candidates.at(-1);
                if (!p) throw new ToolUserError('no rendered candidate to compare — call design_preview first');
                const b = self.#box(a);
                const scale = Math.max(1, Math.min(3, Math.floor(520 / Math.max(b.w, b.h))));
                const s = im.crop(self.source, b), r = im.crop(p.masked, b);
                const kernel = scale >= 3 ? 'cubic' : 'lanczos3';
                const png = await im.sideBySide([
                    { label: 'SOURCE', png: await im.withRulers(await im.toPng(s, { scale, kernel }), b, scale, { grid: false }) },
                    { label: `CANDIDATE ${p.n}`, png: await im.toPng(r, { scale, kernel }) },
                    { label: 'DIFFERENCE', png: await im.toPng(im.diffImage(s, r), { scale, kernel: 'nearest' }) },
                ]);
                const ks = im.ink(s), kr = im.ink(r);
                const sh = im.bestShift(self.sourceGray, im.gray(p.masked), self.W, self.H, b, 32);
                return { json: { candidate: p.n, region: arr(b), zoom: scale, mae: +im.mae(self.source, p.masked, b).toFixed(4), shift: sh,
                    sourceInk: arr(im.offset(ks.box, b)), renderInk: arr(im.offset(kr.box, b)), sourceBackground: ks.background, renderBackground: kr.background,
                    note: 'shift = how far the render content must move to match the source (+x right, +y down); trust it when errorAfter is clearly below errorBefore.' }, images: [png] };
            },
        };
    }
}

export const TEMPLATE_APP_TOOLS = ['font_rank', 'design_preview', 'design_patch'];
