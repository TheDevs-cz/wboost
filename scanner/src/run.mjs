// One scan, end to end, against a JobApi (protocol.mjs): the intake lane (normalise + pre-check) and
// the build lane (pre-pass → model loop under caps → keep-best → measured report → finish).
// Nothing here knows whether the job came from a file or from the app.
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import sharp from 'sharp';
import { readDefinition, contextFromClaim } from './protocol.mjs';
import { Heartbeat } from './timers.mjs';
import { Caps, limitsFromDefinition } from './caps.mjs';
import { ToolServer } from './tools/server.mjs';
import { prepareImage } from './tools/images.mjs';
import { TemplateBuild, TEMPLATE_APP_TOOLS } from './adapters/template.mjs';
import { buildTemplateReport } from './finish/report.mjs';
import { CalendarBuild, CALENDAR_APP_TOOLS } from './adapters/calendar.mjs';
import { buildCalendarReport } from './finish/calendar-report.mjs';
import { normaliseInChild } from './intake/normalise.mjs';
import { precheck } from './intake/precheck.mjs';
import { userContent, probe } from './engine/index.mjs';
import { outcomeForClass } from './engine/classify.mjs';
import { addUsage, emptyUsage, priceUsage, LIST_PRICES } from './engine/pricing.mjs';
import { loadRgb } from './instruments/raster.mjs';

/**
 * The lines of text the reading found, as the app takes them in a heartbeat (`textBoxes`): whole numbers in the
 * pixels of the working copy, inside the picture, at most 300 boxes, no text. → {width, height, boxes} | null
 */
export function textBoxesOf(boxes, source) {
    const W = source.width, H = source.height;
    const out = [];
    for (const b of boxes ?? []) {
        if (!b) continue;
        const x = Math.max(0, Math.round(b.x)), y = Math.max(0, Math.round(b.y));
        const w = Math.min(W - x, Math.round(b.w)), h = Math.min(H - y, Math.round(b.h));
        if (w >= 1 && h >= 1 && x < W && y < H) out.push([x, y, w, h]);
        if (out.length >= 300) break;
    }
    return out.length ? { width: W, height: H, boxes: out } : null;
}

const pricesOf = (definition) => (definition.prices?.models && Object.keys(definition.prices.models).length ? definition.prices : LIST_PRICES);

/**
 * Usage in the shape a heartbeat / finish / release carries — CUMULATIVE for this (scan, run, lane).
 * The app prices a row from `model` + tokens with the definition's table. When a run used more than one
 * model (a harness that re-ran a refused turn elsewhere before it was stopped), no single model prices
 * the tokens: `model` is then `mixed` — unknown to the table — and `engineCostMicroUsd` carries the
 * scanner's own sum, every model at its own price, which is what the app books for an unknown model.
 */
export function usagePayload(usage, extra = {}) {
    const byModel = usage?.byModel ?? {};
    const models = Object.keys(byModel);
    const total = Object.values(byModel).reduce(addUsage, emptyUsage());
    const costMicroUsd = Math.round((usage?.costUsd ?? 0) * 1e6);
    const engineCostMicroUsd = usage?.engineCostUsd === null || usage?.engineCostUsd === undefined ? null : Math.round(usage.engineCostUsd * 1e6);
    return {
        provider: 'anthropic', model: models.length > 1 ? 'mixed' : (models[0] ?? null),
        inputTokens: total.input, outputTokens: total.output, cacheReadTokens: total.cacheRead, cacheWriteTokens: total.cacheWrite5m + total.cacheWrite1h,
        cacheWrite5mTokens: total.cacheWrite5m, cacheWrite1hTokens: total.cacheWrite1h,
        costMicroUsd, engineCostMicroUsd: models.length > 1 ? costMicroUsd : engineCostMicroUsd,
        priceVersion: usage?.priceVersion ?? null, byModel, ...extra,
    };
}

