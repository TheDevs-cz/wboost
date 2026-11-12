// One claimed job in the main process (JobRunner) with a scripted child process: the heartbeat is a timer of
// the main process, an abort kills the child's process tree by pid, the usage row is closed on every path,
// the scratch directory is gone afterwards. Times are §4.9 ÷ 150.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readdirSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HttpScannerApi } from '../src/protocol.mjs';
import { JobRunner, Lane } from '../src/lanes.mjs';
import { alive, descendants, killTree, memoryLimitBytes, processTable } from '../src/proc.mjs';
import { startFakeApp, definitionWith, until, TOKEN } from './support/fake-app.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const STUB = join(HERE, 'support', 'stub-worker.mjs');
const TIMING = { heartbeatSeconds: 0.1, heartbeatAttemptSeconds: 0.08, selfAbortSeconds: 0.7, killGraceSeconds: 0.3, releaseAttemptSeconds: 0.3, appCallAttemptSeconds: 0.3, appOutageToleranceSeconds: 0.6, runCapSeconds: 2.4, intakeLeaseSeconds: 1 };
const DESIGN = { canvas: { width: 200, height: 100 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }] };

async function fixture(t, { stub = {}, lane = 'build', timing = {}, options = {} } = {}) {
    const app = await startFakeApp({ files: definitionWith({ timing: { ...TIMING, ...timing } }) }); t.after(() => app.close());
    const scratchRoot = mkdtempSync(join(tmpdir(), 'scan-lanes-'));
    const scan = await app.addScan({ lane, design: DESIGN, original: Buffer.from('original'), options: { stub } });
    const scanner = new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: 't', timing: { ...TIMING, ...timing } });
    const { job } = await scanner.claim(lane, 2);
    const logs = [];
    const runner = new JobRunner({ scanner, job, options: { scratchRoot, workerPath: STUB, threads: 1, log: (level, msg, f) => logs.push({ level, msg, ...f }), ...options } });
    return { app, scan, scanner, job, runner, scratchRoot, logs };
}
const pidsOf = (file) => readFileSync(file, 'utf8').trim().split('\n').map((l) => JSON.parse(l));

test('a build that ends by itself: finish by the child, heartbeats by the main process, no closing call, scratch removed', async (t) => {
    const f = await fixture(t, { stub: { waitMs: 350 } });
    const s = await f.runner.run();
    assert.deepEqual([s.outcome, s.finish.status, s.closing.call, s.exit.code], ['proposed', 'ready', null, 0]);
    assert.ok(f.scan.beats.length >= 2, `beats: ${f.scan.beats.length}`);
    assert.equal(f.scan.release, null);
    assert.deepEqual(readdirSync(f.scratchRoot), [], 'the run\'s scratch directory is removed');
    assert.equal(s.killed.survivors, 0);
});

test('the heartbeat continues while the run\'s process is blocked: 2 s without a turn, an event or an IPC message', async (t) => {
    const f = await fixture(t, { stub: { blockMs: 2000 } });
    const s = await f.runner.run();
    assert.equal(s.outcome, 'proposed');
    const during = f.scan.beats.filter((b) => b.stage === 'build');
    assert.ok(during.length >= 15, `beats while the child's event loop was blocked: ${during.length}`);
    assert.ok(during.every((b) => b.usage.outputTokens === 100 && b.usage.model === 'claude-opus-5-5'), 'each carries the last usage the child reported');
    assert.ok(during.at(-1).usage.durationMs > during[0].usage.durationMs, 'the duration grows while the run is silent');
    assert.deepEqual(during.map((b) => b.seq), during.map((_, i) => during[0].seq + i), 'every beat has its own, growing seq');
    assert.equal(s.beats.failed, 0);
});

test('cancel during a build: the next beat says so, the child is told, nothing is finished, the closing beat records the usage', async (t) => {
    const f = await fixture(t, { stub: { waitMs: 5000 } });
    const run = f.runner.run();
    await until(() => f.scan.beats.length >= 2);
    f.app.end(f.scan, 'cancelled');
    const s = await run;
    assert.deepEqual([s.outcome, s.reason, s.closing.call, s.closing.status], ['cancelled', 'cancelled', 'beat', 'cancelled']);
    assert.equal(f.scan.finish, null); assert.equal(f.scan.release, null);
    assert.equal(f.scan.usage.outputTokens, 300, 'the last figures of the run reached the app');
    assert.ok(s.wallMs < 2000);
    assert.deepEqual(readdirSync(f.scratchRoot), []);
});

test('mode off, a lost lease (409) and a deleted project (410) each abort the run; only 410 sends no closing beat', async (t) => {
    for (const [end, reason, closing] of [
        [(f) => { f.app.state.mode = 'off'; f.app.end(f.scan, 'failed'); }, 'mode_off', 'beat'],
        [(f) => { f.scan.leaseLive = false; f.scan.status = 'queued'; }, 'lease_lost', 'beat'],
        [(f) => { f.app.state.scans.delete(f.scan.id); }, 'gone', null],
    ]) {
        const f = await fixture(t, { stub: { waitMs: 5000 } });
        const run = f.runner.run();
        await until(() => f.scan.beats.length >= 1);
        end(f);
        const s = await run;
        assert.deepEqual([s.outcome, s.reason, s.closing.call], ['cancelled', reason, closing]);
        assert.equal(f.scan.finish, null);
        assert.deepEqual(readdirSync(f.scratchRoot), []);
    }
});

