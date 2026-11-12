import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import { runBuild, runIntake, heartbeat, withRendererRetry, usagePayload, releaseOnce } from '../src/run.mjs';
import { definitionFiles } from '../src/drivers/file-job.mjs';
import { readDefinition, contextFromClaim, HttpScannerApi } from '../src/protocol.mjs';
import { ocrPython } from '../src/instruments/ocr.mjs';
import { fakeApp, drawDesign, scriptedEngine, usage } from './support/fakes.mjs';

const DESIGN = { canvas: { width: 800, height: 600 }, elements: [
    { kind: 'background', id: 'bg', fill: '#ffffff' },
    { kind: 'shape', id: 'karta', shape: 'rectangle', x: 40, y: 40, width: 720, height: 200, fill: '#0b3d6b' },
    { kind: 'image', id: 'logo', asset: 'standin', x: 640, y: 440, width: 120, height: 120, input: { name: 'Logo' } },
] };
const FINISH = { status: 'done', preferredCandidate: 1, headline: '', placeholders: [{ id: 'logo', name: 'Logo', role: 'logo', fillable: true }], fonts: [], approximations: [], omitted: [], lowConfidenceTexts: [] };

/** A JobApi over the fake app; `over` tunes the definition (timing in particular). */
async function fixture({ timing = {}, caps = {}, beat = null, app = fakeApp() } = {}) {
    const files = definitionFiles();
    const def = JSON.parse(files['definition.json']);
    Object.assign(def.timing, timing); Object.assign(def.caps, caps);
    files['definition.json'] = JSON.stringify(def);
    const working = await sharp(await drawDesign(DESIGN)).webp({ lossless: true }).toBuffer();
    const sent = { beats: [], finish: null, release: null };
    const api = {
        source: async () => working, putArtifact: async () => {},
        beat: async (b) => { sent.beats.push({ at: Date.now(), ...b }); return beat ? beat(b, sent.beats.length) : { cancel: false, mode: 'on' }; },
        render: async ({ design }) => app.render(design), faces: async (req) => app.faces(req),
        finish: async (p) => { sent.finish = p; return { ok: true }; }, release: async (p) => { sent.release = p; return { ok: true }; },
    };
    const job = { id: 'scan-1', kind: 'template', lane: 'build', run: 1, definition: { version: 't', files }, context: { fonts: [{ font: 'Sans', face: 'Regular' }, { font: 'Sans', face: 'Bold' }], palette: [], standInAssetId: 'standin' } };
    return { api, job, sent, app, scratchDir: mkdtempSync(join(tmpdir(), 'scan-run-')) };
}
const hasOcr = existsSync(ocrPython());
const skip = !hasOcr && 'the build runs the OCR pre-pass: needs scanner/.venv';

test('build: pre-pass → model loop → keep-best → measured report → finish(proposed)', { skip }, async () => {
    const f = await fixture();
    let firstMessage = null;
    const engine = scriptedEngine(async (ctx) => {
        firstMessage = ctx.options.content;
        const off = structuredClone(DESIGN); off.elements[1].x = 70;
        await ctx.tool('design_preview', { design: off });
        const r = await ctx.tool('design_patch', { set: [{ id: 'karta', props: { x: 40 } }] });
        assert.equal(JSON.parse(r.content[0].text).candidate, 2);
        return { ...FINISH, preferredCandidate: 1 };
    });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.equal(out.outcome, 'proposed');
    assert.equal(out.candidate, 2, 'the scanner keeps the better-measured candidate, not the model\'s preference');
    assert.equal(out.report.unfinished, false);
    assert.deepEqual(out.report.placeholders.map((p) => [p.id, p.role, p.fillable]), [['logo', 'logo', true]]);
    assert.equal(out.report.measures.keepBest.agrees, false);
    assert.deepEqual(f.sent.finish.outcome, 'proposed'); assert.equal(f.sent.finish.candidate, 2);
    assert.ok(Array.isArray(f.sent.finish.boxes) && f.sent.finish.report.boxes === undefined, 'boxes travel next to the report');
    assert.equal(f.sent.finish.usage.model, 'claude-opus-5-5'); assert.ok(f.sent.finish.usage.costMicroUsd > 0);
    // the model got the picture and the pre-pass as its input
    assert.equal(firstMessage[0].type, 'image');
    assert.match(firstMessage[1].text, /"ocrLines":/); assert.match(firstMessage[1].text, /"background":/); assert.match(firstMessage[1].text, /"fonts":\["Sans \(Regular\)","Sans \(Bold\)"\]/);
    assert.equal(out.stats.caps.images, 3, 'the source + two candidate renders');
    assert.deepEqual(readdirSync(f.scratchDir), [], 'the per-run scratch directory is removed');
});

