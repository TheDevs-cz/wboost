#!/usr/bin/env node
// G2a — the reliability gate that runs locally (docs/ai-scan/architecture.md §10 "Gates"; the evidence is
// docs/ai-scan/reliability-report.md). Every command drives the REAL app (its own upload endpoint, a logged-in
// session), the REAL scanner service (`bin/scanner.mjs serve` as a process of its own, the real engine binary,
// the real instruments, the app's real renders) and — unless `--real` — a FAKE provider that this script owns:
// the model's answers are the recorded tool calls of an earlier scan of the same picture, or an injected fault.
//
//   WBOOST_LOGIN_FILE=… node eval/g2a.mjs <command> [options]
//
//   soak   [--scans 100] [--inputs a,b,c] [--think-ms 300]   N scans back to back; RSS of scanner / web / Gotenberg, leftovers
//   faults [name …]                                          provider faults through the engine: classification, state, ledger, probe
//   queue                                                    8 waiting, a 9th refused, a poison input, cancels in both phases, 20 tabs polling
//   limits                                                   daily starts, retry bounds, monthly limit, ceilings: an MCP client in a loop + the web
//   kill   [--input id]                                      REAL model: the scanner killed mid-build twice — is the spend recorded?
//   orphans [--rounds 3]                                     the scanner SIGKILLed mid-build: how long its processes live, do they still call the provider
//   idle   [--minutes 45] [--real]                           the scanner sits idle, then a scan
//   audit                                                    stuck rows, open usage rows, objects without a row, scratch
//
// Environment: WBOOST_APP_URL (http://localhost:8099), SCANNER_DEV_CONTAINER (wboost-web-1), SCANNER_TOKEN,
// G2A_DB (the app's database name, for read-only checks through the postgres container), G2A_BUCKET
// (the private bucket), G2A_PROJECTS (comma-separated project ids with fonts; the first is the default).
// Everything it writes goes to eval/runs/_g2a/ (git-ignored).
import { spawn, execFileSync } from 'node:child_process';
import { createWriteStream, mkdirSync, readFileSync, writeFileSync, readdirSync, existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import sharp from 'sharp';
import { AppUser } from '../dev/app-user.mjs';
import { digest } from './e2e.mjs';
import { startFakeProvider, planFromRecording } from './fake-provider.mjs';
import { LOCAL_STAND_IN } from '../src/drivers/file-job.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');
const APP = (process.env.WBOOST_APP_URL ?? 'http://localhost:8099').replace(/\/+$/, '');
const CONTAINER = process.env.SCANNER_DEV_CONTAINER ?? 'wboost-web-1';
const TOKEN = process.env.SCANNER_TOKEN ?? 'dev-scanner-token-not-a-secret-0000';
const DB = process.env.G2A_DB ?? 'wboost';
const BUCKET = process.env.G2A_BUCKET ?? 'wboost-private';
const PROJECTS = (process.env.G2A_PROJECTS ?? '01a1cf47-d5e5-71a3-a917-efe29965cc15,01a1cf52-7f0f-71d1-bb42-90a16ae6ee6e').split(',');
const STAND_IN = '00000000-0000-4000-8000-0000000a15ca';
const OUT = join(HERE, 'runs', '_g2a'); mkdirSync(OUT, { recursive: true });
const END = ['ready', 'failed', 'stopped', 'refused', 'cancelled'];
const sets = JSON.parse(readFileSync(join(HERE, 'sets.json'), 'utf8'));
const pictureOf = (id) => join(ROOT, [...sets.easy, ...sets.photo, ...sets.hard].find((x) => x.id === id).image);
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
const argv = process.argv.slice(2);
const opt = (name, fallback) => { const i = argv.indexOf(`--${name}`); return i >= 0 ? (argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : true) : fallback; };
const stamp = () => new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const say = (...a) => console.error(new Date().toISOString().slice(11, 19), ...a);
const mb = (kb) => Math.round(kb / 1024);

// ---- the app, from outside ---------------------------------------------------------------------------------

