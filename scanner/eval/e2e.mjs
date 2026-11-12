#!/usr/bin/env node
// End-to-end runs through the REAL app job core: every scan is started through the app's own upload
// endpoint (a logged-in session), the running scanner service claims and finishes it, and what the app
// stored — row, usage rows, ledger lines, proposal, report, the kept render — is written to a private
// directory (eval/runs/_e2e/, git-ignored).
//
//   WBOOST_LOGIN_FILE=… node eval/e2e.mjs --project <id> <inputId | picture> [...]   (input ids: eval/sets.json)
// The scanner service must be running against the same app (`node bin/scanner.mjs serve`).
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { AppUser } from '../dev/app-user.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const args = process.argv.slice(2);
const opt = (name, fallback) => { const i = args.indexOf(`--${name}`); return i >= 0 ? args[i + 1] : fallback; };
const project = opt('project', process.env.PROJECT ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15');
const container = opt('container', process.env.SCANNER_DEV_CONTAINER ?? 'wboost-web-1');
const outRoot = resolve(opt('out', join(HERE, 'runs', '_e2e')));
const inputs = args.filter((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--')));
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const known = new Map(['easy', 'photo', 'hard'].flatMap((s) => sets[s].map((x) => [x.id, { ...x, set: s }])));

/** scanner/dev/scan.php inside the app's container. */
export function appShow(scanId, command = 'show', { container: c = container } = {}) {
    const out = execFileSync('docker', ['exec', c, 'php', '/app/scanner/dev/scan.php', command, scanId], { maxBuffer: 256 << 20 });
    return command === 'image' ? out : JSON.parse(out.toString());
}

/** What one scan left in the app, in numbers: for the tables. */
export function digest(show) {
    const s = show.scan, usage = show.usage, ledger = show.ledger;
    const sum = (k) => usage.reduce((a, u) => a + Number(u[k] ?? 0), 0);
    return {
        scan: s.id, status: s.status, code: s.error_code, try: s.try, run: s.run, intakeTries: s.intake_tries, unfinished: s.unfinished, kept: s.kept_candidate, candidates: s.candidates,
        usageRows: usage.map((u) => `${u.step}#${u.run} ${u.model ?? '–'} ${u.outcome ?? 'OPEN'}${u.error_code ? `/${u.error_code}` : ''} $${(u.cost_micro_usd / 1e6).toFixed(4)}`),
        openUsageRows: usage.filter((u) => !u.closed_at).length,
        costUsd: +(sum('cost_micro_usd') / 1e6).toFixed(4), engineCostUsd: +(sum('engine_cost_micro_usd') / 1e6).toFixed(4), scanCostUsd: +(s.cost_micro_usd / 1e6).toFixed(4),
        tokens: { in: sum('input_tokens'), out: sum('output_tokens'), cacheRead: sum('cache_read_tokens'), cacheWrite: sum('cache_write_tokens') },
        // the scan row's totals are the sums of its usage rows
        consistent: Number(s.cost_micro_usd) === sum('cost_micro_usd') && Number(s.input_tokens) === sum('input_tokens') && Number(s.output_tokens) === sum('output_tokens'),
        ledger: ledger.map((l) => `${l.reason}${l.delta > 0 ? '+' : ''}${l.delta}@try${l.try}`), credit: show.credit,
        events: (s.events ?? []).map((e) => e.type).join(' → '),
    };
}

if (import.meta.url === `file://${process.argv[1]}`) {
    const user = await new AppUser().login();
    const rows = [];
    for (const input of inputs) {
        const k = known.get(input);
        const picture = k ? join(ROOT, k.image) : resolve(input);
        if (!existsSync(picture)) { console.error(`no such picture: ${picture}`); continue; }
        const name = k?.id ?? basename(picture).replace(/\.[^.]+$/, '');
        const t0 = Date.now();
        const started = await user.start(project, picture);
        if (!started.ok) { console.error(`${name}: the app refused the upload: ${started.code}`); rows.push({ name, refused: started.code }); continue; }
        const id = started.scan.id;
        const timeline = [];
        const end = await user.wait(id, { timeoutS: 900, onChange: (s) => { timeline.push({ t: +((Date.now() - t0) / 1000).toFixed(1), status: s.status, stage: s.stage ?? null, note: s.note ?? null, round: s.round ?? null, waiting: s.waiting ?? null }); console.error(`  ${name} +${timeline.at(-1).t}s ${s.status} ${s.stage ?? ''} ${s.note ?? ''}`); } });
        const wallS = +((Date.now() - t0) / 1000).toFixed(1);
        const dir = join(outRoot, `${name}-${id.slice(0, 8)}`); mkdirSync(dir, { recursive: true });
        const show = appShow(id);
        writeFileSync(join(dir, 'show.json'), JSON.stringify(show, null, 1));
        writeFileSync(join(dir, 'timeline.json'), JSON.stringify(timeline, null, 1));
        if (show.scan.has_proposal) { writeFileSync(join(dir, 'proposal.json'), JSON.stringify(appShow(id, 'proposal'), null, 1)); try { writeFileSync(join(dir, 'render.webp'), appShow(id, 'image')); } catch { /* no kept render */ } }
        const d = { name, set: k?.set ?? null, wallS, ...digest(show), stages: timeline.filter((x) => x.status === 'running').map((x) => x.stage).filter((x, i, a) => x && a.indexOf(x) === i).join(' → ') };
        if (show.scan.has_proposal) {
            const p = JSON.parse(readFileSync(join(dir, 'proposal.json'), 'utf8')).scan;
            const fonts = p.report?.fonts ?? [];
            d.report = { texts: p.report?.counts?.texts ?? null, places: p.report?.placeholders?.length ?? null, fonts: fonts.map((f) => `${f.pattern}${f.verified ? `(${f.verifiedScore})` : ''}`), fontVerification: p.report?.measures?.fontVerification ?? null, elements: p.proposal?.design?.elements?.length ?? p.proposal?.elements?.length ?? null };
        }
        writeFileSync(join(dir, 'digest.json'), JSON.stringify(d, null, 1));
        rows.push(d);
        console.log(JSON.stringify(d));
    }
    writeFileSync(join(outRoot, `summary-${new Date().toISOString().replace(/[:.]/g, '-')}.json`), JSON.stringify(rows, null, 1));
    process.exit(0);
}