/**
 * The usage the run's process hands to the main process, which sends it with every beat, with the release and
 * with the closing beat — the fields the app reads. The cache-write split travels with it: the app prices 1-hour
 * writes at their own rate, and a beat is the only word a killed or replaced run gets in.
 */
export function wireUsage(u) {
    if (!u) return null;
    const cacheWriteTokens = u.cacheWriteTokens ?? 0;
    const cacheWrite1hTokens = Math.min(u.cacheWrite1hTokens ?? 0, cacheWriteTokens);
    return {
        provider: u.provider ?? 'anthropic', model: u.model ?? null, inputTokens: u.inputTokens ?? 0, outputTokens: u.outputTokens ?? 0,
        cacheReadTokens: u.cacheReadTokens ?? 0, cacheWriteTokens, cacheWrite5mTokens: u.cacheWrite5mTokens ?? (cacheWriteTokens - cacheWrite1hTokens), cacheWrite1hTokens,
        engineCostMicroUsd: u.engineCostMicroUsd ?? 0, turns: u.turns ?? 0, durationMs: u.durationMs ?? 0, costMicroUsd: u.costMicroUsd ?? 0,
    };
}

/** What the caller of a lane owns. The file driver runs everything in one process; under main.mjs the
 *  MAIN process owns the heartbeat and every release, and the run's child process reports its state. */
const HOST = Object.freeze({ ownsHeartbeat: true, ownsRelease: true, onState: null });
const reasonOf = (signal) => (typeof signal?.reason === 'string' ? signal.reason : 'shutdown');
const CAP_REASONS = ['run_time', 'builder_time', 'turns', 'budget'];

const timeoutAfter = (promise, ms, code) => { let t; return Promise.race([promise, new Promise((_, no) => { t = setTimeout(() => no(Object.assign(new Error(code), { code })), ms); t.unref?.(); })]).finally(() => clearTimeout(t)); };

/** One `release` attempt per job, cut at 20 s (architecture §4.9 shutdown): a release that does not arrive becomes a lost lease. */
export async function releaseOnce(api, payload, timing = {}) {
    try { await timeoutAfter(api.release(payload), (timing.releaseAttemptSeconds ?? 20) * 1000, 'release_timeout'); return true; } catch { return false; }
}

// ------------------------------------------------------------------------------------------ intake

/**
 * → {outcome: 'passed' | 'stopped' | 'failed' | 'refused' | 'blocked', reason?, preCheck, sourceMeta, usage, error?, timings, resources}
 * and the finish payload has been sent (unless `blocked`, which releases).
 */
