// Engine backend 1: the Claude Agent SDK (TypeScript), locked down to the scanner's own tools.
//
// Lock-down (verified against @anthropic-ai/claude-agent-sdk 0.3.322, see eval/RESULTS.md):
//   tools: []                  no built-in tool at all (no Bash / Read / Write / Web / subagents)
//   mcpServers + strictMcpConfig   only the in-process "scan" server; nothing from .mcp.json, plugins, connectors
//   allowedTools + dontAsk     the scan tools are pre-approved, anything else is denied without a prompt
//   settingSources: []         no CLAUDE.md, no user / project / local settings, hooks or skills
//   systemPrompt: string       the scanner's own prompt replaces the harness prompt
//   persistSession: false      no transcript on disk
//   cwd                        an empty per-run scratch directory
//   CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK=1   a refused turn is never re-run on another model
//   CLAUDE_CONFIG_DIR          a dedicated directory — ONLY when an API key is the credential: with it set
//                              the harness no longer finds the locally logged-in plan ("Not logged in")
//
// Credentials come from the environment only: ANTHROPIC_API_KEY set → the key; unset → the plan the
// machine is logged in to (development and tests).
import { query, createSdkMcpServer } from '@anthropic-ai/claude-agent-sdk';
import { ListToolsRequestSchema, CallToolRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { UsageMeter, fromModelUsage } from './pricing.mjs';
import { classifyProviderError } from './classify.mjs';

export const SERVER_NAME = 'scan';

/**
 * The tools the harness announced that are not the scanner's: everything but `mcp__scan__*` and the harness's
 * own `StructuredOutput` (how a finish schema is answered). The lock-down above leaves none; an SDK that
 * brings a built-in tool back (Bash, Read, a web tool, a subagent) must fail the run, not widen it silently.
 */
export function foreignTools(announced) {
    return (Array.isArray(announced) ? announced : []).map(String).filter((name) => !(name.startsWith(`mcp__${SERVER_NAME}__`) || name === 'StructuredOutput'));
}

function toolServer(tools, dispatch) {
    const server = createSdkMcpServer({ name: SERVER_NAME, version: '1.0.0', tools: [] });
    const low = server.instance.server;
    low.registerCapabilities({ tools: {} });
    low.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: tools.map((t) => ({ name: t.name, description: t.description, inputSchema: t.input_schema })) }));
    low.setRequestHandler(CallToolRequestSchema, async (req) => dispatch(req.params.name, req.params.arguments ?? {}));
    return server;
}

