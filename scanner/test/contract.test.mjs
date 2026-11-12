// The contract fixture (test/contract/scanner-api.v1.json): exchanges recorded between this client and the
// REAL app. Replayed here from the scanner's side: for every recorded exchange the client must (1) send
// exactly the recorded request — method, path, headers, body — and (2) read the recorded answer the way
// the scanner relies on. The app's CI replays the same file from its side (test/contract/README.md).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HttpScannerApi, ScannerApiError, PROTOCOL_VERSION } from '../src/protocol.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const fixture = JSON.parse(readFileSync(join(HERE, 'contract', `scanner-api.v${PROTOCOL_VERSION}.json`), 'utf8'));
const bytesOf = (s) => Number(/^<(\d+) bytes/.exec(String(s))?.[1] ?? NaN);

/** The recorded answer as a fetch Response (pictures and byte bodies are zero-filled at their recorded size). */
function responseOf(r) {
    if (r.status === 404) return new Response(null, { status: 404 });
    if (typeof r.body === 'string' && Number.isFinite(bytesOf(r.body))) return new Response(Buffer.alloc(bytesOf(r.body)), { status: r.status, headers: { 'Content-Type': r.contentType } });
    const fill = (v) => (Array.isArray(v) ? v.map(fill) : v && typeof v === 'object' ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, k === 'base64' ? Buffer.alloc(bytesOf(x)).toString('base64') : fill(x)])) : v);
    return new Response(JSON.stringify(fill(r.body)), { status: r.status, headers: { 'Content-Type': r.contentType || 'application/json' } });
}

/** Plays one recorded exchange through the client. → {sent, result | error} */
async function replay(entry) {
    const { request: q, response: r } = entry;
    const sent = [];
    const http = new HttpScannerApi({
        baseUrl: 'http://app', token: q.headers.Authorization.replace('Bearer ', ''), scannerVersion: q.body?.scannerVersion ?? '0.2.0+contract',
        timing: { appOutageToleranceSeconds: 0.01 }, // one attempt: the recording holds one answer
        fetchImpl: async (url, init) => { sent.push({ method: init.method, path: url.replace('http://app', ''), headers: init.headers, body: init.body }); return responseOf(r); },
    });
    const m = /^\/internal\/scanner\/(?:(.+?)\/)?(tick|claim|source|artifact|beat|render|faces|finish|release)(?:\/(working|preview))?(?:\?run=(\d+))?$/.exec(q.path);
    assert.ok(m, `a path of the contract: ${q.path}`);
    const [, id, op, which, queryRun] = m;
    const body = q.body && typeof q.body === 'object' ? q.body : {};
    const job = { id, run: body.run ?? Number(queryRun), leaseToken: q.headers['X-Scan-Lease'] };
    const { run: _run, ...rest } = body;
    const api = id ? http.forJob(job) : null;
    const call = {
        tick: () => http.tick({ provider: body.provider ?? null }),
        claim: () => http.claim(body.lane, body.capacity),
        source: () => api.source(), artifact: () => api.putArtifact(which, Buffer.alloc(bytesOf(q.body))),
        beat: () => api.beat(rest), render: () => api.render(rest), faces: () => api.faces(rest), finish: () => api.finish(rest), release: () => api.release(rest),
    }[op];
    try { return { op, sent, result: await call() }; } catch (error) { if (!(error instanceof ScannerApiError)) throw error; return { op, sent, error }; }
}

test(`contract fixture v${PROTOCOL_VERSION}: it carries no secret and no picture`, () => {
    const text = JSON.stringify(fixture);
    assert.equal(fixture.protocolVersion, PROTOCOL_VERSION);
    assert.ok(fixture.entries.length >= 50);
    assert.equal(/[0-9a-f]{64}/.test(text), false, 'no lease token');
    assert.equal(/"base64":"(?!<\d+ bytes)/.test(text), false, 'no picture bytes');
    assert.equal(/token-not-a-secret|wb_mcp_|sk-ant-/.test(text), false, 'no token');
    assert.equal(/@[a-z0-9-]+\.[a-z]{2,}/i.test(text), false, 'no e-mail address');
    const ids = text.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/g) ?? [];
    assert.deepEqual([...new Set(ids)].sort(), ['00000000-0000-4000-8000-0000000a15ca', '00000000-0000-7000-8000-000000000000'], 'only the reserved stand-in asset id and the made-up scan id');
});

test('contract fixture: the client sends exactly the recorded requests', async () => {
    for (const entry of fixture.entries) {
        const { sent } = await replay(entry);
        const q = entry.request, s = sent[0];
        assert.equal(sent.length, 1, entry.step);
        assert.equal(s.method, q.method, entry.step); assert.equal(s.path, q.path, entry.step);
        assert.equal(s.headers.Authorization, q.headers.Authorization, entry.step);
        assert.equal(s.headers['X-Scan-Lease'], q.headers['X-Scan-Lease'], `${entry.step}: lease header`);
        if (q.headers['X-Scanner-Protocol'] === String(PROTOCOL_VERSION)) assert.equal(s.headers['X-Scanner-Protocol'], String(PROTOCOL_VERSION), entry.step);
        if (typeof q.body === 'string') assert.equal(s.body.length, bytesOf(q.body), `${entry.step}: byte body`);
        else if (q.body) assert.deepEqual(JSON.parse(s.body), q.body, `${entry.step}: body`);
        else assert.equal(s.body, undefined, entry.step);
    }
});