test('build: a cap hit with a valid candidate ends as a proposal the scanner reports alone (unfinished)', { skip }, async () => {
    const f = await fixture({ caps: { turns: 2 } });
    const engine = scriptedEngine(async (ctx) => {
        await ctx.tool('design_preview', { design: DESIGN });
        for (let i = 0; i < 10; i++) await ctx.tool('scan_measure', { x: 0, y: 0, w: 100, h: 100 });
        return FINISH;
    });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.equal(out.outcome, 'proposed'); assert.equal(out.report.unfinished, true); assert.equal(out.report.stopReason, 'turns');
    assert.deepEqual(out.report.placeholders.map((p) => p.role), ['other'], 'model fields are empty: nobody finished');
    assert.equal(out.stats.abortReason, 'turns');
});

test('build: a cap hit without a candidate is failed timeout; a dead end is failed build', { skip }, async () => {
    const f = await fixture({ timing: { builderCapSeconds: 0.3 } });
    const slow = scriptedEngine(async (ctx) => { await new Promise((ok) => ctx.options.signal.addEventListener('abort', ok)); return FINISH; });
    const out = await runBuild({ job: f.job, api: f.api, engine: slow, scratchDir: f.scratchDir });
    assert.deepEqual([out.outcome, out.error, out.detail], ['failed', 'timeout', 'builder_time']);
    assert.deepEqual(f.sent.finish.error, 'timeout');
    const g = await fixture();
    const none = await runBuild({ job: g.job, api: g.api, engine: scriptedEngine(async () => FINISH), scratchDir: g.scratchDir });
    assert.deepEqual([none.outcome, none.error], ['failed', 'build']);
});

test('build: a renderer that stays away ends the scan as renderer_unavailable, not as a failed build', { skip }, async () => {
    const f = await fixture({ timing: { appOutageToleranceSeconds: 0.2 } });
    f.api.render = async () => { throw Object.assign(new Error('timeout'), { name: 'TimeoutError' }); };
    const out = await runBuild({ job: f.job, api: f.api, scratchDir: f.scratchDir, engine: scriptedEngine(async (ctx) => { const r = await ctx.tool('design_preview', { design: DESIGN }); assert.equal(r.isError, true); return FINISH; }) });
    assert.deepEqual([out.outcome, out.error], ['failed', 'renderer_unavailable']);
});

test('build: refusal → refused; billing error confirmed by the probe → released as provider_blocked; transient → provider_unavailable', { skip }, async () => {
    const f = await fixture();
    const refused = await runBuild({ job: f.job, api: f.api, scratchDir: f.scratchDir, engine: scriptedEngine(async () => ({ outcome: 'refusal', error: { class: 'refusal', code: 'refusal' } })) });
    assert.equal(refused.outcome, 'refused'); assert.equal(f.sent.finish.outcome, 'refused');

    const g = await fixture();
    let calls = 0;
    const blocked = await runBuild({ job: g.job, api: g.api, scratchDir: g.scratchDir, engine: { name: 'x', run: async () => { calls++; return { outcome: 'provider', error: { class: 'billing_auth', code: 'billing' }, usage: usage(), turns: 0, wallMs: 1, facts: {} }; } } });
    assert.equal(blocked.outcome, 'blocked'); assert.equal(calls, 2, 'the builder call and the 1-token probe');
    assert.equal(g.sent.finish, null); assert.equal(g.sent.release.reason, 'provider_blocked');

    const h = await fixture();
    const down = await runBuild({ job: h.job, api: h.api, scratchDir: h.scratchDir, engine: { name: 'x', run: async () => ({ outcome: 'provider', error: { class: 'transient', code: 'overloaded' }, usage: usage(), turns: 0, wallMs: 1, facts: {} }) } });
    assert.deepEqual([down.outcome, down.error], ['failed', 'provider_unavailable']);
});

