import { test } from 'node:test';
import assert from 'node:assert/strict';
import Anthropic from '@anthropic-ai/sdk';
import { runMessages, toToolResultContent } from '../src/engine/messages.mjs';
import { engineEnv, runSdk, foreignTools } from '../src/engine/sdk.mjs';
import { engine, userContent } from '../src/engine/index.mjs';
import { UsageMeter, priceUsage, fromApiUsage, fromModelUsage, LIST_PRICES } from '../src/engine/pricing.mjs';
import { mkdtempSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/** A fake Messages client: each call to stream() takes the next scripted answer (a Message, or an Error to throw). */
function fakeClient(script) {
    const requests = [];
    return { requests, messages: { stream(params) { requests.push(structuredClone(params)); const next = script.shift(); return { finalMessage: async () => { if (next instanceof Error) throw next; if (typeof next === 'function') return next(params); return next; } }; } } };
}
const usage = (o = {}) => ({ input_tokens: 100, output_tokens: 50, cache_read_input_tokens: 0, cache_creation_input_tokens: 0, ...o });
const msg = (id, stop_reason, content, u = usage()) => ({ id, type: 'message', role: 'assistant', model: 'claude-opus-5-5', stop_reason, content, usage: u });
const toolUse = (id, name, input = {}) => ({ type: 'tool_use', id, name, input });
const apiError = (status, type, message = type, details = null) => Anthropic.APIError.generate(status, { type: 'error', error: { type, message, ...(details ? { details } : {}) } }, message, new Headers());
const TOOLS = [{ name: 'scan_measure', description: 'measure', input_schema: { type: 'object', properties: {} } }];
const SCHEMA = { type: 'object', properties: { status: { type: 'string' } }, required: ['status'], additionalProperties: false };
const base = (client, over = {}) => ({ client, model: 'claude-opus-5-5', effort: 'high', system: 'SYSTEM', content: [{ type: 'text', text: 'go' }], tools: TOOLS, finishSchema: SCHEMA, settings: { retries: 4, retryDelaysSeconds: [0.001, 0.001, 0.001, 0.001] }, dispatch: async () => ({ content: [{ type: 'text', text: '{"ok":true}' }, { type: 'image', data: 'QUJD', mimeType: 'image/png' }], isError: false }), ...over });

test('Messages loop: tool round, then the final report; the request carries the pinned shape', async () => {
    const client = fakeClient([
        msg('m1', 'tool_use', [{ type: 'thinking', thinking: '', signature: 's' }, toolUse('t1', 'scan_measure', { x: 1 }), toolUse('t2', 'scan_measure', { x: 2 })]),
        msg('m2', 'end_turn', [{ type: 'text', text: '{"status":"done"}' }], usage({ output_tokens: 70, cache_read_input_tokens: 900 })),
    ]);
    const calls = [];
    const r = await runMessages(base(client, { dispatch: async (name, input) => { calls.push([name, input]); return { content: [{ type: 'text', text: '{"ok":true}' }, { type: 'image', data: 'QUJD', mimeType: 'image/png' }], isError: name === 'x' }; } }));
    assert.equal(r.outcome, 'finished'); assert.deepEqual(r.finish, { status: 'done' }); assert.equal(r.turns, 2);
    assert.deepEqual(calls, [['scan_measure', { x: 1 }], ['scan_measure', { x: 2 }]]);
    const [first, second] = client.requests;
    assert.equal(first.model, 'claude-opus-5-5');
    assert.deepEqual(first.thinking, { type: 'adaptive' });
    assert.equal(first.output_config.effort, 'high');
    assert.deepEqual(first.output_config.format, { type: 'json_schema', schema: SCHEMA });
    assert.equal(first.system[0].text, 'SYSTEM');
    assert.equal(first.tool_choice, undefined, 'forced tool choice is not supported on this model');
    assert.equal(second.messages.length, 3);
    assert.deepEqual(second.messages[1].content, client.requests[1].messages[1].content, 'the assistant turn goes back unchanged (thinking blocks included)');
    assert.equal(second.messages[1].content[0].type, 'thinking');
    const results = second.messages[2].content;
    assert.deepEqual(results.map((b) => [b.type, b.tool_use_id]), [['tool_result', 't1'], ['tool_result', 't2']], 'all results of a turn in ONE user message');
    assert.deepEqual(results[0].content[1], { type: 'image', source: { type: 'base64', media_type: 'image/png', data: 'QUJD' } });
    assert.deepEqual(r.usage.byModel['claude-opus-5-5'], { input: 200, output: 120, cacheRead: 900, cacheWrite5m: 0, cacheWrite1h: 0 });
    assert.equal(r.usage.costUsd, +((200 * 4 + 120 * 20 + 900 * 0.2) / 1e6).toFixed(6));
});

test('Messages loop: 529 and 429 are retried with the pinned delays, then the run goes on', async () => {
    const client = fakeClient([apiError(529, 'overloaded_error'), apiError(429, 'rate_limit_error'), msg('m1', 'end_turn', [{ type: 'text', text: '{"status":"done"}' }])]);
    const events = [];
    const r = await runMessages(base(client, { onEvent: (e) => events.push(e) }));
    assert.equal(r.outcome, 'finished');
    assert.deepEqual(r.facts.retries.map((x) => [x.attempt, x.of, x.status, x.error]), [[1, 4, 529, 'overloaded'], [2, 4, 429, 'rate_limit']]);
    assert.equal(events.filter((e) => e.type === 'retry').length, 2);
    assert.ok(events.find((e) => e.type === 'retry').lostMs >= 1);
});

test('Messages loop: a stream that goes silent is cut by the idle watchdog and retried', async () => {
    const hung = { requests: [], messages: { stream(params) { this.requests = (this.requests ?? []); hung.requests.push(params); let fail; const first = hung.requests.length === 1;
        return { on() {}, abort() { fail?.(new Error('aborted by the client')); }, finalMessage: () => (first ? new Promise((_, no) => { fail = no; }) : Promise.resolve(msg('m1', 'end_turn', [{ type: 'text', text: '{"status":"done"}' }]))) }; } } };
    const t0 = Date.now();
    const r = await runMessages(base(hung, { settings: { retries: 2, retryDelaysSeconds: [0.001], streamIdleSeconds: 0.05, requestTimeoutSeconds: 5 } }));
    assert.equal(r.outcome, 'finished'); assert.ok(Date.now() - t0 < 2000);
    assert.deepEqual(r.facts.retries.map((x) => x.error), ['stream_interrupted']);
});

test('Messages loop: retries used up → provider / transient', async () => {
    const client = fakeClient([0, 1, 2, 3, 4].map(() => apiError(529, 'overloaded_error')));
    const r = await runMessages(base(client));
    assert.equal(r.outcome, 'provider');
    assert.deepEqual([r.error.class, r.error.code, r.error.status, r.error.attempts], ['transient', 'overloaded', 529, 5]);
    assert.equal(client.requests.length, 5, '1 + 4 retries');
});

test('Messages loop: billing, auth, spend cap and usage limit are not retried', async () => {
    for (const [err, code] of [
        [apiError(402, 'billing_error'), 'billing'], [apiError(401, 'authentication_error'), 'authentication'], [apiError(403, 'permission_error'), 'permission'],
        [apiError(429, 'rate_limit_error', 'spend limit', { error_code: 'enforced_spend_limit_reached' }), 'spend_limit'],
        [apiError(400, 'invalid_request_error', 'You have reached your specified workspace API usage limits.'), 'usage_limit'],
    ]) {
        const client = fakeClient([err]);
        const r = await runMessages(base(client));
        assert.deepEqual([r.outcome, r.error.class, r.error.code], ['provider', 'billing_auth', code]);
        assert.equal(client.requests.length, 1);
    }
});

test('Messages loop: a bad request is a failure of this scan', async () => {
    const r = await runMessages(base(fakeClient([apiError(400, 'invalid_request_error', 'image too large')])));
    assert.deepEqual([r.outcome, r.error.class, r.error.code], ['provider', 'request', 'invalid_request']);
});

test('Messages loop: a refusal is its own outcome and no tool runs', async () => {
    let ran = 0;
    const refusal = { ...msg('m1', 'refusal', [toolUse('t1', 'scan_measure')]), stop_details: { type: 'refusal', category: 'cyber' } };
    const r = await runMessages(base(fakeClient([refusal]), { dispatch: async () => { ran++; return { content: [], isError: false }; } }));
    assert.deepEqual([r.outcome, r.error.class, r.error.category], ['refusal', 'refusal', 'cyber']);
    assert.equal(ran, 0);
});

test('Messages loop: turn cap, budget cap, abort, cut-off answer, unreadable report', async () => {
    const loop = () => fakeClient(Array.from({ length: 20 }, (_, i) => msg(`m${i}`, 'tool_use', [toolUse(`t${i}`, 'scan_measure')], usage({ output_tokens: 10_000 }))));
    const turns = await runMessages(base(loop(), { maxTurns: 3 }));
    assert.deepEqual([turns.outcome, turns.turns], ['max_turns', 3]);
    const budget = await runMessages(base(loop(), { maxBudgetUsd: 0.5 }));
    assert.equal(budget.outcome, 'max_budget'); assert.ok(budget.usage.costUsd >= 0.5 && budget.usage.costUsd < 0.9, 'stopped right after the request that passed the cap');
    const controller = new AbortController();
    const aborted = await runMessages(base(loop(), { signal: controller.signal, dispatch: async () => { controller.abort(); return { content: [], isError: false }; } }));
    assert.equal(aborted.outcome, 'aborted'); assert.equal(aborted.turns, 1);
    assert.ok(aborted.usage.byModel['claude-opus-5-5'].output === 10_000, 'usage of the finished request survives the abort');
    const cut = await runMessages(base(fakeClient([msg('m1', 'max_tokens', [{ type: 'text', text: '{"sta' }])])));
    assert.deepEqual([cut.outcome, cut.error.code], ['error', 'max_output_tokens']);
    const prose = await runMessages(base(fakeClient([msg('m1', 'end_turn', [{ type: 'text', text: 'All done!' }])])));
    assert.deepEqual([prose.outcome, prose.error.code], ['error', 'finish_not_json']);
});

test('toToolResultContent / userContent', () => {
    assert.deepEqual(toToolResultContent([{ type: 'text', text: 'a' }, { type: 'image', data: 'QQ==', mimeType: 'image/webp' }]), [{ type: 'text', text: 'a' }, { type: 'image', source: { type: 'base64', media_type: 'image/webp', data: 'QQ==' } }]);
    assert.deepEqual(userContent(['hi', { data: Buffer.from('A'), mimeType: 'image/png' }]), [{ type: 'text', text: 'hi' }, { type: 'image', source: { type: 'base64', media_type: 'image/png', data: 'QQ==' } }]);
    assert.throws(() => engine('nope'), /unknown engine/);
    assert.deepEqual(Object.keys({ sdk: engine('sdk'), messages: engine('messages') }), ['sdk', 'messages']);
});

test('usage: cache writes are priced by their lifetime; the 5-minute basis is kept next to it', () => {
    const u = fromApiUsage({ input_tokens: 1000, output_tokens: 2000, cache_read_input_tokens: 50_000, cache_creation_input_tokens: 10_000, cache_creation: { ephemeral_5m_input_tokens: 4000, ephemeral_1h_input_tokens: 6000 } });
    assert.deepEqual(u, { input: 1000, output: 2000, cacheRead: 50_000, cacheWrite5m: 4000, cacheWrite1h: 6000 });
    const p = priceUsage({ 'claude-opus-5-5': u });
    assert.equal(p.costUsd, +((1000 * 4 + 2000 * 20 + 50_000 * 0.2 + 4000 * 5 + 6000 * 8) / 1e6).toFixed(6));
    assert.equal(p.costUsd5m, +((1000 * 4 + 2000 * 20 + 50_000 * 0.2 + 10_000 * 5) / 1e6).toFixed(6));
    assert.deepEqual(priceUsage({ 'some-model': u }).unknownModels, ['some-model']);
    assert.deepEqual(fromModelUsage({ inputTokens: 1, outputTokens: 2, cacheReadInputTokens: 3, cacheCreationInputTokens: 10, cacheCreationEphemeral1hInputTokens: 10 }), { input: 1, output: 2, cacheRead: 3, cacheWrite5m: 0, cacheWrite1h: 10 });
});

test('UsageMeter: the latest figure per message replaces the earlier one; final totals win', () => {
    const m = new UsageMeter(LIST_PRICES);
    m.note('a', 'claude-opus-5-5', { input_tokens: 10, output_tokens: 1 });
    m.note('a', 'claude-opus-5-5', { input_tokens: 10, output_tokens: 40 });
    m.note('a', 'claude-opus-5-5', { input_tokens: 10, output_tokens: 5 });
    m.note('b', 'claude-haiku-5-5', { input_tokens: 100, output_tokens: 10 });
    m.note(null, 'x', { input_tokens: 1 });
    assert.deepEqual(m.byModel(), { 'claude-opus-5-5': { input: 10, output: 40, cacheRead: 0, cacheWrite5m: 0, cacheWrite1h: 0 }, 'claude-haiku-5-5': { input: 100, output: 10, cacheRead: 0, cacheWrite5m: 0, cacheWrite1h: 0 } });
    assert.equal(m.turns(), 2); assert.equal(m.snapshot().final, false);
    m.setFinal({ 'claude-opus-5-5': { input: 11, output: 41, cacheRead: 0, cacheWrite5m: 0, cacheWrite1h: 0 } });
    assert.equal(m.snapshot().final, true); assert.equal(m.byModel()['claude-opus-5-5'].output, 41);
});

test('engine environment: request behaviour is pinned; the config directory is isolated only with a key', () => {
    const scratchDir = mkdtempSync(join(tmpdir(), 'scan-env-'));
    const settings = { requestTimeoutSeconds: 120, retries: 4, streamIdleSeconds: 60 };
    const plan = engineEnv({ base: { PATH: '/bin', ANTHROPIC_AUTH_TOKEN: 'x', CLAUDE_CODE_EFFORT_LEVEL: 'max' }, settings, scratchDir });
    assert.deepEqual([plan.API_TIMEOUT_MS, plan.CLAUDE_CODE_MAX_RETRIES, plan.CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS], ['120000', '4', '60000']);
    // the harness keeps its own back-off: the definition's `sdkRetries` is the count whose waits add up to the plan's 60 s
    assert.equal(engineEnv({ base: { PATH: '/bin' }, settings: { ...settings, sdkRetries: 7 }, scratchDir }).CLAUDE_CODE_MAX_RETRIES, '7');
    assert.equal(plan.CLAUDE_CONFIG_DIR, undefined, 'the plan login lives in the default config');
    assert.equal(plan.ANTHROPIC_API_KEY, undefined); assert.equal(plan.ANTHROPIC_AUTH_TOKEN, undefined); assert.equal(plan.CLAUDE_CODE_EFFORT_LEVEL, undefined);
    assert.equal(plan.CLAUDE_CODE_DISABLE_AUTO_MEMORY, '1');
    const keyed = engineEnv({ base: { ANTHROPIC_API_KEY: 'sk-test' }, settings, scratchDir });
    assert.equal(keyed.ANTHROPIC_API_KEY, 'sk-test');
    // the app's token is the scanner's business: the engine never gets it, with either credential
    assert.equal(engineEnv({ base: { ANTHROPIC_API_KEY: 'sk-test', SCANNER_TOKEN: 'app-token' }, settings, scratchDir }).SCANNER_TOKEN, undefined);
    assert.equal(engineEnv({ base: { PATH: '/bin', SCANNER_TOKEN: 'app-token' }, settings, scratchDir }).SCANNER_TOKEN, undefined);
    assert.ok(keyed.CLAUDE_CONFIG_DIR.startsWith(scratchDir) && existsSync(keyed.CLAUDE_CONFIG_DIR));
});

test('Agent SDK: a tool the harness announces that is not the scanner\'s ends the run before the first request', async () => {
    assert.deepEqual(foreignTools(['mcp__scan__scan_crop', 'mcp__scan__design_preview', 'StructuredOutput']), []);
    assert.deepEqual(foreignTools(['mcp__scan__scan_crop', 'Bash', 'Read', 'mcp__other__x', 'mcp__scanner__x', 'Task']), ['Bash', 'Read', 'mcp__other__x', 'mcp__scanner__x', 'Task']);
    assert.deepEqual(foreignTools(undefined), []);
    const scratchDir = mkdtempSync(join(tmpdir(), 'scan-tools-'));
    const init = (tools) => ({ type: 'system', subtype: 'init', model: 'claude-opus-5-5', tools, mcp_servers: [{ name: 'scan', status: 'connected' }], claude_code_version: 'x', apiKeySource: 'none', permissionMode: 'dontAsk' });
    const result = { type: 'result', subtype: 'success', is_error: false, result: 'ok', num_turns: 1, duration_api_ms: 1, total_cost_usd: 0, modelUsage: {} };
    /** A stand-in for the SDK's `query`: what it was asked for, how far it was read, whether it was closed. */
    const harness = (tools) => { const seen = { options: null, yielded: 0, closed: false }; return { seen, query: ({ options }) => { seen.options = options; return (async function* () { try { for (const m of [init(tools), result]) { seen.yielded++; yield m; } } finally { seen.closed = true; } })(); } }; };
    const run = (h) => runSdk({ model: 'claude-opus-5-5', system: 's', content: [{ type: 'text', text: 'x' }], tools: [{ name: 'scan_crop', description: 'd', input_schema: { type: 'object' } }], dispatch: async () => ({ content: [] }), scratchDir, env: { PATH: '/bin' }, query: h.query });

    // the lock-down as it is: the scan tools and the harness's own StructuredOutput — the run goes on
    const ok = harness(['mcp__scan__scan_crop', 'StructuredOutput']);
    const fine = await run(ok);
    assert.equal(fine.outcome, 'finished'); assert.equal(fine.facts.foreignTools, undefined);
    assert.deepEqual([ok.seen.options.tools, ok.seen.options.allowedTools, ok.seen.options.strictMcpConfig, ok.seen.options.permissionMode], [[], ['mcp__scan__scan_crop'], true, 'dontAsk']);

    // an SDK that brings built-in tools back: stopped at the init message, named, nothing after it is read
    const bad = harness(['mcp__scan__scan_crop', 'StructuredOutput', 'Bash', 'WebFetch']);
    const stopped = await run(bad);
    assert.deepEqual([stopped.outcome, stopped.error.code, stopped.finish], ['error', 'engine_tools', null]);
    assert.deepEqual(stopped.facts.foreignTools, ['Bash', 'WebFetch']); assert.match(stopped.error.message, /Bash, WebFetch/);
    assert.deepEqual([bad.seen.yielded, bad.seen.closed, bad.seen.options.abortController.signal.aborted], [1, true, true], 'the engine is aborted and the stream closed');
    // it wins over an abort from outside that arrives in the same moment: the reason is not lost
    const controller = new AbortController(); controller.abort();
    const both = await runSdk({ model: 'm', system: 's', content: [], tools: [], dispatch: async () => ({}), scratchDir, env: {}, signal: controller.signal, query: harness(['Bash']).query });
    assert.equal(both.error?.code, 'engine_tools');
    rmSync(scratchDir, { recursive: true, force: true });
});