/** A read-only query on the app's database (through the postgres container). → rows of strings */
function sql(query) {
    const out = execFileSync('docker', ['exec', process.env.G2A_PG_CONTAINER ?? 'wboost-postgres-1', 'psql', '-U', 'postgres', '-d', DB, '-At', '-F', '\t', '-c', query], { maxBuffer: 64 << 20 }).toString();
    return out.split('\n').filter(Boolean).map((l) => l.split('\t'));
}
const one = (query) => sql(query)[0]?.[0] ?? null;
/** `bin/console app:ai-scan:status …` in the app's container. */
function consoleStatus(...options) { return execFileSync('docker', ['exec', CONTAINER, 'bin/console', 'app:ai-scan:status', ...options], { maxBuffer: 16 << 20 }).toString(); }
/** The runtime row in one line. */
const runtime = () => { const r = sql('SELECT mode, provider_state, consecutive_failures, COALESCE(failure_class, \'\'), max_running, daily_ceiling_micro_usd, project_daily_micro_usd, daily_starts_user, daily_starts_project FROM ai_scan_runtime WHERE id = 1')[0]; return { mode: r[0], provider: r[1], consecutiveFailures: +r[2], failureClass: r[3] || null, maxRunning: +r[4], dailyCeilingUsd: r[5] / 1e6, projectCeilingUsd: r[6] / 1e6, startsUser: +r[7], startsProject: +r[8] }; };
/** Memory of a container, MB (cgroup `memory.current` minus the page cache it could give back). */
function containerMb(name) {
    try {
        const stat = execFileSync('docker', ['exec', name, 'sh', '-c', 'cat /sys/fs/cgroup/memory.current; grep -E "^(inactive_file|active_file) " /sys/fs/cgroup/memory.stat'], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim().split('\n');
        const current = Number(stat[0]); const file = stat.slice(1).reduce((a, l) => a + Number(l.split(' ')[1]), 0);
        return { mb: Math.round(current / 1048576), anonMb: Math.round((current - file) / 1048576) };
    } catch { return { mb: null, anonMb: null }; }
}
/** What a scan left in the app (dev/scan.php in the container — a development reader, so it runs as `dev` even when the app under test runs as `prod`). */
const stored = (id) => digest(JSON.parse(execFileSync('docker', ['exec', '-e', 'APP_ENV=dev', '-e', 'APP_DEBUG=1', '-e', 'XDEBUG_MODE=off', CONTAINER, 'php', '/app/scanner/dev/scan.php', 'show', id], { maxBuffer: 256 << 20 }).toString()));
/** Objects of the private bucket under ai-scan/, by scan id. */
function bucketObjects() {
    let out = '';
    try { out = execFileSync('docker', ['exec', process.env.G2A_MINIO_CONTAINER ?? 'wboost-minio-1', 'sh', '-c', `mc alias set g2a http://localhost:9000 wboost wboostminio >/dev/null 2>&1; mc ls -r g2a/${BUCKET}/ai-scan/ 2>/dev/null || true`], { maxBuffer: 256 << 20 }).toString(); } catch { /* an empty bucket */ }
    const byScan = new Map();
    for (const line of out.split('\n')) { const m = /\s(\S+)$/.exec(line.trim()); if (!m) continue; const [project, scan, , file] = m[1].split('/'); if (!file) continue; if (!byScan.has(scan)) byScan.set(scan, { project, files: [] }); byScan.get(scan).files.push(file); }
    return byScan;
}

// ---- the provider this script owns -------------------------------------------------------------------------

/** The recorded tool calls of a scan of `input` (eval/runs/sdk/<input>/r1), as a plan indexed by the builder's turn. */
function recording(input) {
    const rec = join(HERE, 'runs', 'sdk', input, 'r1');
    const tools = readFileSync(join(rec, 'tools.jsonl'), 'utf8').split(LOCAL_STAND_IN).join(STAND_IN).trim().split('\n').map((l) => JSON.parse(l));
    const run = JSON.parse(readFileSync(join(rec, 'run.json'), 'utf8'));
    return planFromRecording(tools, run.build?.modelFinish);
}

/**
 * A provider that answers by what a request is: the pre-check (the small model, with its structured-output tool)
 * gets "design"; a builder request gets the step of the recording that belongs to its turn — the number of
 * assistant turns it carries — so any number of scans can follow each other. `fault` overrides either:
 *   fault.preCheck / fault.builder   a step for every such request ({status, type, message} | {stall} | {refusal})
 *   fault.all                        a step for EVERY request, the 1-token probe included
 *   fault.probe                      a step for requests without tools (the probe)
 *   fault.builderOnce(n)             that step for the first n builder requests only
 */
async function provider({ thinkMs = 0, usage = true } = {}) {
    const p = { current: null, plans: new Map(), fault: {}, counts: { preCheck: 0, builder: 0, other: 0, faulted: 0 } };
    // What a turn "cost": input + a 1-hour cache write + cache reads, so that the app's pricing of the split is exercised.
    const turnUsage = (turn) => (usage ? { input_tokens: 900, cache_creation_input_tokens: turn === 0 ? 6000 : 1500, cache_creation: { ephemeral_1h_input_tokens: turn === 0 ? 6000 : 1500, ephemeral_5m_input_tokens: 0 }, cache_read_input_tokens: turn === 0 ? 0 : 6000 + 1500 * (turn - 1) } : {});
    const fake = await startFakeProvider({
        latencyMs: thinkMs,
        stepFor: (j, rec) => {
            if (!String(rec.url).includes('/v1/messages') || String(rec.url).includes('count_tokens')) return undefined;
            const small = /haiku/.test(String(j.model ?? ''));
            const turn = (j.messages ?? []).filter((m) => m.role === 'assistant').length;
            if (p.fault.all) { p.counts.faulted++; return p.fault.all; }
            if (rec.tools === 0) { p.counts.other++; if (p.fault.probe) { p.counts.faulted++; return p.fault.probe; } return undefined; } // the probe, a helper request
            if (small) {
                p.counts.preCheck++;
                if (p.fault.preCheck) { p.counts.faulted++; return p.fault.preCheck; }
                return { final: { verdict: 'design', confidence: 'high' } };
            }
            p.counts.builder++;
            if (p.fault.builder && (p.fault.builderTimes === undefined || p.fault.builderTimes-- > 0)) { p.counts.faulted++; return p.fault.builder; }
            const plan = p.plans.get(p.current);
            if (!plan) return { final: { status: 'done', preferredCandidate: 1, headline: '', placeholders: [], fonts: [], approximations: [], omitted: [], lowConfidenceTexts: [] } };
            return { ...plan[Math.min(turn, plan.length - 1)], usage: turnUsage(turn) };
        },
    });
    p.url = fake.url; p.close = fake.close; p.requests = fake.state.requests;
    p.use = (input) => { if (!p.plans.has(input)) p.plans.set(input, recording(input)); p.current = input; };
    return p;
}

// ---- the scanner service as a process of its own -----------------------------------------------------------

function startScanner(name, { providerUrl = null, real = false, concurrency = 1, extraEnv = {} } = {}) {
    const scratch = mkdtempSync(join(tmpdir(), 'g2a-scratch-'));
    const env = { ...process.env, SCANNER_APP_URL: APP, SCANNER_TOKEN: TOKEN, SCANNER_LOG: 'json', SCANNER_HEALTH_PORT: '0', SCANNER_SCRATCH_DIR: scratch, SCANNER_CONCURRENCY: String(concurrency), ...extraEnv };
    delete env.ANTHROPIC_AUTH_TOKEN;
    if (real) { delete env.ANTHROPIC_API_KEY; delete env.ANTHROPIC_BASE_URL; } // the owner's plan — the rule for development
    else { env.ANTHROPIC_API_KEY = 'sk-ant-g2a-not-a-key'; env.ANTHROPIC_BASE_URL = providerUrl; }
    const child = spawn(process.execPath, [join(HERE, '..', 'bin', 'scanner.mjs'), 'serve'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    const logFile = join(OUT, `${name}.scanner.log`);
    const file = createWriteStream(logFile, { flags: 'a' });
    const logs = []; let buf = '';
    child.stdout.on('data', (d) => { file.write(d); buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { try { logs.push({ at: Date.now(), ...JSON.parse(buf.slice(0, i)) }); } catch { /* not JSON */ } buf = buf.slice(i + 1); } if (logs.length > 20000) logs.splice(0, 10000); });
    child.stderr.on('data', (d) => file.write(d));
    const exited = new Promise((ok) => child.on('exit', (code, signal) => ok({ code, signal })));
    const s = {
        child, pid: child.pid, logs, logFile, scratch, exited,
        untilLog: async (test, { since = 0, timeoutS = 120 } = {}) => { for (let i = 0; i < timeoutS * 10; i++) { const l = logs.find((x) => x.at >= since && test(x)); if (l) return l; await sleep(100); } return null; },
        stop: async (signal = 'SIGTERM') => { const t0 = Date.now(); child.kill(signal); const r = await exited; return { ...r, stopMs: Date.now() - t0 }; },
        /** The scanner's process tree: [{pid, ppid, rssKb, command}] — the service itself first. */
        tree: () => {
            const all = execFileSync('ps', ['-axo', 'pid=,ppid=,rss=,comm='], { maxBuffer: 16 << 20 }).toString().split('\n').map((l) => /^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/.exec(l)).filter(Boolean).map((m) => ({ pid: +m[1], ppid: +m[2], rssKb: +m[3], command: m[4].split('/').pop() }));
            const out = []; const take = (pid) => { const p = all.find((x) => x.pid === pid); if (!p) return; out.push(p); for (const c of all.filter((x) => x.ppid === pid)) take(c.pid); };
            take(child.pid); return out;
        },
        scratchEntries: () => { try { return readdirSync(scratch).length; } catch { return 0; } },
    };
    return s;
}
async function scannerUp(name, options) {
    const s = startScanner(name, options);
    if (!(await s.untilLog((l) => l.msg === 'scanner started', { timeoutS: 60 }))) throw new Error(`the scanner did not start — see ${s.logFile}`);
    return s;
}

// ---- one scan as the user sees it --------------------------------------------------------------------------

async function scan(user, picture, { project = PROJECTS[0], timeoutS = 900, until = END, onChange = null } = {}) {
    const t0 = Date.now();
    const started = await user.start(project, picture);
    if (!started.ok) return { refused: started.code, http: started.http, body: started };
    const id = started.scan.id; const timeline = [];
    const end = await user.wait(id, { until, timeoutS, onChange: (s) => { timeline.push({ t: +((Date.now() - t0) / 1000).toFixed(1), status: s.status, stage: s.stage ?? null, waiting: s.waiting ?? null, restarted: s.restarted ?? null, code: s.code ?? null }); onChange?.(s, id); } });
    const s = end.scan ?? end;
    return { id, status: s.status, code: s.code ?? null, wallS: +((Date.now() - t0) / 1000).toFixed(1), timeline, timedOut: !!end.timedOut };
}
/** A picture nobody uploaded before (its own sha256): the synthetic design with a random tint. */
async function freshPicture(name = 'fresh') {
    const tint = Math.floor(Math.random() * 0xffffff).toString(16).padStart(6, '0');
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1350"><rect width="1080" height="1350" fill="#f4f1ea"/><rect x="80" y="90" width="920" height="420" fill="#${tint}"/><text x="120" y="330" font-family="Arial" font-size="96" fill="#ffffff">Letní kemp</text><text x="120" y="700" font-family="Arial" font-size="48" fill="#222222">Přihlášky do 30. 6. · ${tint}</text></svg>`;
    const file = join(mkdtempSync(join(tmpdir(), 'g2a-pic-')), `${name}-${tint}.png`);
    writeFileSync(file, await sharp(Buffer.from(svg)).png().toBuffer());
    return file;
}

// ---- audit: what must not be left behind -------------------------------------------------------------------

function audit({ since = null, scanner = null } = {}) {
    const inProgress = sql("SELECT status, COUNT(*) FROM ai_scan WHERE status IN ('checking', 'queued', 'running') GROUP BY status");
    const openUsage = +one('SELECT COUNT(*) FROM ai_usage_event WHERE closed_at IS NULL');
    const unclosedLedger = +one("SELECT COUNT(*) FROM (SELECT scan_id, try FROM ai_credit_ledger GROUP BY scan_id, try HAVING COUNT(*) FILTER (WHERE reason = 'reserve') = 1 AND COUNT(*) FILTER (WHERE reason IN ('settle', 'refund')) <> 1) x JOIN ai_scan s ON s.id = x.scan_id WHERE s.status NOT IN ('checking', 'queued', 'running')");
    const doubleLedger = +one("SELECT COUNT(*) FROM (SELECT scan_id, try FROM ai_credit_ledger WHERE reason IN ('settle', 'refund') GROUP BY scan_id, try HAVING COUNT(*) > 1) x");
    const rows = new Map(sql(`SELECT id, status, COALESCE(kept_candidate, 0) FROM ai_scan${since ? ` WHERE created_at >= '${since}'` : ''}`).map((r) => [r[0], { status: r[1], kept: +r[2] }]));
    const allRows = new Set(sql('SELECT id FROM ai_scan').map((r) => r[0]));
    const objects = bucketObjects();
    const orphanObjects = [...objects.keys()].filter((id) => !allRows.has(id));
    // of the scans of this run: what each one keeps in the bucket
    const kept = {}; let extraCandidates = 0, missingKept = 0;
    for (const [id, row] of rows) {
        const files = objects.get(id)?.files ?? [];
        const shape = files.map((f) => f.replace(/\.(png|jpe?g|webp|heic)$/i, '.*').replace(/candidate-\d+/, 'candidate-N')).sort().join(' ');
        const key = `${row.status}: ${shape || '(nothing)'}`; kept[key] = (kept[key] ?? 0) + 1;
        const candidates = files.filter((f) => /^candidate-\d+\.webp$/.test(f));
        if (row.status === 'ready' && !candidates.includes(`candidate-${row.kept}.webp`)) missingKept++;
        if (['ready', 'accepted', 'failed', 'cancelled', 'refused', 'stopped'].includes(row.status)) extraCandidates += candidates.filter((f) => f !== `candidate-${row.kept}.webp`).length;
    }
    const tree = scanner ? scanner.tree() : null;
    return {
        inProgress: Object.fromEntries(inProgress.map((r) => [r[0], +r[1]])), openUsageRows: openUsage, triesWithoutOneClosingLine: unclosedLedger, triesWithTwoClosingLines: doubleLedger,
        scansChecked: rows.size, objectsWithoutARow: orphanObjects.length, readyWithoutItsKeptRender: missingKept, candidateRendersNotKept: extraCandidates, bucketShapes: kept,
        scannerProcesses: tree ? tree.map((p) => p.command) : null, scratchEntries: scanner ? scanner.scratchEntries() : null,
        queuedMessages: +one('SELECT COUNT(*) FROM messenger_messages'),
    };
}

// ---- soak --------------------------------------------------------------------------------------------------

async function soak() {
    const total = Number(opt('scans', 100));
    const inputs = String(opt('inputs', 'A1-kandidat,B3-letak-a4,vedeli-jste,A3-terapie-a4')).split(',');
    const name = `soak-${stamp()}`;
    const t0 = new Date(Date.now() - 1000).toISOString().replace('T', ' ').slice(0, 19);
    const prov = await provider({ thinkMs: Number(opt('think-ms', 0)) });
    const scanner = await scannerUp(name, { providerUrl: prov.url });
    const user = await new AppUser({ baseUrl: APP }).login();
    const sample = () => { const tree = scanner.tree(); return { scannerMb: mb(tree.reduce((a, p) => a + p.rssKb, 0)), mainMb: mb(tree[0]?.rssKb ?? 0), processes: tree.length, web: containerMb(CONTAINER), gotenberg: containerMb(process.env.G2A_GOTENBERG_CONTAINER ?? 'wboost-gotenberg-1') }; };
    const rows = []; const baseline = sample();
    say('soak', { total, inputs, app: APP, baseline });
    for (let i = 1; i <= total; i++) {
        const input = inputs[(i - 1) % inputs.length];
        prov.use(input);
        let peak = 0; const sampler = setInterval(() => { try { peak = Math.max(peak, mb(scanner.tree().reduce((a, p) => a + p.rssKb, 0))); } catch { /* a process ended while it was read */ } }, 1500);
        const r = await scan(user, pictureOf(input), { timeoutS: 600 });
        clearInterval(sampler);
        await sleep(1500); // the run's process is gone, the lane is idle again
        const after = sample();
        let d = null; try { d = r.id ? stored(r.id) : null; } catch { /* read below as an error */ }
        const row = { i, input, id: r.id ?? null, status: r.status ?? `refused:${r.refused}`, code: r.code ?? null, wallS: r.wallS ?? null, peakMb: peak, idleMb: after.scannerMb, mainMb: after.mainMb, processes: after.processes, webMb: after.web.anonMb, webTotalMb: after.web.mb, gotenbergMb: after.gotenberg.anonMb, gotenbergTotalMb: after.gotenberg.mb, scratch: scanner.scratchEntries(), usage: d?.usageRows ?? null, open: d?.openUsageRows ?? null, ledger: d?.ledger ?? null, kept: d ? `${d.kept}/${d.candidates}` : null, costUsd: d?.costUsd ?? null, engineCostUsd: d?.engineCostUsd ?? null, consistent: d?.consistent ?? null };
        rows.push(row);
        say(`#${i}`, row.input, row.status, `${row.wallS}s`, `peak ${row.peakMb} idle ${row.idleMb} MB (${row.processes}p)`, `web ${row.webMb} got ${row.gotenbergMb}`, `scratch ${row.scratch}`, row.ledger?.join(',') ?? '');
        if (i % 10 === 0) writeFileSync(join(OUT, `${name}.json`), JSON.stringify({ baseline, rows }, null, 1));
    }
    const final = audit({ since: t0, scanner });
    const stop = await scanner.stop();
    await prov.close();
    const trend = (key) => { const ys = rows.map((r) => r[key]).filter((v) => typeof v === 'number'); const n = ys.length; if (n < 4) return null; const xm = (n + 1) / 2, ym = ys.reduce((a, b) => a + b, 0) / n; let sxy = 0, sxx = 0; ys.forEach((y, k) => { sxy += (k + 1 - xm) * (y - ym); sxx += (k + 1 - xm) ** 2; }); const tenth = Math.max(1, Math.floor(n / 10)); const avg = (a) => +(a.reduce((x, y) => x + y, 0) / a.length).toFixed(1); return { first: ys[0], last: ys[n - 1], min: Math.min(...ys), max: Math.max(...ys), firstTenth: avg(ys.slice(0, tenth)), lastTenth: avg(ys.slice(-tenth)), slopePerScan: +(sxy / sxx).toFixed(3) }; };
    const statuses = {}; for (const r of rows) statuses[r.status] = (statuses[r.status] ?? 0) + 1;
    const summary = {
        scans: rows.length, statuses, wallS: trend('wallS'), baseline,
        scannerIdleMb: trend('idleMb'), scannerMainMb: trend('mainMb'), scannerPeakMb: trend('peakMb'), webAnonMb: trend('webMb'), webTotalMb: trend('webTotalMb'), gotenbergAnonMb: trend('gotenbergMb'), gotenbergTotalMb: trend('gotenbergTotalMb'),
        processesAfterEveryScan: [...new Set(rows.map((r) => r.processes))], scratchAfterEveryScan: [...new Set(rows.map((r) => r.scratch))],
        everyScanTwoClosedUsageRows: rows.every((r) => r.usage?.length === 2 && r.open === 0), everyScanOneReserveOneSettle: rows.every((r) => r.ledger?.length === 2 && /^reserve/.test(r.ledger[0]) && /^settle/.test(r.ledger[1])), totalsConsistent: rows.every((r) => r.consistent === true),
        recordedUsd: +rows.reduce((a, r) => a + (r.costUsd ?? 0), 0).toFixed(4), engineUsd: +rows.reduce((a, r) => a + (r.engineCostUsd ?? 0), 0).toFixed(4),
        providerRequests: prov.counts, audit: final, stop,
    };
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify({ summary, baseline, rows }, null, 1));
    console.log(JSON.stringify(summary, null, 1));
}

// ---- provider faults ---------------------------------------------------------------------------------------

const FAULTS = {
    // name: [where, step, what is expected]
    'builder-429': ['builder', { status: 429, type: 'rate_limit_error', message: 'Number of request tokens has exceeded your per-minute rate limit', retryAfter: 1 }],
    'builder-529': ['builder', { status: 529, type: 'overloaded_error', message: 'Overloaded' }],
    'builder-500': ['builder', { status: 500, type: 'api_error', message: 'Internal server error' }],
    'builder-401': ['all', { status: 401, type: 'authentication_error', message: 'invalid x-api-key' }],
    'builder-403': ['all', { status: 403, type: 'permission_error', message: 'Your API key does not have permission to use the specified resource' }],
    'builder-billing': ['all', { status: 400, type: 'invalid_request_error', message: 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.' }],
    'builder-spend-limit': ['all', { status: 429, type: 'rate_limit_error', message: 'This request would exceed your workspace spend limit', details: { error_code: 'enforced_spend_limit_reached' } }],
    'builder-402-blocked': ['builder+probe', { status: 402, type: 'billing_error', message: 'Payment required' }],
    'builder-401-probe-ok': ['builder', { status: 401, type: 'authentication_error', message: 'invalid x-api-key' }],
    'builder-400': ['builder', { status: 400, type: 'invalid_request_error', message: 'messages.3.content.0.image.source.base64: image exceeds the maximum' }],
    'builder-stall': ['builder', { stall: 400_000 }],
    'builder-refusal': ['builder', { refusal: true }],
    'builder-529-then-ok': ['builder', { status: 529, type: 'overloaded_error', message: 'Overloaded' }, { times: 2 }],
    'precheck-529': ['preCheck', { status: 529, type: 'overloaded_error', message: 'Overloaded' }],
    'precheck-refusal': ['preCheck', { refusal: true }],
    'precheck-401': ['all', { status: 401, type: 'authentication_error', message: 'invalid x-api-key' }],
};

async function faults() {
    const names = argv.filter((a) => FAULTS[a]); const list = names.length ? names : Object.keys(FAULTS);
    const name = `faults-${stamp()}`;
    const prov = await provider();
    const scanner = await scannerUp(name, { providerUrl: prov.url });
    const user = await new AppUser({ baseUrl: APP }).login();
    prov.use('A1-kandidat');
    const results = [];
    for (const fault of list) {
        const [where, step, o = {}] = FAULTS[fault];
        consoleStatus('--mode=on'); // a row of failures trips the breaker (as designed): every scenario starts from `on`
        execFileSync('docker', ['exec', process.env.G2A_PG_CONTAINER ?? 'wboost-postgres-1', 'psql', '-U', 'postgres', '-d', DB, '-qc', "UPDATE ai_scan_runtime SET consecutive_failures = 0, failure_class = NULL"]);
        const before = runtime(); const mark = Date.now(); const requestsBefore = prov.requests.length;
        prov.fault = where === 'all' ? { all: step } : where === 'builder+probe' ? { builder: step, probe: step } : { [where]: step, ...(o.times ? { builderTimes: o.times } : {}) };
        say(`fault ${fault}`, JSON.stringify(step));
        const blocking = where === 'all' || where === 'builder+probe';
        const started = await user.start(PROJECTS[0], await freshPicture(fault));
        if (!started.ok) { results.push({ fault, refusedUpload: started.code }); continue; }
        const id = started.scan.id; const t0 = Date.now(); let end;
        if (blocking) {
            // a billing / auth class: the scan must go back and WAIT; nothing may fail it
            const until = Date.now() + 240_000; let seen = null;
            while (Date.now() < until) { const s = (await user.status(id)).scan; if (s.waiting === 'provider_blocked' || END.includes(s.status)) { seen = s; break; } await sleep(1000); }
            end = seen ?? (await user.status(id)).scan;
        } else end = (await user.wait(id, { timeoutS: 900 })).scan;
        const elapsedS = +((Date.now() - t0) / 1000).toFixed(1);
        await sleep(1500);
        const after = runtime(); const d = stored(id);
        const reqs = prov.requests.slice(requestsBefore).filter((r) => String(r.url).includes('/v1/messages') && !String(r.url).includes('count_tokens'));
        const runs = scanner.logs.filter((l) => l.at >= mark && l.msg === 'run ended' && l.scan === id).map((l) => ({ lane: l.lane, outcome: l.outcome, code: l.code ?? null, error: l.error ?? null, providerError: l.providerError ?? null, closing: l.closing ?? null, wallMs: l.wallMs ?? null, turns: l.turns ?? null }));
        const r = {
            fault, injected: step, at: where, status: end.status, code: end.code ?? null, waiting: end.waiting ?? null, elapsedS,
            scannerRuns: runs, scannerLog: scanner.logs.filter((l) => l.at >= mark && ['provider blocked: probing until it answers', 'provider still blocked', 'provider answers again', 'run aborted'].includes(l.msg)).map((l) => `${l.msg}${l.error ? ` (${typeof l.error === 'string' ? l.error : JSON.stringify(l.error)})` : ''}${l.reason ? ` (${l.reason})` : ''}`),
            providerRequests: reqs.length, providerModels: [...new Set(reqs.map((x) => x.model))], requestGapsS: reqs.slice(1).map((x, k) => +((x.at - reqs[k].at) / 1000).toFixed(1)).slice(0, 12),
            usageRows: d.usageRows, openUsageRows: d.openUsageRows, ledger: d.ledger, events: d.events,
            providerStateBefore: before.provider, providerStateAfter: after.provider, modeAfter: after.mode, breaker: `${after.consecutiveFailures} × ${after.failureClass ?? '–'}`,
        };
        if (blocking) {
            // claims are refused while blocked; then the provider comes back: the probe must clear the state by itself
            const upload = await user.start(PROJECTS[0], await freshPicture(`${fault}-while-blocked`));
            r.uploadWhileBlocked = upload.ok ? 'accepted' : upload.code;
            if (upload.ok) await user.act(upload.scan.id, 'cancel');
            const claimsWhileBlocked = scanner.logs.filter((l) => l.at >= mark && l.msg === 'claimed' && l.scan === id).length;
            prov.fault = {}; const cleared0 = Date.now();
            const again = await scanner.untilLog((l) => l.msg === 'provider answers again', { since: mark, timeoutS: 420 });
            r.probeClearedAfterS = again ? +((again.at - cleared0) / 1000).toFixed(1) : null;
            const fin = (await user.wait(id, { timeoutS: 600 })).scan;
            r.afterClear = { status: fin.status, code: fin.code ?? null, providerState: runtime().provider, claims: scanner.logs.filter((l) => l.at >= mark && l.msg === 'claimed' && l.scan === id).length, claimsWhileBlocked, ledger: stored(id).ledger, usageRows: stored(id).usageRows };
        }
        prov.fault = {};
        results.push(r); say(JSON.stringify(r));
        writeFileSync(join(OUT, `${name}.json`), JSON.stringify(results, null, 1));
    }
    consoleStatus('--mode=on');
    const final = audit({ scanner });
    const stop = await scanner.stop(); await prov.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify({ results, audit: final, stop }, null, 1));
    console.log(JSON.stringify({ results, audit: final, stop }, null, 1));
}

// ---- queue -------------------------------------------------------------------------------------------------

async function queue() {
    const name = `queue-${stamp()}`;
    const t0 = new Date(Date.now() - 1000).toISOString().replace('T', ' ').slice(0, 19);
    const prov = await provider({ thinkMs: Number(opt('think-ms', 2500)) }); prov.use('A1-kandidat');
    const user = await new AppUser({ baseUrl: APP }).login();
    const out = { steps: [] }; const note = (what, data) => { out.steps.push({ what, ...data }); say(what, JSON.stringify(data)); };
    // 1. no scanner claims yet: fill the queue. 4 in progress per project, 8 waiting in total.
    const hold = await scannerUp(`${name}-tick`, { providerUrl: prov.url }); // the app accepts uploads only while a scanner is seen
    await hold.stop(); // …and now nobody claims: everything uploaded stays `checking`
    const ids = []; const refusals = [];
    for (let i = 0; i < 10; i++) {
        const project = PROJECTS[i < 4 ? 0 : 1] ?? PROJECTS[0];
        const s = await user.start(project, await freshPicture(`q${i}`));
        if (s.ok) ids.push({ id: s.scan.id, project, i }); else refusals.push({ i, project: project.slice(0, 8), code: s.code, http: s.http });
    }
    // a fifth in the first project while it holds four
    const fifth = await user.start(PROJECTS[0], await freshPicture('q-fifth'));
    note('queue filled without a scanner', { accepted: ids.length, refusals, fifthInTheFullProject: fifth.ok ? 'accepted' : `${fifth.code} (HTTP ${fifth.http})`, waiting: one("SELECT COUNT(*) FROM ai_scan WHERE status IN ('checking', 'queued')") });
    // estimates as the last one in sees them
    const lastStatus = (await user.status(ids.at(-1).id)).scan;
    note('the last scan in', { status: lastStatus.status, waiting: lastStatus.waiting ?? null, position: lastStatus.queuePosition ?? null, estimatedStartSeconds: lastStatus.estimatedStartSeconds ?? null, estimatedFinishSeconds: lastStatus.estimatedFinishSeconds ?? null });
    // 2. 20 tabs polling for the whole test: the status of every waiting scan, every 2 s each
    const lat = []; let polling = true; let pollErrors = 0;
    const tabs = Array.from({ length: 20 }, (_, k) => (async () => { const u = await new AppUser({ baseUrl: APP }).login(); while (polling) { const t = performance.now(); try { const s = await u.status(ids[k % ids.length].id); if (s.http !== 200) pollErrors++; } catch { pollErrors++; } lat.push(performance.now() - t); await sleep(2000); } })());
    // 3. the scanner arrives
    const webBefore = containerMb(CONTAINER);
    const scanner = await scannerUp(name, { providerUrl: prov.url });
    const started = Date.now();
    // cancel one while it waits (checking / queued) …
    const cancelWaiting = ids[5]; const cw = await user.act(cancelWaiting.id, 'cancel');
    note('cancel while waiting', { scan: cancelWaiting.id.slice(0, 8), statusBefore: 'checking/queued', answer: cw.scan?.status ?? cw.code, ledger: stored(cancelWaiting.id).ledger });
    // … one while it runs …
    const cancelRunning = ids[1];
    const wr = await user.wait(cancelRunning.id, { until: END, stage: 'build', timeoutS: 900 });
    if ((wr.scan ?? wr).status === 'running') { await sleep(2500); const tc = Date.now(); const cr = await user.act(cancelRunning.id, 'cancel'); const aborted = await scanner.untilLog((l) => l.msg === 'run aborted' && l.scan === cancelRunning.id, { since: tc - 100, timeoutS: 40 }); await sleep(2000); const d = stored(cancelRunning.id); note('cancel while running', { scan: cancelRunning.id.slice(0, 8), answer: cr.scan?.status ?? cr.code, scannerAbortedAfterS: aborted ? +((aborted.at - tc) / 1000).toFixed(1) : null, reason: aborted?.reason ?? null, ledger: d.ledger, usageRows: d.usageRows }); } else note('cancel while running', { skipped: (wr.scan ?? wr).status });
    // … and the poison input: whatever runs it, its process dies — twice
    const poison = ids[3]; const kills = [];
    for (let k = 0; k < 2; k++) {
        const w = await user.wait(poison.id, { until: END, stage: 'build', timeoutS: 1500 });
        const s = w.scan ?? w; if (s.status !== 'running') { kills.push({ skipped: s.status }); break; }
        await sleep(2000);
        const tree = scanner.tree(); const victim = tree.find((p) => p.ppid === scanner.pid && /node/.test(p.command));
        if (!victim) { kills.push({ skipped: 'no run process' }); break; }
        const tk = Date.now(); process.kill(victim.pid, 'SIGKILL');
        const ended = await scanner.untilLog((l) => l.msg === 'run ended' && l.scan === poison.id && l.at >= tk - 50, { since: tk - 50, timeoutS: 30 });
        // the lease must lapse (the scanner never releases a run that crashed): the app's reaper puts it back once, then fails it
        const next = await user.wait(poison.id, { until: k === 0 ? ['queued', ...END] : END, timeoutS: 240 });
        kills.push({ run: k + 1, killedPid: victim.pid, scannerSaw: ended ? `${ended.outcome}${ended.closing ? ` / closing ${JSON.stringify(ended.closing)}` : ''}` : null, appStateAfter: (next.scan ?? next).status, code: (next.scan ?? next).code ?? null, restarted: (next.scan ?? next).restarted ?? null, afterS: +((Date.now() - tk) / 1000).toFixed(1), processesLeft: scanner.tree().length });
    }
    { const d = stored(poison.id); note('poison input', { scan: poison.id.slice(0, 8), kills, final: d.status, code: d.code, ledger: d.ledger, usageRows: d.usageRows, failureCount: one(`SELECT failure_count FROM ai_scan WHERE id = '${poison.id}'`) }); }
    // 4. let the queue drain
    const done = {}; const waits = [];
    for (const s of ids) { const w = await user.wait(s.id, { timeoutS: 1800 }); const st = (w.scan ?? w).status; done[st] = (done[st] ?? 0) + 1; waits.push({ i: s.i, status: st, code: (w.scan ?? w).code ?? null }); }
    polling = false; await Promise.all(tabs);
    lat.sort((a, b) => a - b); const q = (p) => +lat[Math.min(lat.length - 1, Math.floor(p * lat.length))].toFixed(1);
    note('queue drained', { inS: +((Date.now() - started) / 1000).toFixed(0), outcomes: done, perScan: waits });
    note('20 tabs polling every 2 s', { polls: lat.length, errors: pollErrors, p50Ms: q(0.5), p95Ms: q(0.95), p99Ms: q(0.99), maxMs: +lat.at(-1).toFixed(1), webMbBefore: webBefore, webMbAfter: containerMb(CONTAINER) });
    out.audit = audit({ since: t0, scanner });
    out.stop = await scanner.stop(); await prov.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify(out, null, 1));
    console.log(JSON.stringify(out, null, 1));
}

// ---- kill (REAL model) -------------------------------------------------------------------------------------

async function kill() {
    const input = String(opt('input', 'vedeli-jste')); const name = `kill-${stamp()}`;
    const user = await new AppUser({ baseUrl: APP }).login();
    const out = { input, runs: [] };
    let scanner = await scannerUp(`${name}-1`, { real: true });
    const started = await user.start(PROJECTS[0], pictureOf(input));
    if (!started.ok) throw new Error(`the app refused the upload: ${started.code}`);
    const id = started.scan.id; out.scan = id;
    for (let k = 1; k <= 2; k++) {
        const w = await user.wait(id, { until: END, stage: 'build', timeoutS: 600 });
        if ((w.scan ?? w).status !== 'running') { out.runs.push({ run: k, skipped: (w.scan ?? w).status }); break; }
        // let it spend: wait for a heartbeat that carries tokens, then one more model turn
        let beat = null; for (let i = 0; i < 150 && !beat; i++) { await sleep(1000); const u = sql(`SELECT output_tokens, cost_micro_usd, engine_cost_micro_usd, turns FROM ai_usage_event WHERE scan_id = '${id}' AND step = 'builder' AND run = ${k}`)[0]; if (u && +u[0] > 0) beat = u; }
        const before = sql(`SELECT input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cache_write_1h_tokens, cost_micro_usd, engine_cost_micro_usd, turns, COALESCE(outcome, 'open') FROM ai_usage_event WHERE scan_id = '${id}' AND step = 'builder' AND run = ${k}`)[0];
        const tk = Date.now(); const tree = scanner.tree();
        process.kill(scanner.pid, 'SIGKILL'); await scanner.exited;
        // how long does what the scanner left behind live? (the run's process must end the engine and itself)
        const aliveNow = () => tree.slice(1).filter((p) => { try { process.kill(p.pid, 0); return true; } catch { return false; } });
        const died = {}; let orphans = aliveNow();
        for (let i = 0; i < 240 && orphans.length; i++) { await sleep(250); const now = aliveNow(); for (const p of orphans) if (!now.some((q) => q.pid === p.pid)) died[p.command] = +((Date.now() - tk) / 1000).toFixed(2); orphans = now; }
        const next = await user.wait(id, { until: k === 1 ? ['queued', ...END] : END, timeoutS: 300 });
        const reapedAfterS = +((Date.now() - tk) / 1000).toFixed(0);
        const row = sql(`SELECT input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cache_write_1h_tokens, cost_micro_usd, engine_cost_micro_usd, turns, COALESCE(outcome, 'open'), (closed_at IS NOT NULL) FROM ai_usage_event WHERE scan_id = '${id}' AND step = 'builder' AND run = ${k}`)[0];
        out.runs.push({ run: k, killedAfterBeatWith: before && { in: +before[0], out: +before[1], cacheRead: +before[2], cacheWrite: +before[3], cacheWrite1h: +before[4], costUsd: before[5] / 1e6, engineUsd: before[6] / 1e6, turns: +before[7], outcome: before[8] }, processTreeAtKill: tree.map((p) => p.command), leftoverProcessesDiedAfterS: died, stillAliveAfter60s: orphans.map((p) => p.command), appState: (next.scan ?? next).status, code: (next.scan ?? next).code ?? null, restarted: (next.scan ?? next).restarted ?? null, reapedAfterS, usageRowAfter: row && { in: +row[0], out: +row[1], cacheRead: +row[2], cacheWrite: +row[3], cacheWrite1h: +row[4], costUsd: row[5] / 1e6, engineUsd: row[6] / 1e6, turns: +row[7], outcome: row[8], closed: row[9] === 't' } });
        say(JSON.stringify(out.runs.at(-1)));
        if (k === 1) scanner = await scannerUp(`${name}-2`, { real: true });
    }
    const d = stored(id);
    out.final = { status: d.status, code: d.code, usageRows: d.usageRows, openUsageRows: d.openUsageRows, ledger: d.ledger, scanCostUsd: d.scanCostUsd, sumOfRowsUsd: d.costUsd, engineUsd: d.engineCostUsd, consistent: d.consistent };
    out.status = consoleStatus().split('\n').filter((l) => /Spend today|Open usage|Scans /.test(l)).map((l) => l.trim());
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify(out, null, 1));
    console.log(JSON.stringify(out, null, 1));
}

// ---- orphans: the scanner's main process is killed (SIGKILL: an OOM kill, `docker kill`) mid-build ------------

async function orphans() {
    const rounds = Number(opt('rounds', 3)); const name = `orphans-${stamp()}`;
    const prov = await provider({ thinkMs: 1500 }); prov.use('B3-letak-a4');
    const user = await new AppUser({ baseUrl: APP }).login();
    const out = { rounds: [] };
    for (let k = 1; k <= rounds; k++) {
        const scanner = await scannerUp(`${name}-${k}`, { providerUrl: prov.url });
        const started = await user.start(PROJECTS[0], pictureOf('B3-letak-a4'));
        if (!started.ok) { out.rounds.push({ round: k, refused: started.code }); await scanner.stop(); continue; }
        const id = started.scan.id;
        const w = await user.wait(id, { until: END, stage: 'build', timeoutS: 600 });
        if ((w.scan ?? w).status !== 'running') { out.rounds.push({ round: k, skipped: (w.scan ?? w).status }); await scanner.stop(); continue; }
        // into the model loop: at least two builder requests made
        const b0 = prov.counts.builder; for (let i = 0; i < 120 && prov.counts.builder < b0 + 2; i++) await sleep(500);
        const tree = scanner.tree(); const tk = Date.now(); const requestsAtKill = prov.requests.length;
        process.kill(scanner.pid, 'SIGKILL');
        const alive = () => tree.slice(1).filter((p) => { try { process.kill(p.pid, 0); return true; } catch { return false; } });
        const gone = {}; let left = alive();
        for (let i = 0; i < 240 && left.length; i++) { await sleep(250); const now = alive(); for (const p of left) if (!now.some((q) => q.pid === p.pid)) gone[`${p.command}#${p.pid}`] = +((Date.now() - tk) / 1000).toFixed(2); left = now; }
        await sleep(1000);
        const late = prov.requests.slice(requestsAtKill).filter((r) => String(r.url).includes('/v1/messages'));
        const scratchLeft = scanner.scratchEntries();
        const row = { round: k, scan: id.slice(0, 8), treeAtKill: tree.map((p) => `${p.command}#${p.pid}`), diedAfterS: gone, stillAliveAfter60s: left.map((p) => p.command), providerRequestsAfterTheKill: late.length, lastOneAfterS: late.length ? +((late.at(-1).at - tk) / 1000).toFixed(1) : null, scratchEntriesLeft: scratchLeft };
        // the next scanner must find the scan again: lease lost → queued → claimed (and its scratch swept at start)
        for (const p of left) { try { process.kill(p.pid, 'SIGKILL'); } catch { /* gone */ } }
        const next = await scannerUp(`${name}-${k}b`, { providerUrl: prov.url, extraEnv: { SCANNER_SCRATCH_DIR: scanner.scratch } });
        row.scratchAfterTheNextStart = next.scratchEntries();
        const end = await user.wait(id, { timeoutS: 900 }); const d = stored(id);
        Object.assign(row, { final: (end.scan ?? end).status, code: (end.scan ?? end).code ?? null, usageRows: d.usageRows, ledger: d.ledger, events: d.events });
        out.rounds.push(row); say(JSON.stringify(row));
        await next.stop();
    }
    await prov.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify(out, null, 1));
    console.log(JSON.stringify(out, null, 1));
}

