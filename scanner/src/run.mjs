// One scan, end to end, against a JobApi (protocol.mjs): the intake lane (normalise + pre-check) and
// the build lane (pre-pass → model loop under caps → keep-best → measured report → finish).
// Nothing here knows whether the job came from a file or from the app.
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import sharp from 'sharp';
import { readDefinition, contextFromClaim } from './protocol.mjs';
import { Caps, limitsFromDefinition } from './caps.mjs';
import { ToolServer } from './tools/server.mjs';
import { prepareImage } from './tools/images.mjs';
import { TemplateBuild, TEMPLATE_APP_TOOLS } from './adapters/template.mjs';
import { buildTemplateReport } from './finish/report.mjs';
import { normaliseInChild } from './intake/normalise.mjs';
import { precheck } from './intake/precheck.mjs';
import { userContent, probe } from './engine/index.mjs';
import { outcomeForClass } from './engine/classify.mjs';
import { addUsage, emptyUsage, priceUsage, LIST_PRICES } from './engine/pricing.mjs';
import { loadRgb } from './instruments/raster.mjs';

const pricesOf = (definition) => (definition.prices?.models && Object.keys(definition.prices.models).length ? definition.prices : LIST_PRICES);

/** Usage in the shape a heartbeat / finish carries: totals + cost + per-model detail. */
export function usagePayload(usage, extra = {}) {
    const byModel = usage?.byModel ?? {};
    const total = Object.values(byModel).reduce(addUsage, emptyUsage());
    return {
        model: Object.keys(byModel)[0] ?? null,
        inputTokens: total.input, outputTokens: total.output, cacheReadTokens: total.cacheRead, cacheWriteTokens: total.cacheWrite5m + total.cacheWrite1h,
        cacheWrite5mTokens: total.cacheWrite5m, cacheWrite1hTokens: total.cacheWrite1h,
        costMicroUsd: Math.round((usage?.costUsd ?? 0) * 1e6), engineCostMicroUsd: usage?.engineCostUsd === null || usage?.engineCostUsd === undefined ? null : Math.round(usage.engineCostUsd * 1e6),
        priceVersion: usage?.priceVersion ?? null, byModel, ...extra,
    };
}

const timeoutAfter = (promise, ms, code) => { let t; return Promise.race([promise, new Promise((_, no) => { t = setTimeout(() => no(Object.assign(new Error(code), { code })), ms); t.unref?.(); })]).finally(() => clearTimeout(t)); };

/** One `release` attempt per job, cut at 20 s (architecture §4.9 shutdown): a release that does not arrive becomes a lost lease. */
export async function releaseOnce(api, payload, timing = {}) {
    try { await timeoutAfter(api.release(payload), (timing.releaseAttemptSeconds ?? 20) * 1000, 'release_timeout'); return true; } catch { return false; }
}

/**
 * Heartbeat: a timer in the scanner's main process, independent of model turns (architecture §4.9).
 * Every beat is its own attempt, cut at 10 s; the next goes out on the timer and is never queued
 * behind a hung one. It reports stage / note / usage, learns about a cancel, and aborts the run when
 * `selfAbortSeconds` (105) have passed since the SEND time of the last acknowledged beat — the
 * engine must stop spending before the app gives up the lease.
 */
export function heartbeat({ api, timing = {}, state, abort }) {
    const everyMs = (timing.heartbeatSeconds ?? 15) * 1000, selfAbortMs = (timing.selfAbortSeconds ?? 105) * 1000, attemptMs = (timing.heartbeatAttemptSeconds ?? 10) * 1000;
    let lastAckedSend = Date.now(), seq = 0, stopped = false;
    const beat = async () => {
        if (stopped) return;
        const sentAt = Date.now();
        try {
            const r = await timeoutAfter(api.beat({ seq: ++seq, stage: state.stage, note: state.note, noteParams: state.noteParams, round: state.round, usage: usagePayload(state.usage?.(), { turns: state.turns?.() ?? 0 }), resources: state.resources?.() ?? null }), attemptMs, 'beat_timeout');
            lastAckedSend = Math.max(lastAckedSend, sentAt);
            if (r?.cancel) abort('cancelled');
            else if (r?.mode === 'off') abort('mode_off');
        } catch (e) {
            if (e?.status === 409 || e?.status === 410 || e?.code === 'lease_lost' || e?.code === 'stale_run' || e?.code === 'gone') abort('lease_lost');
        }
    };
    const timer = setInterval(() => { if (!stopped && Date.now() - lastAckedSend > selfAbortMs) abort('app_unreachable'); beat(); }, everyMs);
    timer.unref?.();
    return { beat, stop: () => { stopped = true; clearInterval(timer); }, beats: () => seq };
}

