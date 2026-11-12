#!/usr/bin/env node
// Pre-check confusion matrix: the Haiku pre-check alone on positives (the eval sets, calendar pages)
// and on negatives made here from them — a "photo of a print" (the design on a desk, turned, with
// uneven light and noise), two designs side by side, a blurred small copy, a plain photo, a blank
// picture, a page of text. One call at a time, on the plan.
//   node eval/precheck-eval.mjs [--runs 1]
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import sharp from 'sharp';
import { precheck } from '../src/intake/precheck.mjs';
import { normalise } from '../src/intake/normalise.mjs';
import { definitionFiles } from '../src/drivers/file-job.mjs';
import { readDefinition } from '../src/protocol.mjs';
import { engine } from '../src/engine/index.mjs';
import { parseArgs } from '../bin/scanner.mjs';

delete process.env.ANTHROPIC_API_KEY; delete process.env.ANTHROPIC_AUTH_TOKEN;
const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '..', '..');
const args = parseArgs(process.argv.slice(2));
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const out = join(HERE, 'runs', '_precheck'); mkdirSync(out, { recursive: true });
const p = (f) => resolve(REPO, f);
const T = (slug) => p(`docs/ai-scan/examples/templates/${slug}/truth.png`);

async function photoOfPrint(file, angle, tint) {
    const print = await sharp(file).resize({ width: 900 }).rotate(angle, { background: tint }).modulate({ brightness: 0.92 }).blur(0.6).toBuffer();
    const meta = await sharp(print).metadata();
    const W = meta.width + 500, H = meta.height + 400;
    const light = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><defs><radialGradient id="g" cx="25%" cy="20%" r="90%"><stop offset="0" stop-color="#fff" stop-opacity="0.28"/><stop offset="1" stop-color="#000" stop-opacity="0.35"/></radialGradient></defs><rect width="100%" height="100%" fill="url(#g)"/></svg>`);
    const noise = await sharp({ create: { width: W, height: H, channels: 3, background: tint, noise: { type: 'gaussian', mean: 128, sigma: 14 } } }).png().toBuffer();
    return sharp({ create: { width: W, height: H, channels: 3, background: tint } }).composite([{ input: noise, blend: 'soft-light' }, { input: print, left: 260, top: 180 }, { input: light }]).jpeg({ quality: 78 }).toBuffer();
}
const sideBySide = async (a, b) => { const A = await sharp(a).resize({ height: 1000 }).toBuffer(), B = await sharp(b).resize({ height: 1000 }).toBuffer(); const wa = (await sharp(A).metadata()).width, wb = (await sharp(B).metadata()).width; return sharp({ create: { width: wa + wb + 120, height: 1100, channels: 3, background: '#e9e9e9' } }).composite([{ input: A, left: 40, top: 50 }, { input: B, left: wa + 80, top: 50 }]).png().toBuffer(); };
const textPage = () => sharp(Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1240" height="1754"><rect width="100%" height="100%" fill="#fff"/>${Array.from({ length: 46 }, (_, i) => `<rect x="120" y="${140 + i * 33}" width="${i % 7 === 6 ? 520 : 1000}" height="9" fill="#333"/>`).join('')}</svg>`)).png().toBuffer();

