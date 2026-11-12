#!/usr/bin/env node
// Engine facts without a model: runs each engine against the fake provider (eval/fake-provider.mjs)
// and prints how provider errors, refusals, stalls, caps and aborts surface.
//   node eval/engine-facts.mjs [sdk|messages|cli] [scenario,…]
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startFakeProvider } from './fake-provider.mjs';
import { runSdk, engineEnv } from '../src/engine/sdk.mjs';
import { runMessages } from '../src/engine/messages.mjs';
import { runCli } from './engine-cli.mjs';
import Anthropic from '@anthropic-ai/sdk';

const which = process.argv[2] ?? 'sdk';
const only = process.argv[3]?.split(',') ?? null;
const TOOL = [{ name: 'scan_measure', description: 'Measures a region.', input_schema: { type: 'object', properties: { x: { type: 'number' } }, additionalProperties: false } }];
const FINAL = { final: { status: 'done' } };
const SCHEMA = { type: 'object', properties: { status: { type: 'string' } }, required: ['status'], additionalProperties: false };
const call = { tools: [{ name: 'scan_measure', input: { x: 1 } }] };
const err = (status, type, extra = {}) => ({ status, type, message: `fake ${type}`, ...extra });
const settings = { requestTimeoutSeconds: 15, retries: 4, retryDelaysSeconds: [2, 8, 20, 30], streamIdleSeconds: 10 };

const SCENARIOS = {
    ok: { plan: [call, FINAL] },
    '529_then_ok': { plan: [err(529, 'overloaded_error'), err(529, 'overloaded_error'), call, FINAL] },
    '529_forever': { plan: [err(529, 'overloaded_error')] },
    '429_no_retry_after_then_ok': { plan: [err(429, 'rate_limit_error'), call, FINAL] },
    '429_retry_after_3_then_ok': { plan: [err(429, 'rate_limit_error', { retryAfter: 3 }), call, FINAL] },
    '429_spend_limit': { plan: [err(429, 'rate_limit_error', { details: { error_code: 'enforced_spend_limit_reached' }, message: 'spend limit reached' })] },
    '402_billing': { plan: [err(402, 'billing_error')] },
    '401_auth': { plan: [err(401, 'authentication_error')] },
    '403_permission': { plan: [err(403, 'permission_error')] },
    '400_usage_limit': { plan: [err(400, 'invalid_request_error', { message: 'You have reached your specified workspace API usage limits. You will regain access on 2026-12-01 at 00:00 UTC.' })] },
    '400_bad_request': { plan: [err(400, 'invalid_request_error', { message: 'messages.0.content.0.image: image exceeds the maximum' })] },
    '404_model': { plan: [err(404, 'not_found_error', { message: 'model: claude-nope' })] },
    '500_then_ok': { plan: [err(500, 'api_error'), call, FINAL] },
    refusal: { plan: [{ refusal: true }] },
    stall_then_ok: { plan: [{ stall: 120_000 }, call, FINAL] },
    abort_mid_run: { plan: [call, call, call, call, call, call, FINAL], abortAfterTools: 2 },
    max_turns: { plan: [call], maxTurns: 3 },
    hung_tool_abort: { plan: [call, FINAL], hungTool: true, abortAfterMs: 4000 },
};

const run = { sdk: runSdk, cli: runCli, messages: runMessages }[which];
for (const [name, sc] of Object.entries(SCENARIOS)) {
    if (only && !only.includes(name)) continue;
    const fake = await startFakeProvider({ plan: sc.plan });
    fake.state.treatAllAsMain = which === 'messages';
    const scratchDir = mkdtempSync(join(tmpdir(), 'scan-facts-'));
    const controller = new AbortController();
    let toolCalls = 0;
    const events = [];
    const env = engineEnv({ base: { ...process.env, ANTHROPIC_API_KEY: 'sk-ant-fake-key-for-the-local-fake-provider', ANTHROPIC_BASE_URL: fake.url }, settings, scratchDir });
    const t0 = Date.now();
    if (sc.abortAfterMs) setTimeout(() => controller.abort(), sc.abortAfterMs);
    let r;
    try {
        r = await run({
            model: 'claude-opus-5-5', effort: 'high', system: 'You are a test agent. __MAIN__', content: [{ type: 'text', text: 'Measure, then report.' }], tools: TOOL, finishSchema: SCHEMA,
            dispatch: async () => { toolCalls++; if (sc.abortAfterTools && toolCalls >= sc.abortAfterTools) controller.abort(); if (sc.hungTool) await new Promise(() => {}); return { content: [{ type: 'text', text: '{"ok":true}' }], isError: false }; },
            maxTurns: sc.maxTurns ?? 20, maxBudgetUsd: 1.5, signal: controller.signal, settings, scratchDir, env,
            client: which === 'messages' ? new Anthropic({ apiKey: 'sk-ant-fake', baseURL: fake.url, maxRetries: 0, timeout: settings.requestTimeoutSeconds * 1000 }) : undefined,
            onEvent: (e) => events.push({ t: Date.now() - t0, type: e.type, ...(e.type === 'retry' ? { attempt: e.attempt, of: e.of, delayMs: e.delayMs, status: e.status, error: e.error } : {}) }),
        });
    } catch (e) { r = { outcome: 'THROWN', error: { message: String(e?.message ?? e).slice(0, 200) }, facts: {}, usage: {} }; }
    const reqs = fake.state.requests.filter((q) => q.url.includes('/v1/messages'));
    const f = r.facts ?? {};
    console.log(JSON.stringify({
        engine: which, scenario: name, outcome: r.outcome, error: r.error ? { class: r.error.class, code: r.error.code, status: r.error.status ?? null, sdkError: r.error.sdkError ?? null, message: r.error.message?.slice(0, 90) } : null,
        wallS: +((Date.now() - t0) / 1000).toFixed(1), turns: r.turns, toolCalls, finish: r.finish,
        usage: r.usage?.byModel ? Object.fromEntries(Object.entries(r.usage.byModel).map(([m, u]) => [m, `${u.input}/${u.output}`])) : null, usageFinal: r.usage?.final ?? null, engineCost: r.usage?.engineCostUsd ?? null,
        requests: reqs.length, mainRequests: fake.state.main, requestTimes: reqs.map((q) => +((q.at - t0) / 1000).toFixed(1)), helperModels: [...new Set(reqs.filter((q) => !q.tools).map((q) => q.model))],
        retries: (f.retries ?? []).map((x) => `${x.attempt}/${x.of} ${x.status ?? '-'} ${x.error ?? ''} wait ${Math.round((x.delayMs ?? 0) / 100) / 10}s`),
        resultSubtype: f.resultSubtype ?? null, terminalReason: f.terminalReason ?? null, exitCode: f.exitCode ?? null, signal: f.signal ?? null, thrown: f.thrown?.message?.slice(0, 120) ?? null, assistantErrors: f.assistantErrors ?? null, refusal: f.refusal ?? null, resultAfterAbort: f.resultAfterAbort ?? null,
        firstRequest: reqs[0] ? { auth: reqs[0].auth, effort: reqs[0].effort, thinking: reqs[0].thinking, format: reqs[0].format, maxTokens: reqs[0].maxTokens, toolNames: reqs.find((q) => q.tools)?.toolNames } : null,
    }));
    await fake.close();
}
process.exit(0);