// ------------------------------------------------------------------------------------------ intake

/**
 * → {outcome: 'passed' | 'stopped' | 'failed' | 'refused' | 'blocked', reason?, preCheck, sourceMeta, usage, error?, timings, resources}
 * and the finish payload has been sent (unless `blocked`, which releases).
 */
export async function runIntake({ job, api, engine, scratchDir, signal = null, probeResources = null }) {
    const { definition, files } = readDefinition(job.definition.files);
    const timing = definition.timing ?? {};
    const dir = join(scratchDir, `intake-${job.run}`);
    mkdirSync(dir, { recursive: true });
    const timings = {}; let t0 = Date.now();
    // the intake cap (120 s) is enforced here: the model call is aborted, what is left is one finish
    const cap = new AbortController();
    const capTimer = setTimeout(() => cap.abort(), (timing.intakeCapSeconds ?? 120) * 1000); capTimer.unref?.();
    signal?.addEventListener('abort', () => cap.abort(), { once: true });
    const usage0 = usagePayload(null);
    try {
        probeResources?.mark('intake:normalise');
        const original = join(dir, 'original.bin');
        writeFileSync(original, await api.source());
        const n = await normaliseInChild(original, dir, { format: definition.workingCopy?.format ?? 'webp', limits: definition.upload, working: { longEdgePx: definition.workingCopy?.longEdgePx ?? 4096, previewLongEdgePx: definition.workingCopy?.previewLongEdgePx ?? 1600 }, timeoutMs: (timing.normaliseSeconds ?? 20) * 1000 });
        timings.normaliseMs = Date.now() - t0;
        if (!n.ok) {
            const payload = { outcome: 'stopped', reason: n.stop, preCheck: { detail: n.detail ?? null }, usage: usage0 };
            await api.finish(payload);
            return { ...payload, timings, normalise: n };
        }
        const { readFile } = await import('node:fs/promises');
        await api.putArtifact('working', await readFile(n.files.working));
        await api.putArtifact('preview', await readFile(n.files.preview));
        t0 = Date.now();
        probeResources?.mark('intake:precheck');
        const pc = await precheck({ run: engine.run, definition, files, preview: await readFile(n.files.preview), kind: job.kind, scratchDir: dir, signal: cap.signal, prices: pricesOf(definition), approximate: !!job.approximate });
        timings.precheckMs = Date.now() - t0;
        const usage = usagePayload(pc.engine.usage, { turns: pc.engine.turns, durationMs: pc.engine.wallMs });
        const preCheck = { verdict: pc.verdict, confidence: pc.confidence, approximate: !!pc.decision.approximate, ms: timings.precheckMs };
        const extra = { timings, normalise: n, preCheckEngine: pc.engine, files: n.files };
        if (signal?.aborted) { await releaseOnce(api, { usage, reason: 'shutdown' }, timing); return { outcome: 'released', usage, ...extra }; }
        let payload;
        if (pc.decision.pass) payload = { outcome: 'passed', preCheck, sourceMeta: n.meta, usage };
        else if (pc.decision.stop) payload = { outcome: 'stopped', reason: pc.decision.stop, preCheck: { ...preCheck, continuable: !!pc.decision.continuable }, usage };
        else if (pc.decision.refused) payload = { outcome: 'refused', usage };                                  // rule 3c
        else {
            // the pre-check could not be made: a provider problem, not a property of the picture (rule 3c)
            if ((pc.decision.error?.class ?? 'unknown') === 'billing_auth') {
                const p = await probe({ run: engine.run, model: definition.models.preCheck, scratchDir: dir, settings: definition.preCheck ?? definition.engine });
                if (!p.ok) { await releaseOnce(api, { usage, reason: 'provider_blocked', providerError: pc.decision.error?.code ?? null }, timing); return { outcome: 'blocked', error: pc.decision.error, usage, ...extra }; }
            }
            payload = { outcome: 'failed', error: 'provider_unavailable', usage };
            await api.finish(payload);
            return { ...payload, detail: pc.decision.error ?? null, ...extra };
        }
        await api.finish(payload);
        return { ...payload, ...extra };
    } finally { clearTimeout(capTimer); }
}

