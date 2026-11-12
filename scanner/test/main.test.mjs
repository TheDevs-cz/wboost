// The scanner service in one process, with a scripted child per run: the two lanes, the protocol handshake,
// the provider probe, health, shutdown — and that two runs never cross. Times are §4.9 ÷ 150.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readdirSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Scanner, logger } from '../src/main.mjs';
import { loadConfig, capacityOf, memoryNeededBytes, describeConfig, ConfigError } from '../src/config.mjs';
import { assess, startHealthServer, healthcheck } from '../src/health.mjs';
import { installShutdown } from '../src/shutdown.mjs';
import { alive } from '../src/proc.mjs';
import { startFakeApp, definitionWith, until, TOKEN } from './support/fake-app.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const STUB = join(HERE, 'support', 'stub-worker.mjs');
const TIMING = { heartbeatSeconds: 0.1, heartbeatAttemptSeconds: 0.08, selfAbortSeconds: 0.7, killGraceSeconds: 0.3, releaseAttemptSeconds: 0.3, appCallAttemptSeconds: 0.3, appOutageToleranceSeconds: 0.6, claimPollSeconds: 0.05, tickSeconds: 0.2, providerProbeSeconds: 0.25, runCapSeconds: 20, intakeLeaseSeconds: 20 };
const DESIGN = { canvas: { width: 200, height: 100 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }] };
const GIB = 1024 ** 3;

async function service(t, { concurrency = 1, stub = {}, protocolVersion = 1, start = true } = {}) {
    const app = await startFakeApp({ files: definitionWith({ timing: TIMING }), protocolVersion });
    const scratch = mkdtempSync(join(tmpdir(), 'scan-main-'));
    const logs = [];
    const config = loadConfig({ SCANNER_APP_URL: app.url, SCANNER_TOKEN: TOKEN, SCANNER_CONCURRENCY: String(concurrency), SCANNER_SCRATCH_DIR: join(scratch, 'root'), SCANNER_HEALTH_PORT: '0', SCANNER_LOG: 'silent' }, { readMemoryLimit: () => null });
    const scanner = new Scanner(config, { workerPath: STUB, timing: TIMING, instruments: ['ocr', 'crop', 'measure', 'font_rank', 'compare'], workerEnv: { STUB_WORKER: JSON.stringify(stub) }, log: (level, msg, f) => logs.push({ level, msg, ...f }) });
    t.after(async () => { await scanner.stop('test'); await app.close(); });
    if (start) await scanner.start();
    return { app, scanner, config, logs, scratch };
}

test('config: the environment only; what is missing or wrong is named; the token is never printed', () => {
    assert.throws(() => loadConfig({}), (e) => e instanceof ConfigError && e.problems.includes('SCANNER_APP_URL is not set') && e.problems.includes('SCANNER_TOKEN is not set'));
    assert.throws(() => loadConfig({ SCANNER_APP_URL: 'web', SCANNER_TOKEN: 'short' }), (e) => e.problems.length === 2);
    assert.throws(() => loadConfig({ SCANNER_APP_URL: 'http://web', SCANNER_TOKEN: TOKEN, SCANNER_ENGINE: 'messages' }), /needs ANTHROPIC_API_KEY/);
    assert.throws(() => loadConfig({ SCANNER_APP_URL: 'http://web', SCANNER_TOKEN: TOKEN, SCANNER_CONCURRENCY: '0' }), /SCANNER_CONCURRENCY/);
    const c = loadConfig({ SCANNER_APP_URL: 'http://web/', SCANNER_TOKEN: TOKEN, SCANNER_BUILD: '0123456789abcdef0123' }, { readMemoryLimit: () => null });
    assert.deepEqual([c.appUrl, c.concurrency, c.engine, c.healthPort, c.threadsPerRun, c.credential], ['http://web', 1, 'sdk', 8787, 3, 'plan-login']);
    assert.match(c.version, /^\d+\.\d+\.\d+\+0123456789ab$/);
    assert.equal(JSON.stringify(describeConfig(c)).includes(TOKEN), false);
    assert.equal(loadConfig({ SCANNER_APP_URL: 'http://web', SCANNER_TOKEN: TOKEN, ANTHROPIC_API_KEY: 'k' }, { readMemoryLimit: () => null }).credential, 'api-key');
    assert.equal(loadConfig({ SCANNER_APP_URL: 'http://web', SCANNER_TOKEN: TOKEN, SCANNER_CONCURRENCY: '2' }, { readMemoryLimit: () => null }).threadsPerRun, 1, 'thread pools are pinned to floor(3 ÷ concurrency)');
});

