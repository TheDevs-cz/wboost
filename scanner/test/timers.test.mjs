import { readFileSync } from 'node:fs';
// The heartbeat at the real numbers of architecture §4.9 — on a mocked clock: H 15 s, one attempt ≤ 10 s,
// self-abort 105 s after the SEND time of the last acknowledged beat, app-outage tolerance 60 s.
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { Heartbeat, every } from '../src/timers.mjs';
import { ScannerApiError, DEFAULT_TIMING } from '../src/protocol.mjs';

/** Advances the mocked clock in 1 s steps, letting promises settle in between. */
async function advance(seconds, step = 1000) {
    for (let t = 0; t < seconds * 1000; t += step) { mock.timers.tick(step); for (let i = 0; i < 5; i++) await Promise.resolve(); }
}
function clock() { mock.timers.enable({ apis: ['setInterval', 'setTimeout', 'Date'], now: 1_000_000 }); }
const state = () => ({ stage: 'build', note: 'measuring', noteParams: null, round: 1, usage: { model: 'claude-opus-5-5', inputTokens: 10, outputTokens: 5, turns: 2 } });

test('timing: the pairs of §4.9 hold in the defaults (heartbeat < self-abort < lease; attempts inside R end before the tool timeout)', () => {
    const t = DEFAULT_TIMING;
    assert.ok(t.heartbeatAttemptSeconds < t.heartbeatSeconds);
    // the last beat before an outage is up to H old, the first after it leaves up to H later and is acknowledged within one attempt
    assert.ok(2 * t.heartbeatSeconds + t.appOutageToleranceSeconds + t.heartbeatAttemptSeconds < t.selfAbortSeconds, '15 + 60 + 15 + 10 = 100 < 105');
    assert.ok(t.selfAbortSeconds + t.killGraceSeconds < t.leaseSeconds, 'the engine is dead before the app gives up the lease');
    assert.ok(t.appOutageToleranceSeconds + t.appCallAttemptSeconds <= 90);
    assert.ok(t.killGraceSeconds + t.releaseAttemptSeconds <= t.shutdownSeconds, '5 + 20 = 25 ≤ 30');
    assert.ok(t.intakeCapSeconds + t.appCallAttemptSeconds <= t.intakeLeaseSeconds);
});

test('timing: every number the scanner carries itself is the definition\'s — one source, no drift', () => {
    // The service needs its transport timing BEFORE the first claim brings a definition (claim poll, tick, the
    // HTTP client's attempt and tolerance, the probe, the shutdown), so those are constants here. They must be
    // the numbers of resources/ai-scan/definition/definition.json — the document's §4.9 has one value per quantity.
    const timing = JSON.parse(readFileSync(new URL('../../resources/ai-scan/definition/definition.json', import.meta.url), 'utf8')).timing;
    for (const [key, value] of Object.entries(DEFAULT_TIMING)) assert.equal(timing[key], value, `timing.${key}: definition ${timing[key]}, scanner ${value}`);
    // and nothing in the definition's timing is a name the scanner does not know
    const read = new Set([...Object.keys(DEFAULT_TIMING), 'localToolTimeoutSeconds', 'appToolTimeoutSeconds', 'normaliseSeconds']);
    assert.deepEqual(Object.keys(timing).filter((k) => !read.has(k)), [], 'a timing key nobody reads');
});

test('heartbeat: beats leave every 15 s for 200 s while nothing else happens, each with its own seq, the stage and the usage', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const sent = [], aborts = [];
    const hb = new Heartbeat({ api: { beat: async (b) => { sent.push({ at: Date.now(), ...b }); return { ok: true, cancel: false, mode: 'on' }; } }, state, onAbort: (r) => aborts.push(r) }).start();
    await advance(200); // the engine is blocked for 200 s: no event, no turn
    hb.stop();
    assert.equal(sent.length, 13, '200 s ÷ 15 s');
    assert.deepEqual(sent.map((b) => b.seq), Array.from({ length: 13 }, (_, i) => i + 1));
    assert.ok(sent.every((b, i) => i === 0 || b.at - sent[i - 1].at === 15_000));
    assert.deepEqual([sent[0].stage, sent[0].note, sent[0].round, sent[0].usage.outputTokens], ['build', 'measuring', 1, 5]);
    assert.deepEqual(aborts, []);
    await advance(60);
    assert.equal(sent.length, 13, 'a stopped heartbeat is silent');
});