test('contract fixture: the client reads every recorded answer the way the scanner relies on', async () => {
    const seen = new Set();
    for (const entry of fixture.entries) {
        const { op, result, error } = await replay(entry);
        const r = entry.response, b = r.body, step = entry.step;
        const kind = r.status === 200 && b && typeof b === 'object' && b.ok === false ? `200:${b.code}` : String(r.status);
        seen.add(`${op} ${kind}`);
        if (r.status === 404) { assert.deepEqual([error.code, error.retryable], ['not_scanner', true], step); continue; }
        if (b?.code === 'protocol_too_old') { assert.equal(error.code, 'protocol_too_old', step); assert.equal(error.body.protocolVersion, b.protocolVersion); continue; }
        if ((r.status === 409 || r.status === 410) && op !== 'finish') { assert.deepEqual([error.code, error.status, error.runOver, error.retryable], [b.code, r.status, true, false], step); continue; }
        // a refusal with a code inside a 200 answer: render / faces throw it by name (a busy or absent renderer is returned for the retry)
        if (b && b.ok === false && (op === 'render' || op === 'faces') && !['renderer_busy', 'renderer_unavailable'].includes(b.code)) { assert.deepEqual([error.code, error.status, error.runOver], [b.code, 200, false], step); continue; }
        assert.equal(error, undefined, `${step}: ${error?.code}`);
        switch (op) {
            case 'tick': assert.deepEqual([result.mode, result.providerState, result.protocolVersion], [b.mode, b.providerState, b.protocolVersion], step); assert.equal(typeof result.maxRunning, 'number'); break;
            case 'claim':
                assert.deepEqual([result.mode, result.reason], [b.mode, b.reason ?? null], step);
                if (b.job) { assert.deepEqual([result.job.id, result.job.lane, result.job.run, typeof result.job.leaseToken, typeof result.job.definition.files['definition.json']], [b.job.id, b.job.lane, b.job.run, 'string', 'string'], step); assert.equal(typeof result.api.finish, 'function'); assert.equal(b.job.lane === 'build', 'context' in result.job, 'context is in build claims only'); } else assert.equal(result.job, null, step);
                break;
            case 'source': assert.equal(result.length, bytesOf(b), step); break;
            case 'artifact': assert.deepEqual(result, b, step); break;
            case 'beat': assert.equal(result.cancel, b.cancel, step); assert.equal(result.mode, b.mode, step); if (b.cancel) assert.equal(typeof result.status, 'string', step); else assert.equal(typeof result.leaseExpiresAt, 'string', step); break;
            case 'render':
                if (b.ok === false) { assert.equal(result.rendered, false); assert.equal(result.code, b.code, step); } // only renderer_busy / renderer_unavailable come back as a code
                else if (b.candidate === null) { assert.deepEqual([result.rendered, result.candidate, result.image, result.status], [false, null, null, 'rejected'], step); assert.ok(result.issues.length >= 1 && result.issues.every((i) => typeof i.code === 'string' && typeof i.severity === 'string'), step); }
                else assert.deepEqual([result.rendered, result.candidate, result.image.mimeType, result.image.data.length, result.width, result.height], [true, b.candidate, b.image.contentType, bytesOf(b.image.base64), b.width, b.height], step);
                break;
            case 'faces': assert.deepEqual([result.cells.length, result.width, result.height, result.image.data.length], [b.cells.length, b.width, b.height, bytesOf(b.image.base64)], step); assert.deepEqual(Object.keys(result.cells[0]).sort(), ['face', 'fontSize', 'height', 'line', 'width', 'x', 'y'], step); break;
            case 'finish':
                if (r.status === 409) assert.deepEqual([result.ok, result.applied, result.late, result.code, result.status], [false, false, true, b.code, b.status ?? null], step);
                else if (b.ok === false) assert.deepEqual([result.ok, result.applied, result.code], [false, false, b.code], step);
                else assert.deepEqual([result.ok, result.applied, result.late, result.status, result.code], [true, b.applied, false, b.status, b.code ?? null], step);
                break;
            case 'release': assert.deepEqual([result.ok, result.applied, result.status], [true, b.applied, b.status], step); break;
            default: assert.fail(op);
        }
    }
    // the recording covers what the scanner has to tell apart
    for (const k of ['tick 200', 'tick 200:protocol_too_old', 'tick 404', 'claim 200', 'source 200', 'artifact 200', 'beat 200', 'beat 409', 'beat 410', 'render 200', 'render 409', 'faces 200', 'finish 200', 'finish 409', 'finish 200:missing_artifact', 'release 200']) assert.ok(seen.has(k), `the fixture has no exchange "${k}" (has: ${[...seen].sort().join(', ')})`);
});

test('contract fixture: refusals with a code inside a 200 answer are errors the caller can name — never a silent success', async () => {
    const by = (needle) => fixture.entries.find((e) => e.step.includes(needle));
    const limit = await replay(by('past the cap'));
    assert.deepEqual([limit.error.code, limit.error.status, limit.error.runOver], ['candidate_limit', 200, false]);
    const invalid = await replay(by('faces without lines'));
    assert.deepEqual([invalid.error.code, invalid.error.status], ['invalid_request', 200]);
    const early = await replay(by('before the working copy is stored'));
    assert.deepEqual([early.result.ok, early.result.applied, early.result.code], [false, false, 'missing_artifact']);
});