export async function runIntake({ job, api, engine, scratchDir, signal = null, probeResources = null, host = {} }) {
    host = { ...HOST, ...host };
    const { definition, files } = readDefinition(job.definition.files);
    const timing = definition.timing ?? {};
    const dir = join(scratchDir, `intake-${job.run}`);
    mkdirSync(dir, { recursive: true });
    const timings = {}; let t0 = Date.now();
    // the intake cap (120 s) is enforced here: the model call is aborted, what is left is one finish
    const cap = new AbortController();
    const capTimer = setTimeout(() => cap.abort(), (timing.intakeCapSeconds ?? 120) * 1000); capTimer.unref?.();
    if (signal?.aborted) cap.abort(); else signal?.addEventListener('abort', () => cap.abort(), { once: true });
    let usage = usagePayload(null);
    const state = (stage) => host.onState?.({ stage, note: null, noteParams: null, round: null, usage, turns: 0 });
    // an abort from outside (shutdown; the scan was cancelled): nothing is finished, the lease goes back
    const given = async () => { const reason = reasonOf(signal); if (reason === 'shutdown' && host.ownsRelease) await releaseOnce(api, { usage, reason: 'shutdown' }, timing); return { outcome: reason === 'shutdown' ? 'released' : 'cancelled', reason, usage, timings }; };
    const finish = async (payload, extra = {}) => ({ ...payload, ...extra, finish: await api.finish(payload) });
    try {
        probeResources?.mark('intake:normalise');
        state('check');
        const original = join(dir, 'original.bin');
        writeFileSync(original, await api.source());
        if (signal?.aborted) return await given();
        const n = await normaliseInChild(original, dir, { format: definition.workingCopy?.format ?? 'webp', limits: { ...definition.upload, ...(definition.kinds?.[job.kind]?.upload ?? {}) }, working: { longEdgePx: definition.workingCopy?.longEdgePx ?? 4096, previewLongEdgePx: definition.workingCopy?.previewLongEdgePx ?? 1600 }, timeoutMs: (timing.normaliseSeconds ?? 20) * 1000, signal });
        timings.normaliseMs = Date.now() - t0;
        if (signal?.aborted) return await given();
        if (!n.ok) return await finish({ outcome: 'stopped', reason: n.stop, preCheck: { detail: n.detail ?? null }, usage }, { timings, normalise: n });
        const workingBytes = await readFile(n.files.working);
        await api.putArtifact('working', workingBytes);
        await api.putArtifact('preview', await readFile(n.files.preview));
        timings.transferMs = Date.now() - t0 - timings.normaliseMs;
        if (signal?.aborted) return await given();
        t0 = Date.now();
        probeResources?.mark('intake:precheck');
        const pc = await precheck({ run: engine.run, definition, files, preview: await readFile(n.files.preview), kind: job.kind, scratchDir: dir, signal: cap.signal, prices: pricesOf(definition), approximate: !!job.approximate });
        timings.precheckMs = Date.now() - t0;
        usage = usagePayload(pc.engine.usage, { turns: pc.engine.turns, durationMs: pc.engine.wallMs });
        const preCheck = { verdict: pc.verdict, confidence: pc.confidence, approximate: !!pc.decision.approximate, ms: timings.precheckMs };
        const extra = { timings, normalise: n, preCheckEngine: pc.engine, files: n.files };
        if (signal?.aborted) return { ...(await given()), ...extra };
        if (pc.decision.pass) return await finish({ outcome: 'passed', preCheck, sourceMeta: { ...n.meta, workingBytes: workingBytes.length }, usage }, extra);
        if (pc.decision.stop) return await finish({ outcome: 'stopped', reason: pc.decision.stop, preCheck: { ...preCheck, continuable: !!pc.decision.continuable }, usage }, extra);
        if (pc.decision.refused) return await finish({ outcome: 'refused', usage }, extra);                              // rule 3c
        // the pre-check could not be made: a provider problem, not a property of the picture (rule 3c)
        if ((pc.decision.error?.class ?? 'unknown') === 'billing_auth') {
            const p = await probe({ run: engine.run, model: definition.models.preCheck, scratchDir: dir, settings: definition.preCheck ?? definition.engine });
            if (!p.ok) {
                const providerError = pc.decision.error?.code ?? null;
                if (host.ownsRelease) await releaseOnce(api, { usage, reason: 'provider_blocked', providerError }, timing);
                return { outcome: 'blocked', error: pc.decision.error, providerError, usage, ...extra };
            }
        }
        return await finish({ outcome: 'failed', error: 'provider_unavailable', usage }, { detail: pc.decision.error ?? null, ...extra });
    } catch (e) {
        if (signal?.aborted) return await given();
        // 409 / 410 on a transfer: this intake no longer holds the scan (cancelled, mode off, project deleted)
        if (e?.runOver) return { outcome: 'cancelled', reason: e.code === 'gone' ? 'gone' : 'lease_lost', usage, timings };
        throw e;
    } finally {
        clearTimeout(capTimer);
        if (process.env.SCANNER_KEEP_SCRATCH !== '1') rmSync(dir, { recursive: true, force: true });
    }
}

// ------------------------------------------------------------------------------------------- build