test('heartbeat: the found-text boxes travel with the beats until one that carried them is acknowledged, and again when they change', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const sent = [];
    let boxes = null, down = true;
    const hb = new Heartbeat({ api: { beat: async (b) => { sent.push(b); if (down) throw Object.assign(new Error('away'), { code: 'network' }); return { ok: true, cancel: false, mode: 'on' }; } }, state: () => ({ ...state(), textBoxes: boxes }), onAbort: () => {} }).start();
    await advance(16);
    assert.equal('textBoxes' in sent[0], false, 'nothing to send before the reading');
    boxes = { width: 1080, height: 1350, boxes: [[80, 600, 520, 36]] };
    await advance(30);                       // two beats while the app does not answer: both carry the boxes
    down = false;
    await advance(30);                       // acknowledged once, then no more
    const carried = sent.map((b) => !!b.textBoxes);
    assert.deepEqual(carried.slice(1), [true, true, true, false], JSON.stringify(carried));
    assert.deepEqual(sent[1].textBoxes, boxes);
    boxes = { width: 1080, height: 1350, boxes: [[80, 600, 520, 36], [80, 670, 380, 36]] };   // a restart of the reading found other lines
    await advance(15);
    assert.deepEqual(sent.at(-1).textBoxes, boxes);
    hb.stop();
});

test('heartbeat: without any acknowledgement the run is aborted 105 s after the claim — not before, not later', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const aborts = []; const t0 = Date.now();
    new Heartbeat({ api: { beat: async () => { throw new ScannerApiError('network', null, { retryable: true }); } }, state, onAbort: (r, d) => aborts.push({ r, at: Date.now() - t0, d }) }).start();
    await advance(104);
    assert.deepEqual(aborts, []);
    await advance(2);
    assert.equal(aborts.length, 1); assert.equal(aborts[0].r, 'app_unreachable'); assert.equal(aborts[0].at, 105_000);
    await advance(120);
    assert.equal(aborts.length, 1, 'aborted once');
});

test('heartbeat: self-abort counts from the SEND time of the last acknowledged beat, not from its acknowledgement', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const aborts = []; const t0 = Date.now(); let n = 0;
    // beats 1–3 are acknowledged 9 s after they were sent (inside the 10 s attempt); then the app is gone
    const api = { beat: () => (++n <= 3 ? new Promise((ok) => setTimeout(() => ok({ ok: true, cancel: false, mode: 'on' }), 9000)) : Promise.reject(new ScannerApiError('network', null))) };
    new Heartbeat({ api, state, onAbort: (r) => aborts.push({ r, at: Date.now() - t0 }) }).start();
    await advance(200);
    // beat 3 was SENT at 45 s (acknowledged at 54 s): 45 + 105 = 150 s
    assert.deepEqual(aborts, [{ r: 'app_unreachable', at: 150_000 }]);
});

test('heartbeat: a 60 s app outage does not trip the self-abort, in the worst alignment (15 + 60 + 15 + 10 = 100 s < 105 s)', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const aborts = []; const t0 = Date.now();
    // the outage begins right after the beat sent at 15 s was acknowledged and lasts 60 s (until 75.5 s);
    // the next beat leaves at 90 s and is acknowledged 9.9 s later
    const down = (at) => at > 15_500 && at < 75_500;
    const api = { beat: () => { const at = Date.now() - t0; return down(at) ? Promise.reject(new ScannerApiError('network', null)) : new Promise((ok) => setTimeout(() => ok({ ok: true, cancel: false, mode: 'on' }), at >= 75_500 && at < 91_000 ? 9900 : 10)); } };
    const hb = new Heartbeat({ api, state, onAbort: (r) => aborts.push(r) }).start();
    await advance(240);
    hb.stop();
    assert.deepEqual(aborts, [], 'the run lives through the outage');
    assert.ok(hb.acked >= 10 && hb.failed === 4, `acked ${hb.acked}, failed ${hb.failed}`);
});

