#!/usr/bin/env node
// Calendar scans, measured: every input goes through the REAL app job core (upload → intake → build by
// the running scanner service → proposal), then the PROPOSAL — the layout the app stored — is scored
// with the calendar prototype's scorer: key by key against the ground truth (synthetic inputs) and as
// a picture (the layout applied to a scratch calendar that holds the input's content, rendered, compared
// with the source outside the picture boxes).
//
//   WBOOST_LOGIN_FILE=… CALENDAR_PROTO_DIR=<…/docs/ai-scan/prototypes/calendar> \
//     node eval/calendar.mjs --calendar <calendarId> [--project <id>] [--out <dir>] [--no-score] <input …>
//   node eval/calendar.mjs --table [--out <dir>]            the tables from the stored digests
//
// `<input>` = a name in the prototype's inputs/ (`<name>.png|jpg` + `<name>.content.json` + optional
// `<name>.truth.json`). The prototype directory is local, git-ignored material: nothing here imports it
// unless CALENDAR_PROTO_DIR points at it. `--calendar` = a 7-day calendar of the project the scans are
// made for (it is never changed: a scan only proposes). The scanner service must be running against the
// same app (`node bin/scanner.mjs serve`), on the developer's plan — never on the API key.
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { AppUser } from '../dev/app-user.mjs';
import { appShow, digest } from './e2e.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const opt = (name, fallback) => { const i = args.indexOf(`--${name}`); return i >= 0 ? args[i + 1] : fallback; };
const flag = (name) => args.includes(`--${name}`);
const outRoot = resolve(opt('out', join(HERE, 'runs', '_calendar')));
const inputs = args.filter((a, i) => !a.startsWith('--') && !(i > 0 && ['--calendar', '--project', '--out'].includes(args[i - 1])));
const FEATURES = ['captions', 'weekNumbers', 'moon', 'timeChange', 'seasons', 'waste', 'events', 'shifts', 'specialPages', 'qr'];
const mean = (a) => { const v = a.filter((x) => x !== null && x !== undefined && Number.isFinite(x)); return v.length ? v.reduce((s, x) => s + x, 0) / v.length : null; };
const f = (v, d = 2) => (v === null || v === undefined ? '–' : Number(v).toFixed(d));

function table() {
    const rows = existsSync(outRoot) ? readdirSync(outRoot).map((d) => join(outRoot, d, 'digest.json')).filter(existsSync).map((p) => JSON.parse(readFileSync(p, 'utf8'))) : [];
    // the latest run of every input
    const latest = new Map(); for (const r of rows.sort((a, b) => String(a.at).localeCompare(String(b.at)))) latest.set(r.name, r);
    const all = [...latest.values()].sort((a, b) => a.name.localeCompare(b.name));
    const head = ['input', 'status', 'wall s', 'build s', 'turns', 'cands', 'cost $', 'box IoU', 'frame err mm', 'anchor err mm', 'type err pt', 'ΔE00', 'categorical', 'shown', 'face exact', 'family', 'masked edge F1', 'masked MAE', 'wrong'];
    const line = (r) => { const L = r.score?.layout, I = r.score?.image; return [r.name, r.status + (r.unfinished ? ' (unfinished)' : '') + (r.code ? ` ${r.code}` : ''), f(r.wallS, 0), f(r.buildS, 0), r.turns ?? '–', r.candidates ?? '–', f(r.costUsd, 3), f(L?.boxIoU, 3), f(L?.boxEdgeErrMm), f(L?.anchorErrMm), f(L?.sizeErrPt), f(L?.colourDeltaE, 1), L ? `${Math.round(L.categoricalAcc * 100)} %` : '–', L ? `${Math.round(L.shownAcc * 100)} %` : '–', L ? `${Math.round(L.fontExactAcc * 100)} %` : '–', L ? `${Math.round(L.fontFamilyAcc * 100)} %` : '–', f(I?.maskedEdgeF1, 3), f(I?.maskedMae, 3), [...(L?.categoricalWrong ?? []), ...(L?.shownWrong ?? [])].join(' ')]; };
    const out = [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...all.map((r) => `| ${line(r).join(' | ')} |`)];
    const truth = all.filter((r) => r.score?.layout);
    if (truth.length) {
        const L = (k) => mean(truth.map((r) => r.score.layout[k])), I = (k) => mean(truth.map((r) => r.score.image[k]));
        out.push(`| **mean of ${truth.length} with truth** | | ${f(mean(truth.map((r) => r.wallS)), 0)} | ${f(mean(truth.map((r) => r.buildS)), 0)} | ${f(mean(truth.map((r) => r.turns)), 1)} | ${f(mean(truth.map((r) => r.candidates)), 1)} | ${f(mean(truth.map((r) => r.costUsd)), 3)} | ${f(L('boxIoU'), 3)} | ${f(L('boxEdgeErrMm'))} | ${f(L('anchorErrMm'))} | ${f(L('sizeErrPt'))} | ${f(L('colourDeltaE'), 1)} | ${Math.round(L('categoricalAcc') * 100)} % | ${Math.round(L('shownAcc') * 100)} % | ${Math.round(L('fontExactAcc') * 100)} % | ${Math.round(L('fontFamilyAcc') * 100)} % | ${f(I('maskedEdgeF1'), 3)} | ${f(I('maskedMae'), 3)} | |`);
    }
    return out.join('\n');
}