/** The environment the harness subprocess gets: the scanner's own WITHOUT the app token, plus pinned request behaviour. */
export function engineEnv({ base = process.env, settings = {}, scratchDir }) {
    const env = { ...base };
    delete env.SCANNER_TOKEN; // the engine talks to the provider only; the app is the scanner's business
    // the credential comes from the environment: an API key (production), or the plan's token for a machine
    // that has no login (CLAUDE_CODE_OAUTH_TOKEN — a container in development)
    const keyed = !!env.ANTHROPIC_API_KEY || !!env.CLAUDE_CODE_OAUTH_TOKEN;
    Object.assign(env, {
        // request behaviour pinned by the definition (the harness defaults are 10 retries / 600 s)
        API_TIMEOUT_MS: String((settings.requestTimeoutSeconds ?? 120) * 1000),
        // the harness keeps its own back-off (0.5 s doubling, capped near 32 s — it cannot be pinned): the number
        // of retries is what decides how long a provider that is away is waited for. `sdkRetries` (definition) is
        // chosen so that the waits add up to the plan's 60 s (§4.9); without it the Messages loop's count is used
        CLAUDE_CODE_MAX_RETRIES: String(settings.sdkRetries ?? settings.retries ?? 4),
        // the event-level watchdog cannot be set below 5 minutes; the byte-level one can (clamped 10 s … 30 min)
        CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS: String(Math.max(10, settings.streamIdleSeconds ?? 60) * 1000),
        CLAUDE_CODE_DISABLE_AUTO_MEMORY: '1',
        CLAUDE_CODE_DISABLE_TERMINAL_TITLE: '1',
        CLAUDE_CODE_DISABLE_BACKGROUND_TASKS: '1',
        CLAUDE_CODE_MCP_AUTO_BACKGROUND_MS: '0',
        DISABLE_AUTOUPDATER: '1',
        DISABLE_ERROR_REPORTING: '1',
        MAX_MCP_OUTPUT_TOKENS: '50000',
        // No silent model fallback (decisions.md): by default the harness re-runs a turn a safety classifier
        // flagged on another model (Opus 5.5 → claude-opus-4-8 for `cyber`, → claude-opus-5 for `bio`) and
        // goes on there at that model's prices. Switched off — documented at code.claude.com/docs/en/env-vars:
        // "Set to 1 to turn off the automatic model switch when a safety classifier flags a request".
        // A refusal then ends the turn (`model_refusal_no_fallback`) and the scan is `refused` (§5.2).
        CLAUDE_CODE_DISABLE_REFUSAL_FALLBACK: '1',
    });
    delete env.CLAUDE_CODE_REFUSAL_FALLBACK_CATCH_ALL; // would switch a catch-all fallback back on
    delete env.CLAUDE_CODE_EFFORT_LEVEL; // would override the definition's effort
    if (keyed) {
        // nothing of the engine outlives the run or touches the image: its configuration and its home are in the run's scratch
        env.CLAUDE_CONFIG_DIR = join(scratchDir, 'engine-config');
        env.HOME = join(scratchDir, 'engine-home');
        mkdirSync(env.CLAUDE_CONFIG_DIR, { recursive: true });
        mkdirSync(env.HOME, { recursive: true });
        env.CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC = '1';
    } else {
        delete env.ANTHROPIC_AUTH_TOKEN;
    }
    return env;
}

/**
 * Runs one model loop.
 * @param o {model, effort, system, content, tools, dispatch, finishSchema, maxTurns, maxBudgetUsd,
 *           signal, onEvent, settings, scratchDir, prices, env, query (tests: a stand-in for the SDK's `query`)}
 *   content  user content blocks in Messages-API shape (text / image base64)
 * → {outcome, finish, text, usage, turns, apiMs, wallMs, error, facts}
 */
