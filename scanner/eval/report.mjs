#!/usr/bin/env node
// Collects eval/runs/<label>/<id>/r<N>/record.json and prints the tables of RESULTS.md as Markdown.
//   node eval/report.mjs [--label sdk] [--json]
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const RUNS = join(HERE, 'runs');
const argv = process.argv.slice(2);
const only = argv.includes('--label') ? argv[argv.indexOf('--label') + 1] : null;
const dirs = (d) => (existsSync(d) ? readdirSync(d).filter((f) => !f.startsWith('_') && statSync(join(d, f)).isDirectory()) : []);
const records = [];
for (const label of dirs(RUNS)) for (const id of dirs(join(RUNS, label))) for (const r of dirs(join(RUNS, label, id))) {
    const f = join(RUNS, label, id, r, 'record.json');
    if (existsSync(f) && (!only || only === label)) records.push(JSON.parse(readFileSync(f, 'utf8')));
}
if (argv.includes('--json')) { console.log(JSON.stringify(records)); process.exit(0); }

const mean = (v) => { const x = v.filter((n) => typeof n === 'number' && Number.isFinite(n)); return x.length ? x.reduce((a, b) => a + b, 0) / x.length : null; };
const range = (v) => { const x = v.filter((n) => typeof n === 'number'); return x.length ? [Math.min(...x), Math.max(...x)] : [null, null]; };
const pctl = (v, p) => { const x = v.filter((n) => typeof n === 'number').sort((a, b) => a - b); return x.length ? x[Math.min(x.length - 1, Math.ceil(p * x.length) - 1)] : null; };
const f = (v, d = 2) => (v === null || v === undefined ? '–' : (+v).toFixed(d));
const mr = (v, d = 2) => { const [a, b] = range(v); return a === null ? '–' : `${f(mean(v), d)} (${f(a, d)}–${f(b, d)})`; };
const row = (cells) => `| ${cells.join(' | ')} |`;
const table = (head, rows) => [row(head), row(head.map(() => '---')), ...rows.map(row)].join('\n');
const tok = (r) => (r.usage ? r.usage.inputTokens + r.usage.outputTokens + r.usage.cacheReadTokens + r.usage.cacheWriteTokens : null);
const cost5m = (r) => { // every cache write at the 5-minute rate — the basis of the prototypes' easy-set figures
    if (!r.usage?.byModel) return null;
    let c = 0;
    for (const [m, u] of Object.entries(r.usage.byModel)) { const p = m.includes('haiku') ? [0.1, 0.5, 0.01, 0.125] : m.includes('sonnet') ? [2, 10, 0.1, 2.5] : [4, 20, 0.2, 5]; c += u.input * p[0] + u.output * p[1] + u.cacheRead * p[2] + (u.cacheWrite5m + u.cacheWrite1h) * p[3]; }
    return c / 1e6;
};

const labels = [...new Set(records.map((r) => r.label))];
const groups = ['easy', 'photo', 'hard'];
console.log('## Per run\n');
console.log(table(['label', 'input', 'run', 'outcome', 'kept / cands', 'turns', 'wall s', 'model s', 'cost $', 'masked comp.', 'text exact', 'text IoU', 'face', 'size agr.', 'close', 'pict. P / R', 'unfinished'],
    records.sort((a, b) => a.label.localeCompare(b.label) || groups.indexOf(a.group) - groups.indexOf(b.group) || a.id.localeCompare(b.id) || a.run - b.run).map((r) => {
        const t = r.score?.canvas?.text, p = r.score?.pictures;
        return [r.label, r.id, r.run, r.outcome + (r.reason ? ` ${r.reason}` : ''), `${r.kept ?? '–'} / ${r.candidates.length}`, r.turns ?? '–', f(r.wallS, 0), f((r.stagesMs?.model ?? 0) / 1000, 0), f(r.costUsd, 3), f(r.score?.imageMasked?.composite, 3), f(t?.normalisedExactRate), f(t?.meanBoxIoU), f(t?.fontFaceExactRate), f(t?.fontSizeAgreement), f(r.score?.close?.all), p ? `${f(p.precision)} / ${f(p.recall)}` : '–', r.unfinished ? `yes (${r.stopReason})` : 'no'];
    })));