// ---- modes: what `paused` and `off` do to scans in each state (the runbook's kill switch) --------------------

async function modes() {
    const name = `modes-${stamp()}`; const prov = await provider({ thinkMs: 2000 }); prov.use('A1-kandidat');
    const scanner = await scannerUp(name, { providerUrl: prov.url }); const user = await new AppUser({ baseUrl: APP }).login();
    const out = {}; consoleStatus('--mode=on');
    // paused: one running, one waiting
    const a = await user.start(PROJECTS[0], await freshPicture('m-a')); await user.wait(a.scan.id, { until: END, stage: 'build', timeoutS: 300 });
    const b = await user.start(PROJECTS[0], await freshPicture('m-b')); await sleep(8000);
    consoleStatus('--mode=paused'); const tp = Date.now();
    const refused = await user.start(PROJECTS[0], await freshPicture('m-c'));
    const aEnd = (await user.wait(a.scan.id, { timeoutS: 600 })).scan; await sleep(12_000);
    const bNow = (await user.status(b.scan.id)).scan;
    out.paused = { uploadWhilePaused: refused.ok ? 'accepted' : `${refused.code} (HTTP ${refused.http})`, theRunningScan: `${aEnd.status} ${+((Date.now() - tp) / 1000).toFixed(0)} s after the switch`, ledgerOfIt: stored(a.scan.id).ledger, theWaitingScan: { status: bNow.status, waiting: bNow.waiting }, claimsWhilePaused: scanner.logs.filter((l) => l.at >= tp && l.msg === 'claimed').length };
    consoleStatus('--mode=on'); const bEnd = (await user.wait(b.scan.id, { timeoutS: 600 })).scan; out.paused.afterOn = bEnd.status;
    // off: one running, one waiting
    const c = await user.start(PROJECTS[0], await freshPicture('m-d')); await user.wait(c.scan.id, { until: END, stage: 'build', timeoutS: 300 });
    const d = await user.start(PROJECTS[0], await freshPicture('m-e')); await sleep(6000);
    const requestsBefore = prov.requests.length; consoleStatus('--mode=off'); const to = Date.now();
    const aborted = await scanner.untilLog((l) => l.msg === 'run aborted' && l.scan === c.scan.id, { since: to - 100, timeoutS: 40 });
    await sleep(8000); const late = prov.requests.slice(requestsBefore).filter((r) => String(r.url).includes('/v1/messages'));
    const cNow = (await user.status(c.scan.id)).scan, dNow = (await user.status(d.scan.id)).scan; const up = await user.start(PROJECTS[0], await freshPicture('m-f'));
    const ready = await user.act(a.scan.id, 'discard');
    out.off = { theRunningScan: `${cNow.status} ${cNow.code}`, theWaitingScan: `${dNow.status} ${dNow.code}`, ledgers: [stored(c.scan.id).ledger, stored(d.scan.id).ledger], usageOfTheRunningOne: stored(c.scan.id).usageRows, scannerAbortedAfterS: aborted ? +((aborted.at - to) / 1000).toFixed(1) : null, reason: aborted?.reason ?? null, lastProviderRequestAfterS: late.length ? +((late.at(-1).at - to) / 1000).toFixed(1) : 'none after the switch', uploadWhileOff: up.ok ? 'accepted' : `${up.code} (HTTP ${up.http})`, aReadyProposalCanStillBeDiscarded: ready.scan?.status ?? ready.code };
    consoleStatus('--mode=on'); out.audit = audit({ scanner }); out.stop = await scanner.stop(); await prov.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify(out, null, 1)); console.log(JSON.stringify(out, null, 1));
}

