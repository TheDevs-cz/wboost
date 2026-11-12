// Both engines against a local fault proxy in place of the provider (eval/fake-provider.mjs): the pinned
// request timeout, the stream-idle watchdog, the retries, what is never retried, a refusal. No model.
// The Agent SDK tests start the real engine binary.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Anthropic from '@anthropic-ai/sdk';
import { startFakeProvider } from '../eval/fake-provider.mjs';
import { runMessages } from '../src/engine/messages.mjs';
import { runSdk, engineEnv } from '../src/engine/sdk.mjs';
import { LIST_PRICES } from '../src/engine/pricing.mjs';
import { alive, descendants } from '../src/proc.mjs';

const TOOL = [{ name: 'scan_measure', description: 'Measures a region.', input_schema: { type: 'object', properties: { x: { type: 'number' } }, additionalProperties: false } }];
const SCHEMA = { type: 'object', properties: { status: { type: 'string' } }, required: ['status'], additionalProperties: false };
const call = { tools: [{ name: 'scan_measure', input: { x: 1 } }] };
const FINAL = { final: { status: 'done' } };
const err = (status, type, extra = {}) => ({ status, type, message: `fake ${type}`, ...extra });
const base = { model: 'claude-opus-5-5', effort: 'high', system: 'You are a test agent. __MAIN__', content: [{ type: 'text', text: 'Measure, then report.' }], tools: TOOL, finishSchema: SCHEMA, maxTurns: 20, maxBudgetUsd: 1.5 };
const times = (fake) => fake.state.requests.filter((q) => q.url.includes('/v1/messages')).map((q) => q.at);

// ------------------------------------------------------------------ the Messages loop: pinned exactly

/** §4.9 ÷ 100: request 1.2 s, stream idle 0.6 s, 4 retries after 0.02 / 0.08 / 0.2 / 0.3 s. */
const PINNED = { requestTimeoutSeconds: 1.2, streamIdleSeconds: 0.6, retries: 4, retryDelaysSeconds: [0.02, 0.08, 0.2, 0.3], maxOutputTokens: 1000 };
async function messages(t, plan, { settings = PINNED, latencyMs = 0 } = {}) {
    const fake = await startFakeProvider({ plan, latencyMs }); t.after(() => fake.close());
    fake.state.treatAllAsMain = true;
    const events = []; const t0 = Date.now();
    const r = await runMessages({ ...base, settings, scratchDir: mkdtempSync(join(tmpdir(), 'scan-ml-')), dispatch: async () => ({ content: [{ type: 'text', text: '{"ok":true}' }], isError: false }),
        client: new Anthropic({ apiKey: 'sk-ant-not-a-key', baseURL: fake.url, maxRetries: 0, timeout: settings.requestTimeoutSeconds * 1000 }),
        onEvent: (e) => events.push({ t: Date.now() - t0, ...e }) });
    return { r, fake, events, ms: Date.now() - t0 };
}

test('Messages loop × proxy: 529 for ever → exactly 4 retries after the pinned delays, then failed transient', async (t) => {
    const { r, fake, events } = await messages(t, [err(529, 'overloaded_error')]);
    assert.deepEqual([r.outcome, r.error.class, r.error.code], ['provider', 'transient', 'overloaded']);
    const at = times(fake);
    assert.equal(at.length, 5, 'one request and four retries');
    const gaps = at.slice(1).map((x, i) => x - at[i]);
    PINNED.retryDelaysSeconds.forEach((d, i) => assert.ok(gaps[i] >= d * 1000 - 5 && gaps[i] < d * 1000 + 250, `retry ${i + 1} after ${gaps[i]} ms (pinned ${d * 1000})`));
    assert.deepEqual(events.filter((e) => e.type === 'retry').map((e) => [e.attempt, e.of]), [[1, 4], [2, 4], [3, 4], [4, 4]]);
});

test('Messages loop × proxy: 429 and 500 are retried and the run goes on; the time lost is reported', async (t) => {
    const { r, fake, events } = await messages(t, [err(429, 'rate_limit_error'), err(500, 'api_error'), call, FINAL]);
    assert.equal(r.outcome, 'finished'); assert.deepEqual(r.finish, { status: 'done' });
    assert.equal(times(fake).length, 4);
    assert.ok(events.filter((e) => e.type === 'retry').every((e) => e.lostMs >= 0 || e.delayMs >= 0));
});

test('Messages loop × proxy: a stream that goes silent is cut by the idle watchdog (not by the request timeout) and retried', async (t) => {
    const { r, fake } = await messages(t, [{ stall: 30_000 }, call, FINAL]);
    assert.equal(r.outcome, 'finished');
    const at = times(fake);
    const cut = at[1] - at[0];
    assert.ok(cut >= 600 && cut < 1150, `the stalled request was given up after ${cut} ms: idle watchdog 600 ms + first delay, before the 1200 ms request timeout`);
});