test('build: the heartbeat keeps beating while the engine is blocked, and carries stage and usage', { skip }, async () => {
    const f = await fixture({ timing: { heartbeatSeconds: 0.05 } });
    const engine = scriptedEngine(async (ctx) => {
        await ctx.tool('design_preview', { design: DESIGN });
        await new Promise((ok) => setTimeout(ok, 600));     // one long silent model turn
        return FINISH;
    });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.equal(out.outcome, 'proposed');
    const during = f.sent.beats.filter((b) => b.stage === 'build' || b.stage === 'refine');
    assert.ok(during.length >= 8, `beats during the blocked turn: ${during.length}`);
    assert.ok(f.sent.beats.some((b) => b.usage.outputTokens > 0 && b.usage.turns >= 1), 'usage travels with the beat');
    assert.deepEqual([...new Set(f.sent.beats.map((b) => b.seq))].length, f.sent.beats.length, 'every beat has its own seq');
});

test('build: cancel from a beat aborts the engine; nothing is finished', { skip }, async () => {
    const f = await fixture({ timing: { heartbeatSeconds: 0.05 }, beat: (b, n) => ({ cancel: n >= 3, mode: 'on' }) });
    const engine = scriptedEngine(async (ctx) => { await ctx.tool('design_preview', { design: DESIGN }); await new Promise((ok) => ctx.options.signal.addEventListener('abort', ok)); return FINISH; });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.deepEqual([out.outcome, out.reason], ['cancelled', 'cancelled']);
    assert.equal(f.sent.finish, null);
});

test('build: SIGTERM path — abort, usage, release; the restart is not consumed', { skip }, async () => {
    const f = await fixture();
    const controller = new AbortController();
    const engine = scriptedEngine(async (ctx) => { await ctx.tool('design_preview', { design: DESIGN }); controller.abort(); await new Promise((ok) => setTimeout(ok, 20)); return FINISH; });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir, signal: controller.signal });
    assert.equal(out.outcome, 'released'); assert.equal(f.sent.release.reason, 'shutdown'); assert.equal(f.sent.finish, null);
    assert.ok(f.sent.release.usage.outputTokens > 0);
});

test('heartbeat: self-abort counts from the SEND time of the last acknowledged beat; a lost lease aborts at once', async () => {
    const aborts = [];
    const dead = heartbeat({ api: { beat: async () => { throw new Error('ECONNREFUSED'); } }, timing: { heartbeatSeconds: 0.02, selfAbortSeconds: 0.1 }, state: {}, abort: (r) => aborts.push(r) });
    await new Promise((ok) => setTimeout(ok, 250));
    dead.stop();
    assert.equal(aborts[0], 'app_unreachable');
    // every beat is acknowledged, but only 80 ms after it was sent: measured from the acknowledgement the
    // run would live for ever; measured from the send time (limit 60 ms) it must abort
    const slow = [];
    const late = heartbeat({ api: { beat: () => new Promise((ok) => setTimeout(() => ok({ cancel: false }), 80)) }, timing: { heartbeatSeconds: 0.02, selfAbortSeconds: 0.06, heartbeatAttemptSeconds: 1 }, state: {}, abort: (r) => slow.push(r) });
    await new Promise((ok) => setTimeout(ok, 300)); late.stop();
    assert.equal(slow[0], 'app_unreachable');
    const lost = [];
    const hb = heartbeat({ api: { beat: async () => { throw Object.assign(new Error('gone'), { status: 410 }); } }, timing: { heartbeatSeconds: 0.02 }, state: {}, abort: (r) => lost.push(r) });
    await new Promise((ok) => setTimeout(ok, 60)); hb.stop();
    assert.equal(lost[0], 'lease_lost');
});

