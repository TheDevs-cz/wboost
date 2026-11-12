// A local stand-in for api.anthropic.com/v1/messages, for two jobs that need no model:
//   1. fault injection — how each engine surfaces 429 / 529 / 402 / 401 / 400 / a stalled stream / a refusal;
//   2. replay — the tool calls of a recorded scan are played back, so that the real engine binary, the
//      real instruments and the real renders can be measured (memory, CPU) where no credential exists
//      (a linux/amd64 container).
// The engine is pointed at it with ANTHROPIC_BASE_URL and a dummy ANTHROPIC_API_KEY.
//
// A plan is a list of steps consumed one per request of the MAIN model (the one that carries tools):
//   {status, type, message, details?, retryAfter?}   an HTTP error
//   {stall: ms}                                      headers + message_start, then silence
//   {refusal: true}                                  stop_reason "refusal"
//   {tools: [{name, input}]}                         a tool_use turn
//   {final: object | string}                         the final report
// After the plan is used up the last step repeats. Other requests (helpers) get a short text answer.
import { createServer } from 'node:http';

let counter = 0;
const id = (p) => `${p}_fake${String(++counter).padStart(6, '0')}`;

export function startFakeProvider({ plan = [], port = 0, host = '127.0.0.1', latencyMs = 0, log = null, stepFor = null }) {
    // `stepFor(body, rec, state)` → a step, or undefined for the plan: a provider that answers by what a request IS
    // (its model, how many assistant turns it carries) instead of by the order requests arrive in — so that one
    // provider serves any number of scans one after another (the soak) and its behaviour can be changed while it runs.
    const state = { requests: [], main: 0, plan: [...plan], stepFor };
    const server = createServer((req, res) => {
        let body = '';
        req.on('data', (d) => { body += d; });
        req.on('end', async () => {
            const url = req.url ?? '';
            let j = {}; try { j = JSON.parse(body || '{}'); } catch { /* not JSON */ }
            const tools = (j.tools ?? []).map((t) => t.name);
            const rec = { at: Date.now(), method: req.method, url, model: j.model ?? null, tools: tools.length, toolNames: tools, stream: !!j.stream, messages: j.messages?.length ?? 0, bytes: body.length, auth: req.headers['x-api-key'] ? 'x-api-key' : req.headers.authorization ? 'bearer' : 'none', effort: j.output_config?.effort ?? null, thinking: j.thinking?.type ?? null, format: j.output_config?.format?.type ?? null, maxTokens: j.max_tokens ?? null, betas: req.headers['anthropic-beta'] ?? null };
            state.requests.push(rec);
            log?.(rec);
            const json = (status, obj, headers = {}) => { res.writeHead(status, { 'Content-Type': 'application/json', 'request-id': id('req'), ...headers }).end(JSON.stringify(obj)); };
            if (!url.includes('/v1/messages') || req.method !== 'POST') return json(404, { type: 'error', error: { type: 'not_found_error', message: 'not here' } });
            if (url.includes('count_tokens')) return json(200, { input_tokens: 1000 });
            const isMain = tools.some((t) => t.startsWith('mcp__') || state.toolNames?.includes(t)) || j.system?.toString().includes('__MAIN__') || rec.tools > 0 || state.treatAllAsMain;
            const chosen = state.stepFor ? state.stepFor(j, rec, state) : undefined;
            const step = chosen !== undefined ? chosen : isMain ? (state.plan.length > 1 ? state.plan.shift() : state.plan[0]) : { text: 'ok' };
            if (isMain) state.main++;
            rec.step = Object.keys(step)[0];
            if (latencyMs) await new Promise((ok) => setTimeout(ok, latencyMs));
            if (step.status) return json(step.status, { type: 'error', error: { type: step.type, message: step.message ?? step.type, ...(step.details ? { details: step.details } : {}) }, request_id: id('req') }, step.retryAfter !== undefined ? { 'retry-after': String(step.retryAfter) } : {});
            const model = j.model ?? 'claude-opus-5-5';
            // a step may carry the usage the provider reports (`usage: {input_tokens, cache_creation_input_tokens, cache_creation: {ephemeral_1h_input_tokens}, …}`)
            const usage = { input_tokens: 1200, cache_creation_input_tokens: 0, cache_read_input_tokens: 0, output_tokens: 1, ...(step.usage ?? {}) };
            const start = { type: 'message_start', message: { id: id('msg'), type: 'message', role: 'assistant', model, content: [], stop_reason: null, stop_sequence: null, usage } };
            const blocks = [];
            let stop = 'end_turn';
            const structured = tools.find((t) => /structured/i.test(t));
            if (step.tools) { stop = 'tool_use'; for (const t of step.tools) blocks.push({ type: 'tool_use', id: id('toolu'), name: tools.find((n) => n === t.name || n.endsWith(`__${t.name}`)) ?? t.name, input: t.input ?? {} }); }
            else if (step.final !== undefined) {
                // the harness asks for the final JSON through a synthetic tool when one is offered
                const done = (j.messages ?? []).some((m) => Array.isArray(m.content) && m.content.some((b) => b.type === 'tool_use' && b.name === structured));
                if (structured && !done) { stop = 'tool_use'; blocks.push({ type: 'tool_use', id: id('toolu'), name: structured, input: typeof step.final === 'string' ? JSON.parse(step.final) : step.final }); }
                else blocks.push({ type: 'text', text: typeof step.final === 'string' ? step.final : JSON.stringify(step.final) });
            } else if (step.refusal) { stop = 'refusal'; }
            else blocks.push({ type: 'text', text: step.text ?? 'ok' });
            const outTokens = Math.max(8, Math.round(JSON.stringify(blocks).length / 4));
            // a stall is a provider that does not answer: a request without a stream gets nothing either (the
            // engine falls back to one when a stream went silent), until the client gives up
            if (step.stall && !j.stream) { rec.stalled = true; setTimeout(() => res.destroy(), step.stall).unref(); return; }
            if (!j.stream) return json(200, { ...start.message, content: blocks, stop_reason: stop, ...(stop === 'refusal' ? { stop_details: { type: 'refusal', category: 'cyber', explanation: 'fake' } } : {}), usage: { ...usage, output_tokens: outTokens } });
            res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache', 'request-id': id('req') });
            const send = (ev) => res.write(`event: ${ev.type}\ndata: ${JSON.stringify(ev)}\n\n`);
            send(start);
            if (step.stall) { setTimeout(() => res.destroy(), step.stall).unref(); return; }
            blocks.forEach((b, index) => {
                if (b.type === 'text') {
                    send({ type: 'content_block_start', index, content_block: { type: 'text', text: '' } });
                    send({ type: 'content_block_delta', index, delta: { type: 'text_delta', text: b.text } });
                } else {
                    send({ type: 'content_block_start', index, content_block: { type: 'tool_use', id: b.id, name: b.name, input: {} } });
                    const s = JSON.stringify(b.input);
                    for (let i = 0; i < s.length; i += 4000) send({ type: 'content_block_delta', index, delta: { type: 'input_json_delta', partial_json: s.slice(i, i + 4000) } });
                }
                send({ type: 'content_block_stop', index });
            });
            send({ type: 'message_delta', delta: { stop_reason: stop, stop_sequence: null, ...(stop === 'refusal' ? { stop_details: { type: 'refusal', category: 'cyber', explanation: 'fake' } } : {}) }, usage: { output_tokens: outTokens } });
            send({ type: 'message_stop' });
            res.end();
        });
    });
    return new Promise((ok) => server.listen(port, host, () => ok({ url: `http://${host === '0.0.0.0' ? '127.0.0.1' : host}:${server.address().port}`, port: server.address().port, state, close: () => new Promise((done) => { server.closeAllConnections?.(); server.close(done); }) })));
}

/** A replay plan from a recorded scan: its tool calls in order, then the model's final report. */
export function planFromRecording(toolLines, modelFinish) {
    const steps = toolLines.filter((l) => l.args !== undefined).map((l) => ({ tools: [{ name: l.tool, input: l.args }] }));
    return [...steps, { final: modelFinish ?? { status: 'done', preferredCandidate: 1, headline: '', placeholders: [], fonts: [], approximations: [], omitted: [], lowConfidenceTexts: [] } }];
}