if (flag('table')) { console.log(table()); process.exit(0); }

const calendarId = opt('calendar', null);
const project = opt('project', process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15');
const protoDir = process.env.CALENDAR_PROTO_DIR ? resolve(process.env.CALENDAR_PROTO_DIR) : null;
if (!calendarId || !inputs.length) { console.error('usage: calendar.mjs --calendar <calendarId> [--project <id>] [--no-score] <input …>   |   calendar.mjs --table'); process.exit(2); }
if (!protoDir || !existsSync(join(protoDir, 'lib', 'score.mjs'))) { console.error('set CALENDAR_PROTO_DIR to the calendar prototype directory (inputs/, lib/score.mjs) — local material, not in the repository'); process.exit(2); }
const proto = async (file) => import(pathToFileURL(join(protoDir, file)).href);
const { loadInput, scratchCalendar, finish, pagePicture } = await proto('lib/run.mjs');
const { scoreDir } = await proto('score.mjs');

const user = await new AppUser().login();
for (const name of inputs) {
    const input = loadInput(name);
    const t0 = Date.now();
    const started = await user.start(project, input.image, { kind: 'calendar', calendarId });
    if (!started.ok) { console.error(`${name}: the app refused the upload: ${started.code}`); continue; }
    const id = started.scan.id;
    const timeline = [];
    await user.wait(id, { timeoutS: 900, onChange: (s) => { timeline.push({ t: +((Date.now() - t0) / 1000).toFixed(1), status: s.status, stage: s.stage ?? null, note: s.note ?? null }); console.error(`  ${name} +${timeline.at(-1).t}s ${s.status} ${s.stage ?? ''} ${s.note ?? ''}`); } });
    const wallS = +((Date.now() - t0) / 1000).toFixed(1);
    const dir = join(outRoot, `${name}-${id.slice(0, 8)}`); mkdirSync(dir, { recursive: true });
    const show = appShow(id);
    writeFileSync(join(dir, 'show.json'), JSON.stringify(show, null, 1));
    writeFileSync(join(dir, 'timeline.json'), JSON.stringify(timeline, null, 1));
    const builder = show.usage.filter((u) => u.step === 'builder');
    const d = { name, at: new Date().toISOString(), wallS, buildS: +(builder.reduce((a, u) => a + Number(u.duration_ms ?? 0), 0) / 1000).toFixed(1), turns: builder.reduce((a, u) => a + Number(u.turns ?? 0), 0), ...digest(show) };
    if (show.scan.has_proposal) {
        const p = appShow(id, 'proposal').scan;
        writeFileSync(join(dir, 'proposal.json'), JSON.stringify(p, null, 1));
        try { writeFileSync(join(dir, 'kept.webp'), appShow(id, 'image')); } catch { /* no kept render */ }
        const doc = p.proposal.document, report = p.report;
        const layout = JSON.parse(doc.layoutJson);
        d.report = { pageBox: report.pageBox, recognised: Object.fromEntries(report.recognised.map((r) => [r.element, r.state])), needsUpload: report.needsUpload.map((n) => n.slot), featuresOffered: report.featuresOffered.map((x) => x.key), fonts: report.fonts.map((x) => `${x.group}:${x.pattern}`), notExpressible: report.notExpressible.map((n) => n.code), notes: report.notes.map((n) => n.code), measures: report.measures };
        if (!flag('no-score')) {
            // the proposal as the prototype's candidate: the scanned calendar has every switch off, so what is on is what the scan offered
            const candidate = { type: 'week', preset: doc.preset, features: Object.fromEntries(FEATURES.map((k) => [k, doc.featuresOn.includes(k)])), boxes: layout.boxes, order: layout.order, styles: layout.styles, needsUpload: report.needsUpload.map((n) => ({ what: n.slot === 'header' ? 'headerImage' : n.slot })) };
            try {
                const [x, y, w, h] = report.pageBox;
                const pagePng = await pagePicture(input.image, { x, y, w, h });
                const scratch = await scratchCalendar(`p5 ${name}`, input.content, 'week');
                const built = await finish(dir, scratch.calendarId, scratch.page, candidate, pagePng);
                if (!built.ok) d.scoreError = `${built.reason ?? 'refused'}: ${String(built.error).slice(0, 200)}`;
                else { const s = await scoreDir(name, dir); d.score = { image: s.image, layout: s.layout?.summary ?? null }; writeFileSync(join(dir, 'score.json'), JSON.stringify(s, null, 1)); }
            } catch (e) { d.scoreError = String(e?.message ?? e).slice(0, 300); }
        }
    }
    writeFileSync(join(dir, 'digest.json'), JSON.stringify(d, null, 1));
    console.log(JSON.stringify({ name, status: d.status, code: d.code, wallS: d.wallS, buildS: d.buildS, turns: d.turns, costUsd: d.costUsd, score: d.score ? { ...(d.score.layout ?? {}), maskedEdgeF1: d.score.image.maskedEdgeF1, maskedMae: d.score.image.maskedMae } : (d.scoreError ?? null) }));
}
console.log(table());
process.exit(0);
