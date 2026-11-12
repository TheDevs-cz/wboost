// The HTTP client against a stand-in for the app's scanner endpoints: headers, shapes, retries inside the
// app-outage tolerance, the answers that end a run, idempotent render and finish. Times are §4.9 ÷ 100
// (attempt 0.3 s, R 0.6 s, beat 0.1 s, release 0.2 s) — the real-time run of the same is test/slow/.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { HttpScannerApi, ScannerApiError, PROTOCOL_VERSION } from '../src/protocol.mjs';
import { withRendererRetry } from '../src/run.mjs';
import { ToolServer } from '../src/tools/server.mjs';
import { Caps } from '../src/caps.mjs';
import { startFakeApp, TOKEN } from './support/fake-app.mjs';

const FAST = { appCallAttemptSeconds: 0.3, appOutageToleranceSeconds: 0.6, heartbeatAttemptSeconds: 0.1, releaseAttemptSeconds: 0.2 };
const DESIGN = { canvas: { width: 300, height: 200 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }, { kind: 'shape', id: 'a', shape: 'rectangle', x: 10, y: 10, width: 100, height: 50, fill: '#0b3d6b' }] };
const client = (app, timing = FAST) => new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: '0.2.0+test', timing });
async function claimed(t, { lane = 'build', timing = FAST } = {}) {
    const app = await startFakeApp(); t.after(() => app.close());
    const scan = await app.addScan({ lane, design: DESIGN, original: Buffer.from('original') });
    const http = client(app, timing);
    const c = await http.claim(lane, 1);
    assert.equal(c.job.id, scan.id);
    return { app, scan, http, api: c.api, job: c.job };
}

test('every call carries the bearer token and the protocol header; per-scan calls the lease token and the run', async (t) => {
    const { app, scan, api } = await claimed(t);
    assert.equal((await api.source()).length > 0, true);
    await api.beat({ seq: 1, stage: 'read', usage: { inputTokens: 1 } });
    const r = await api.render({ seq: 1, design: DESIGN });
    assert.deepEqual([r.rendered, r.candidate, r.image.mimeType, r.image.data.length > 100], [true, 1, 'image/png', true]);
    const f = await api.faces({ seq: 2, lines: ['Ahoj'], faces: ['Sans (Bold)'], size: 40 });
    assert.equal(f.cells.length, 1);
    assert.deepEqual(await api.finish({ outcome: 'proposed', candidate: 1, report: {}, usage: {} }), { ok: true, applied: true, late: false, status: 'ready', code: null });
    for (const c of app.state.calls) { assert.equal(c.protocol, String(PROTOCOL_VERSION)); if (c.scan) { assert.equal(c.lease, scan.lease, c.op); assert.equal(c.run, 1, c.op); } else assert.equal(c.lease, null); }
    assert.deepEqual(app.calls('claim')[0].body, { lane: 'build', scannerVersion: '0.2.0+test', instruments: ['ocr', 'crop', 'measure', 'font_rank', 'compare'], capacity: 1 });
    assert.equal(app.state.calls.some((c) => c.crossed), false);
});

test('a wrong token (or a caller outside the scan network) is 404: named, and never mistaken for "no work"', async (t) => {
    const app = await startFakeApp(); t.after(() => app.close());
    const http = new HttpScannerApi({ baseUrl: app.url, token: 'not-the-token-not-the-token-000', scannerVersion: 'x', timing: FAST });
    await assert.rejects(http.claim('build'), (e) => e instanceof ScannerApiError && e.code === 'not_scanner' && e.status === 404);
    assert.equal(http.lastError.code, 'not_scanner'); assert.equal(http.lastOkAt, null);
});

test('claim answers: a job; nothing to do; paused, off, provider blocked, too old — each with its reason', async (t) => {
    const app = await startFakeApp(); t.after(() => app.close());
    const http = client(app);
    assert.deepEqual(await http.claim('intake'), { job: null, mode: 'on', reason: null, api: null });
    for (const [set, reason] of [[() => { app.state.mode = 'paused'; }, 'paused'], [() => { app.state.mode = 'off'; }, 'off'], [() => { app.state.mode = 'on'; app.state.providerState = 'blocked'; }, 'provider_blocked'], [() => { app.state.providerState = 'ok'; app.state.claimReason = 'spend_ceiling'; }, 'spend_ceiling']]) { set(); assert.equal((await http.claim('build')).reason, reason); }
    app.state.claimReason = null;
    const few = new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: 'x', instruments: ['crop'], timing: FAST });
    assert.equal((await few.claim('build')).reason, 'scanner_too_old', 'an instrument the definition requires is missing');
});