console.log('\n## Means per label and set\n');
const rows = [];
for (const label of labels) for (const g of groups) {
    const rs = records.filter((r) => r.label === label && r.group === g);
    if (!rs.length) continue;
    const ok = rs.filter((r) => r.outcome === 'proposed');
    const t = (k) => ok.map((r) => r.score?.canvas?.text?.[k]);
    rows.push([label, g, `${ok.length} / ${rs.length}`, ok.filter((r) => r.unfinished).length, mr(ok.map((r) => r.turns), 1), mr(ok.map((r) => r.candidates.length), 1), mr(ok.map((r) => r.wallS), 0), f(pctl(ok.map((r) => r.wallS), 0.95), 0), mr(ok.map((r) => r.costUsd), 3), f(pctl(ok.map((r) => r.costUsd), 0.95), 3), f(mean(ok.map(cost5m)), 3), f(mean(ok.map(tok)) / 1000, 0),
        f(mean(t('normalisedExactRate'))), f(mean(t('meanBoxIoU'))), f(mean(t('fontFaceExactRate'))), f(mean(t('fontSizeAgreement'))), f(mean(ok.map((r) => r.score?.close?.all))), f(mean(ok.map((r) => r.score?.imageMasked?.composite)), 3), `${f(mean(ok.map((r) => r.score?.pictures?.precision)))} / ${f(mean(ok.map((r) => r.score?.pictures?.recall)))}`]);
}
console.log(table(['label', 'set', 'proposed', 'unfinished', 'turns', 'candidates', 'wall s', 'p95 s', 'cost $', 'p95 $', 'cost $ (5-min cache basis)', 'k tokens', 'text exact', 'text IoU', 'face exact', 'size agr.', 'close texts', 'masked comp.', 'pictures P / R'], rows));

console.log('\n## Stages and resources (proposed runs)\n');
const srows = [];
for (const label of labels) for (const g of groups) {
    const ok = records.filter((r) => r.label === label && r.group === g && r.outcome === 'proposed');
    if (!ok.length) continue;
    const st = (k) => ok.map((r) => (r.stagesMs?.[k] ?? 0) / 1000);
    const res = (pick) => ok.map((r) => pick(r.resources ?? {}));
    srows.push([label, g, mr(ok.map((r) => (r.intake?.normaliseMs ?? 0) / 1000), 1), mr(ok.map((r) => (r.intake?.precheckMs ?? 0) / 1000), 1), mr(st('prepass'), 1), mr(st('model'), 0), mr(st('finish'), 1),
        f(mean(ok.map((r) => (r.apiS && r.wallS ? r.apiS / r.wallS : null))) * 100, 0) + ' %', mr(ok.map((r) => (r.engineFacts?.maxModelGapMs ?? 0) / 1000), 0),
        mr(res((x) => x.peakRssMb), 0), mr(res((x) => x.avgRssMb), 0), mr(res((x) => x.byProcess?.engine?.peakRssMb), 0), mr(res((x) => x.byProcess?.node?.peakRssMb), 0), mr(res((x) => x.byProcess?.ocr?.peakRssMb), 0), mr(res((x) => x.cpuSeconds), 1), mr(res((x) => x.peakCores), 1), mr(ok.map((r) => r.appRenders?.count), 1)]);
}
console.log(table(['label', 'set', 'normalise s', 'pre-check s', 'pre-pass s', 'model loop s', 'finish s', 'provider share of wall', 'longest silent turn s', 'peak RSS MB (tree)', 'avg RSS MB', 'engine peak MB', 'node peak MB', 'OCR peak MB', 'CPU-s', 'peak cores', 'app renders'], srows));

console.log('\n## Pre-check\n');
console.log(table(['label', 'set', 'verdicts', 'seconds', 'cost $'], labels.flatMap((label) => groups.map((g) => {
    const rs = records.filter((r) => r.label === label && r.group === g && r.intake?.verdict !== undefined && r.intake?.precheckMs);
    if (!rs.length) return null;
    const counts = rs.reduce((m, r) => ({ ...m, [r.intake.verdict ?? 'none']: (m[r.intake.verdict ?? 'none'] ?? 0) + 1 }), {});
    return [label, g, Object.entries(counts).map(([k, v]) => `${k} ${v}`).join(', '), mr(rs.map((r) => r.intake.precheckMs / 1000), 1), mr(rs.map((r) => r.intake.costUsd), 4)];
}).filter(Boolean))));

console.log('\n## Keep-best and fonts\n');
console.log(table(['label', 'set', 'runs with > 1 candidate', 'model preferred ≠ kept', 'kept ≠ last', 'decided by text fit', 'font groups', 'matched', 'face score, matched (mean)', 'face score, not matched (mean)'], labels.flatMap((label) => groups.map((g) => {
    const ok = records.filter((r) => r.label === label && r.group === g && r.outcome === 'proposed');
    if (!ok.length) return null;
    const multi = ok.filter((r) => r.candidates.length > 1);
    const fonts = ok.flatMap((r) => r.report?.fonts ?? []);
    return [label, g, `${multi.length} / ${ok.length}`, multi.filter((r) => r.keepBest?.agrees === false).length, multi.filter((r) => r.kept !== r.candidates.length).length, multi.filter((r) => r.keepBest?.by === 'text_fit').length, fonts.length, fonts.filter((x) => x.matched).length, f(mean(fonts.filter((x) => x.matched).map((x) => x.score))), f(mean(fonts.filter((x) => x.matched === false).map((x) => x.score)))];
}).filter(Boolean))));
