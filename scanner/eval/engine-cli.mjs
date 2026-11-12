// Evaluation only: the same scanner (tools, pre-pass, caps, finish) driven by the `claude -p` binary
// instead of the Agent SDK — the engine the prototypes used — to measure parity between the two.
// The tool server is offered to the binary over a loopback HTTP MCP endpoint.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { createInterface } from 'node:readline';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { UsageMeter, fromModelUsage } from '../src/engine/pricing.mjs';
import { classifyProviderError } from '../src/engine/classify.mjs';
import { engineEnv } from '../src/engine/sdk.mjs';

function mcpBridge(tools, dispatch) {
    const server = createServer((req, res) => {
        if (req.method !== 'POST') { res.writeHead(405).end(); return; }
        let body = '';
        req.on('data', (d) => { body += d; });
        req.on('end', async () => {
            let msg; try { msg = JSON.parse(body); } catch { res.writeHead(400).end(); return; }
            const reply = (result) => { res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, result })); };
            if (msg.id === undefined) { res.writeHead(202).end(); return; }
            if (msg.method === 'initialize') return reply({ protocolVersion: msg.params?.protocolVersion ?? '2025-06-18', capabilities: { tools: {} }, serverInfo: { name: 'scan', version: '1.0.0' } });
            if (msg.method === 'ping') return reply({});
            if (msg.method === 'tools/list') return reply({ tools: tools.map((t) => ({ name: t.name, description: t.description, inputSchema: t.input_schema })) });
            if (msg.method === 'tools/call') return reply(await dispatch(msg.params?.name, msg.params?.arguments ?? {}));
            res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, error: { code: -32601, message: 'method not found' } }));
        });
    });
    return new Promise((ok) => server.listen(0, '127.0.0.1', () => ok({ url: `http://127.0.0.1:${server.address().port}/mcp`, close: () => server.close() })));
}