const cases = [
    ...['easy', 'photo', 'hard'].flatMap((g) => sets[g].map((it) => ({ id: it.id, kind: 'template', expect: 'pass', file: p(it.image) }))),
    ...['s01', 's02', 's04'].map((s) => ({ id: `calendar-${s}`, kind: 'calendar', expect: 'pass', file: p(`docs/ai-scan/examples/calendars/synthetic/${s}.png`) })),
    { id: 'calendar-chotebuz', kind: 'calendar', expect: 'pass', file: p('docs/ai-scan/examples/calendars/chotebuz-2026-leden.png') },
    { id: 'design-as-calendar', kind: 'calendar', expect: 'not_expected_kind', file: T('argumenty') },
    { id: 'photo-of-print-1', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(T('argumenty'), 7, '#6b5a48') },
    { id: 'photo-of-print-2', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(p('docs/ai-scan/examples/hard/inputs/B2-sleva.png'), -11, '#3c4046') },
    { id: 'photo-of-print-3', kind: 'template', expect: 'photo_of_print', make: () => photoOfPrint(p('docs/ai-scan/examples/hard/inputs/B5-otevreno.png'), 4, '#b9b2a6') },
    { id: 'several-1', kind: 'template', expect: 'several_designs', make: () => sideBySide(T('argumenty'), T('vedeli-jste')) },
    { id: 'several-2', kind: 'template', expect: 'several_designs', make: () => sideBySide(p('docs/ai-scan/examples/hard/inputs/B1-koncert.png'), p('docs/ai-scan/examples/hard/inputs/B2-sleva.png')) },
    { id: 'blurry-1', kind: 'template', expect: 'blurry_or_small', make: () => sharp(T('argumenty')).resize({ width: 640 }).blur(5).toBuffer() },
    { id: 'blurry-2', kind: 'template', expect: 'blurry_or_small', make: async () => sharp(await sharp(p('docs/ai-scan/examples/hard/inputs/B3-letak-a4.png')).resize({ width: 300 }).jpeg({ quality: 20 }).toBuffer()).resize({ width: 900 }).png().toBuffer() },
    { id: 'plain-photo', kind: 'template', expect: 'not_expected_kind', file: p('docs/ai-scan/prototypes/t1-agent/inputs/photo.jpg') },
    { id: 'blank', kind: 'template', expect: 'not_expected_kind', make: () => sharp({ create: { width: 1200, height: 1200, channels: 3, background: '#ffffff' } }).png().toBuffer() },
    { id: 'text-page', kind: 'template', expect: 'not_expected_kind', make: textPage },
];
const { definition, files } = readDefinition(definitionFiles());
const run = engine('sdk').run;
const rows = [];
for (let r = 1; r <= Number(args.runs ?? 1); r++) for (const c of cases) {
    const bytes = c.make ? await c.make() : readFileSync(c.file);
    if (c.make) writeFileSync(join(out, `${c.id}.${bytes[0] === 0xff ? 'jpg' : 'png'}`), bytes);
    const n = await normalise(bytes);
    if (!n.ok) { rows.push({ id: c.id, run: r, expect: c.expect, got: `normalise:${n.stop}`, ok: n.stop === c.expect }); console.error(c.id, 'normalise', n.stop, n.detail); continue; }
    const t0 = Date.now();
    const pc = await precheck({ run, definition, files, preview: n.preview, kind: c.kind, scratchDir: tmpdir() });
    const got = pc.decision.pass ? 'pass' : pc.decision.stop ?? (pc.decision.refused ? 'refused' : 'error');
    rows.push({ id: c.id, run: r, kind: c.kind, expect: c.expect, verdict: pc.verdict, confidence: pc.confidence, got, ok: got === c.expect, ms: Date.now() - t0, apiMs: pc.engine.apiMs, costUsd: pc.engine.usage?.costUsd ?? null, error: pc.decision.error ?? null });
    console.error(`${c.id} r${r}: expect ${c.expect} → ${pc.verdict} (${pc.confidence}) ${got === c.expect ? 'ok' : 'WRONG'} ${Date.now() - t0} ms $${pc.engine.usage?.costUsd}`);
    if (pc.engine.facts?.rateLimit?.status === 'rejected') { console.error('plan limit — stopping'); break; }
}
writeFileSync(join(out, 'results.json'), JSON.stringify(rows, null, 1));
const ms = rows.filter((x) => x.ms).map((x) => x.ms).sort((a, b) => a - b);
console.log(JSON.stringify({ n: rows.length, correct: rows.filter((x) => x.ok).length, wrong: rows.filter((x) => !x.ok).map((x) => `${x.id}: ${x.expect} → ${x.got}`), msMedian: ms[ms.length >> 1], msMax: ms.at(-1), costMean: rows.reduce((a, x) => a + (x.costUsd ?? 0), 0) / rows.length }, null, 1));