test('config: the scanner refuses a concurrency its memory limit cannot hold (3 g → 1, 5 g → 2, 7 g → 3)', () => {
    assert.deepEqual([capacityOf(null), capacityOf(2 * GIB), capacityOf(3 * GIB), capacityOf(4.9 * GIB), capacityOf(5 * GIB), capacityOf(7 * GIB)], [Infinity, 0, 1, 1, 2, 3]);
    assert.deepEqual([1, 2, 3].map((c) => memoryNeededBytes(c) / GIB), [3, 5, 7]);
    const env = { SCANNER_APP_URL: 'http://web', SCANNER_TOKEN: TOKEN };
    assert.equal(loadConfig({ ...env, SCANNER_CONCURRENCY: '1' }, { readMemoryLimit: () => 3 * GIB }).concurrency, 1);
    assert.throws(() => loadConfig({ ...env, SCANNER_CONCURRENCY: '2' }, { readMemoryLimit: () => 3 * GIB }), /needs a memory limit of 5 GiB, this container has 3\.00 GiB/);
    assert.throws(() => loadConfig({ ...env }, { readMemoryLimit: () => 2 * GIB }), /needs a memory limit of 3 GiB/);
    assert.equal(loadConfig({ ...env, SCANNER_CONCURRENCY: '2', SCANNER_MEMORY_LIMIT_MB: '5120' }, { readMemoryLimit: () => 1 }).concurrency, 2);
});

test('the intake lane never waits behind a running build', async (t) => {
    const { app, scanner } = await service(t);
    const build = await app.addScan({ design: DESIGN, options: { stub: { waitMs: 1500 } } });
    await until(() => build.status === 'running');
    const intake = await app.addScan({ lane: 'intake', original: Buffer.from('o') });
    await until(() => intake.status === 'queued', { timeoutMs: 1200, what: 'the intake to pass while the build runs' });
    assert.equal(build.status, 'running', 'the build was still running when the pre-check finished');
    assert.equal(scanner.status().lanes.build.active, 1);
    // …and the scan the intake queued waits for the build slot, then runs
    await until(() => build.status === 'ready' && intake.status === 'ready', { timeoutMs: 6000 });
    assert.equal(intake.run, 1);
});