// ------------------------------------------------------------------------------------------- build

/**
 * → {outcome: 'proposed' | 'failed' | 'refused' | 'blocked' | 'cancelled', candidate, report, proposal, error, usage, stats}
 * The finish (or release) payload has been sent.
 */
export async function runBuild({ job, api, engine, scratchDir, signal = null, probeResources = null, log = null }) {
    const { definition, files, kind } = readDefinition(job.definition.files);
    if (job.kind !== 'template') throw new Error(`no adapter for kind "${job.kind}" yet`);
    const spec = kind(job.kind);
    const prices = pricesOf(definition);
    const caps = new Caps(limitsFromDefinition(definition));
    const dir = join(scratchDir, `build-${job.run}`);
    mkdirSync(dir, { recursive: true });
    const controller = new AbortController();
    let abortReason = null;
    const abort = (reason) => { if (!abortReason) { abortReason = reason; controller.abort(); } };
    if (signal?.aborted) abort('shutdown'); else signal?.addEventListener('abort', () => abort('shutdown'), { once: true });
    const state = { stage: 'read', note: null, noteParams: null, round: 0, usage: () => liveUsage, turns: () => caps.turns, resources: () => probeResources?.summary?.() ?? null };
    let liveUsage = null;
    const hb = heartbeat({ api, timing: definition.timing, state, abort });
    const runTimer = setTimeout(() => abort('run_time'), caps.limits.runMs); runTimer.unref?.();
    let builderTimer = null;
    const stats = { stages: {}, engine: null, tools: [], prepass: null, caps: null, abortReason: null };
    const stage = (name, t0) => { stats.stages[name] = (stats.stages[name] ?? 0) + Date.now() - t0; };
    const finish = async (payload) => { hb.stop(); clearTimeout(runTimer); clearTimeout(builderTimer); stats.caps = caps.snapshot(); stats.abortReason = abortReason; stats.beats = hb.beats(); await api.finish(payload); return payload; };
    const failed = (error, usage) => finish({ outcome: 'failed', error, usage });
    let build = null, result = null;
    try {
        // ---- the working copy
        let t0 = Date.now();
        probeResources?.mark('build:read');
        const working = await api.source();
        const sourcePath = join(dir, 'working.png');
        await sharp(working).png({ compressionLevel: 3 }).toFile(sourcePath);
        const source = await loadRgb(sourcePath);
        const context = contextFromClaim(job.context);
        if (!context.fonts.length) return { ...(await failed('build', usagePayload(null))), detail: 'no_fonts', stats };
        const seq = { n: 0 };
        const app = {
            render: (design) => withRendererRetry(() => api.render({ seq: ++seq.n, design }), definition.timing, controller.signal),
            faces: (req) => withRendererRetry(() => api.faces({ seq: ++seq.n, ...req }), definition.timing, controller.signal),
        };
        build = new TemplateBuild({ source, sourcePath, context, app, caps, signal: controller.signal,
            settings: { availableScore: definition.fonts?.availableScore ?? 0.8, prepassLines: definition.fonts?.prePassLines ?? 6, faceSheets: definition.caps?.faceSheets ?? 12, snapDeltaE: definition.paletteSnapDeltaE ?? 2, ocrThreads: Number(process.env.SCANNER_OCR_THREADS ?? 3) },
            onNote: (note, params = null) => {
                if (['read', 'analyse', 'build', 'refine', 'finish'].includes(note)) { state.stage = note; if (note === 'refine') state.round = Math.min(2, Math.max(1, build.candidates.length)); } else { state.note = note; state.noteParams = params; }
            } });
        stage('read', t0);

        // ---- the mandatory measured pre-pass
        t0 = Date.now();
        probeResources?.mark('build:prepass');
        state.stage = 'analyse';
        const prepass = await build.prepass();
        stats.prepass = { ms: build.stats.prepassMs, ocr: build.stats.ocr, lines: prepass.ocrLines.length, ranked: prepass.ocrLines.filter((l) => l.font).length, faceSheets: build.stats.faceSheets, background: prepass.background.kind };
        writeFileSync(join(dir, 'prepass.json'), JSON.stringify(prepass, null, 2));
        stage('prepass', t0);
        if (controller.signal.aborted) throw Object.assign(new Error(abortReason), { code: 'aborted' });

        // ---- the model loop under caps
        t0 = Date.now();
        probeResources?.mark('build:model');
        state.stage = 'build';
        caps.startBuilder();
        builderTimer = setTimeout(() => abort('builder_time'), caps.limits.builderMs); builderTimer.unref?.();
        const server = new ToolServer({ definitions: spec.tools, impl: build.tools(), caps, imageRule: definition.imageRule,
            timeouts: { localMs: (definition.timing?.localToolTimeoutSeconds ?? 30) * 1000, appMs: (definition.timing?.appToolTimeoutSeconds ?? 95) * 1000 },
            appTools: TEMPLATE_APP_TOOLS, log: log ? (rec, args) => log({ ...rec, args }) : null });
        caps.takeImages(1); // the source picture in the first message
        const sourceImage = await prepareImage(source, definition.imageRule);
        const first = `${spec.task}\n\nPre-pass (measured by the scanner; boxes are [x, y, w, h] in canvas px${sourceImage.scale < 1 ? `; the attached picture is shown at scale ${sourceImage.scale.toFixed(4)} of the canvas` : ''}):\n${JSON.stringify(prepass)}`;
        result = await engine.run({
            model: definition.models.builder, effort: definition.effort?.[job.kind] ?? 'high', system: spec.system,
            content: userContent([sourceImage, first]), tools: server.definitions, dispatch: (name, args) => server.dispatch(name, args),
            finishSchema: spec.finishSchema, maxTurns: caps.limits.turns, maxBudgetUsd: caps.limits.budgetUsd,
            signal: controller.signal, settings: definition.engine, scratchDir: dir, prices,
            onEvent: (ev) => {
                if (ev.type === 'usage') { liveUsage = ev.usage; caps.noteCost(ev.usage.costUsd); }
                if (ev.type === 'turn') { liveUsage = ev.usage; caps.noteTurns(ev.turns); caps.noteCost(ev.usage.costUsd); const hit = caps.exceeded(); if (hit) abort(hit); }
                if (ev.type === 'retry') { caps.noteProviderLost(ev.lostMs ?? ev.delayMs ?? 0); state.note = 'provider_retry'; state.noteParams = { n: ev.attempt, of: ev.of }; }
            },
        });
        clearTimeout(builderTimer);
        liveUsage = result.usage;
        stats.engine = { name: engine.name, outcome: result.outcome, turns: result.turns, apiMs: result.apiMs, wallMs: result.wallMs, error: result.error, facts: result.facts };
        stats.tools = server.calls;
        stage('model', t0);

        const usage = usagePayload(result.usage, { turns: result.turns, durationMs: result.wallMs });
        // ---- provider-side endings
        if (abortReason === 'cancelled' || abortReason === 'mode_off' || abortReason === 'lease_lost' || abortReason === 'app_unreachable') { hb.stop(); clearTimeout(runTimer); stats.abortReason = abortReason; return { outcome: 'cancelled', reason: abortReason, usage, stats }; }
        if (abortReason === 'shutdown') { hb.stop(); clearTimeout(runTimer); await releaseOnce(api, { usage, reason: 'shutdown' }, definition.timing); stats.abortReason = abortReason; return { outcome: 'released', usage, stats }; }
        if (result.outcome === 'refusal') return { ...(await finish({ outcome: 'refused', usage })), stats };
        if (result.outcome === 'provider') {
            const o = outcomeForClass(result.error.class);
            if (o.state === 'blocked') {
                // confirm with a 1-token probe: a blocked provider is released, a failing scan is a failure of that scan
                const p = await probe({ run: engine.run, model: definition.models.preCheck, scratchDir: dir, settings: definition.engine });
                if (!p.ok) { hb.stop(); clearTimeout(runTimer); await releaseOnce(api, { usage, reason: 'provider_blocked', providerError: result.error.code }, definition.timing); return { outcome: 'blocked', error: result.error, usage, stats }; }
                if (!build.candidates.length) return { ...(await failed('build', usage)), detail: result.error, stats };
            } else if (!build.candidates.length) return { ...(await failed(o.code, usage)), detail: result.error, stats };
        }

        // ---- finish: keep-best, measured report
        t0 = Date.now();
        probeResources?.mark('build:finish');
        state.stage = 'finish';
        const capHit = abortReason ?? (result.outcome === 'max_turns' ? 'turns' : result.outcome === 'max_budget' ? 'budget' : null);
        // a run cut short by the provider is not charged — with or without a candidate (§4.9 provider-lost time)
        if (capHit && caps.providerLostDominates(definition.caps?.providerLostShare ?? 0.5)) return { ...(await failed('provider_unavailable', usage)), detail: capHit, stats };
        if (!build.candidates.length) return { ...(await failed(capHit ? 'timeout' : 'build', usage)), detail: capHit ?? result.error ?? 'no_candidate', stats };
        const modelFinish = result.outcome === 'finished' && result.finish && typeof result.finish === 'object' ? result.finish : null;
        const report = await buildTemplateReport({ build, finish: modelFinish, unfinished: !modelFinish, stopReason: modelFinish ? null : (capHit ?? result.outcome),
            rank: caps.runLeftMs() > 20_000 ? (lines) => build.rank(lines) : null });
        stage('finish', t0);
        if (report.status !== 'proposed') return { ...(await failed('build', usage)), detail: report.error, stats };
        const kept = build.candidates.find((c) => c.n === report.candidate);
        const { boxes, ...rest } = report;
        const payload = await finish({ outcome: 'proposed', candidate: report.candidate, report: rest, boxes, usage });
        return { ...payload, proposal: kept.design, render: { data: kept.bytes, mimeType: kept.mimeType }, modelFinish, stats, candidates: build.candidates.map((c) => ({ n: c.n, via: c.via, mae: c.mae, textFit: c.textFit, placeholderShare: c.placeholderShare })), rankings: build.rankings.map(({ text, box, group, origin, best, runnerUp, available }) => ({ text, box, group, origin, best, runnerUp, available })) };
    } catch (e) {
        hb.stop(); clearTimeout(runTimer); clearTimeout(builderTimer);
        stats.caps = caps.snapshot(); stats.abortReason = abortReason; stats.thrown = String(e?.message ?? e).slice(0, 300);
        const usage = usagePayload(liveUsage);
        if (abortReason === 'shutdown') { await releaseOnce(api, { usage, reason: 'shutdown' }, definition.timing); return { outcome: 'released', usage, stats }; }
        if (abortReason && !['run_time', 'builder_time', 'turns', 'budget'].includes(abortReason)) return { outcome: 'cancelled', reason: abortReason, usage, stats };
        const code = e?.code === 'renderer_unavailable' || e?.code === 'renderer_busy' ? 'renderer_unavailable' : abortReason ? 'timeout' : 'build';
        await api.finish({ outcome: 'failed', error: code, usage });
        return { outcome: 'failed', error: code, detail: stats.thrown, usage, stats };
    } finally {
        if (process.env.SCANNER_KEEP_SCRATCH !== '1') rmSync(dir, { recursive: true, force: true });
    }
}