test('self-abort: no beat is acknowledged → the run is aborted and its processes are dead before the lease would end', async (t) => {
    const f = await fixture(t, { stub: { waitMs: 60_000, ignoreAbort: true } });
    const t0 = Date.now();
    const run = f.runner.run();
    await new Promise((ok) => setTimeout(ok, 150));
    f.app.down(60_000);
    const s = await run;
    const ms = Date.now() - t0;
    assert.deepEqual([s.outcome, s.reason], ['aborted', 'app_unreachable']);
    // self-abort 0.7 s after the last acknowledged send + the kill grace 0.3 s; the lease (120 ÷ 150 = 0.8 s after … ) is the app's
    assert.ok(ms >= 700 && ms < 2500, `aborted after ${ms} ms`);
    assert.equal(s.killed.survivors, 0); assert.equal(alive(f.runner.child.pid), false);
    assert.deepEqual(readdirSync(f.scratchRoot), []);
});

test('abort kills the engine and OCR process groups — by pid: the child, a process in its group, one leading its own group, a grandchild', async (t) => {
    const spawnLog = join(mkdtempSync(join(tmpdir(), 'scan-pids-')), 'pids.jsonl');
    const f = await fixture(t, { stub: { spawn: spawnLog, waitMs: 60_000, ignoreAbort: true } });
    const run = f.runner.run();
    await until(() => existsSync(spawnLog) && f.scan.beats.length >= 1);
    const p = pidsOf(spawnLog)[0];
    const grandchild = Number(readFileSync(join(f.runner.scratchDir, 'grandchild.pid'), 'utf8'));
    const all = [p.worker, p.inGroup, p.ownGroup, p.shell, grandchild];
    assert.ok(all.every((pid) => alive(pid)), 'all five are running');
    assert.ok(descendants(p.worker).length >= 4);
    f.runner.abort('cancelled');
    const s = await run;
    assert.equal(s.outcome, 'aborted');
    assert.deepEqual(all.filter((pid) => alive(pid)), [], 'none is left — the one that led its own process group included');
    assert.equal(s.killed.survivors, 0); assert.ok(s.killed.processes >= 5);
    assert.ok(s.killed.ms < 3000);
    assert.deepEqual(readdirSync(f.scratchRoot), [], 'scratch directory empty after the abort');
});

test('a child that obeys the abort gets the grace to report its last usage; one that does not is killed after it', async (t) => {
    const obeys = await fixture(t, { stub: { waitMs: 60_000 } });
    let run = obeys.runner.run(); await until(() => obeys.scan.beats.length >= 1);
    let t0 = Date.now(); obeys.runner.abort('cancelled');
    let s = await run;
    assert.equal(s.result.outcome, 'cancelled'); assert.equal(s.usage.outputTokens, 300); assert.ok(Date.now() - t0 < 280, 'ended inside the grace');
    const ignores = await fixture(t, { stub: { waitMs: 60_000, ignoreAbort: true } });
    run = ignores.runner.run(); await until(() => ignores.scan.beats.length >= 1);
    t0 = Date.now(); ignores.runner.abort('cancelled');
    s = await run;
    assert.equal(s.result, null); assert.equal(s.exit.signal, 'SIGKILL'); assert.ok(Date.now() - t0 >= 300, 'the grace (0.3 s) was given');
    assert.equal(s.usage.outputTokens, 100, 'the last figures the child had reported');
});

test('a child that dies: one closing beat with the last usage, no release and no finish — the lease lapses and the reaper restarts the scan once', async (t) => {
    const f = await fixture(t, { stub: { crash: true } });
    const s = await f.runner.run();
    assert.deepEqual([s.outcome, s.exit.code, s.closing.call, s.closing.acknowledged], ['crashed', 7, 'beat', true]);
    assert.equal(f.scan.release, null); assert.equal(f.scan.finish, null); assert.equal(f.scan.status, 'running');
    assert.equal(f.scan.usage.outputTokens, 100);
    assert.deepEqual(readdirSync(f.scratchRoot), []);
});

test('a child that hangs past the run cap and one finish is killed by the main process\'s guard', async (t) => {
    const f = await fixture(t, { stub: { hang: true }, options: { guardMs: 400 } });
    const s = await f.runner.run();
    assert.deepEqual([s.outcome, s.reason, s.closing.call], ['aborted', 'hung', 'beat']);
    assert.equal(alive(f.runner.child.pid), false);
});