test('two workers never cross scans: each run has its own process, lease, run number and scratch directory', async (t) => {
    const log = join(mkdtempSync(join(tmpdir(), 'scan-two-')), 'workers.jsonl');
    const { app, scanner } = await service(t, { concurrency: 2, stub: { log } });
    const scans = [];
    for (let i = 0; i < 6; i++) scans.push(await app.addScan({ design: DESIGN, options: { stub: { waitMs: 150 + 40 * (i % 3) } } }));
    let peak = 0; const watch = setInterval(() => { peak = Math.max(peak, scans.filter((s) => s.status === 'running').length); }, 10);
    await until(() => scans.every((s) => s.status === 'ready') && scanner.status().lanes.build.active === 0, { timeoutMs: 10_000 }); clearInterval(watch);
    assert.equal(peak, 2, 'two builds ran at once, never three');
    assert.equal(app.state.calls.some((c) => c.crossed), false, 'no call carried another scan\'s lease or run');
    for (const s of scans) {
        assert.equal(s.candidates.length, 1); assert.equal(s.candidates[0].design.elements[1].id, `scan-${s.id}`, 'the candidate stored for a scan was rendered by that scan\'s run');
        assert.ok(app.calls('', s.id).every((c) => c.lease === s.lease));
    }
    const workers = readFileSync(log, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
    assert.equal(new Set(workers.map((w) => w.pid)).size, 6, 'one child process per run');
    assert.equal(new Set(workers.map((w) => w.scratchDir)).size, 6);
    assert.ok(workers.every((w) => w.tmpdir === w.scratchDir && w.threads === '1'), 'temporary files land in the run\'s scratch; threads pinned to floor(3 ÷ 2)');
    assert.ok(workers.every((w) => !existsSync(w.scratchDir)), 'every scratch directory is gone');
});

test('protocol mismatch: the scanner keeps ticking, claims nothing, and says so in its health document', async (t) => {
    const { app, scanner, logs } = await service(t, { protocolVersion: 3 });
    await app.addScan({ design: DESIGN });
    await new Promise((ok) => setTimeout(ok, 400));
    assert.equal(app.calls('claim').length, 0);
    assert.ok(app.calls('tick').length >= 2);
    const s = scanner.status();
    assert.deepEqual(s.protocol, { ours: 1, app: 3, accepted: false });
    assert.equal(assess(s).ok, true, 'a scanner that is too old is alive — the app shows the notice');
    assert.ok(logs.some((l) => l.level === 'error' && /protocol mismatch/.test(l.msg)));
    app.state.protocolVersion = 2; // the app is rolled back to one that accepts N − 1… here: accepts 1
    await until(() => app.calls('claim').some((c) => c.answer?.job), { timeoutMs: 3000, what: 'claims to resume' });
});

test('scanner too old by instruments: the app refuses every claim with its reason; the scanner says so once and goes on asking', async (t) => {
    const { app, scanner, logs } = await service(t, { start: false });
    app.state.requireInstruments = ['ocr', 'an_instrument_of_a_newer_definition'];
    const scan = await app.addScan({ design: DESIGN });
    await scanner.start();
    await until(() => app.calls('claim').length >= 6);
    assert.ok(app.calls('claim').every((c) => c.answer.job === null && c.answer.reason === 'scanner_too_old'));
    assert.equal(scan.status, 'queued');
    assert.equal(logs.filter((l) => /refuses this scanner as too old/.test(l.msg)).length, 1);
    assert.equal(scanner.status().lanes.build.lastReason, 'scanner_too_old');
});

test('provider blocked: the run is released with the reason, the scanner probes on its timer and reports "ok" when the provider answers', async (t) => {
    const { app, scanner, logs } = await service(t, { stub: { probe: 'blocked' } });
    const scan = await app.addScan({ design: DESIGN, options: { stub: { result: { outcome: 'blocked', providerError: 'billing' } } } });
    await until(() => app.state.providerState === 'blocked');
    assert.equal(scan.status, 'queued'); assert.equal(scan.release.reason, 'provider_blocked');
    await until(() => scanner.provider.probes >= 2, { timeoutMs: 4000, what: 'two failed probes' });
    assert.equal(scanner.provider.state, 'blocked');
    assert.ok(app.state.ticks.some((x) => x.provider?.state === 'blocked'));
    assert.equal(app.calls('claim').filter((c) => c.answer?.job).length, 1, 'no claim is handed out while blocked');
    // the provider answers again: the next probe succeeds
    scanner.deps.workerEnv = { STUB_WORKER: JSON.stringify({ probe: 'ok' }) };
    scan.options.stub = { waitMs: 50 };
    await until(() => app.state.providerState === 'ok', { timeoutMs: 4000, what: 'the probe to clear the block' });
    assert.ok(app.state.ticks.some((x) => x.provider?.state === 'ok'));
    await until(() => scan.status === 'ready', { timeoutMs: 4000, what: 'the released scan to run' });
    assert.equal(scan.run, 2);
    assert.ok(logs.some((l) => /provider answers again/.test(l.msg)));
});

test('a block the app reports at start (an earlier scanner life) is probed at once', async (t) => {
    const { app, scanner } = await service(t, { start: false, stub: { probe: 'ok' } });
    app.state.providerState = 'blocked';
    await scanner.start();
    await until(() => app.state.providerState === 'ok', { timeoutMs: 3000 });
    assert.equal(scanner.provider.state, 'ok');
});

test('shutdown: claiming stops, every running job is released once with its usage, the scratch root is gone — well inside the limit', async (t) => {
    const { app, scanner, config } = await service(t, { concurrency: 2 });
    const builds = [await app.addScan({ design: DESIGN, options: { stub: { waitMs: 60_000 } } }), await app.addScan({ design: DESIGN, options: { stub: { waitMs: 60_000, ignoreAbort: true } } })];
    const intake = await app.addScan({ lane: 'intake', original: Buffer.from('o'), options: { stub: { waitMs: 60_000 } } });
    await until(() => builds.every((s) => s.status === 'running' && s.beats.length >= 1) && intake.leaseLive);
    const pids = scanner.children();
    assert.equal(pids.length, 3);
    let exitCode = null;
    const { shutdown } = installShutdown({ scanner, limitMs: 30_000 / 150 * 10, exit: (c) => { exitCode = c; }, signals: [], log: () => {} });
    const t0 = Date.now();
    await shutdown('SIGTERM');
    const ms = Date.now() - t0;
    assert.equal(exitCode, 0);
    assert.ok(ms < 1500, `shutdown took ${ms} ms (kill grace 0.3 s + release ≤ 0.3 s)`);
    for (const s of builds) { assert.equal(s.status, 'queued'); assert.equal(s.release.reason, 'shutdown'); assert.ok(s.release.usage.outputTokens >= 100); assert.equal(s.finish, null); }
    assert.equal(intake.status, 'checking'); assert.equal(intake.leaseLive, false);
    assert.equal(app.calls('release').length, 3, 'one release per job');
    assert.deepEqual(pids.filter((p) => alive(p)), [], 'no run process outlives the scanner');
    assert.equal(existsSync(config.scratchDir), false);
    const claims = app.calls('claim').length; await new Promise((ok) => setTimeout(ok, 250));
    assert.equal(app.calls('claim').length, claims, 'nothing is claimed after the signal');
    assert.equal(assess(scanner.status()).ok, false);
});

test('shutdown past its limit: the process trees are killed and the exit code says so', async () => {
    const killed = [];
    let code = null;
    const stuck = { stop: () => new Promise(() => {}), children: () => { killed.push('asked'); return []; } };
    const { shutdown } = installShutdown({ scanner: stuck, limitMs: 80, exit: (c) => { code = c; }, signals: [], log: () => {} });
    assert.equal(await shutdown('SIGTERM'), 1); assert.equal(code, 1); assert.deepEqual(killed, ['asked']);
    assert.equal(await shutdown('SIGTERM'), 1, 'a second signal joins the first shutdown');
});

test('the scratch root is swept at start: what a killed scanner left behind is removed', async (t) => {
    const { scanner, config } = await service(t, { start: false });
    const { mkdirSync, writeFileSync } = await import('node:fs');
    mkdirSync(join(config.scratchDir, 'build-deadbeef-r1-x'), { recursive: true }); writeFileSync(join(config.scratchDir, 'build-deadbeef-r1-x', 'working.png'), 'x');
    await scanner.start();
    assert.deepEqual(readdirSync(config.scratchDir), []);
});

test('health: 200 while the lanes poll; 503 when a lane with a free slot has not completed a claim poll for 60 s, or when stopping', async () => {
    const lane = (o = {}) => ({ slots: 1, active: 0, running: true, lastPollOkAgoS: 3, lastLoopAgoS: 3, held: false, ...o });
    const base = { now: 100_000, startedAt: 0, stopping: false, lanes: { intake: lane(), build: lane() } };
    assert.deepEqual(assess(base), { ok: true, reasons: [], upS: 100 });
    assert.deepEqual(assess({ ...base, lanes: { intake: lane({ lastPollOkAgoS: 61 }), build: lane() } }).reasons, ['intake_claim_poll_overdue']);
    assert.equal(assess({ ...base, lanes: { intake: lane(), build: lane({ active: 1, lastPollOkAgoS: 300 }) } }).ok, true, 'a lane whose slots are busy does not poll — a 6-minute build is not an outage');
    assert.equal(assess({ ...base, now: 30_000, lanes: { intake: lane({ lastPollOkAgoS: null }), build: lane({ lastPollOkAgoS: null }) } }).ok, true, 'the first 60 s after start');
    assert.deepEqual(assess({ ...base, lanes: { intake: lane({ lastPollOkAgoS: null }), build: lane({ lastPollOkAgoS: null }) } }).reasons, ['intake_claim_poll_overdue', 'build_claim_poll_overdue'], 'the app was never reached');
    assert.deepEqual(assess({ ...base, stopping: true }).reasons, ['stopping']);
    assert.equal(assess({ ...base, lanes: { intake: lane({ held: true, lastPollOkAgoS: 999 }), build: lane({ held: true, lastPollOkAgoS: 999 }) } }).ok, true);
});

test('health: the endpoint and the healthcheck command (exit 0 / 1); the document carries codes and numbers only', async (t) => {
    const { app, scanner } = await service(t);
    const h = startHealthServer({ port: 0, status: () => scanner.status() });
    const port = await h.ready; t.after(() => h.close());
    const res = await fetch(`http://127.0.0.1:${port}/health`);
    const doc = await res.json();
    assert.equal(res.status, 200); assert.equal(doc.status, 'ok'); assert.equal(doc.lanes.build.slots, 1); assert.equal(doc.protocol.accepted, true);
    assert.equal(JSON.stringify(doc).includes(TOKEN), false);
    assert.equal((await fetch(`http://127.0.0.1:${port}/other`)).status, 404);
    const out = [];
    assert.equal(await healthcheck({ port, out: (l) => out.push(l) }), 0);
    app.down(60_000);
    const unhealthy = startHealthServer({ port: 0, status: () => ({ ...scanner.status(), now: Date.now() + 61_000, lanes: Object.fromEntries(Object.entries(scanner.status().lanes).map(([k, l]) => [k, { ...l, lastPollOkAgoS: 61 }])) }) });
    const p2 = await unhealthy.ready; t.after(() => unhealthy.close());
    assert.equal((await fetch(`http://127.0.0.1:${p2}/health`)).status, 503);
    assert.equal(await healthcheck({ port: p2, out: (l) => out.push(l) }), 1);
    assert.equal(await healthcheck({ port: 1, out: (l) => out.push(l), timeoutMs: 500 }), 1, 'no scanner listening');
    assert.match(out[1], /claim_poll_overdue/);
});

test('logs carry ids, codes and counts — as JSON lines', () => {
    const lines = [];
    logger('json', (l) => lines.push(l))('info', 'run ended', { scan: 'abc', outcome: 'proposed', turns: 7 });
    const j = JSON.parse(lines[0]);
    assert.deepEqual([j.level, j.msg, j.scan, j.outcome, j.turns], ['info', 'run ended', 'abc', 'proposed', 7]);
    logger('silent', (l) => lines.push(l))('info', 'x'); assert.equal(lines.length, 1);
});