// ---- idle --------------------------------------------------------------------------------------------------

async function idle() {
    const minutes = Number(opt('minutes', 45)); const real = opt('real', false) === true; const name = `idle-${stamp()}`;
    const prov = real ? null : await provider(); prov?.use('A1-kandidat');
    const scanner = await scannerUp(name, real ? { real: true } : { providerUrl: prov.url });
    const user = await new AppUser({ baseUrl: APP }).login();
    const samples = []; const t0 = Date.now();
    const first = await scan(user, real ? pictureOf(String(opt('input', 'informace-s-fotkou'))) : await freshPicture('idle-before'), { timeoutS: 600 });
    say('before the idle period', first.status, `${first.wallS}s`);
    while (Date.now() - t0 < minutes * 60_000) { const tree = scanner.tree(); samples.push({ minute: +((Date.now() - t0) / 60000).toFixed(1), scannerMb: mb(tree.reduce((a, p) => a + p.rssKb, 0)), processes: tree.length, scratch: scanner.scratchEntries(), absent: runtime().mode }); await sleep(60_000); }
    const idleLog = { ticksFailed: scanner.logs.filter((l) => l.msg === 'tick failed').length, errors: scanner.logs.filter((l) => l.level === 'error').length, warnings: [...new Set(scanner.logs.filter((l) => l.level === 'warn').map((l) => l.msg))] };
    const seenAgo = one("SELECT EXTRACT(EPOCH FROM (NOW() - scanner_seen_at))::int FROM ai_scan_runtime WHERE id = 1");
    const after = await scan(user, real ? pictureOf(String(opt('input', 'informace-s-fotkou'))) : await freshPicture('idle-after'), { timeoutS: 600 });
    const d = after.id ? stored(after.id) : null;
    const out = { minutesIdle: minutes, real, before: { status: first.status, wallS: first.wallS }, idle: { samples: samples.length, scannerMbFirst: samples[0]?.scannerMb, scannerMbLast: samples.at(-1)?.scannerMb, scannerMbMax: Math.max(...samples.map((s) => s.scannerMb)), processes: [...new Set(samples.map((s) => s.processes))], scratch: [...new Set(samples.map((s) => s.scratch))], ...idleLog, scannerSeenAgoSAtTheEnd: +seenAgo }, after: { status: after.status, code: after.code, wallS: after.wallS, firstClaimDelayS: after.timeline.find((t) => t.status !== 'checking' || t.stage)?.t ?? null, usageRows: d?.usageRows, ledger: d?.ledger, costUsd: d?.costUsd, engineUsd: d?.engineCostUsd }, audit: audit({ scanner }) };
    out.stop = await scanner.stop(); await prov?.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify({ ...out, samples }, null, 1));
    console.log(JSON.stringify(out, null, 1));
}

