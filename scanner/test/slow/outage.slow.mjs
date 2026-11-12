// The app-outage rules at their REAL times (architecture §4.9) — no scaling, no mocked clock:
// an app that is away for 55 s and then needs 25 s for the render. An app-backed tool call must come back
// with the candidate inside its 95 s timeout, and the heartbeat must not abort the run meanwhile.
// Takes ≈ 85 s:  npm run test:slow
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { HttpScannerApi, DEFAULT_TIMING } from '../../src/protocol.mjs';
import { Heartbeat } from '../../src/timers.mjs';
import { withRendererRetry } from '../../src/run.mjs';
import { ToolServer } from '../../src/tools/server.mjs';
import { Caps } from '../../src/caps.mjs';
import { startFakeApp, TOKEN } from '../support/fake-app.mjs';

const DESIGN = { canvas: { width: 300, height: 200 }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' }, { kind: 'shape', id: 'a', shape: 'rectangle', x: 10, y: 10, width: 100, height: 50, fill: '#0b3d6b' }] };

test('a 55 s app outage followed by a 25 s render: the tool call succeeds inside 95 s and the run is not aborted', { timeout: 150_000 }, async (t) => {
    const app = await startFakeApp(); t.after(() => app.close());
    const scan = await app.addScan({ design: DESIGN });
    const http = new HttpScannerApi({ baseUrl: app.url, token: TOKEN, scannerVersion: 'slow' });
    assert.deepEqual([http.timing.appOutageToleranceSeconds, http.timing.appCallAttemptSeconds, http.timing.selfAbortSeconds, http.timing.heartbeatSeconds], [60, 30, 105, 15]);
    const { api, job } = await http.claim('build');
    const aborts = [];
    const hb = new Heartbeat({ api, timing: DEFAULT_TIMING, state: () => ({ stage: 'build', usage: {} }), onAbort: (r) => aborts.push(r) }).start();
    t.after(() => hb.stop());
    let seq = 0;
    const impl = { design_preview: async (a) => { const s = ++seq; const r = await withRendererRetry(() => api.render({ seq: s, design: a.design }), DEFAULT_TIMING); return { json: { candidate: r.candidate } }; } };
    const server = new ToolServer({ definitions: [{ name: 'design_preview', description: '', input_schema: { type: 'object' } }], impl, caps: new Caps(), appTools: ['design_preview'], timeouts: { localMs: 30_000, appMs: 95_000 } });
    await new Promise((ok) => setTimeout(ok, 16_000)); // one beat is acknowledged, then the app goes away
    assert.equal(hb.acked, 1);
    app.down(55_000); app.state.delay.render = 25_000;
    const t0 = Date.now();
    const r = await server.dispatch('design_preview', { design: DESIGN });
    const ms = Date.now() - t0;
    assert.equal(r.isError, false, r.content[0].text);
    assert.equal(JSON.parse(r.content[0].text).candidate, 1);
    assert.ok(ms >= 79_000 && ms < 95_000, `the tool call took ${Math.round(ms / 1000)} s (55 s outage + 25 s render)`);
    assert.equal(scan.candidates.length, 1); assert.equal(job.run, 1);
    assert.deepEqual(aborts, [], 'the heartbeat did not abort the run: the outage stayed below the self-abort');
    assert.ok(hb.failed >= 3, `beats failed during the outage: ${hb.failed}`);
    await new Promise((ok) => setTimeout(ok, 16_000));
    assert.ok(hb.acked >= 2, 'beats are acknowledged again');
});
