// A stand-in for src/worker.mjs: the same IPC, a scripted run. What it does comes from the job's `options.stub`
// (or the STUB_WORKER environment variable): block the event loop, spawn children, report usage, finish, crash.
import { spawn } from 'node:child_process';
import { appendFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { HttpScannerApi } from '../../src/protocol.mjs';

const send = (m) => new Promise((ok) => { if (!process.connected) return ok(); process.send(m, () => ok()); });
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
let abortReason = null, onAbort = null;
const usage = (n) => ({ provider: 'anthropic', model: 'claude-opus-5-5', inputTokens: 1000 * n, outputTokens: 100 * n, cacheReadTokens: 0, cacheWriteTokens: 0, engineCostMicroUsd: 6000 * n, turns: n, durationMs: 0 });

process.on('message', async (m) => {
    if (m.type === 'abort') { abortReason = m.reason; onAbort?.(); return; }
    if (m.type === 'probe') { const b = JSON.parse(process.env.STUB_WORKER ?? '{}'); await send({ type: 'result', result: { lane: 'probe', outcome: b.probe ?? 'ok', error: b.probe === 'blocked' ? 'billing' : null } }); process.exit(0); }
    if (m.type !== 'job') return;
    const b = { ...JSON.parse(process.env.STUB_WORKER ?? '{}'), ...(m.job.options?.stub ?? {}) };
    mkdirSync(m.scratchDir, { recursive: true });
    writeFileSync(join(m.scratchDir, 'leftover.bin'), Buffer.alloc(1024));
    if (b.log) appendFileSync(b.log, JSON.stringify({ scan: m.job.id, run: m.job.run, lane: m.lane, pid: process.pid, scratchDir: m.scratchDir, tmpdir: process.env.TMPDIR, threads: process.env.SCANNER_OCR_THREADS }) + '\n');
    if (b.spawn) {
        // like the engine binary (in this process's group), like a sidecar that leads its own group, and a grandchild
        const inGroup = spawn('sleep', ['300'], { stdio: 'ignore' });
        const ownGroup = spawn('sleep', ['300'], { stdio: 'ignore', detached: true });
        const shell = spawn('sh', ['-c', 'sleep 300 & echo $! > "$0"; wait', join(m.scratchDir, 'grandchild.pid')], { stdio: 'ignore' });
        await sleep(150);
        appendFileSync(b.spawn, JSON.stringify({ scan: m.job.id, worker: process.pid, inGroup: inGroup.pid, ownGroup: ownGroup.pid, shell: shell.pid }) + '\n');
    }
    await send({ type: 'state', state: { stage: 'build', note: 'measuring', noteParams: null, round: 0, usage: usage(1), turns: 1 } });
    if (b.crash) process.exit(7);
    if (b.blockMs) { const end = Date.now() + b.blockMs; while (Date.now() < end) { /* the event loop is blocked: no IPC, no timer */ } }
    if (b.waitMs) await Promise.race([sleep(b.waitMs), b.ignoreAbort ? new Promise(() => {}) : new Promise((ok) => { onAbort = ok; if (abortReason) ok(); })]);
    if (b.hang) await new Promise(() => {});
    await send({ type: 'state', state: { stage: 'finish', note: null, noteParams: null, round: 1, usage: usage(3), turns: 3 } });
    if (abortReason && !b.ignoreAbort) { await send({ type: 'result', result: { lane: m.lane, outcome: abortReason === 'shutdown' ? 'released' : 'cancelled', reason: abortReason, usage: usage(3) } }); process.exit(0); }
    if (b.result) { await send({ type: 'result', result: { lane: m.lane, usage: usage(3), ...b.result } }); process.exit(0); }
    const api = new HttpScannerApi({ baseUrl: m.app.baseUrl, token: m.app.token, scannerVersion: m.app.scannerVersion, timing: m.app.timing }).forJob(m.job);
    let finish;
    if (m.lane === 'intake') {
        await api.source(); await api.putArtifact('working', Buffer.from('working')); await api.putArtifact('preview', Buffer.from('preview'));
        finish = await api.finish({ outcome: 'passed', preCheck: { verdict: 'design' }, usage: usage(1) });
        await send({ type: 'result', result: { lane: 'intake', outcome: 'passed', finish, usage: usage(1) } });
    } else {
        const design = { canvas: { width: 200, height: 100 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }, { kind: 'shape', id: `scan-${m.job.id}`, shape: 'rectangle', x: 10, y: 10, width: 50, height: 50, fill: '#112233' }] };
        const r = await api.render({ seq: 1, design });
        finish = await api.finish({ outcome: b.outcome ?? 'proposed', candidate: r.candidate, report: { status: 'proposed', candidate: r.candidate }, ...(b.outcome === 'failed' ? { error: 'build' } : {}), usage: usage(3) });
        await send({ type: 'result', result: { lane: 'build', outcome: b.outcome ?? 'proposed', candidate: r.candidate, finish, usage: usage(3) } });
    }
    process.exit(0);
});
process.on('SIGTERM', () => {});
send({ type: 'ready' });