// ---- limits: an MCP client in a loop, and the web ----------------------------------------------------------

/** A minimal MCP client (streamable HTTP, JSON answers) with a personal access token. */
class Mcp {
    constructor(token) { this.token = token; this.session = null; this.n = 0; }
    async rpc(method, params, { notify = false } = {}) {
        const res = await fetch(`${APP}/_mcp`, { method: 'POST', headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...(this.session ? { 'Mcp-Session-Id': this.session } : {}), 'MCP-Protocol-Version': '2025-06-18' }, body: JSON.stringify({ jsonrpc: '2.0', ...(notify ? {} : { id: ++this.n }), method, params }) });
        this.session = res.headers.get('mcp-session-id') ?? this.session;
        const text = await res.text(); if (notify) return null;
        const body = (res.headers.get('content-type') ?? '').includes('event-stream') ? text.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5).trim()).join('') : text;
        try { return { http: res.status, ...JSON.parse(body) }; } catch { return { http: res.status, raw: text.slice(0, 300) }; }
    }
    async open() { const r = await this.rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'g2a-loop', version: '1' } }); await this.rpc('notifications/initialized', {}, { notify: true }); return r; }
    /** → {ok, code, data} — a refusal is a tool error whose text starts with the code. */
    async scanDesign(args) {
        const r = await this.rpc('tools/call', { name: 'scan_design', arguments: args });
        const text = r.result?.content?.map((c) => c.text ?? '').join('') ?? r.error?.message ?? r.raw ?? '';
        if (r.error || r.result?.isError) return { ok: false, code: (/^([a-z_]+)\b/.exec(text) ?? [])[1] ?? 'error', text: text.slice(0, 220) };
        let data = r.result?.structuredContent ?? null; if (!data) { try { data = JSON.parse(text); } catch { data = { text: text.slice(0, 200) }; } }
        return { ok: true, data };
    }
}
const setRuntime = (...o) => consoleStatus(...o);
const pgWrite = (q) => execFileSync('docker', ['exec', process.env.G2A_PG_CONTAINER ?? 'wboost-postgres-1', 'psql', '-U', 'postgres', '-d', DB, '-qc', q]);

