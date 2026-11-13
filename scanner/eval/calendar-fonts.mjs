#!/usr/bin/env node
// The font rows of calendar reports against the ground truth of the synthetic pages (eval/RESULTS.md §9.3):
// for every stored calendar scan of eval/runs/_calendar (or --out <dir>) whose input has a truth file, each
// row `fonts[{group, pattern, face}]` of the proposal is compared with the face the page was drawn in
// (`truth.layout.styles[group].family`). Prints the table pattern × (chosen face = truth), per run.
//   CALENDAR_PROTO_DIR=… node eval/calendar-fonts.mjs [--out <dir>] [--since <ISO time>] [--only s03,s08]
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > 0 ? process.argv[i + 1] : fallback; };
const outRoot = resolve(arg('out', join(HERE, 'runs', '_calendar')));
const since = arg('since', '');
const only = arg('only', null)?.split(',') ?? null;
const inputs = resolve(process.env.CALENDAR_PROTO_DIR ?? join(HERE, '..', '..', 'docs', 'ai-scan', 'prototypes', 'calendar'), 'inputs');
const read = (p) => JSON.parse(readFileSync(p, 'utf8'));
const find = (o, key) => { if (o && typeof o === 'object') { if (key in o && Array.isArray(o[key])) return o[key]; for (const v of Object.values(o)) { const r = find(v, key); if (r) return r; } } return null; };

const table = { measured_match: [0, 0], nearest_available: [0, 0], undetermined: [0, 0] };
const rows = [];
for (const d of existsSync(outRoot) ? readdirSync(outRoot).sort() : []) {
    const dir = join(outRoot, d);
    if (!existsSync(join(dir, 'digest.json')) || !existsSync(join(dir, 'proposal.json'))) continue;
    const digest = read(join(dir, 'digest.json'));
    if (digest.status !== 'ready' || String(digest.at) < since || (only && !only.includes(digest.name))) continue;
    const truthFile = join(inputs, `${digest.name}.truth.json`);
    if (!existsSync(truthFile)) continue;
    const styles = read(truthFile).layout?.styles ?? {};
    for (const f of find(read(join(dir, 'proposal.json')), 'fonts') ?? []) {
        const truth = styles[f.group]?.family ?? null;
        if (!truth || !f.face) continue;
        const right = f.face === truth;
        table[f.pattern][right ? 0 : 1]++;
        rows.push({ input: digest.name, scan: digest.scan?.slice(0, 8), group: f.group, pattern: f.pattern, right, closeness: f.closeness ?? null, rankScore: f.rankScore ?? null, glyphs: f.glyphs ?? null, face: f.face, truth });
    }
}
console.log(`${rows.length} font rows of ${new Set(rows.map((r) => r.scan)).size} scans (${[...new Set(rows.map((r) => r.input))].join(', ')})`);
console.log('| | chosen face = truth | chosen face ≠ truth |\n|---|---|---|');
for (const [pattern, [yes, no]] of Object.entries(table)) console.log(`| \`${pattern}\` | ${yes} | ${no} |`);
const determined = rows.filter((r) => r.pattern !== 'undetermined'), present = determined.filter((r) => r.right);
const pct = (n, d) => (d ? `${n} of ${d} (${Math.round(100 * n / d)} %)` : '–');
console.log(`a face that IS the page's, reported as "nearest available": ${pct(present.filter((r) => r.pattern === 'nearest_available').length, present.length)} of the determined rows with the right face`);
console.log(`"measured match" on a face that is NOT the page's: ${pct(rows.filter((r) => r.pattern === 'measured_match' && !r.right).length, rows.filter((r) => r.pattern === 'measured_match').length)}`);
console.log(`face chosen = truth: ${pct(rows.filter((r) => r.right).length, rows.length)}`);
if (process.argv.includes('--list')) for (const r of rows) console.log(`${r.input} ${r.scan} ${r.group.padEnd(6)} ${r.pattern.padEnd(18)} ${r.right ? '=' : '≠'} closeness ${r.closeness ?? '–'} rank ${r.rankScore ?? '–'} glyphs ${r.glyphs ?? '–'}  ${r.face}${r.right ? '' : `  (truth ${r.truth})`}`);
