// The child process of ONE run (architecture §7: one child process per run — nothing grows across scans).
// The main process forks it, hands it one claimed job over IPC and owns everything around it: the
// heartbeat, the abort, the kill of this process's group, the release, the scratch directory.
//
//   main → worker   {type: 'job', lane: 'intake' | 'build', job, app: {baseUrl, token, scannerVersion, timing}, scratchDir, engine}
//                   {type: 'probe', model, settings, scratchDir, engine}
//                   {type: 'abort', reason}            shutdown | cancelled | mode_off | ended | lease_lost | gone | app_unreachable
//   worker → main   {type: 'ready'}
//                   {type: 'state', state: {stage, note, noteParams, round, usage, turns, resources}}
//                   {type: 'result', result}           then the worker exits
//
// The worker sends `finish` itself (it holds the report); it never sends a beat or a release.
import { pathToFileURL } from 'node:url';
import { mkdirSync } from 'node:fs';
import sharp from 'sharp';
import { HttpScannerApi } from './protocol.mjs';
import { runBuild, runIntake, wireUsage } from './run.mjs';
import { engine as pickEngine, probe } from './engine/index.mjs';
import { ResourceProbe } from './resources.mjs';
import { descendants } from './proc.mjs';

const controller = new AbortController();
let started = false;

const send = (m) => new Promise((ok) => { if (!process.connected) return ok(false); process.send(m, (err) => ok(!err)); });

async function loadEngine(spec) {
    if (spec && typeof spec === 'object' && spec.module) {
        // tests: a scripted engine from a module — {run(options)}
        const mod = await import(pathToFileURL(spec.module).href);
        return { name: spec.name ?? 'module', run: mod.run ?? mod.default };
    }
    return pickEngine(spec || 'sdk');
}

/** What crosses the process boundary of a result: codes, counts and numbers — no design, no picture, no text of one. */
function brief(lane, r) {
    const stats = r.stats ?? {};
    return {
        lane, outcome: r.outcome, reason: r.reason ?? null, error: typeof r.error === 'string' ? r.error : (r.error?.code ?? null),
        providerError: r.providerError ?? null, detail: typeof r.detail === 'string' ? r.detail.slice(0, 200) : (r.detail?.code ?? null),
        candidate: r.candidate ?? null, unfinished: r.report?.unfinished ?? null, finish: r.finish ?? null, usage: wireUsage(r.usage),
        timings: r.timings ?? null, preCheck: r.preCheck ? { verdict: r.preCheck.verdict ?? null, confidence: r.preCheck.confidence ?? null, ms: r.preCheck.ms ?? null } : null,
        stages: stats.stages ?? null, caps: stats.caps ? { turns: stats.caps.turns, costUsd: stats.caps.costUsd, candidates: stats.caps.candidates, rejections: stats.caps.rejections, images: stats.caps.images, builderMs: stats.caps.builderMs, runMs: stats.caps.runMs, providerLostMs: stats.caps.providerLostMs } : null,
        abortReason: stats.abortReason ?? null, rendererFailures: stats.rendererFailures ?? 0,
        engine: stats.engine ? { outcome: stats.engine.outcome, turns: stats.engine.turns, apiMs: stats.engine.apiMs, error: stats.engine.error?.code ?? null, refusalFallback: stats.engine.facts?.refusalFallback ?? null, refusal: stats.engine.facts?.refusal?.category ?? null } : null,
        prepass: stats.prepass ?? null, fontVerification: r.report?.measures?.fontVerification ?? null,
    };
}
async function run(m) {
    const threads = Math.max(1, Number(process.env.SCANNER_OCR_THREADS ?? 3));
    sharp.concurrency(threads);
    mkdirSync(m.scratchDir, { recursive: true });
    const engine = await loadEngine(m.engine);
    if (m.type === 'probe') {
        const p = await probe({ run: engine.run, model: m.model, scratchDir: m.scratchDir, settings: m.settings ?? {}, signal: controller.signal });
        return { lane: 'probe', outcome: p.ok ? 'ok' : 'blocked', error: p.error?.code ?? null, errorClass: p.error?.class ?? null };
    }
    const scanner = new HttpScannerApi({ baseUrl: m.app.baseUrl, token: m.app.token, scannerVersion: m.app.scannerVersion, timing: m.app.timing });
    const api = scanner.forJob(m.job, { signal: controller.signal });
    // the latest state only: a burst of usage events becomes one message
    let pending = null, timer = null;
    const flush = () => { timer = null; if (pending) { const state = pending; pending = null; send({ type: 'state', state }); } };
    const onState = (s) => { pending = { stage: s.stage ?? null, note: s.note ?? null, noteParams: s.noteParams ?? null, round: s.round ?? null, usage: wireUsage(s.usage), turns: s.turns ?? 0, resources: s.resources ?? null }; if (!timer) timer = setTimeout(flush, 200); };
    const host = { ownsHeartbeat: false, ownsRelease: false, onState };
    const probeResources = new ResourceProbe({ intervalMs: 1000 }).start();
    try {
        const r = m.lane === 'intake'
            ? await runIntake({ job: m.job, api, engine, scratchDir: m.scratchDir, signal: controller.signal, probeResources, host })
            : await runBuild({ job: m.job, api, engine, scratchDir: m.scratchDir, signal: controller.signal, probeResources, host });
        clearTimeout(timer); flush();
        return { ...brief(m.lane, r), resources: await probeResources.stop() };
    } finally { clearTimeout(timer); }
}

process.on('message', async (m) => {
    if (m?.type === 'abort') { if (!controller.signal.aborted) controller.abort(typeof m.reason === 'string' ? m.reason : 'shutdown'); return; }
    if ((m?.type !== 'job' && m?.type !== 'probe') || started) return;
    started = true;
    let result;
    try { result = await run(m); } catch (e) {
        // an app call that failed for the whole tolerance, or a bug: the main process decides (the lease lapses)
        result = { lane: m.lane ?? m.type, outcome: controller.signal.aborted ? 'aborted' : 'error', reason: controller.signal.aborted ? String(controller.signal.reason) : null, error: e?.code ?? 'error', detail: String(e?.message ?? e).slice(0, 200), usage: null };
    }
    await send({ type: 'result', result });
    // whoever is still below this process ends with it (the main process kills the group too — unless it is gone)
    endTree(0);
});
/** Kills every process that descends from this one — the engine binary, the OCR sidecar — and exits. */
let ending = false;
function endTree(code) {
    ending = true;
    for (const d of descendants(process.pid)) { try { process.kill(d.pid, 'SIGKILL'); } catch { /* already gone */ } }
    try { process.disconnect?.(); } catch { /* the channel is closed */ }
    process.exit(code);
}

// The main process died (an OOM kill, `docker kill`): nothing may go on spending, and nobody is left to kill this
// run's process tree — so this process ends it itself. The abort gets 3 s to stop the engine the ordinary way
// (the run then returns and ends through `endTree` above); after that whatever still descends from here is
// killed by pid. Without that the engine binary outlived the run's process by 2–3 s (measured with the real
// provider: the run's process gone 2.4 s after the kill, the engine 4.7–5.0 s after it).
process.on('disconnect', () => {
    if (ending) return;
    if (!controller.signal.aborted) controller.abort('shutdown');
    setTimeout(() => endTree(1), 3000).unref();
});
// SIGTERM reaches the whole group at a container stop: the main process drives the shutdown over IPC
process.on('SIGTERM', () => {});
process.on('SIGINT', () => {});
send({ type: 'ready' });
