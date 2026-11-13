#!/usr/bin/env node
// Font verification, measured on the recorded scans (no model): for every text group of every kept design
// in eval/runs/<label>/**, the app renders the group's ranked line in the face the design uses AT THE FITTED
// SIZE and the scanner compares it 1 : 1 with the source line (src/instruments/fonts.mjs verifyFaces).
// Prints both error rates — a present font reported as a substitute (easy + photo sets: the true faces are
// in the project) and an absent font reported as a match (hard set) — before (the ranking score at 0.85)
// and after, for a range of thresholds.
//
//   WBOOST_LOGIN_FILE=… node eval/font-verify.mjs [--project <id>] [--label sdk] [--runs 1,2,3] [--out eval/runs/_fonts]
// Needs the local app with mode `on` and NO scanner service running (this script holds a build lease).
import { readFileSync, writeFileSync, existsSync, readdirSync, mkdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import sharp from 'sharp';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { holdBuildLease } from './lease.mjs';
import { verifyFaces, verifiedMatch } from '../src/instruments/fonts.mjs';
import { runOcr, mergeLines } from '../src/instruments/ocr.mjs';
import { sourceLinesOf, verificationLines } from '../src/finish/report.mjs';
import { lineInk } from '../src/instruments/textink.mjs';
import { loadRgb } from '../src/instruments/raster.mjs';
import { similarity } from '../src/finish/text.mjs';
import { withRendererRetry } from '../src/run.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const label = arg('label', 'sdk'), runs = arg('runs', '1,2,3').split(',').map(Number), outDir = resolve(arg('out', join(HERE, 'runs', '_fonts')));
const project = arg('project', process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15');
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const setOf = new Map(['easy', 'photo', 'hard'].flatMap((s) => sets[s].map((i) => [i.id, s])));
const read = (p) => JSON.parse(readFileSync(p, 'utf8'));
const FONTS = read(join(HERE, '..', '..', 'resources', 'ai-scan', 'definition', 'definition.json')).fonts;
const RULE = { verifiedScore: FONTS.verifiedScore, verifiedScoreLong: FONTS.verifiedScoreLong, verifiedLongGlyphs: FONTS.verifiedLongGlyphs, verifiedScoreLonger: FONTS.verifiedScoreLonger ?? null, verifiedLongerGlyphs: FONTS.verifiedLongerGlyphs ?? 20, verifiedSmallInkPx: FONTS.verifiedSmallInkPx ?? 0 };
const outTmp = mkdtempSync(join(tmpdir(), 'scan-fontverify-'));

const lease = await holdBuildLease({ projectId: project });
const faces = new Set((lease.job.context.fonts ?? []).map((f) => `${f.font} (${f.face})`));
let seq = 100, renders = 0;
const renderFaces = (req) => { const s = ++seq; renders++; return withRendererRetry(() => lease.api.faces({ seq: s, ...req })); };
const rows = [];
try {
    for (const input of readdirSync(join(HERE, 'runs', label)).sort()) {
        const set = setOf.get(input);
        if (!set) continue;
        for (const run of runs) {
            const dir = join(HERE, 'runs', label, input, `r${run}`);
            if (!existsSync(join(dir, 'report.json')) || !existsSync(join(dir, 'proposal.json'))) continue;
            const report = read(join(dir, 'report.json')), design = read(join(dir, 'proposal.json'));
            const source = await loadRgb(join(dir, 'working.webp'));
            // the pre-pass, as the scanner runs it: OCR lines with their measured ink
            const png = join(outTmp, 'working.png');
            await sharp(join(dir, 'working.webp')).png({ compressionLevel: 3 }).toFile(png);
            const lines = mergeLines((await runOcr(png, { threads: 3 })).lines).map((l) => ({ ...l, ink: lineInk(source, l.box) }));
            const byId = new Map(design.elements.map((e) => [e.id, e]));
            const items = [];
            for (const g of report.fonts ?? []) {
                const texts = g.texts.map((id) => byId.get(id)).filter((t) => t && t.kind === 'text' && faces.has(t.font));
                const asks = verificationLines(texts, (id) => sourceLinesOf(byId.get(id), lines));
                const row = { set, input, run, group: g.group, face: g.face, before: { score: g.score, matched: g.matched }, asked: asks.length, after: null };
                rows.push(row);
                for (const a of asks) items.push({ row, ...a });
            }
            if (!items.length) continue;
            const { results } = await verifyFaces({ items: items.map(({ text, ink, face, size }) => ({ text, ink, face, size })), renderFaces, loadRaster: (d) => loadRgb(d), maxRenders: 12, supersample: Number(arg('supersample', FONTS.verifySupersample ?? 3)) });
            items.forEach((it, i) => {
                const r = results[i];
                if (!r || r.wrapped) return;
                const cur = { score: r.score, scale: r.scale, wrapped: false, words: r.words ?? null, glyphs: r.glyphs ?? null, size: r.size, n: r.n ?? 1, inkHeight: r.inkHeight ?? null };
                const best = it.row.after;
                if (!best || (verifiedMatch(cur, RULE) && !verifiedMatch(best, RULE)) || (verifiedMatch(cur, RULE) === verifiedMatch(best, RULE) && cur.score > best.score)) it.row.after = cur;
            });
            items.forEach((it) => { it.row.all = [...(it.row.all ?? []), results[items.indexOf(it)]?.score ?? null]; });
            console.error(`${input} r${run}: ${[...new Set(items.map((it) => it.row))].map((r) => `${r.before.score ?? '–'}→${r.after?.score ?? '–'}`).join('  ')}`);
        }
    }
} finally { console.error(JSON.stringify(await lease.close())); }

mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'verify.json'), JSON.stringify({ at: new Date().toISOString(), label, runs, renders, rows }, null, 1));