test('shutdown of a running build: abort, kill, ONE release with the usage — the scan is queued again, the restart not consumed', async (t) => {
    const f = await fixture(t, { stub: { waitMs: 60_000 } });
    const run = f.runner.run(); await until(() => f.scan.beats.length >= 1);
    const t0 = Date.now();
    const s = await f.runner.shutdown();
    assert.equal(await run, s);
    assert.deepEqual([s.outcome, s.reason, s.closing.call, s.closing.applied, s.closing.status], ['released', 'shutdown', 'release', true, 'queued']);
    assert.equal(f.app.calls('release').length, 1);
    assert.deepEqual([f.scan.release.reason, f.scan.release.usage.outputTokens, f.scan.release.run], ['shutdown', 300, 1]);
    assert.ok(Date.now() - t0 < 1000); assert.equal(f.scan.finish, null);
    assert.deepEqual(readdirSync(f.scratchRoot), []);
});

test('shutdown when the app does not answer: the release is ONE attempt cut at its limit; the job still ends (a lost lease then)', async (t) => {
    const f = await fixture(t, { stub: { waitMs: 60_000 } });
    const run = f.runner.run(); await until(() => f.scan.beats.length >= 1);
    f.app.hang(10_000);
    const t0 = Date.now();
    const s = await f.runner.shutdown();
    const ms = Date.now() - t0;
    assert.deepEqual([s.closing.call, s.closing.failed], ['release', 'timeout']);
    assert.equal(f.app.calls('release').length, 1);
    assert.ok(ms < 0.3 * 1000 + 0.3 * 1000 + 500, `kill grace + release limit: ${ms} ms`);
    await run;
});

test('shutdown of a running intake: the intake lease is given back (rule 3d)', async (t) => {
    const f = await fixture(t, { lane: 'intake', stub: { waitMs: 60_000 } });
    const run = f.runner.run(); await new Promise((ok) => setTimeout(ok, 200));
    const s = await f.runner.shutdown();
    assert.deepEqual([s.closing.call, s.closing.status, f.scan.status, f.scan.leaseLive], ['release', 'checking', 'checking', false]);
    assert.equal(f.scan.beats.length, 0, 'the intake sends no heartbeat');
    await run;
});

test('provider blocked (the child\'s probe failed): released with the reason — the app records the provider as blocked', async (t) => {
    const f = await fixture(t, { stub: { result: { outcome: 'blocked', providerError: 'billing' } } });
    const s = await f.runner.run();
    assert.deepEqual([s.outcome, s.closing.call, s.closing.providerBlocked, s.closing.status], ['blocked', 'release', true, 'queued']);
    assert.deepEqual([f.scan.release.reason, f.scan.release.providerError], ['provider_blocked', 'billing']);
    assert.equal(f.app.state.providerState, 'blocked');
});

test('Lane: polls every claim-poll interval, starts a job per free slot, asks again at once after a claim, never above its slots', async (t) => {
    const app = await startFakeApp({ files: definitionWith({ timing: TIMING }) }); t.after(() => app.close());
    const scanner = new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: 't', timing: TIMING });
    const scratchRoot = mkdtempSync(join(tmpdir(), 'scan-lane-'));
    const ended = [];
    for (let i = 0; i < 3; i++) await app.addScan({ design: DESIGN, options: { stub: { waitMs: 400 } } });
    const lane = new Lane({ name: 'build', slots: 2, capacity: 2, scanner, pollMs: 60, makeRunner: (job) => new JobRunner({ scanner, job, options: { scratchRoot, workerPath: STUB } }), onEnd: (_, s) => ended.push(s) }).start();
    await until(() => lane.active.size === 2);
    const firstTwo = app.calls('claim').filter((c) => c.answer.job);
    assert.ok(firstTwo[1].at - firstTwo[0].at < 55, 'the second claim followed the first without waiting for the poll interval');
    assert.equal([...app.state.scans.values()].filter((s) => s.status === 'running').length, 2);
    await until(() => ended.length === 3, { timeoutMs: 5000 });
    assert.ok(Math.max(...app.calls('claim').map((c) => c.body.capacity)) === 2);
    assert.deepEqual(ended.map((s) => s.outcome), ['proposed', 'proposed', 'proposed']);
    const s = lane.status(); assert.deepEqual([s.active, s.claimed, s.ended, s.running], [0, 3, 3, true]);
    await lane.stop();
    const n = app.calls('claim').length; await new Promise((ok) => setTimeout(ok, 200));
    assert.equal(app.calls('claim').length, n, 'a stopped lane does not claim');
});

test('proc: descendants, killTree and the cgroup memory limit reader', async () => {
    assert.ok(processTable().some((r) => r.pid === process.pid));
    assert.deepEqual((await killTree(99999999, { waitMs: 100 })).survivors, []);
    assert.equal(memoryLimitBytes(() => 'max\n'), null);
    assert.equal(memoryLimitBytes((p) => { if (p.endsWith('memory.max')) return '3221225472\n'; throw new Error('no'); }), 3 * 1024 ** 3);
    assert.equal(memoryLimitBytes((p) => { if (p.endsWith('limit_in_bytes')) return '5368709120'; throw new Error('no'); }), 5 * 1024 ** 3);
    assert.equal(memoryLimitBytes((p) => { if (p.endsWith('limit_in_bytes')) return '9223372036854771712'; throw new Error('no'); }), null, 'cgroup v1 "no limit"');
});