test('Messages loop × proxy: a request without an answer is cut at the pinned request timeout, retried, then failed transient', async (t) => {
    const settings = { ...PINNED, requestTimeoutSeconds: 0.4, retries: 1, retryDelaysSeconds: [0.05] };
    const { r, fake, ms } = await messages(t, [call, FINAL], { settings, latencyMs: 3000 });
    assert.deepEqual([r.outcome, r.error.class], ['provider', 'transient']);
    assert.equal(times(fake).length, 2, 'the request and its one retry');
    assert.ok(ms >= 800 && ms < 2000, `2 × 0.4 s + 0.05 s: ${ms} ms`);
});

test('Messages loop × proxy: billing, auth, a spend cap, a usage limit and a bad request are never retried', async (t) => {
    for (const [step, cls, code] of [
        [err(402, 'billing_error'), 'billing_auth', 'billing'], [err(401, 'authentication_error'), 'billing_auth', 'authentication'], [err(403, 'permission_error'), 'billing_auth', 'permission'],
        [err(429, 'rate_limit_error', { details: { error_code: 'enforced_spend_limit_reached' } }), 'billing_auth', 'spend_limit'],
        [err(400, 'invalid_request_error', { message: 'You have reached your specified workspace API usage limits.' }), 'billing_auth', 'usage_limit'],
        [err(400, 'invalid_request_error', { message: 'image exceeds the maximum' }), 'request', 'invalid_request'], [err(404, 'not_found_error'), 'request', 'model_not_found'],
    ]) {
        const { r, fake } = await messages(t, [step]);
        assert.deepEqual([r.outcome, r.error.class, r.error.code, times(fake).length], ['provider', cls, code, 1], JSON.stringify(step));
    }
});

test('Messages loop × proxy: a refusal is its own outcome; nothing is sent after it', async (t) => {
    const { r, fake } = await messages(t, [{ refusal: true }]);
    assert.equal(r.outcome, 'refusal'); assert.equal(times(fake).length, 1);
    assert.deepEqual(fake.state.requests.map((q) => q.model), ['claude-opus-5-5']);
});

// ------------------------------------------------------------------ the Agent SDK: the real engine binary

async function sdk(t, plan, { settings = { requestTimeoutSeconds: 15, retries: 4, streamIdleSeconds: 10 }, dropEnv = [], dispatch = null, signal = null, latencyMs = 0 } = {}) {
    const fake = await startFakeProvider({ plan, latencyMs }); t.after(() => fake.close());
    const scratchDir = mkdtempSync(join(tmpdir(), 'scan-sdk-'));
    const env = engineEnv({ base: { ...process.env, ANTHROPIC_API_KEY: 'sk-ant-not-a-key', ANTHROPIC_BASE_URL: fake.url }, settings, scratchDir });
    for (const k of dropEnv) delete env[k];
    const events = []; const t0 = Date.now();
    const r = await runSdk({ ...base, settings, scratchDir, env, signal, prices: LIST_PRICES, dispatch: dispatch ?? (async () => ({ content: [{ type: 'text', text: '{"ok":true}' }], isError: false })), onEvent: (e) => events.push({ t: Date.now() - t0, ...e }) });
    return { r, fake, events, ms: Date.now() - t0 };
}

test('Agent SDK × proxy: a tool round and the final report; the request carries the pinned shape and only the scan tools', async (t) => {
    const { r, fake } = await sdk(t, [call, FINAL]);
    assert.equal(r.outcome, 'finished'); assert.deepEqual(r.finish, { status: 'done' });
    const first = fake.state.requests.find((q) => q.tools > 0);
    assert.deepEqual([first.model, first.effort, first.thinking], ['claude-opus-5-5', 'high', 'adaptive']);
    assert.deepEqual(first.toolNames.filter((n) => n.startsWith('mcp__')), ['mcp__scan__scan_measure'], 'no built-in tool, no other server');
    assert.ok(first.toolNames.every((n) => n.startsWith('mcp__scan__') || ['StructuredOutput', 'DeferredToolPlaceholder'].includes(n)), first.toolNames.join(', '));
    assert.ok(r.usage.byModel['claude-opus-5-5'].input > 0 && r.usage.costUsd > 0);
});

test('Agent SDK × proxy: 429 and 500 are retried by the harness (retry count pinned to 4) and the run goes on', async (t) => {
    const { r, events } = await sdk(t, [err(429, 'rate_limit_error'), err(500, 'api_error'), call, FINAL]);
    assert.equal(r.outcome, 'finished');
    const retries = events.filter((e) => e.type === 'retry');
    assert.ok(retries.length >= 2, `retry events: ${retries.length}`);
    assert.ok(retries.every((e) => e.of === 4), 'CLAUDE_CODE_MAX_RETRIES = the definition\'s 4');
});

test('Agent SDK × proxy: billing and auth errors come back classified for the probe', async (t) => {
    const { r, fake } = await sdk(t, [err(402, 'billing_error')]);
    assert.deepEqual([r.outcome, r.error.class], ['provider', 'billing_auth']);
    assert.equal(fake.state.main, 1, '402 is not retried');
});