// ---- the two error rates
const present = rows.filter((r) => r.set !== 'hard'), absent = rows.filter((r) => r.set === 'hard');
const pct = (n, d) => (d ? `${(100 * n / d).toFixed(0)} % (${n} / ${d})` : '–');
const verified = (r) => r.after && !r.after.wrapped;
/** matched after: the verification decides where there is one, the ranking score (≥ 0.85) where there is none */
const after = (r, t) => (verified(r) ? verifiedMatch(r.after, t) : (r.before.score !== null && r.before.score >= 0.85));
console.log(`groups: ${present.length} with the true face in the project (easy + photo), ${absent.length} with it absent (hard); verified ${rows.filter(verified).length}; ${renders} renders`);
console.log(`before (ranking score ≥ 0.85): present reported as a substitute ${pct(present.filter((r) => !r.before.matched).length, present.length)} · absent reported as a match ${pct(absent.filter((r) => r.before.matched).length, absent.length)}`);
const rule = (name, t) => console.log(`after, ${name}: present reported as a substitute ${pct(present.filter((r) => !after(r, t)).length, present.length)} [of the verified: ${pct(present.filter((r) => verified(r) && !after(r, t)).length, present.filter(verified).length)}] · absent reported as a match ${pct(absent.filter((r) => after(r, t)).length, absent.length)}`);
for (const th of [0.7, 0.75, 0.8, 0.85, 0.9]) rule(`one threshold ${th.toFixed(2)}`, { verifiedScore: th, verifiedScoreLong: th, verifiedScoreLonger: null });
rule('0.85, and 0.80 from 12 glyphs (the rule before the sub-pixel comparison)', { verifiedScore: 0.85, verifiedScoreLong: 0.8, verifiedLongGlyphs: 12 });
rule('0.90, and 0.83 from 12 glyphs (definition 7)', { verifiedScore: 0.9, verifiedScoreLong: 0.83, verifiedLongGlyphs: 12 });
rule(`${RULE.verifiedScore}; ${RULE.verifiedScoreLong} from ${RULE.verifiedLongGlyphs} glyphs; ${RULE.verifiedScoreLonger} from ${RULE.verifiedLongerGlyphs} (the definition)`, RULE);
const dist = (list) => { const v = list.filter(verified).map((r) => r.after.score).sort((a, b) => a - b); return v.length ? `n ${v.length}: min ${v[0]} · p10 ${v[Math.floor(v.length * 0.1)]} · median ${v[v.length >> 1]} · p90 ${v[Math.floor(v.length * 0.9)]} · max ${v.at(-1)}` : 'none'; };
console.log(`verified score, present: ${dist(present)}`);
console.log(`verified score, absent:  ${dist(absent)}`);
console.log(`verified score, absent, ≥ 12 glyphs: ${dist(absent.filter((r) => (r.after?.glyphs ?? 0) >= 12))} · < 12 glyphs: ${dist(absent.filter((r) => (r.after?.glyphs ?? 0) < 12))}`);
console.log(`verified score, present, ≥ 12 glyphs: ${dist(present.filter((r) => (r.after?.glyphs ?? 0) >= 12))} · < 12 glyphs: ${dist(present.filter((r) => (r.after?.glyphs ?? 0) < 12))}`);