test('heartbeat: a hung beat is cut and never delays the next one', async () => {
    const sent = []; const aborts = [];
    const hb = heartbeat({ api: { beat: (b) => { sent.push(b.seq); return b.seq === 1 ? new Promise(() => {}) : Promise.resolve({ cancel: false }); } }, timing: { heartbeatSeconds: 0.03, heartbeatAttemptSeconds: 0.05, selfAbortSeconds: 5 }, state: {}, abort: (r) => aborts.push(r) });
    await new Promise((ok) => setTimeout(ok, 200)); hb.stop();
    assert.ok(sent.length >= 4, `beats kept going out behind the hung one: ${sent}`);
    assert.deepEqual(aborts, []);
});

test('shutdown: one release attempt, cut short when the app does not answer', async () => {
    let calls = 0;
    const t0 = Date.now();
    assert.equal(await releaseOnce({ release: () => { calls++; return new Promise(() => {}); } }, {}, { releaseAttemptSeconds: 0.05 }), false);
    assert.ok(Date.now() - t0 < 1000); assert.equal(calls, 1);
    assert.equal(await releaseOnce({ release: async () => ({ ok: true }) }, {}), true);
});

test('build: a cap hit with half of the builder time lost to the provider is failed provider_unavailable — even with a candidate', { skip }, async () => {
    const f = await fixture({ caps: { turns: 3 } });
    const engine = scriptedEngine(async (ctx) => {
        await ctx.tool('design_preview', { design: DESIGN });
        await new Promise((ok) => setTimeout(ok, 50));
        ctx.options.onEvent({ type: 'retry', attempt: 1, of: 4, lostMs: 10 * 60_000 });
        for (let i = 0; i < 5; i++) await ctx.tool('scan_measure', { x: 0, y: 0, w: 10, h: 10 });
        return FINISH;
    });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.deepEqual([out.outcome, out.error, out.detail], ['failed', 'provider_unavailable', 'turns']);
    assert.equal(f.sent.finish.error, 'provider_unavailable');
});

test('withRendererRetry: a busy or failing renderer is retried inside R, a lost lease is not', async () => {
    let n = 0;
    const r = await withRendererRetry(async () => (++n < 3 ? { code: 'renderer_busy' } : { rendered: true }), { appOutageToleranceSeconds: 10 });
    assert.deepEqual([r.rendered, n], [true, 3]);
    await assert.rejects(withRendererRetry(async () => ({ code: 'renderer_busy' }), { appOutageToleranceSeconds: 0 }), { code: 'renderer_busy' });
    let m = 0;
    await assert.rejects(withRendererRetry(async () => { m++; throw Object.assign(new Error('x'), { status: 410 }); }, { appOutageToleranceSeconds: 10 }), /x/);
    assert.equal(m, 1);
});