test('Agent SDK × proxy: a stalled stream is cut by the byte watchdog (pinned 10 s) and the request retried', async (t) => {
    const { r, fake, ms } = await sdk(t, [{ stall: 120_000 }, call, FINAL]);
    assert.equal(r.outcome, 'finished');
    const main = fake.state.requests.filter((q) => q.tools > 0).map((q) => q.at);
    assert.ok(main[1] - main[0] >= 9_500 && main[1] - main[0] < 20_000, `retried ${main[1] - main[0]} ms after the stalled request began`);
    assert.ok(ms < 40_000);
});

test('Agent SDK × proxy: a stream that stalls every time is a provider that is not there — transient, never a finished run without a result', async (t) => {
    // the stream goes silent (cut by the byte watchdog, 10 s), the request the harness repeats without a stream
    // gets no answer either (cut by the request timeout, 4 s here), and so on until the retries are used up
    const { r, fake, ms } = await sdk(t, [{ stall: 120_000 }], { settings: { requestTimeoutSeconds: 4, retries: 2, streamIdleSeconds: 10 } });
    const main = fake.state.requests.filter((q) => q.tools > 0);
    assert.deepEqual([r.outcome, r.error?.class], ['provider', 'transient'], `after ${main.length} stalled requests in ${ms} ms: ${JSON.stringify({ outcome: r.outcome, error: r.error, subtype: r.facts.resultSubtype, terminal: r.facts.terminalReason, text: String(r.text ?? '').slice(0, 200), assistantErrors: r.facts.assistantErrors, retries: r.facts.retries?.length })}`);
    assert.equal(r.finish, null);
    assert.ok(main.length >= 2, 'the stalled request was retried at least once');
});

test('Agent SDK × proxy: a refusal ends the run — the switch that keeps the harness from re-running the turn on another model is set', async (t) => {
    const { r, fake } = await sdk(t, [{ refusal: true }]);
    assert.deepEqual([r.outcome, r.error.class], ['refusal', 'refusal']);
    assert.deepEqual([...new Set(fake.state.requests.map((q) => q.model))], ['claude-opus-5-5'], 'only the builder model was asked');
    assert.deepEqual(Object.keys(r.usage.byModel), ['claude-opus-5-5']);
    assert.equal(r.facts.refusalFallback, undefined);
    assert.equal(r.facts.refusal.subtype, 'model_refusal_no_fallback');
});

test('Agent SDK × proxy: were the switch ignored, the fallback is detected, the run stopped as a refusal, and the other model\'s usage priced by its own row', async (t) => {
    // the harness as it behaves by default: the refused turn is re-run on claude-opus-4-8
    const { r, fake } = await sdk(t, [{ refusal: true }], { dropEnv: ['CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK'] });
    assert.equal(r.outcome, 'refusal', 'never "finished" on another model');
    assert.deepEqual([r.facts.refusalFallback.from, r.facts.refusalFallback.to, r.facts.refusalFallback.stopped], ['claude-opus-5-5', 'claude-opus-4-8', true]);
    assert.ok(fake.state.requests.some((q) => q.model === 'claude-opus-4-8'));
    assert.equal(fake.state.requests.filter((q) => q.model === 'claude-opus-4-8').length, 1, 'stopped after the one request the harness had already sent');
    const u = r.usage.byModel;
    assert.ok(u['claude-opus-4-8']?.input > 0, 'the fallback request\'s tokens are recorded under its model');
    const p = LIST_PRICES.models;
    const expected = (u['claude-opus-5-5'].input * p['claude-opus-5-5'].input + u['claude-opus-5-5'].output * p['claude-opus-5-5'].output + u['claude-opus-4-8'].input * p['claude-opus-4-8'].input + u['claude-opus-4-8'].output * p['claude-opus-4-8'].output) / 1e6;
    assert.ok(Math.abs(r.usage.costUsd - expected) < 1e-6, `each model at its own price: ${r.usage.costUsd} vs ${expected}`);
    assert.equal(r.usage.unknownModels.length, 0);
});

test('Agent SDK × proxy: an abort stops the engine process (by pid) and the usage seen so far survives', async (t) => {
    const controller = new AbortController();
    let calls = 0, enginePids = [];
    const { r } = await sdk(t, [call, call, call, call, call, FINAL], { signal: controller.signal, latencyMs: 300, dispatch: async () => {
        if (++calls === 2) { enginePids = descendants(process.pid).filter((p) => /claude/i.test(p.comm)).map((p) => p.pid); controller.abort(); }
        return { content: [{ type: 'text', text: '{"ok":true}' }], isError: false };
    } });
    assert.equal(r.outcome, 'aborted');
    assert.ok(enginePids.length >= 1, 'the engine binary was running');
    await new Promise((ok) => setTimeout(ok, 1500));
    assert.deepEqual(enginePids.filter((p) => alive(p)), [], 'the engine process is gone');
    assert.ok(r.usage.byModel['claude-opus-5-5']?.input > 0, 'usage of an aborted run is metered from stream events');
});