async function limits() {
    const name = `limits-${stamp()}`; const token = process.env.G2A_MCP_TOKEN;
    if (!token) throw new Error('set G2A_MCP_TOKEN (bin/console app:mcp:token:create … for a user whose MCP scopes include ai:scan)');
    const project = PROJECTS[0]; const email = (await new AppUser({ baseUrl: APP }).login()).email;
    const user = await new AppUser({ baseUrl: APP }).login();
    const out = { steps: [] }; const note = (what, data) => { out.steps.push({ what, ...data }); say(what, JSON.stringify(data)); };
    const prov = await provider(); prov.use('A1-kandidat');
    const scanner = await scannerUp(name, { providerUrl: prov.url });
    const mcp = new Mcp(token); const hello = await mcp.open();
    note('MCP client connected', { server: hello.result?.serverInfo ?? null, http: hello.http });
    const b64 = async () => (await sharp(readFileSync(await freshPicture('mcp'))).png().toBuffer()).toString('base64');
    const tally = (list) => { const t = {}; for (const x of list) t[x] = (t[x] ?? 0) + 1; return t; };
    const cancelAll = async () => { for (const [id] of sql("SELECT id FROM ai_scan WHERE status IN ('checking', 'queued', 'running')")) await user.act(id, 'cancel'); await sleep(1500); };
    const todays = () => ({ startsToday: +one(`SELECT COUNT(*) FROM ai_credit_ledger WHERE reason = 'reserve' AND created_at >= date_trunc('day', NOW() AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'Europe/Prague'`), usedThisMonth: -one(`SELECT COALESCE(SUM(delta), 0) FROM ai_credit_ledger WHERE project_id = '${project}' AND period = to_char(NOW() AT TIME ZONE 'Europe/Prague', 'YYYY-MM')`), spentTodayUsd: +(one(`SELECT COALESCE(SUM(cost_micro_usd), 0) FROM ai_usage_event WHERE occurred_at >= date_trunc('day', NOW() AT TIME ZONE 'Europe/Prague') AT TIME ZONE 'Europe/Prague'`) / 1e6).toFixed(4) });
    const clearToday = () => { pgWrite("DELETE FROM ai_credit_ledger WHERE scan_id IN (SELECT id FROM ai_scan WHERE created_at >= NOW() - INTERVAL '2 days')"); pgWrite("DELETE FROM ai_usage_event WHERE occurred_at >= NOW() - INTERVAL '2 days'"); pgWrite("DELETE FROM ai_scan WHERE created_at >= NOW() - INTERVAL '2 days'"); };

    // --- 1. daily starts: 10 per user (default). Start + cancel in a loop — a refund gives no start back.
    clearToday(); setRuntime('--mode=on', '--daily-starts-user=10', '--daily-starts-project=20', '--daily-ceiling-usd=20', '--project-ceiling-usd=5', '--max-running=1'); pgWrite(`UPDATE project SET ai_scan_monthly_limit = 500 WHERE id = '${project}'`);
    { const codes = []; for (let i = 0; i < 16; i++) { const s = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() }); if (s.ok) { const id = s.data.scanId ?? s.data.scan?.id ?? s.data.id; codes.push('started'); const c = await mcp.scanDesign({ action: 'cancel', scanId: id }); if (!c.ok) codes.push(`cancel:${c.code}`); } else { codes.push(s.code); if (i === 15) out.lastRefusalText = s.text; } }
      note('MCP loop: start + cancel × 16, daily starts 10 per user', { outcomes: tally(codes), refusal: out.lastRefusalText, ...todays(), ledgerOfToday: tally(sql("SELECT reason FROM ai_credit_ledger WHERE created_at >= NOW() - INTERVAL '1 hour'").map((r) => r[0])) }); }
    { const s = await user.start(project, await freshPicture('web-after-mcp')); note('the web upload after the MCP loop used the day up', { answer: s.ok ? 'accepted' : s.code, http: s.http, body: Object.fromEntries(Object.entries(s).filter(([k]) => !['scan'].includes(k))) }); }

    // --- 2. per-project daily starts: 20 (the user's limit raised out of the way)
    clearToday(); setRuntime('--daily-starts-user=500', '--daily-starts-project=20');
    { const codes = []; let last = null; for (let i = 0; i < 26; i++) { const s = await user.start(project, await freshPicture(`p${i}`)); if (s.ok) { codes.push('started'); await user.act(s.scan.id, 'cancel'); } else { codes.push(s.code); last = { http: s.http, code: s.code }; } }
      note('web loop: upload + cancel × 26, daily starts 20 per project', { outcomes: tally(codes), refusal: last, ...todays() }); }

    // --- 3. retry bounds: 2 × "Zkusit znovu" per upload; "Zkusit přesto" once
    clearToday(); setRuntime('--daily-starts-user=500', '--daily-starts-project=500');
    { prov.fault = { builder: { status: 529, type: 'overloaded_error', message: 'Overloaded' } }; // every build fails `provider_unavailable` (not counted against the picture)
      const s = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() }); const id = s.data?.scanId ?? s.data?.scan?.id ?? s.data?.id; const trail = [];
      for (let k = 0; k < 4 && id; k++) { const w = await user.wait(id, { timeoutS: 600 }); const st = w.scan ?? w; trail.push(`${st.status}${st.code ? `/${st.code}` : ''} retriesLeft=${st.retriesLeft ?? '?'}`); pgWrite("UPDATE ai_scan_runtime SET consecutive_failures = 0, failure_class = NULL, mode = 'on'"); const r = await mcp.scanDesign({ action: 'retry', scanId: id }); trail.push(r.ok ? 'retry accepted' : `retry refused: ${r.code}`); if (!r.ok) { out.retryRefusalText = r.text; break; } }
      const web = await user.act(id, 'retry'); prov.fault = {};
      note('retry in a loop on one upload (every build made to fail)', { trail, refusal: out.retryRefusalText, webRetryAfter: `${web.code ?? web.scan?.status} (HTTP ${web.http})`, ledger: stored(id).ledger, tries: one(`SELECT try FROM ai_scan WHERE id = '${id}'`) }); }
    pgWrite("UPDATE ai_scan_runtime SET consecutive_failures = 0, failure_class = NULL, mode = 'on'");

    // --- 4. the monthly limit of the project (credits): 3
    clearToday(); pgWrite(`UPDATE project SET ai_scan_monthly_limit = (SELECT -COALESCE(SUM(delta), 0) FROM ai_credit_ledger WHERE project_id = '${project}' AND period = to_char(NOW() AT TIME ZONE 'Europe/Prague', 'YYYY-MM')) + 3 WHERE id = '${project}'`);
    { const codes = []; let last = null; for (let i = 0; i < 6; i++) { const s = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() }); if (!s.ok) { codes.push(s.code); last = s.text; continue; } const id = s.data.scanId ?? s.data.scan?.id ?? s.data.id; const w = await user.wait(id, { timeoutS: 600 }); codes.push((w.scan ?? w).status); }
      note('MCP loop: 6 scans to the end, monthly limit = 3 more', { outcomes: tally(codes), refusal: last, ...todays() }); }
    pgWrite(`UPDATE project SET ai_scan_monthly_limit = 500 WHERE id = '${project}'`);

    // --- 5. the per-project budget: $1.60 a day. One run reserves $1.50; nothing may start that could pass it.
    clearToday(); setRuntime('--project-ceiling-usd=1.6', '--daily-ceiling-usd=20');
    { const codes = []; let last = null; let maxRunning = 0; const ids = [];
      for (let i = 0; i < 8; i++) { const s = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() }); if (s.ok) { codes.push('started'); ids.push(s.data.scanId); } else { codes.push(s.code); last = s.text; } maxRunning = Math.max(maxRunning, +one("SELECT COUNT(*) FROM ai_scan WHERE status = 'running'")); await sleep(400); }
      // what was let in is built one at a time — and only while recorded spend + $1.50 still fits
      const builds0 = scanner.logs.filter((l) => l.msg === 'claimed' && l.lane === 'build').length;
      for (let i = 0; i < 300; i++) { await sleep(2000); maxRunning = Math.max(maxRunning, +one("SELECT COUNT(*) FROM ai_scan WHERE status = 'running'")); if (+one("SELECT COUNT(*) FROM ai_scan WHERE status IN ('checking', 'running')") === 0 && i > 5) break; }
      await sleep(12_000); // two more claim polls: nothing else may be claimed
      const states = tally(sql(`SELECT status FROM ai_scan WHERE id IN (${ids.map((x) => `'${x}'`).join(',')})`).map((r) => r[0]));
      const queued = sql("SELECT id FROM ai_scan WHERE status = 'queued' LIMIT 1")[0]?.[0]; const view = queued ? (await user.status(queued)).scan : null;
      note('MCP loop: 8 starts at once, project ceiling $1.60', { outcomes: tally(codes), refusal: last, statesAfter: states, buildsClaimed: scanner.logs.filter((l) => l.msg === 'claimed' && l.lane === 'build').length - builds0, maxRunningSeen: maxRunning, aQueuedScanShows: view && { status: view.status, waiting: view.waiting, estimatedStartSeconds: view.estimatedStartSeconds }, ...todays(), statusLine: consoleStatus().split('\n').find((l) => /Spend today/.test(l))?.trim() });
      const again = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() });
      note('…and one more start after that', { answer: again.ok ? 'accepted' : `${again.code}: ${again.text}` }); }
    await cancelAll();
    // recorded spend above ceiling − $1.50: nothing starts, whatever asks
    { const m = await mcp.scanDesign({ action: 'start', projectId: project, imageBase64: await b64() }); const w = await user.start(project, await freshPicture('over')); const other = await user.start(PROJECTS[1], await freshPicture('other-project'));
      note('with the project at its ceiling (recorded spend + $1.50 > $1.60)', { mcp: m.ok ? 'accepted' : `${m.code}: ${m.text}`, web: w.ok ? 'accepted' : `${w.code} (HTTP ${w.http})`, anotherProject: other.ok ? 'accepted' : other.code, ...todays() }); if (other.ok) await user.wait(other.scan.id, { timeoutS: 600 }); }

    // --- 6. the daily ceiling of the whole system: $1.90. A replayed build costs about $0.27, so two fit
    //        (0 + 1.50, then 0.27 + 1.50) and a third does not (0.54 + 1.50): what is queued then waits.
    clearToday(); setRuntime('--project-ceiling-usd=100', '--daily-ceiling-usd=1.9', '--max-running=1');
    { const codes = []; let last = null; const ids = [];
      for (let i = 0; i < 6; i++) { const s = await user.start(PROJECTS[i % 2] ?? project, await freshPicture(`d${i}`)); if (s.ok) { codes.push('started'); ids.push(s.scan.id); } else { codes.push(s.code); last = { code: s.code, http: s.http }; } await sleep(300); }
      let maxRunning = 0; const builds0 = scanner.logs.filter((l) => l.msg === 'claimed' && l.lane === 'build').length;
      for (let i = 0; i < 400; i++) { await sleep(2000); maxRunning = Math.max(maxRunning, +one("SELECT COUNT(*) FROM ai_scan WHERE status = 'running'")); if (+one("SELECT COUNT(*) FROM ai_scan WHERE status IN ('checking', 'running')") === 0 && i > 5) break; }
      await sleep(12_000);
      const states = tally(sql(`SELECT status FROM ai_scan WHERE id IN (${ids.map((x) => `'${x}'`).join(',')})`).map((r) => r[0]));
      const late = await user.start(project, await freshPicture('late'));
      const held = sql("SELECT id FROM ai_scan WHERE status = 'queued' LIMIT 1")[0]?.[0]; const heldView = held ? (await user.status(held)).scan : null;
      out.heldByTheTotalCeiling = heldView && { status: heldView.status, waiting: heldView.waiting, estimatedStartSeconds: heldView.estimatedStartSeconds };
      note('web loop: 6 uploads over two projects, total ceiling $1.90', { outcomes: tally(codes), refusal: last, statesAfter: states, buildsClaimed: scanner.logs.filter((l) => l.msg === 'claimed' && l.lane === 'build').length - builds0, maxRunningSeen: maxRunning, ...todays(), anUploadAfter: late.ok ? 'accepted' : `${late.code} (HTTP ${late.http})`, aQueuedScanShows: out.heldByTheTotalCeiling, statusLine: consoleStatus().split('\n').find((l) => /Spend today/.test(l))?.trim() }); }
    await cancelAll(); clearToday();
    setRuntime('--mode=on', '--daily-starts-user=10', '--daily-starts-project=20', '--daily-ceiling-usd=20', '--project-ceiling-usd=5', '--max-running=1');
    out.audit = audit({ scanner }); out.stop = await scanner.stop(); await prov.close();
    writeFileSync(join(OUT, `${name}.json`), JSON.stringify(out, null, 1));
    console.log(JSON.stringify(out, null, 1));
}

const commands = { soak, faults, queue, kill, idle, limits, orphans, modes, audit: async () => console.log(JSON.stringify(audit(), null, 1)) };
const command = argv[0];
if (!commands[command]) { console.error(`usage: g2a.mjs ${Object.keys(commands).join(' | ')} — see the head of this file`); process.exit(2); }
await commands[command]();
process.exit(0);
