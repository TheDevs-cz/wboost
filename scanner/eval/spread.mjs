#!/usr/bin/env node
// Run-to-run spread of repeated scans of the same input (eval/runs/<label>/<input>/r<N>/record.json + score.json):
// per input the mean, the standard deviation and the range of each figure over its runs, and the mean of those
// over the inputs.   node eval/spread.mjs [--label gaps]
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const root = join(HERE, 'runs', arg('label', 'gaps'));
const read = (p) => JSON.parse(readFileSync(p, 'utf8'));
const FIGURES = [
    ['wall s', (r) => r.wallS, 0], ['cost $', (r) => r.costUsd, 3], ['turns', (r) => r.turns, 1], ['cands', (r) => r.candidates?.length ?? null, 1],
    ['text exact', (r, s) => s?.canvas?.text?.normalisedExactRate ?? null, 2], ['text box IoU', (r, s) => s?.canvas?.text?.meanBoxIoU ?? null, 3],
    ['close texts', (r, s) => s?.close?.all ?? null, 2], ['size ±5 %', (r, s) => s?.close?.size ?? null, 2], ['masked comp.', (r, s) => s?.imageMasked?.composite ?? null, 3],
    ['pictures R', (r, s) => s?.pictures?.recall ?? null, 2], ['texts', (r, s) => r.report?.texts ?? s?.canvas?.text?.candidate ?? null, 1], ['places', (r) => r.report?.placeholders ?? null, 1],
    ['fonts matched', (r) => (Array.isArray(r.report?.fonts) && r.report.fonts.length ? r.report.fonts.filter((f) => f.matched).length / r.report.fonts.length : null), 2],
];
const stat = (v) => { const x = v.filter((n) => n !== null && Number.isFinite(n)); if (!x.length) return null; const m = x.reduce((a, b) => a + b, 0) / x.length; return { n: x.length, mean: m, sd: Math.sqrt(x.reduce((a, b) => a + (b - m) ** 2, 0) / x.length), min: Math.min(...x), max: Math.max(...x) }; };
const f = (v, d) => (v === null || v === undefined ? '–' : v.toFixed(d));
const inputs = existsSync(root) ? readdirSync(root).filter((d) => !d.startsWith('_') && existsSync(join(root, d))).sort() : [];
const perInput = [];
console.log(`| input | runs | ${FIGURES.map(([n]) => n).join(' | ')} |\n|---|---|${FIGURES.map(() => '---').join('|')}|`);
for (const input of inputs) {
    const runs = readdirSync(join(root, input)).filter((d) => existsSync(join(root, input, d, 'record.json'))).map((d) => ({ r: read(join(root, input, d, 'record.json')), s: existsSync(join(root, input, d, 'score.json')) ? read(join(root, input, d, 'score.json')) : null })).filter((x) => x.r.outcome === 'proposed');
    if (!runs.length) continue;
    const stats = FIGURES.map(([, get]) => stat(runs.map((x) => get(x.r, x.s))));
    perInput.push(stats);
    console.log(`| ${input} | ${runs.length} | ${stats.map((s, i) => (s ? `${f(s.mean, FIGURES[i][2])} (${f(s.min, FIGURES[i][2])}–${f(s.max, FIGURES[i][2])})` : '–')).join(' | ')} |`);
}
const over = (pick) => FIGURES.map((_, i) => { const v = perInput.map((s) => (s[i] ? pick(s[i]) : null)).filter((x) => x !== null); return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null; });
console.log(`| **mean range over the inputs** | | ${over((s) => s.max - s.min).map((v, i) => f(v, Math.max(2, FIGURES[i][2]))).join(' | ')} |`);
console.log(`| **mean standard deviation** | | ${over((s) => s.sd).map((v, i) => f(v, Math.max(2, FIGURES[i][2]))).join(' | ')} |`);