export async function runSdk(o) {
    const started = Date.now();
    const meter = new UsageMeter(o.prices);
    const controller = new AbortController();
    const onAbort = () => controller.abort();
    if (o.signal?.aborted) controller.abort(); else o.signal?.addEventListener('abort', onAbort, { once: true });
    const cwd = join(o.scratchDir, 'engine-cwd');
    mkdirSync(cwd, { recursive: true });
    const tools = o.tools ?? [];
    const facts = { engine: 'agent-sdk', init: null, retries: [], rateLimit: null, maxModelGapMs: 0, maxGapMs: 0, stderrTail: '', refusal: null, assistantErrors: [], resultSubtype: null, terminalReason: null, thrown: null, resultAfterAbort: false, pid: null };
    let result = null, lastEventAt = Date.now(), waitingSince = Date.now(), error = null, planLimit = false, lastText = '';
    const seenMessages = new Set();
    const emit = (ev) => { try { o.onEvent?.(ev); } catch { /* an observer must never break the run */ } };
    async function* input() {
        yield { type: 'user', message: { role: 'user', content: o.content }, parent_tool_use_id: null };
    }
    const options = {
        model: o.model,
        ...(o.effort ? { effort: o.effort } : {}),
        tools: [],
        mcpServers: tools.length ? { [SERVER_NAME]: toolServer(tools, o.dispatch) } : {},
        strictMcpConfig: true,
        allowedTools: tools.map((t) => `mcp__${SERVER_NAME}__${t.name}`),
        permissionMode: 'dontAsk',
        settingSources: [],
        systemPrompt: o.system,
        persistSession: false,
        cwd,
        env: o.env ?? engineEnv({ settings: o.settings, scratchDir: o.scratchDir }),
        abortController: controller,
        ...(o.maxTurns ? { maxTurns: o.maxTurns } : {}),
        ...(o.maxBudgetUsd ? { maxBudgetUsd: o.maxBudgetUsd } : {}),
        ...(o.finishSchema ? { outputFormat: { type: 'json_schema', schema: o.finishSchema } } : {}),
        // stream events carry the running output-token count: without them an aborted run would lose its output usage
        includePartialMessages: true,
        stderr: (d) => { facts.stderrTail = (facts.stderrTail + d).slice(-2000); },
    };
    let streaming = null; // {id, model, usage} of the message being streamed
    try {
        for await (const m of (o.query ?? query)({ prompt: input(), options })) {
            const now = Date.now();
            facts.maxGapMs = Math.max(facts.maxGapMs, now - lastEventAt);
            lastEventAt = now;
            if (m.type === 'system' && m.subtype === 'init') {
                facts.init = { model: m.model, tools: m.tools, mcp: m.mcp_servers, version: m.claude_code_version, apiKeySource: m.apiKeySource, permissionMode: m.permissionMode };
                waitingSince = now;
                // the lock-down is verified on every run: a tool that is not the scanner's ends it before the first request
                const foreign = foreignTools(m.tools);
                if (foreign.length) { facts.foreignTools = foreign.slice(0, 20); controller.abort(); break; }
            } else if (m.type === 'system' && m.subtype === 'api_retry') {
                // time lost to the provider (§4.9): the request that failed — since the engine began to wait for it —
                // and the wait before the next one. The next request leaves when the delay is over.
                const delayMs = m.retry_delay_ms ?? 0;
                const lostMs = (waitingSince !== null ? Math.max(0, now - waitingSince) : 0) + delayMs;
                waitingSince = now + delayMs;
                facts.retries.push({ attempt: m.attempt, of: m.max_retries, delayMs, status: m.error_status, error: m.error, lostMs });
                emit({ type: 'retry', attempt: m.attempt, of: m.max_retries, delayMs, status: m.error_status, error: m.error, lostMs });
            } else if (m.type === 'system' && m.subtype === 'model_refusal_no_fallback') {
                facts.refusal = { subtype: m.subtype, category: m.api_refusal_category ?? null };
            } else if (m.type === 'system' && m.subtype === 'model_refusal_fallback') {
                // the harness re-ran the refused turn on another model: the run goes on, the usage names both models
                // — which must not happen with the switch above. If a harness ignores it, the run is stopped here: no
                // further turn is paid for on the other model, the outcome is a refusal, and whatever the fallback
                // request already used is in the usage under ITS model name (priced by that model's row).
                facts.refusalFallback = { from: m.original_model, to: m.fallback_model, category: m.api_refusal_category ?? null, stopped: true };
                controller.abort();
            } else if (m.type === 'rate_limit_event') {
                facts.rateLimit = m.rate_limit_info;
                if (m.rate_limit_info?.status === 'rejected') planLimit = true;
                emit({ type: 'rate_limit', info: m.rate_limit_info });
            } else if (m.type === 'stream_event') {
                const ev = m.event;
                if (ev?.type === 'message_start' && ev.message) { streaming = { id: ev.message.id, model: ev.message.model, usage: ev.message.usage ?? {} }; meter.note(streaming.id, streaming.model, streaming.usage); }
                else if (ev?.type === 'message_delta' && streaming && ev.usage) { streaming.usage = { ...streaming.usage, ...ev.usage }; meter.note(streaming.id, streaming.model, streaming.usage); emit({ type: 'usage', usage: meter.snapshot() }); }
                if (waitingSince !== null && ev?.type === 'content_block_start') { facts.maxModelGapMs = Math.max(facts.maxModelGapMs, now - waitingSince); waitingSince = null; }
            } else if (m.type === 'assistant') {
                if (waitingSince !== null) { facts.maxModelGapMs = Math.max(facts.maxModelGapMs, now - waitingSince); }
                waitingSince = null;
                const msg = m.message;
                meter.note(msg?.id, msg?.model, msg?.usage);
                if (m.error) facts.assistantErrors.push({ error: m.error, text: (msg?.content ?? []).filter((b) => b.type === 'text').map((b) => b.text).join(' ').slice(0, 200) });
                if (msg?.id && !seenMessages.has(msg.id)) { seenMessages.add(msg.id); emit({ type: 'turn', turns: seenMessages.size, usage: meter.snapshot() }); }
                for (const b of msg?.content ?? []) {
                    if (b.type === 'tool_use') emit({ type: 'tool_use', name: String(b.name).replace(`mcp__${SERVER_NAME}__`, '') });
                    if (b.type === 'text') lastText = b.text;
                }
            } else if (m.type === 'user') {
                waitingSince = now; // a tool result went back: the model is thinking again
            } else if (m.type === 'result') {
                result = m;
                if (controller.signal.aborted) facts.resultAfterAbort = true;
            }
        }
    } catch (e) {
        facts.thrown = { name: e?.name ?? 'Error', message: String(e?.message ?? e).slice(0, 400) };
        const code = /exited with code (\d+)/.exec(String(e?.message ?? ''));
        if (code) facts.exitCode = Number(code[1]);
        error = e;
    } finally {
        o.signal?.removeEventListener('abort', onAbort);
    }
    const wallMs = Date.now() - started;
    // how long the engine had been waiting for the provider's answer when the run ended: a request that was cut
    // (a cap, an abort) while the provider said nothing — a stalled stream, a request that never came back
    facts.pendingWaitMs = waitingSince !== null ? Math.max(0, Date.now() - waitingSince) : 0;
    if (result?.modelUsage && Object.keys(result.modelUsage).length) {
        facts.meterEstimate = meter.estimate();
        meter.setFinal(Object.fromEntries(Object.entries(result.modelUsage).map(([k, v]) => [k.replace(/\[.*\]$/, ''), fromModelUsage(v)])));
    }
    const usage = { ...meter.snapshot(), engineCostUsd: result?.total_cost_usd ?? null };
    facts.resultSubtype = result?.subtype ?? null;
    facts.terminalReason = result?.terminal_reason ?? null;
    facts.permissionDenials = result?.permission_denials?.length ?? 0;
    const base = { finish: null, text: typeof result?.result === 'string' ? result.result : lastText, usage, turns: result?.num_turns ?? seenMessages.size, apiMs: result?.duration_api_ms ?? null, wallMs, facts };
    const providerError = (extra) => {
        const sdkError = facts.assistantErrors.at(-1)?.error ?? facts.retries.at(-1)?.error ?? null;
        const status = result?.api_error_status ?? facts.retries.at(-1)?.status ?? null;
        const c = classifyProviderError({ sdkError, status, planLimit, message: facts.assistantErrors.at(-1)?.text ?? '', ...extra });
        return { ...base, outcome: c.class === 'refusal' ? 'refusal' : 'provider', error: { ...c, status, sdkError, message: base.text?.slice(0, 300) ?? null } };
    };
    if (facts.foreignTools) return { ...base, outcome: 'error', error: { class: 'unknown', code: 'engine_tools', message: `the engine announced tools that are not the scanner's: ${facts.foreignTools.join(', ').slice(0, 200)}` } };
    if (o.signal?.aborted) return { ...base, outcome: 'aborted', error: null };
    if (facts.refusalFallback || facts.refusal || result?.stop_reason === 'refusal') return providerError({ stopReason: 'refusal' });
    if (controller.signal.aborted && !result) return { ...base, outcome: 'aborted', error: null };
    if (!result) return facts.assistantErrors.length || planLimit ? providerError({}) : { ...base, outcome: 'error', error: { class: 'unknown', code: 'engine_exit', message: facts.thrown?.message ?? 'the engine ended without a result' } };
    if (result.subtype === 'error_max_turns') return { ...base, outcome: 'max_turns', error: null };
    if (result.subtype === 'error_max_budget_usd') return { ...base, outcome: 'max_budget', error: null };
    if (result.is_error || result.subtype !== 'success') {
        if (facts.assistantErrors.length || planLimit || result.api_error_status) return providerError({});
        return { ...base, outcome: 'error', error: { class: 'unknown', code: result.subtype, message: (result.errors ?? []).join('; ').slice(0, 300) || base.text?.slice(0, 300) } };
    }
    let finish = result.structured_output ?? null;
    if (finish === null && o.finishSchema && typeof result.result === 'string') { try { finish = JSON.parse(result.result); } catch { /* not JSON */ } }
    return { ...base, outcome: 'finished', finish, error: null };
}