test('heartbeat: an outage of 120 s aborts the run at 105 s — 15 s before the lease would end', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const aborts = []; const t0 = Date.now();
    const api = { beat: () => { const at = Date.now() - t0; return at > 30_500 && at < 150_500 ? Promise.reject(new ScannerApiError('timeout', null)) : Promise.resolve({ ok: true, cancel: false, mode: 'on' }); } };
    new Heartbeat({ api, state, onAbort: (r) => aborts.push({ r, at: Date.now() - t0 }) }).start();
    await advance(200);
    assert.deepEqual(aborts, [{ r: 'app_unreachable', at: 30_000 + 105_000 }]);
});

test('heartbeat: a hung beat is cut at 10 s and never delays the next one', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const sent = [], aborts = [];
    const api = { beat: (b) => { sent.push({ seq: b.seq, at: Date.now() }); return b.seq === 2 ? new Promise(() => {}) : Promise.resolve({ ok: true, cancel: false, mode: 'on' }); } };
    const hb = new Heartbeat({ api, state, onAbort: (r) => aborts.push(r) }).start();
    await advance(76);
    assert.deepEqual(sent.map((b) => b.seq), [1, 2, 3, 4, 5]);
    assert.equal(sent[2].at - sent[1].at, 15_000, 'beat 3 left on the timer although beat 2 never answered');
    assert.deepEqual([hb.acked, hb.failed, hb.inFlight], [4, 1, 0], 'the hung attempt was given up after 10 s');
    assert.deepEqual(aborts, []);
});

test('heartbeat: 409 and 410 end the run at once; cancel and mode off are told apart; an old answer never revives a stopped beat', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const run = async (answer) => {
        const aborts = [];
        const hb = new Heartbeat({ api: { beat: answer }, state, onAbort: (r, d) => aborts.push([r, d?.status ?? d?.code ?? null]) }).start();
        await advance(16); hb.stop();
        return aborts;
    };
    assert.deepEqual(await run(async () => { throw new ScannerApiError('lease_lost', 409); }), [['lease_lost', 'lease_lost']]);
    assert.deepEqual(await run(async () => { throw new ScannerApiError('stale_run', 409); }), [['lease_lost', 'stale_run']]);
    assert.deepEqual(await run(async () => { throw new ScannerApiError('gone', 410); }), [['gone', null]]);
    assert.deepEqual(await run(async () => ({ ok: true, cancel: true, mode: 'on', status: 'cancelled' })), [['cancelled', 'cancelled']]);
    assert.deepEqual(await run(async () => ({ ok: true, cancel: true, mode: 'off', status: 'failed' })), [['mode_off', 'failed']]);
    assert.deepEqual(await run(async () => ({ ok: true, cancel: true, mode: 'on', status: 'failed' })), [['ended', 'failed']]);
    assert.deepEqual(await run(async () => ({ ok: true, cancel: false, mode: 'off' })), [['mode_off', null]]);
});

test('heartbeat: the closing beat of an ended run carries the final usage and aborts nothing', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    const sent = [], aborts = []; let u = 5;
    const hb = new Heartbeat({ api: { beat: async (b) => { sent.push(b); return { ok: true, cancel: true, mode: 'on', status: 'cancelled' }; } }, state: () => ({ ...state(), usage: { outputTokens: u } }), onAbort: (r) => aborts.push(r) }).start();
    await advance(16);
    assert.deepEqual(aborts, ['cancelled']);
    hb.stop(); u = 99;
    const r = await hb.beat({ final: true });
    assert.equal(r.cancel, true); assert.equal(sent.at(-1).usage.outputTokens, 99); assert.equal(sent.at(-1).seq, sent.length);
    assert.deepEqual(aborts, ['cancelled'], 'no second abort');
});

test('every(): one run at a time, the next after the gap; stop ends it', async (t) => {
    clock(); t.after(() => mock.timers.reset());
    let runs = 0, overlapping = 0, inside = 0;
    const loop = every(30_000, async () => { runs++; inside++; if (inside > 1) overlapping++; await new Promise((ok) => setTimeout(ok, 40_000)); inside--; });
    await advance(200);
    loop.stop();
    assert.equal(overlapping, 0); assert.equal(runs, 3, 'each run takes 40 s and the next starts 30 s after it ended');
    await advance(100);
    assert.equal(runs, 3);
});