test('protocol handshake: an app that speaks another version answers protocol_too_old — an error, on every call', async (t) => {
    const app = await startFakeApp({ protocolVersion: 3 }); t.after(() => app.close());
    const http = client(app);
    await assert.rejects(http.tick(), (e) => e.code === 'protocol_too_old' && e.body.protocolVersion === 3);
    assert.equal(http.protocolAccepted, false);
    await assert.rejects(http.claim('build'), (e) => e.code === 'protocol_too_old');
    app.state.protocolVersion = 2; // the app accepts N and N − 1
    assert.equal((await http.tick()).protocolVersion, 2); assert.equal(http.protocolAccepted, true);
});

test('an app outage shorter than the tolerance: source, artifact, render, faces and finish all get through', async (t) => {
    const { app, api, scan } = await claimed(t);
    app.down(450); // R is 0.6 s
    const t0 = Date.now();
    assert.ok((await api.source()).length > 0);
    assert.ok(Date.now() - t0 >= 400, 'the call waited for the app');
    app.down(300); assert.equal((await api.render({ seq: 7, design: DESIGN })).candidate, 1);
    app.down(300); assert.equal((await api.faces({ seq: 8, lines: ['x'] })).cells.length, 2);
    app.down(300); assert.equal((await api.finish({ outcome: 'proposed', candidate: 1, report: {}, usage: {} })).status, 'ready');
    assert.equal(scan.candidates.length, 1, 'the retried render made one candidate');
    assert.ok(app.state.calls.filter((c) => c.fault === 'down').length >= 4);
});

test('an outage longer than the tolerance: the call fails, named an outage, after ≈ R — and attempts start only inside R', async (t) => {
    const { app, api } = await claimed(t);
    app.down(5000);
    const t0 = Date.now();
    await assert.rejects(api.source(), (e) => e.code === 'network' && e.outage === true);
    const ms = Date.now() - t0;
    assert.ok(ms >= 250 && ms < 900, `gave up after ${ms} ms`);
    const attempts = app.state.calls.filter((c) => c.fault === 'down');
    assert.ok(attempts.every((c) => c.at - attempts[0].at < 600), 'no attempt started after R');
});

test('one attempt is cut at the attempt limit: a hanging app is retried, a slow answer inside the limit is waited for', async (t) => {
    const { app, api } = await claimed(t);
    app.state.delay.render = 250; // "a 25 s render": inside one attempt (0.3 s)
    const t0 = Date.now();
    assert.equal((await api.render({ seq: 1, design: DESIGN })).candidate, 1);
    assert.ok(Date.now() - t0 >= 250);
    assert.equal(app.calls('render').length, 1, 'the scanner did not give up on a call the app was still answering');
    app.state.delay.render = 0;
    app.hang(350); // nothing answers for longer than one attempt
    assert.equal((await api.render({ seq: 2, design: DESIGN })).candidate, 2);
    assert.ok(app.state.calls.some((c) => c.fault === 'hang'));
});

test('render is idempotent by (run, seq): a retry after a lost answer sends the same seq and gets the same candidate', async (t) => {
    const { app, api, scan } = await claimed(t);
    app.state.dropAnswerOnce.render = true; // rendered and stored — the answer never arrives
    const r = await api.render({ seq: 5, design: DESIGN });
    assert.equal(r.candidate, 1);
    assert.deepEqual(app.calls('render').map((c) => c.seq), [5, 5]);
    assert.equal(scan.candidates.length, 1);
});

test('a busy renderer is retried inside the tolerance with the same seq; one that stays away is renderer_unavailable', async (t) => {
    const { app, api, scan } = await claimed(t);
    let busy = 2;
    app.state.renderer = () => (busy-- > 0 ? 'renderer_busy' : null);
    const fast = { appOutageToleranceSeconds: 4 };
    let seq = 0; const s = ++seq;
    const r = await withRendererRetry(() => api.render({ seq: s, design: DESIGN }), fast);
    assert.equal(r.candidate, 1); assert.deepEqual(app.calls('render').map((c) => c.seq), [1, 1, 1]);
    app.state.renderer = () => 'renderer_unavailable';
    await assert.rejects(withRendererRetry(() => api.render({ seq: 2, design: DESIGN }), { appOutageToleranceSeconds: 0.3 }), (e) => e.code === 'renderer_unavailable');
    assert.equal(scan.candidates.length, 1);
});

test('409 and 410 are never retried: stale run, lost lease, a deleted project', async (t) => {
    const { app, api, scan } = await claimed(t);
    app.end(scan, 'cancelled');
    await assert.rejects(api.render({ seq: 1, design: DESIGN }), (e) => e.code === 'lease_lost' && e.status === 409 && e.runOver);
    assert.equal(app.calls('render').length, 1);
    assert.deepEqual(await api.beat({ seq: 1, usage: { outputTokens: 7 } }), { ok: true, cancel: true, mode: 'on', status: 'cancelled' });
    app.state.scans.delete(scan.id);
    await assert.rejects(api.beat({ seq: 2, usage: {} }), (e) => e.code === 'gone' && e.status === 410 && e.runOver);
    await assert.rejects(api.source(), (e) => e.status === 410);
});