test('intake: normalise + pre-check through the JobApi; a stop reason ends it, a pass stores the artefacts', async () => {
    const files = definitionFiles();
    const png = await sharp(await drawDesign(DESIGN)).png().toBuffer();
    const make = (source) => { const sent = { artifacts: [], finish: null }; return { sent, api: { source: async () => source, putArtifact: async (w, b) => sent.artifacts.push([w, b.length > 0]), finish: async (p) => { sent.finish = p; }, release: async (p) => { sent.release = p; } } }; };
    const job = { id: 's', kind: 'template', lane: 'intake', run: 1, definition: { files } };
    const engineOf = (verdict) => ({ name: 'x', run: async () => ({ outcome: 'finished', finish: { verdict, confidence: 'high' }, usage: usage(10), turns: 1, wallMs: 3, apiMs: 2, facts: {} }) });
    const a = make(png);
    const pass = await runIntake({ job, api: a.api, engine: engineOf('design'), scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.equal(pass.outcome, 'passed'); assert.deepEqual(a.sent.artifacts, [['working', true], ['preview', true]]);
    assert.deepEqual([a.sent.finish.outcome, a.sent.finish.preCheck.verdict, a.sent.finish.sourceMeta.workingWidth], ['passed', 'design', 800]);
    const b = make(png);
    const stop = await runIntake({ job, api: b.api, engine: engineOf('photo_of_print'), scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.deepEqual([stop.outcome, stop.reason, b.sent.finish.preCheck.continuable], ['stopped', 'photo_of_print', true]);
    const c = make(Buffer.from('not a picture'));
    let called = false;
    const bad = await runIntake({ job, api: c.api, engine: { name: 'x', run: async () => { called = true; } }, scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.deepEqual([bad.outcome, bad.reason, called], ['stopped', 'unusable_input', false], 'no model call for a file that cannot be normalised');
    // rule 3c: the pre-check could not be made → finish failed provider_unavailable; refused → finish refused
    const d = make(png);
    const down = await runIntake({ job, api: d.api, engine: { name: 'x', run: async () => ({ outcome: 'provider', error: { class: 'transient', code: 'overloaded' }, usage: usage(0), facts: {} }) }, scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.deepEqual([down.outcome, down.error, d.sent.finish.outcome, d.sent.finish.error, d.sent.release], ['failed', 'provider_unavailable', 'failed', 'provider_unavailable', undefined]);
    const e = make(png);
    const refused = await runIntake({ job, api: e.api, engine: { name: 'x', run: async () => ({ outcome: 'refusal', error: { class: 'refusal' }, usage: usage(0), facts: {} }) }, scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.deepEqual([refused.outcome, e.sent.finish.outcome], ['refused', 'refused']);
    // billing / auth, confirmed by the probe → the intake is released, the provider is blocked
    const g = make(png);
    const blocked = await runIntake({ job, api: g.api, engine: { name: 'x', run: async () => ({ outcome: 'provider', error: { class: 'billing_auth', code: 'billing' }, usage: usage(0), facts: {} }) }, scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.equal(blocked.outcome, 'blocked'); assert.equal(g.sent.release.reason, 'provider_blocked'); assert.equal(g.sent.finish, null);
});

test('intake: the pre-check runs on its own request settings and ends within its bound; the intake cap is enforced', async () => {
    const files = definitionFiles();
    const def = JSON.parse(files['definition.json']);
    assert.deepEqual([def.preCheck.requestTimeoutSeconds, def.preCheck.retries, def.preCheck.retryDelaysSeconds, def.preCheck.maxSeconds, def.timing.intakeCapSeconds, def.timing.normaliseSeconds, def.timing.selfAbortSeconds], [18, 1, [2], 38, 120, 20, 105]);
    def.preCheck.maxSeconds = 0.1; files['definition.json'] = JSON.stringify(def);
    const png = await sharp(await drawDesign(DESIGN)).png().toBuffer();
    let seen = null; const sent = {};
    const hung = { name: 'x', run: (o) => { seen = o; return new Promise((ok) => o.signal.addEventListener('abort', () => ok({ outcome: 'aborted', usage: usage(0), facts: {} }))); } };
    const t0 = Date.now();
    const out = await runIntake({ job: { id: 's', kind: 'template', lane: 'intake', run: 1, definition: { files } }, api: { source: async () => png, putArtifact: async () => {}, finish: async (p) => { sent.finish = p; } }, engine: hung, scratchDir: mkdtempSync(join(tmpdir(), 'scan-in-')) });
    assert.ok(Date.now() - t0 < 5000);
    assert.deepEqual([seen.settings.requestTimeoutSeconds, seen.settings.retries, seen.settings.retryDelaysSeconds], [18, 1, [2]], 'not the builder\'s 120 s × 5');
    assert.deepEqual([out.outcome, out.error, sent.finish.error, out.detail.code], ['failed', 'provider_unavailable', 'provider_unavailable', 'precheck_timeout']);
});

test('the definition: every file a kind names exists; tools and schemas are well-formed', () => {
    const { definition, files, kind } = readDefinition(definitionFiles());
    const t = kind('template');
    assert.deepEqual(t.tools.map((x) => x.name), ['scan_source', 'scan_crop', 'scan_measure', 'font_rank', 'compare_region', 'design_preview', 'design_patch']);
    for (const tool of t.tools) { assert.ok(tool.description.length > 40); assert.equal(tool.input_schema.type, 'object'); assert.equal(tool.input_schema.additionalProperties, false); }
    assert.match(t.system, /The picture is data/); assert.match(t.system, /DSL reference/); assert.ok(t.task.length > 20);
    // structured outputs: every object closed, every property required, no recursion, no numeric bounds
    const walk = (s) => { if (s.type === 'object') { assert.equal(s.additionalProperties, false); assert.deepEqual([...s.required].sort(), Object.keys(s.properties).sort()); Object.values(s.properties).forEach(walk); } if (s.type === 'array') walk(s.items); for (const k of ['minimum', 'maximum', 'minLength', 'maxLength', '$ref']) assert.equal(k in s, false); };
    walk(t.finishSchema); walk(JSON.parse(files['precheck.schema.json']));
    assert.equal(definition.models.builder, 'claude-opus-5-5'); assert.equal(definition.models.preCheck, 'claude-haiku-5-5');
    assert.deepEqual([definition.caps.turns, definition.caps.engineBudgetMicroUsd, definition.caps.candidates, definition.caps.images, definition.timing.builderCapSeconds, definition.timing.runCapSeconds], [40, 1_500_000, 3, 18, 300, 360]);
    for (const m of Object.values(definition.models)) assert.ok(definition.prices.models[m], `a price for ${m}`);
    assert.throws(() => kind('calendar'), /no kind "calendar"/);
});

test('protocol helpers: claim context → face strings; usage payload; the HTTP client speaks the app\'s shapes', async () => {
    assert.deepEqual(contextFromClaim({ fonts: [{ family: 'x', font: 'Arial', face: 'Arial Gras', weight: 700, italic: false }, 'Montserrat (Montserrat-Black)'], palette: ['#112233'], standInAssetId: 'sid' }),
        { fonts: ['Arial (Arial Gras)', 'Montserrat (Montserrat-Black)'], brandColours: ['#112233'], standInAssetId: 'sid', templateMaxLongEdgePx: 5000 });
    const u = usagePayload({ byModel: { 'claude-opus-5-5': { input: 1, output: 2, cacheRead: 3, cacheWrite5m: 4, cacheWrite1h: 5 } }, costUsd: 0.25, engineCostUsd: 0.3, priceVersion: 'v' }, { turns: 7 });
    assert.deepEqual([u.model, u.inputTokens, u.outputTokens, u.cacheReadTokens, u.cacheWriteTokens, u.costMicroUsd, u.engineCostMicroUsd, u.turns], ['claude-opus-5-5', 1, 2, 3, 9, 250_000, 300_000, 7]);
    const seen = [];
    const http = new HttpScannerApi({ baseUrl: 'http://web/', token: 'tok', scannerVersion: '0.1.0', instruments: ['ocr'], fetchImpl: async (url, init) => { seen.push({ url, init }); return new Response(JSON.stringify(url.endsWith('/claim') ? { ok: true, mode: 'on', job: { id: 'abc', run: 3, leaseToken: 'lease' } } : { ok: true, candidate: 1, issues: [], image: { contentType: 'image/webp', base64: 'QUJD' } }), { status: 200 }); } });
    const c = await http.claim('build');
    assert.equal(seen[0].url, 'http://web/internal/scanner/claim');
    assert.deepEqual(JSON.parse(seen[0].init.body), { protocolVersion: 1, scannerVersion: '0.1.0', lane: 'build', instruments: ['ocr'], capacity: 1 });
    assert.equal(seen[0].init.headers.Authorization, 'Bearer tok');
    const r = await c.api.render({ seq: 4, design: { a: 1 } });
    assert.deepEqual([r.rendered, r.candidate, r.image.data.toString(), r.image.mimeType], [true, 1, 'ABC', 'image/webp']);
    assert.equal(seen[1].url, 'http://web/internal/scanner/abc/render'); assert.equal(seen[1].init.headers['X-Scan-Lease'], 'lease');
    assert.deepEqual(JSON.parse(seen[1].init.body), { protocolVersion: 1, scannerVersion: '0.1.0', run: 3, seq: 4, design: { a: 1 } });
});
