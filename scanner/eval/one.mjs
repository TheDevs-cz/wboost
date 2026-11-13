#!/usr/bin/env node
// One real scan through the app's job core, with the options the other harnesses do not take: a calendar
// (`--calendar <id>`), another project, "Zkusit přesto" after a continuable stop (`--continue`). Prints what
// the app stored as one JSON line and keeps it under eval/runs/_one/<name>-<scan>/.
//   WBOOST_LOGIN_FILE=… node eval/one.mjs --project <id> [--calendar <id>] [--continue] [--name <label>] <picture | pdf>
// The scanner service must be running against the same app.
import { writeFileSync, mkdirSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { AppUser } from '../dev/app-user.mjs';
import { appShow, digest } from './e2e.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const opt = (name, fallback = null) => { const i = args.indexOf(`--${name}`); return i >= 0 ? args[i + 1] : fallback; };
const flag = (name) => args.includes(`--${name}`);
const file = resolve(args.filter((a, i) => !a.startsWith('--') && !(i > 0 && ['--project', '--calendar', '--name'].includes(args[i - 1])))[0]);
const project = opt('project', process.env.PROJECT), calendarId = opt('calendar');
const name = opt('name', basename(file).replace(/\.[^.]+$/, ''));
const user = await new AppUser().login();
const t0 = Date.now();
const started = await user.start(project, file, { kind: calendarId ? 'calendar' : 'template', calendarId });
if (!started.ok) { console.log(JSON.stringify({ name, refused: started.code, params: started.params ?? null })); process.exit(0); }
const id = started.scan.id;
const timeline = [];
const onChange = (s) => { timeline.push({ t: +((Date.now() - t0) / 1000).toFixed(1), status: s.status, stage: s.stage ?? null, note: s.note ?? null, code: s.code ?? null }); };
let end = await user.wait(id, { timeoutS: 900, onChange });
let continued = null;
const scanOf = (answer) => answer.scan ?? answer;
if (flag('continue') && scanOf(end).status === 'stopped') {
    continued = { stoppedFor: scanOf(end).code ?? scanOf(end).errorCode ?? null, at: +((Date.now() - t0) / 1000).toFixed(1) };
    const again = await user.act(id, 'continue');
    continued.accepted = !!again.ok; continued.code = again.code ?? null;
    if (again.ok) end = await user.wait(id, { timeoutS: 900, onChange });
}
const show = appShow(id);
const dir = join(HERE, 'runs', '_one', `${name}-${id.slice(0, 8)}`); mkdirSync(dir, { recursive: true });
writeFileSync(join(dir, 'show.json'), JSON.stringify(show, null, 1));
writeFileSync(join(dir, 'timeline.json'), JSON.stringify(timeline, null, 1));
let report = null;
if (show.scan.has_proposal) {
    const proposal = appShow(id, 'proposal');
    writeFileSync(join(dir, 'proposal.json'), JSON.stringify(proposal, null, 1));
    try { writeFileSync(join(dir, 'render.webp'), appShow(id, 'image')); } catch { /* no kept render */ }
    const r = proposal.scan?.report ?? {};
    report = { texts: r.counts?.texts ?? null, places: r.placeholders?.length ?? null, fonts: (r.fonts ?? []).map((f) => `${f.group}:${f.pattern}${f.verifiedScore != null ? `(${f.verifiedScore})` : ''}`), notes: (r.notes ?? []).map((n) => n.code ?? n), recognised: r.recognised ?? null, approximate: show.scan.approximate ?? null };
}
const out = { name, at: new Date().toISOString(), wallS: +((Date.now() - t0) / 1000).toFixed(1), continued, ...digest(show), sourceMeta: show.scan.source_meta ?? null, preCheck: show.scan.pre_check ?? null, stages: timeline.filter((x) => x.status === 'running').map((x) => x.stage).filter((x, i, a) => x && a.indexOf(x) === i).join(' → '), report };
writeFileSync(join(dir, 'digest.json'), JSON.stringify(out, null, 1));
console.log(JSON.stringify(out));
process.exit(0);