test('finish is idempotent: when the answer of the first attempt is lost, the repeat is told the scan\'s state — no second finish applies', async (t) => {
    const { app, api, scan } = await claimed(t);
    await api.render({ seq: 1, design: DESIGN });
    app.state.dropAnswerOnce.finish = true;
    const f = await api.finish({ outcome: 'proposed', candidate: 1, report: {}, usage: { outputTokens: 9 } });
    assert.deepEqual(f, { ok: false, applied: false, late: true, status: 'ready', code: 'stale_run', errorCode: null });
    assert.equal(scan.finishes.length, 2); assert.equal(scan.status, 'ready');
});

test('a late finish — the scan was cancelled or interrupted meanwhile — records usage only and is not an error', async (t) => {
    const { app, api, scan } = await claimed(t);
    app.end(scan, 'cancelled');
    const f = await api.finish({ outcome: 'proposed', candidate: 1, report: {}, usage: { outputTokens: 11 } });
    assert.deepEqual([f.applied, f.late, f.status, f.code], [false, true, 'cancelled', 'stale_run']);
    assert.equal(scan.usage.outputTokens, 11, 'the usage of the run was recorded');
    assert.equal(scan.finish, null);
});

test('a finish survives an outage inside the tolerance; one the app never hears of fails as an outage (the lease lapses)', async (t) => {
    const { app, api, scan } = await claimed(t);
    await api.render({ seq: 1, design: DESIGN });
    app.down(5000);
    await assert.rejects(api.finish({ outcome: 'proposed', candidate: 1, report: {}, usage: {} }), (e) => e.outage === true);
    assert.equal(scan.finish, null);
});

test('beat: one attempt, cut at its own limit — never retried, the timer sends the next', async (t) => {
    const { app, api } = await claimed(t);
    app.hang(400);
    const t0 = Date.now();
    await assert.rejects(api.beat({ seq: 1, usage: {} }), (e) => e.code === 'timeout');
    assert.ok(Date.now() - t0 < 250, 'cut at 0.1 s'); assert.equal(app.calls('beat').length, 1);
});

test('release: one attempt, cut at 20 s (here 0.2 s); with a reason the provider is recorded as blocked', async (t) => {
    const { app, api, scan } = await claimed(t);
    app.hang(1000);
    const t0 = Date.now();
    await assert.rejects(api.release({ usage: {}, reason: 'shutdown' }), (e) => e.code === 'timeout');
    assert.ok(Date.now() - t0 < 450); assert.equal(app.calls('release').length, 1);
    app.state.hangUntil = 0;
    assert.deepEqual(await api.release({ usage: { outputTokens: 3 }, reason: 'provider_blocked', providerError: 'billing' }), { ok: true, applied: true, status: 'queued' });
    assert.equal(app.state.providerState, 'blocked'); assert.equal(scan.status, 'queued'); assert.equal(scan.usage.outputTokens, 3);
});

test('intake calls: the original comes as bytes, artefacts go as bytes; a pass needs the working copy first', async (t) => {
    const { app, api, scan } = await claimed(t, { lane: 'intake' });
    assert.equal((await api.source()).toString(), 'original');
    assert.deepEqual(await api.putArtifact('working', Buffer.from('w'.repeat(10))), { ok: true, bytes: 10 });
    app.down(300);
    assert.deepEqual(await api.putArtifact('preview', Buffer.from('p')), { ok: true, bytes: 1 });
    assert.equal((await api.finish({ outcome: 'passed', preCheck: { verdict: 'design' }, usage: {} })).status, 'queued');
    assert.equal(scan.artifacts.working.toString(), 'w'.repeat(10));
});

test('an app-backed tool survives an app outage inside the tolerance and a slow render; past it the model gets a tool error, not silence', async (t) => {
    const { app, api } = await claimed(t);
    let seq = 0;
    const timing = { appOutageToleranceSeconds: FAST.appOutageToleranceSeconds };
    const impl = { design_preview: async (a) => { const s = ++seq; const r = await withRendererRetry(() => api.render({ seq: s, design: a.design }), timing); return { json: { candidate: r.candidate } }; } };
    const server = new ToolServer({ definitions: [{ name: 'design_preview', description: '', input_schema: { type: 'object' } }], impl, caps: new Caps(), appTools: ['design_preview'], timeouts: { localMs: 300, appMs: 950 } });
    app.down(500); app.state.delay.render = 250;
    const ok = await server.dispatch('design_preview', { design: DESIGN });
    assert.equal(ok.isError, false); assert.equal(JSON.parse(ok.content[0].text).candidate, 1);
    app.down(5000);
    const lost = await server.dispatch('design_preview', { design: DESIGN });
    assert.equal(lost.isError, true); assert.match(lost.content[0].text, /network|renderer_unavailable/);
    assert.ok(server.calls[1].ms < 950, 'the retries ended before the tool timeout');
});