/**
 * → {outcome: 'proposed' | 'failed' | 'refused' | 'blocked' | 'cancelled', candidate, report, proposal, error, usage, stats}
 * The finish (or release) payload has been sent.
 */
export async function runBuild({ job, api, engine, scratchDir, signal = null, probeResources = null, log = null, host = {} }) {
    host = { ...HOST, ...host };
    const { definition, files, kind } = readDefinition(job.definition.files);
    if (!['template', 'calendar'].includes(job.kind)) throw new Error(`no adapter for kind "${job.kind}"`);
    const calendar = job.kind === 'calendar';
    const spec = kind(job.kind);
    const prices = pricesOf(definition);
    const caps = new Caps(limitsFromDefinition(definition));
    const dir = join(scratchDir, `build-${job.run}`);
    mkdirSync(dir, { recursive: true });
    const controller = new AbortController();
    let abortReason = null;
    const abort = (reason) => { if (!abortReason) { abortReason = reason; controller.abort(); } };
    if (signal?.aborted) abort(reasonOf(signal)); else signal?.addEventListener('abort', () => abort(reasonOf(signal)), { once: true });
    const started = Date.now();
    const state = { stage: 'read', note: null, noteParams: null, round: 0, textBoxes: null };
    let liveUsage = null;
    const snapshot = () => ({ stage: state.stage, note: state.note, noteParams: state.noteParams, round: state.round, textBoxes: state.textBoxes, usage: usagePayload(liveUsage, { turns: caps.turns, durationMs: Date.now() - started }), turns: caps.turns, resources: beatResources(probeResources) });
    const changed = () => { try { host.onState?.(snapshot()); } catch { /* an observer never breaks the run */ } };
    const hb = host.ownsHeartbeat ? new Heartbeat({ api, timing: definition.timing, state: snapshot, onAbort: abort }).start() : { stop() {}, summary: () => null };
    const runTimer = setTimeout(() => abort('run_time'), caps.limits.runMs); runTimer.unref?.();
    let builderTimer = null;
    const stats = { stages: {}, engine: null, tools: [], prepass: null, caps: null, abortReason: null };
    const stage = (name, t0) => { stats.stages[name] = (stats.stages[name] ?? 0) + Date.now() - t0; };
    // the heartbeat goes on while the finish is sent: the lease protects the call (§4.9), not the run cap
    const finish = async (payload) => {
        clearTimeout(runTimer); clearTimeout(builderTimer); stats.caps = caps.snapshot(); stats.abortReason = abortReason;
        try { return { ...payload, finish: await api.finish(payload) }; } finally { hb.stop(); stats.beats = hb.summary(); }
    };
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
        // a template needs a face for every text; a calendar draws with its built-in face when the project has none
        if (!calendar && !context.fonts.length) return { ...(await failed('build', usagePayload(null))), detail: 'no_fonts', stats };
        // a calendar that cannot take a scanned layout (14 days per page, or gone): nothing to build on
        if (calendar && context.calendar?.supported !== true) return { ...(await failed('build', usagePayload(null))), detail: 'calendar_unsupported', stats };
        let seq = 0;
        // a renderer that stays away for the whole tolerance window is counted: a run that ends without a
        // candidate because of it is `renderer_unavailable`, not a failure of the scan (§7)
        const counted = (call) => call.catch((e) => {
            if (['renderer_unavailable', 'renderer_busy'].includes(e?.code)) stats.rendererFailures = (stats.rendererFailures ?? 0) + 1;
            if (e?.runOver) abort(e.code === 'gone' ? 'gone' : 'lease_lost'); // 409 / 410: this run is over — do not wait for the next beat
            throw e;
        });
        // `render` is idempotent by (run, seq): every retry of one call carries the same seq
        const app = {
            render: (design) => { const s = ++seq; return counted(withRendererRetry((o) => api.render({ seq: s, design }, o), definition.timing, controller.signal)); },
            renderCommands: (commands) => { const s = ++seq; return counted(withRendererRetry((o) => api.render({ seq: s, commands }, o), definition.timing, controller.signal)); },
            faces: (req) => { const s = ++seq; return counted(withRendererRetry((o) => api.faces({ seq: s, ...req }, o), definition.timing, controller.signal)); },
        };
        build = new (calendar ? CalendarBuild : TemplateBuild)({ source, sourcePath, context, app, caps, signal: controller.signal, approximate: !!job.approximate,
            settings: { availableScore: definition.fonts?.availableScore ?? 0.85, undeterminedScore: definition.fonts?.undeterminedScore ?? 0.35, verifiedScore: definition.fonts?.verifiedScore ?? 0.88, verifiedScoreLong: definition.fonts?.verifiedScoreLong ?? 0.85, verifiedLongGlyphs: definition.fonts?.verifiedLongGlyphs ?? 12, verifiedScoreLonger: definition.fonts?.verifiedScoreLonger ?? 0.78, verifiedLongerGlyphs: definition.fonts?.verifiedLongerGlyphs ?? 20, verifiedSmallInkPx: definition.fonts?.verifiedSmallInkPx ?? 22, verifyRenders: definition.fonts?.verifyRenders ?? 6, verifySupersample: definition.fonts?.verifySupersample ?? 3, prepassLines: definition.fonts?.prePassLines ?? 6, faceSheets: definition.caps?.faceSheets ?? 12, snapDeltaE: definition.paletteSnapDeltaE ?? 2, ocrThreads: Number(process.env.SCANNER_OCR_THREADS ?? 3) },
            onNote: (note, params = null, extra = null) => {
                // `texts_found` comes with the boxes of the lines the reading found — the waiting screen outlines them
                if (extra?.textBoxes) state.textBoxes = textBoxesOf(extra.textBoxes, source);
                if (['read', 'analyse', 'build', 'refine', 'finish'].includes(note)) { state.stage = note; if (note === 'refine') state.round = Math.min(definition.caps?.correctionRounds ?? 2, Math.max(1, build.candidates.length)); }
                else { state.note = note; state.noteParams = params; if (note === 'texts_found' || note === 'no_texts' || note === 'rows_found') state.stage = 'analyse'; }
                changed();
            } });
        stage('read', t0);

        // ---- the mandatory measured pre-pass
        t0 = Date.now();
        probeResources?.mark('build:prepass');
        changed();
        const prepass = await build.prepass();
        stats.prepass = { ms: build.stats.prepassMs, ocr: build.stats.ocr, lines: prepass.ocrLines.length, ranked: prepass.ocrLines.filter((l) => l.font).length, faceSheets: build.stats.faceSheets, ...(calendar ? { rows: prepass.rules?.count ?? 0, pageBox: build.pageBoxSet ?? 'whole' } : { background: prepass.background.kind }) };
        writeFileSync(join(dir, 'prepass.json'), JSON.stringify(prepass, null, 2));
        stage('prepass', t0);
        if (controller.signal.aborted) throw Object.assign(new Error(abortReason), { code: 'aborted' });

        // ---- the model loop under caps
        t0 = Date.now();
        probeResources?.mark('build:model');
        state.stage = 'build'; changed();
        caps.startBuilder();
        builderTimer = setTimeout(() => abort('builder_time'), caps.limits.builderMs); builderTimer.unref?.();
        const server = new ToolServer({ definitions: spec.tools, impl: build.tools(), caps, imageRule: definition.imageRule,
            timeouts: { localMs: (definition.timing?.localToolTimeoutSeconds ?? 30) * 1000, appMs: (definition.timing?.appToolTimeoutSeconds ?? 95) * 1000 },
            appTools: calendar ? CALENDAR_APP_TOOLS : TEMPLATE_APP_TOOLS, log: log ? (rec, args) => log({ ...rec, args }) : null });
        caps.takeImages(1); // the source picture in the first message
        const sourceImage = await prepareImage(source, definition.imageRule);
        const first = calendar
            ? `${spec.task}\n\nPre-pass (measured by the scanner; boxes are [x, y, w, h] in page mm):\n${JSON.stringify(prepass)}`
            : `${spec.task}\n\nPre-pass (measured by the scanner; boxes are [x, y, w, h] in canvas px${sourceImage.scale < 1 ? `; the attached picture is shown at scale ${sourceImage.scale.toFixed(4)} of the canvas` : ''}):\n${JSON.stringify(prepass)}`;
        result = await engine.run({
            model: definition.models.builder, effort: definition.effort?.[job.kind] ?? 'high', system: spec.system,
            content: userContent([sourceImage, first]), tools: server.definitions, dispatch: (name, args) => server.dispatch(name, args),
            finishSchema: spec.finishSchema, maxTurns: caps.limits.turns, maxBudgetUsd: caps.limits.budgetUsd,
            signal: controller.signal, settings: definition.engine, scratchDir: dir, prices,
            onEvent: (ev) => {
                if (ev.type === 'usage') { liveUsage = ev.usage; caps.noteCost(ev.usage.costUsd); changed(); }
                if (ev.type === 'turn') { liveUsage = ev.usage; caps.noteTurns(ev.turns); caps.noteCost(ev.usage.costUsd); changed(); const hit = caps.exceeded(); if (hit) abort(hit); }
                if (ev.type === 'retry') { caps.noteProviderLost(ev.lostMs ?? ev.delayMs ?? 0); state.note = 'provider_retry'; state.noteParams = { n: ev.attempt, of: ev.of }; changed(); }
            },
        });
        clearTimeout(builderTimer);
        liveUsage = result.usage;
        stats.engine = { name: engine.name, outcome: result.outcome, turns: result.turns, apiMs: result.apiMs, wallMs: result.wallMs, error: result.error, facts: result.facts };
        stats.tools = server.calls;
        stage('model', t0);

        const usage = usagePayload(result.usage, { turns: result.turns, durationMs: Date.now() - started });
        changed();
        // ---- endings decided outside the run: the scan was cancelled / interrupted / lost, or the scanner stops
        if (abortReason === 'shutdown') { hb.stop(); clearTimeout(runTimer); if (host.ownsRelease) await releaseOnce(api, { usage, reason: 'shutdown' }, definition.timing); stats.abortReason = abortReason; return { outcome: 'released', usage, stats }; }
        if (abortReason && !CAP_REASONS.includes(abortReason)) { hb.stop(); clearTimeout(runTimer); stats.abortReason = abortReason; return { outcome: 'cancelled', reason: abortReason, usage, stats }; }
        // ---- provider-side endings. A refusal ends the scan as `refused` (§5.2 rule 6) — also when the harness
        //      had already begun to re-run the turn on another model: that run was stopped, its usage is in `usage`
        if (result.outcome === 'refusal') return { ...(await finish({ outcome: 'refused', usage })), stats };
        if (result.outcome === 'provider') {
            const o = outcomeForClass(result.error.class);
            if (o.state === 'blocked') {
                // confirm with a 1-token probe: a blocked provider is released, a failing scan is a failure of that scan
                const p = await probe({ run: engine.run, model: definition.models.preCheck, scratchDir: dir, settings: definition.engine });
                if (!p.ok) { hb.stop(); clearTimeout(runTimer); if (host.ownsRelease) await releaseOnce(api, { usage, reason: 'provider_blocked', providerError: result.error.code }, definition.timing); return { outcome: 'blocked', error: result.error, providerError: result.error.code ?? null, usage, stats }; }
                if (!build.candidates.length) return { ...(await failed('build', usage)), detail: result.error, stats };
            } else if (!build.candidates.length) return { ...(await failed(o.code, usage)), detail: result.error, stats };
        }

        // ---- finish: keep-best, measured report
        t0 = Date.now();
        probeResources?.mark('build:finish');
        state.stage = 'finish'; state.note = null; state.noteParams = null; changed();
        const capHit = abortReason ?? (result.outcome === 'max_turns' ? 'turns' : result.outcome === 'max_budget' ? 'budget' : null);
        // The request a time cap cut while the provider had said nothing for longer than the stream-idle bound was
        // lost to the provider too (a stalled stream, a request that never came back): the engine reports retries
        // only once a request has failed, and the last one never got that far.
        const pendingWaitMs = result.facts?.pendingWaitMs ?? 0;
        if (capHit && pendingWaitMs >= (definition.engine?.streamIdleSeconds ?? 60) * 1000) caps.noteProviderLost(pendingWaitMs);
        // a run cut short by the provider is not charged — with or without a candidate (§4.9 provider-lost time)
        if (capHit && caps.providerLostDominates(definition.caps?.providerLostShare ?? 0.5)) return { ...(await failed('provider_unavailable', usage)), detail: capHit, stats };
        if (!build.candidates.length) return { ...(await failed(stats.rendererFailures ? 'renderer_unavailable' : capHit ? 'timeout' : 'build', usage)), detail: capHit ?? result.error ?? 'no_candidate', stats };
        const modelFinish = result.outcome === 'finished' && result.finish && typeof result.finish === 'object' ? result.finish : null;
        const report = calendar ? await buildCalendarReport({ build, finish: modelFinish, unfinished: !modelFinish, stopReason: modelFinish ? null : (capHit ?? result.outcome), rank: caps.runLeftMs() > 20_000 ? (lines) => build.rank(lines) : null, verify: caps.runLeftMs() > 30_000 ? (items) => build.verify(items) : null }) : await buildTemplateReport({ build, finish: modelFinish, unfinished: !modelFinish, stopReason: modelFinish ? null : (capHit ?? result.outcome),
            rank: caps.runLeftMs() > 20_000 ? (lines) => build.rank(lines) : null,
            // the verification render decides measured_match / nearest_available (≤ 6 renders; skipped when the run cap is near)
            verify: caps.runLeftMs() > 30_000 ? (items) => build.verify(items) : null });
        stage('finish', t0);
        if (report.status !== 'proposed') return { ...(await failed('build', usage)), detail: report.error, stats };
        const kept = build.candidates.find((c) => c.n === report.candidate);
        const { boxes, ...rest } = report;
        const payload = await finish({ outcome: 'proposed', candidate: report.candidate, report: rest, ...(calendar ? {} : { boxes }), usage: usagePayload(result.usage, { turns: result.turns, durationMs: Date.now() - started }) });
        if (calendar) return { ...payload, proposal: { preset: kept.preset, layout: kept.layout, features: kept.features, commands: kept.commands }, render: { data: kept.bytes, mimeType: kept.mimeType }, modelFinish, stats, candidates: build.candidates.map((c) => ({ n: c.n, mae: c.mae, pictureShare: c.pictureShare })), rankings: build.rankings.map(({ text, group, origin, best, available }) => ({ text, group, origin, best, available })) };
        return { ...payload, proposal: kept.design, render: { data: kept.bytes, mimeType: kept.mimeType }, modelFinish, stats, candidates: build.candidates.map((c) => ({ n: c.n, via: c.via, mae: c.mae, textFit: c.textFit, placeholderShare: c.placeholderShare })), rankings: build.rankings.map(({ text, box, group, origin, best, runnerUp, available }) => ({ text, box, group, origin, best, runnerUp, available })) };
    } catch (e) {
        clearTimeout(runTimer); clearTimeout(builderTimer);
        stats.caps = caps.snapshot(); stats.abortReason = abortReason; stats.thrown = String(e?.message ?? e).slice(0, 300);
        const usage = usagePayload(liveUsage, { turns: caps.turns, durationMs: Date.now() - started });
        if (abortReason === 'shutdown') { hb.stop(); if (host.ownsRelease) await releaseOnce(api, { usage, reason: 'shutdown' }, definition.timing); return { outcome: 'released', usage, stats }; }
        if ((abortReason && !CAP_REASONS.includes(abortReason)) || e?.runOver) { hb.stop(); return { outcome: 'cancelled', reason: abortReason ?? (e.code === 'gone' ? 'gone' : 'lease_lost'), usage, stats }; }
        const code = e?.code === 'renderer_unavailable' || e?.code === 'renderer_busy' ? 'renderer_unavailable' : abortReason ? 'timeout' : 'build';
        try { return { outcome: 'failed', error: code, detail: stats.thrown, usage, stats, finish: await api.finish({ outcome: 'failed', error: code, usage }) }; } finally { hb.stop(); stats.beats = hb.summary(); }
    } finally {
        hb.stop();
        if (process.env.SCANNER_KEEP_SCRATCH !== '1') rmSync(dir, { recursive: true, force: true });
    }
}