/** A failing or busy renderer is retried inside the app-outage tolerance R (architecture §4.9, §7). */
export async function withRendererRetry(call, timing = {}, signal = null) {
    const until = Date.now() + (timing.appOutageToleranceSeconds ?? 60) * 1000;
    for (let attempt = 0; ; attempt++) {
        try {
            const r = await call();
            if (r?.code === 'renderer_busy' && Date.now() < until && !signal?.aborted) { await new Promise((ok) => setTimeout(ok, 1500)); continue; }
            if (r?.code === 'renderer_busy' || r?.code === 'renderer_unavailable') throw Object.assign(new Error(r.code), { code: r.code });
            return r;
        } catch (e) {
            const fatal = signal?.aborted || Date.now() >= until || e?.status === 409 || e?.status === 410 || ['lease_lost', 'stale_run', 'gone', 'candidate_limit'].includes(e?.code);
            if (fatal) throw Object.assign(e, { code: e.code ?? 'renderer_unavailable' });
            await new Promise((ok) => setTimeout(ok, Math.min(5000, 1000 * (attempt + 1))));
        }
    }
}

/** Claims and runs one scan through both lanes (the file driver's whole life; a building block of the lanes in step B). */
export async function scanOnce({ scanner, engine, scratchDir, signal = null, probeResources = null, log = null, skipIntake = false }) {
    const out = { intake: null, build: null };
    if (!skipIntake) {
        const c = await scanner.claim('intake');
        if (c.job) out.intake = await runIntake({ job: c.job, api: c.api, engine, scratchDir, signal, probeResources });
        if (out.intake && out.intake.outcome !== 'passed') return out;
    }
    probeResources?.mark('claim');
    const c = await scanner.claim('build');
    if (c.job) out.build = await runBuild({ job: c.job, api: c.api, engine, scratchDir, signal, probeResources, log });
    return out;
}