/** Same contract as runSdk(). */
export async function runCli(o) {
    const started = Date.now();
    const meter = new UsageMeter(o.prices);
    const dir = join(o.scratchDir, 'engine-cli'); mkdirSync(dir, { recursive: true });
    const tools = o.tools ?? [];
    const bridge = tools.length ? await mcpBridge(tools, o.dispatch) : null;
    const systemFile = join(dir, 'system.md'); writeFileSync(systemFile, o.system);
    const mcpFile = join(dir, 'mcp.json'); writeFileSync(mcpFile, JSON.stringify({ mcpServers: bridge ? { scan: { type: 'http', url: bridge.url } } : {} }));
    const args = ['-p', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose',
        '--model', o.model, ...(o.effort ? ['--effort', o.effort] : []), '--system-prompt-file', systemFile,
        '--mcp-config', mcpFile, '--strict-mcp-config', '--tools', '', '--allowedTools', tools.map((t) => `mcp__scan__${t.name}`).join(','),
        '--permission-mode', 'dontAsk', '--setting-sources', '', '--disable-slash-commands', '--no-session-persistence',
        ...(o.maxTurns ? ['--max-turns', String(o.maxTurns)] : []), ...(o.maxBudgetUsd ? ['--max-budget-usd', String(o.maxBudgetUsd)] : []),
        ...(o.finishSchema ? ['--json-schema', JSON.stringify(o.finishSchema)] : [])];
    const facts = { engine: 'claude -p', init: null, retries: [], rateLimit: null, maxModelGapMs: 0, assistantErrors: [], exitCode: null, signal: null, stderrTail: '', resultAfterAbort: false };
    let result = null, waitingSince = Date.now(), planLimit = false, lastText = '';
    const seen = new Set();
    const child = spawn('claude', args, { cwd: dir, env: o.env ?? engineEnv({ settings: o.settings, scratchDir: o.scratchDir }), stdio: ['pipe', 'pipe', 'pipe'] });
    facts.pid = child.pid;
    const onAbort = () => child.kill('SIGTERM');
    o.signal?.addEventListener('abort', onAbort, { once: true });
    child.stdin.end(JSON.stringify({ type: 'user', message: { role: 'user', content: o.content } }) + '\n');
    child.stderr.on('data', (d) => { facts.stderrTail = (facts.stderrTail + d).slice(-2000); });
    createInterface({ input: child.stdout }).on('line', (line) => {
        let m; try { m = JSON.parse(line); } catch { return; }
        const now = Date.now();
        if (m.type === 'system' && m.subtype === 'init') { facts.init = { model: m.model, tools: m.tools, version: m.claude_code_version, apiKeySource: m.apiKeySource }; waitingSince = now; }
        else if (m.type === 'system' && m.subtype === 'api_retry') { facts.retries.push({ attempt: m.attempt, of: m.max_retries, delayMs: m.retry_delay_ms, status: m.error_status, error: m.error }); o.onEvent?.({ type: 'retry', attempt: m.attempt, of: m.max_retries, delayMs: m.retry_delay_ms }); }
        else if (m.type === 'rate_limit_event') { facts.rateLimit = m.rate_limit_info; if (m.rate_limit_info?.status === 'rejected') planLimit = true; }
        else if (m.type === 'assistant') {
            if (waitingSince !== null) facts.maxModelGapMs = Math.max(facts.maxModelGapMs, now - waitingSince);
            waitingSince = null;
            meter.note(m.message?.id, m.message?.model, m.message?.usage);
            if (m.error) facts.assistantErrors.push({ error: m.error });
            if (m.message?.id && !seen.has(m.message.id)) { seen.add(m.message.id); o.onEvent?.({ type: 'turn', turns: seen.size, usage: meter.snapshot() }); }
            for (const b of m.message?.content ?? []) if (b.type === 'text') lastText = b.text;
        } else if (m.type === 'user') waitingSince = now;
        else if (m.type === 'result') { result = m; if (o.signal?.aborted) facts.resultAfterAbort = true; }
    });
    await new Promise((done) => child.on('close', (code, signal) => { facts.exitCode = code; facts.signal = signal; done(); }));
    o.signal?.removeEventListener('abort', onAbort);
    bridge?.close();
    if (result?.modelUsage && Object.keys(result.modelUsage).length) meter.setFinal(Object.fromEntries(Object.entries(result.modelUsage).map(([k, v]) => [k.replace(/\[.*\]$/, ''), fromModelUsage(v)])));
    const base = { finish: null, text: typeof result?.result === 'string' ? result.result : lastText, usage: { ...meter.snapshot(), engineCostUsd: result?.total_cost_usd ?? null }, turns: result?.num_turns ?? seen.size, apiMs: result?.duration_api_ms ?? null, wallMs: Date.now() - started, facts: { ...facts, resultSubtype: result?.subtype ?? null, terminalReason: result?.terminal_reason ?? null } };
    if (o.signal?.aborted) return { ...base, outcome: 'aborted', error: null };
    if (!result) return { ...base, outcome: planLimit || facts.assistantErrors.length ? 'provider' : 'error', error: { ...classifyProviderError({ sdkError: facts.assistantErrors.at(-1)?.error, planLimit }), message: facts.stderrTail.slice(-200) } };
    if (result.subtype === 'error_max_turns') return { ...base, outcome: 'max_turns', error: null };
    if (result.subtype === 'error_max_budget_usd') return { ...base, outcome: 'max_budget', error: null };
    if (result.is_error || result.subtype !== 'success') return { ...base, outcome: 'provider', error: { ...classifyProviderError({ sdkError: facts.assistantErrors.at(-1)?.error, status: result.api_error_status ?? null, planLimit }), message: base.text?.slice(0, 200) } };
    let finish = result.structured_output ?? null;
    if (finish === null && typeof result.result === 'string') { try { finish = JSON.parse(result.result); } catch { /* not JSON */ } }
    return { ...base, outcome: 'finished', finish, error: null };
}
