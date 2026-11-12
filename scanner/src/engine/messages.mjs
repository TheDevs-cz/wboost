// Engine backend 2: a plain Messages-API loop over the same tool list and the same dispatcher.
// No harness binary; status codes, error types and usage come straight from the API.
// Credentials come from the environment (the official SDK resolves ANTHROPIC_API_KEY itself).
import Anthropic from '@anthropic-ai/sdk';
import { UsageMeter } from './pricing.mjs';
import { classifyProviderError } from './classify.mjs';

const sleep = (ms, signal) => new Promise((resolve) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => { clearTimeout(t); resolve(); }, { once: true });
});

/** MCP content blocks (what the tool server returns) → `tool_result` content blocks. */
export function toToolResultContent(content) {
    return content.map((b) => (b.type === 'image'
        ? { type: 'image', source: { type: 'base64', media_type: b.mimeType, data: b.data } }
        : { type: 'text', text: b.text }));
}

function describeError(e) {
    if (e instanceof Anthropic.APIError) {
        const body = e.error?.error ?? e.error ?? {};
        return { status: e.status ?? null, type: e.type ?? body.type ?? null, errorCode: body.details?.error_code ?? null, message: e.message, network: e instanceof Anthropic.APIConnectionError };
    }
    return { status: null, type: null, message: String(e?.message ?? e), network: true };
}

/**
 * Same contract as runSdk(). `o.client` may be injected (tests); `o.settings` carries the pinned
 * request behaviour: requestTimeoutSeconds, retries, retryDelaysSeconds, maxOutputTokens, refusalFallback.
 */
export async function runMessages(o) {
    const started = Date.now();
    const settings = o.settings ?? {};
    const client = o.client ?? new Anthropic({ maxRetries: 0, timeout: (settings.requestTimeoutSeconds ?? 120) * 1000 });
    const delays = settings.retryDelaysSeconds ?? [2, 8, 20, 30];
    const retries = settings.retries ?? delays.length;
    const meter = new UsageMeter(o.prices);
    const facts = { engine: 'messages', retries: [], maxModelGapMs: 0, requests: 0, stopReasons: [] };
    const emit = (ev) => { try { o.onEvent?.(ev); } catch { /* observers never break the run */ } };
    const tools = (o.tools ?? []).map((t) => ({ name: t.name, description: t.description, input_schema: t.input_schema }));
    const messages = [{ role: 'user', content: o.content }];
    const params = () => ({
        model: o.model,
        max_tokens: settings.maxOutputTokens ?? 32000,
        system: [{ type: 'text', text: o.system, cache_control: { type: 'ephemeral' } }],
        ...(tools.length ? { tools } : {}),
        thinking: { type: 'adaptive' },
        output_config: { ...(o.effort ? { effort: o.effort } : {}), ...(o.finishSchema ? { format: { type: 'json_schema', schema: o.finishSchema } } : {}) },
        cache_control: { type: 'ephemeral' },
        messages,
    });
    let apiMs = 0, turns = 0, lastText = '';
    const base = () => ({ finish: null, text: lastText, usage: { ...meter.snapshot(), engineCostUsd: null }, turns, apiMs, wallMs: Date.now() - started, facts });
    const overBudget = () => o.maxBudgetUsd && meter.snapshot().costUsd >= o.maxBudgetUsd;

    while (true) {
        if (o.signal?.aborted) return { ...base(), outcome: 'aborted', error: null };
        if (o.maxTurns && turns >= o.maxTurns) return { ...base(), outcome: 'max_turns', error: null };
        if (overBudget()) return { ...base(), outcome: 'max_budget', error: null };
        let response = null;
        for (let attempt = 0; ; attempt++) {
            const t0 = Date.now();
            try {
                facts.requests++;
                const stream = client.messages.stream(params(), { signal: o.signal ?? undefined });
                response = await stream.finalMessage();
                apiMs += Date.now() - t0;
                facts.maxModelGapMs = Math.max(facts.maxModelGapMs, Date.now() - t0);
                break;
            } catch (e) {
                apiMs += Date.now() - t0;
                if (o.signal?.aborted) return { ...base(), outcome: 'aborted', error: null };
                const d = describeError(e);
                const c = classifyProviderError(d);
                if (!c.retryable || attempt >= retries) return { ...base(), outcome: 'provider', error: { ...c, status: d.status, type: d.type, message: d.message.slice(0, 300), attempts: attempt + 1 } };
                const delayMs = (delays[Math.min(attempt, delays.length - 1)] ?? 30) * 1000;
                facts.retries.push({ attempt: attempt + 1, of: retries, delayMs, status: d.status, error: c.code });
                emit({ type: 'retry', attempt: attempt + 1, of: retries, delayMs, status: d.status, error: c.code, lostMs: Date.now() - t0 + delayMs });
                await sleep(delayMs, o.signal);
            }
        }
        turns++;
        meter.note(response.id, response.model, response.usage);
        emit({ type: 'turn', turns, usage: meter.snapshot() });
        facts.stopReasons.push(response.stop_reason);
        lastText = response.content.filter((b) => b.type === 'text').map((b) => b.text).join('') || lastText;

        if (response.stop_reason === 'refusal') {
            return { ...base(), outcome: 'refusal', error: { ...classifyProviderError({ stopReason: 'refusal' }), category: response.stop_details?.category ?? null } };
        }
        if (response.stop_reason === 'tool_use') {
            messages.push({ role: 'assistant', content: response.content });
            const results = [];
            // one call at a time, in order: previews mutate the working copy
            for (const b of response.content.filter((x) => x.type === 'tool_use')) {
                emit({ type: 'tool_use', name: b.name });
                const r = await o.dispatch(b.name, b.input ?? {});
                results.push({ type: 'tool_result', tool_use_id: b.id, content: toToolResultContent(r.content), ...(r.isError ? { is_error: true } : {}) });
            }
            messages.push({ role: 'user', content: results });
            continue;
        }
        if (response.stop_reason === 'pause_turn') { messages.push({ role: 'assistant', content: response.content }); continue; }
        if (response.stop_reason === 'max_tokens') return { ...base(), outcome: 'error', error: { class: 'request', code: 'max_output_tokens', message: 'the answer was cut off' } };
        // end_turn / stop_sequence: the final report
        let finish = null;
        if (o.finishSchema) { try { finish = JSON.parse(lastText); } catch { return { ...base(), outcome: 'error', error: { class: 'request', code: 'finish_not_json', message: lastText.slice(0, 200) } }; } }
        return { ...base(), outcome: 'finished', finish };
    }
}
