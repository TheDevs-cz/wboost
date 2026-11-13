#!/usr/bin/env node
// Dumps the PAIRS the font verification compares, so that a comparison can be developed and measured
// offline (no app, no model): for every text group of every recorded scan (eval/runs/<label>/**) the
// source line's coverage map and the app's render of the same words in the design's face — at the design's
// size × N (N = 3, 2 or 1: the largest that stays inside the app's 400 px) — are written to
// eval/runs/_fonts/pairs/. `node eval/font-metric.mjs` then scores them.
//
//   WBOOST_LOGIN_FILE=… node eval/font-pairs.mjs [--project <id>] [--label sdk] [--runs 1,2,3] [--resume]
// Needs the local app with mode `on` and NO scanner service running (this script holds a build lease).
import { readFileSync, writeFileSync, existsSync, readdirSync, mkdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import sharp from 'sharp';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { holdBuildLease } from './lease.mjs';
import { cellInk, sheetRows, SHEET_MAX_WIDTH } from '../src/instruments/fonts.mjs';
import { runOcr, mergeLines } from '../src/instruments/ocr.mjs';
import { sourceLinesOf, verificationLines } from '../src/finish/report.mjs';
import { lineInk } from '../src/instruments/textink.mjs';
import { loadRgb } from '../src/instruments/raster.mjs';
import { withRendererRetry } from '../src/run.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const label = arg('label', 'sdk'), runs = arg('runs', '1,2,3').split(',').map(Number), outDir = resolve(arg('out', join(HERE, 'runs', '_fonts', 'pairs')));
const project = arg('project', process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15');
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const setOf = new Map(['easy', 'photo', 'hard'].flatMap((s) => sets[s].map((i) => [i.id, s])));
const read = (p) => JSON.parse(readFileSync(p, 'utf8'));
const outTmp = mkdtempSync(join(tmpdir(), 'scan-fontpairs-'));
mkdirSync(outDir, { recursive: true });
const glyphs = (t) => [...String(t).replace(/\s/g, '')].length;
const clean = (t) => String(t).replace(/\s+/g, ' ').trim();

const lease = await holdBuildLease({ projectId: project });
const faces = new Set((lease.job.context.fonts ?? []).map((f) => `${f.font} (${f.face})`));
let seq = 100, renders = 0, n = 0;
const renderFaces = (req) => { const s = ++seq; renders++; return withRendererRetry(() => lease.api.faces({ seq: s, ...req })); };
const cache = new Map(); // face|text|size → cell ink
async function render(face, text, size) {
    const key = `${face}|${text}|${size}`;
    if (cache.has(key)) return cache.get(key);
    const sheet = await renderFaces({ lines: [text], faces: [face], size });
    const raster = sheet.raster ?? await loadRgb(sheet.image.data);
    const cell = sheet.cells.find((c) => c.face === face && c.line === 0);
    const r = cell ? cellInk(raster, { x: cell.x, y: cell.y, w: cell.width, h: cell.height }) : null;
    const out = r ? { ...r, fontSize: cell.fontSize ?? size } : null;
    cache.set(key, out);
    return out;
}
// --resume keeps the pairs already written and skips their inputs (a run that was cut short)
const resume = process.argv.includes('--resume') && existsSync(join(outDir, 'index.json')) ? read(join(outDir, 'index.json')).pairs : [];
const index = [...resume];
const done = new Set(resume.map((p) => `${p.input}|${p.run}`));
n = resume.length ? Math.max(...resume.map((p) => Number(p.id))) : 0;
try {
    for (const input of readdirSync(join(HERE, 'runs', label)).sort()) {
        const set = setOf.get(input);
        if (!set) continue;
        for (const run of runs) {
            const dir = join(HERE, 'runs', label, input, `r${run}`);
            if (done.has(`${input}|${run}`)) continue;
            if (!existsSync(join(dir, 'report.json')) || !existsSync(join(dir, 'proposal.json'))) continue;
            const report = read(join(dir, 'report.json')), design = read(join(dir, 'proposal.json'));
            const source = await loadRgb(join(dir, 'working.webp'));
            const png = join(outTmp, 'working.png');
            await sharp(join(dir, 'working.webp')).png({ compressionLevel: 3 }).toFile(png);
            const lines = mergeLines((await runOcr(png, { threads: 3 })).lines).map((l) => ({ ...l, ink: lineInk(source, l.box) }));
            const byId = new Map(design.elements.map((e) => [e.id, e]));
            for (const g of report.fonts ?? []) {
                const texts = g.texts.map((id) => byId.get(id)).filter((t) => t && t.kind === 'text' && faces.has(t.font));
                const asks = verificationLines(texts, (id) => sourceLinesOf(byId.get(id), lines));
                for (const a of asks) {
                    const text = clean(a.text);
                    for (const N of [3, 1]) {
                        // the size that makes the render N × as wide as the source line (one correction round)
                        const cap = (s) => Math.max(8, Math.min(400, Math.round(s), Math.floor((SHEET_MAX_WIDTH - 24) / (text.length * 0.7 + 1))));
                        let size = cap(a.size * N);
                        if (sheetRows(size, text.length) < 1) continue;
                        let r = await render(a.face, text, size);
                        if (!r) continue;
                        let k = glyphs(text) >= 4 ? a.ink.w * N / r.w : a.ink.h * N / r.h;
                        if (Math.abs(k - 1) > 0.01 && cap(size * k) !== size) { size = cap(size * k); r = await render(a.face, text, size); k = glyphs(text) >= 4 ? a.ink.w * N / r.w : a.ink.h * N / r.h; }
                        if (!r) continue;
                        const id = `${String(++n).padStart(4, '0')}`;
                        writeFileSync(join(outDir, `${id}.src.bin`), Buffer.from(a.ink.a));
                        writeFileSync(join(outDir, `${id}.ren.bin`), Buffer.from(r.a));
                        index.push({ id, set, input, run, group: g.group, face: a.face, text, glyphs: glyphs(text), designSize: a.size, N, size, fontSize: r.fontSize, k: +k.toFixed(4), src: { w: a.ink.w, h: a.ink.h }, ren: { w: r.w, h: r.h }, before: { score: g.score, matched: g.matched } });
                    }
                }
            }
            console.error(`${input} r${run}: ${index.length} pairs, ${renders} renders`);
        }
    }
} finally {
    writeFileSync(join(outDir, 'index.json'), JSON.stringify({ at: new Date().toISOString(), label, runs, renders, pairs: index }, null, 1));
    console.error(JSON.stringify(await lease.close()));
}