/** The few numbers of the resource probe a heartbeat carries (the app keeps ≤ 20 named numbers). */
function beatResources(probe) {
    const s = probe?.summary?.();
    if (!s) return null;
    return { peakRssMb: s.peakRssMb, avgRssMb: s.avgRssMb, cpuSeconds: s.cpuSeconds, peakCores: s.peakCores, enginePeakMb: s.byProcess?.engine?.peakRssMb ?? 0, nodePeakMb: s.byProcess?.node?.peakRssMb ?? 0, ocrPeakMb: s.byProcess?.ocr?.peakRssMb ?? 0 };
}

/**
 * A failing or busy renderer is retried inside the app-outage tolerance R (architecture §4.9, §7): attempts
 * START only inside R, counted from the first one — a call the HTTP client already retried for the whole
 * tolerance (`outage`) is not started again, and every call is told when the window ends (`startBy`), so the
 * HTTP client's own retries cannot open a second window after a busy answer: the last attempt ends
 * ≤ R + one attempt = 90 s < the 95 s tool timeout. Every retry of one call carries the same `seq` (the caller
 * fixes it).
 */
export async function withRendererRetry(call, timing = {}, signal = null) {
    const toleranceMs = (timing.appOutageToleranceSeconds ?? 60) * 1000;
    const until = Date.now() + toleranceMs;
    const pause = (ms) => new Promise((ok) => setTimeout(ok, Math.min(ms, toleranceMs / 40)));
    for (let attempt = 0; ; attempt++) {
        try {
            const r = await call({ startBy: until });
            if (r?.code === 'renderer_busy' || r?.code === 'renderer_unavailable') throw Object.assign(new Error(r.code), { code: r.code });
            return r;
        } catch (e) {
            const renderer = e?.code === 'renderer_busy' || e?.code === 'renderer_unavailable';
            const fatal = signal?.aborted || Date.now() >= until || e?.outage || e?.status === 409 || e?.status === 410 || (!renderer && e?.retryable === false) || ['lease_lost', 'stale_run', 'gone', 'candidate_limit', 'aborted'].includes(e?.code);
            // A render the app could not answer for the whole tolerance — attempts that timed out, a connection
            // that failed, a 5xx — is the renderer staying away, whatever word the transport used for it: the
            // scan then fails as `renderer_unavailable` (refunded, not counted against the picture), never as `build`.
            const transport = e?.outage === true || (e?.retryable === true && !signal?.aborted);
            if (fatal) throw Object.assign(e, { code: !renderer && (transport || !e.code) ? 'renderer_unavailable' : e.code, transport: !renderer && transport ? e.code : undefined });
            await pause(renderer ? 1500 : Math.min(5000, 1000 * (attempt + 1)));
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
