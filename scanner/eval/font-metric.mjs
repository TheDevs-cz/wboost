#!/usr/bin/env node
// Scores the pairs dumped by eval/font-pairs.mjs with the comparison of src/instruments/fonts.mjs — offline:
// the whole-pixel comparison on the 1 × render (what the verification did before) and the sub-pixel one on
// the 3 × render. Prints both error rates over a range of thresholds and the two score distributions.
//   node eval/font-metric.mjs [--dir eval/runs/_fonts/pairs] [--json out.json]
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { scoreRender, verifiedMatch } from '../src/instruments/fonts.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const dir = resolve(arg('dir', join(HERE, 'runs', '_fonts', 'pairs')));
const { pairs } = JSON.parse(readFileSync(join(dir, 'index.json'), 'utf8'));
const map = (id, kind, { w, h }) => { const a = new Uint8Array(readFileSync(join(dir, `${id}.${kind}.bin`))); let sum = 0; for (let i = 0; i < a.length; i++) sum += a[i]; return { w, h, a, sum }; };

// one row per (scan, group): the best line of the group, as the report takes it
const groups = new Map();
for (const p of pairs) {
    const src = map(p.id, 'src', p.src), ren = map(p.id, 'ren', p.ren);
    const v = await scoreRender(src, ren, { n: p.N, glyphs: p.glyphs });
    const key = `${p.set}|${p.input}|${p.run}|${p.group}`;
    const g = groups.get(key) ?? { set: p.set, input: p.input, run: p.run, group: p.group, face: p.face, lines: { 1: [], 3: [] } };
    g.lines[p.N].push({ score: +v.score.toFixed(3), glyphs: p.glyphs, words: v.words, mode: v.mode, text: p.text, size: p.designSize, k: p.k, srcH: p.src.h });
    groups.set(key, g);
}
const rows = [...groups.values()];
const present = rows.filter((r) => r.set !== 'hard'), absent = rows.filter((r) => r.set === 'hard');
const pct = (n, d) => (d ? `${(100 * n / d).toFixed(0)} % (${n} / ${d})` : '–');
const best = (r, N, t) => { const ls = r.lines[N]; if (!ls.length) return null; return ls.find((l) => verifiedMatch(l, t)) ?? [...ls].sort((a, b) => b.score - a.score)[0]; };
const matched = (r, N, t) => { const b = best(r, N, t); return !!b && verifiedMatch(b, t); };
for (const N of [1, 3]) {
    console.log(`\n== ${N} × render — ${present.filter((r) => r.lines[N].length).length} groups with the true face in the project, ${absent.filter((r) => r.lines[N].length).length} with it absent`);
    for (const [name, t] of [
        ['0.85 / 0.80 from 12 glyphs', { verifiedScore: 0.85, verifiedScoreLong: 0.8, verifiedLongGlyphs: 12 }],
        ...[0.8, 0.82, 0.84, 0.85, 0.86, 0.88, 0.9].map((v) => [`one threshold ${v.toFixed(2)}`, { verifiedScore: v, verifiedScoreLong: v }]),
        ['0.90 / 0.85 from 12 glyphs', { verifiedScore: 0.9, verifiedScoreLong: 0.85, verifiedLongGlyphs: 12 }],
        ['0.90 / 0.83 from 12 glyphs', { verifiedScore: 0.9, verifiedScoreLong: 0.83, verifiedLongGlyphs: 12 }],
        ['0.88 / 0.83 from 12 glyphs', { verifiedScore: 0.88, verifiedScoreLong: 0.83, verifiedLongGlyphs: 12 }],
    ]) console.log(`${name.padEnd(28)} present reported as a substitute ${pct(present.filter((r) => r.lines[N].length && !matched(r, N, t)).length, present.filter((r) => r.lines[N].length).length)} · absent reported as a match ${pct(absent.filter((r) => matched(r, N, t)).length, absent.filter((r) => r.lines[N].length).length)}`);
    const dist = (list, f = () => true) => { const v = list.flatMap((r) => r.lines[N].filter(f).map((l) => l.score)).sort((a, b) => a - b); return v.length ? `n ${v.length}: min ${v[0]} · p10 ${v[Math.floor(v.length * 0.1)]} · median ${v[v.length >> 1]} · p90 ${v[Math.floor(v.length * 0.9)]} · max ${v.at(-1)}` : 'none'; };
    console.log(`lines, present: ${dist(present)}\nlines, absent:  ${dist(absent)}`);
    console.log(`lines, absent, ≥ 12 glyphs: ${dist(absent, (l) => l.glyphs >= 12)} · < 12: ${dist(absent, (l) => l.glyphs < 12)}`);
    console.log(`lines, present, ≥ 12 glyphs: ${dist(present, (l) => l.glyphs >= 12)} · < 12: ${dist(present, (l) => l.glyphs < 12)}`);
}
if (arg('json')) writeFileSync(resolve(arg('json')), JSON.stringify(rows, null, 1));
if (process.argv.includes('--list')) for (const r of rows) console.log(r.set, r.input, r.run, r.group, r.face, JSON.stringify(r.lines[1].map((l) => [l.score, l.glyphs, l.mode])), JSON.stringify(r.lines[3].map((l) => [l.score, l.glyphs, l.mode, l.srcH])));
