// A stand-in for the app's `/internal/scanner/*` endpoints (docs/architecture/ai-scan.md § "Scanner API"),
// with the faults a scanner has to live through: an outage, a slow call, a lost answer, 409 / 410, cancel.
// It checks what the real app checks — bearer token, protocol header, lease token, run — and records every call.
import { createServer } from 'node:http';
import { randomBytes } from 'node:crypto';
import sharp from 'sharp';
import { definitionFiles } from '../../src/drivers/file-job.mjs';
import { drawDesign, fakeApp } from './fakes.mjs';

export const TOKEN = 'test-scanner-token-0123456789abcdef';

/** definition files with the timing / caps of a test (seconds may be fractions). */
export function definitionWith({ timing = {}, caps = {}, preCheck = {}, engine = {} } = {}) {
    const files = definitionFiles();
    const def = JSON.parse(files['definition.json']);
    Object.assign(def.timing, timing); Object.assign(def.caps, caps); Object.assign(def.preCheck, preCheck); Object.assign(def.engine, engine);
    files['definition.json'] = JSON.stringify(def);
    return files;
}

export async function startFakeApp({ files = definitionFiles(), protocolVersion = 1, port = 0 } = {}) {
    const drawer = fakeApp();
    const state = {
        scans: new Map(), calls: [], mode: 'on', providerState: 'ok', maxRunning: 9, protocolVersion, claimReason: null,
        downUntil: 0, hangUntil: 0, delay: {}, force: {}, dropAnswerOnce: {}, ticks: [], requireInstruments: ['ocr', 'crop', 'measure', 'font_rank', 'compare'],
    };
    const j = (res, status, obj) => { res.writeHead(status, { 'Content-Type': 'application/json' }).end(JSON.stringify(obj)); };
    const read = (req) => new Promise((ok) => { const chunks = []; req.on('data', (d) => chunks.push(d)); req.on('end', () => ok(Buffer.concat(chunks))); });

    /** Adds a scan the lanes can claim. `lane: 'intake'` needs `original` bytes; `lane: 'build'` a working copy (or a design to draw). */
    async function addScan({ id = crypto.randomUUID(), lane = 'build', original = null, design = null, working = null, context = null, kind = 'template', options = {} } = {}) {
        if (lane === 'build' && !working) working = await sharp(await drawDesign(design)).webp({ lossless: true }).toBuffer();
        const scan = {
            id, kind, options, status: lane === 'intake' ? 'checking' : 'queued', run: 0, intakeTries: 0, lease: null, leaseLive: false,
            original, working, artifacts: {}, candidates: [], beats: [], finish: null, finishes: [], release: null, usage: {}, cancel: false,
            context: context ?? { fonts: [{ font: 'Sans', face: 'Regular' }, { font: 'Sans', face: 'Bold' }], palette: [], standInAssetId: 'standin', templateMaxLongEdgePx: 5000 },
        };
        state.scans.set(id, scan);
        return scan;
    }
    const jobOf = (scan, lane) => ({
        id: scan.id, kind: scan.kind, lane, run: lane === 'intake' ? scan.intakeTries : scan.run, try: 1, approximate: false, leaseToken: scan.lease, leaseSeconds: lane === 'intake' ? 150 : 120, options: scan.options,
        source: { sha256: 'x', contentType: lane === 'intake' ? 'image/png' : 'image/webp', bytes: null, width: null, height: null },
        definition: { version: 'test', files }, ...(lane === 'build' ? { context: scan.context } : {}),
    });

    const server = createServer(async (req, res) => {
        const url = new URL(req.url, 'http://x');
        const m = url.pathname.match(/^\/internal\/scanner\/(?:([0-9a-f-]{36})\/)?([a-z]+)(?:\/([a-z]+))?$/);
        const body = await read(req);
        const op = m ? (m[3] ? `${m[2]}/${m[3]}` : m[2]) : 'unknown';
        const opName = m?.[2] ?? 'unknown';
        const now = Date.now();
        const rec = { at: now, op, scan: m?.[1] ?? null, method: req.method, lease: req.headers['x-scan-lease'] ?? null, protocol: req.headers['x-scanner-protocol'] ?? null };
        state.calls.push(rec);
        // the app is away: the connection is reset, or nothing answers
        if (now < state.downUntil) { rec.fault = 'down'; req.socket.destroy(); return; }
        if (now < state.hangUntil) { rec.fault = 'hang'; setTimeout(() => req.socket.destroy(), state.hangUntil - now + 50).unref(); return; }
        if (req.headers.authorization !== `Bearer ${TOKEN}` || !m) { rec.status = 404; res.writeHead(404).end(); return; }
        let json = {};
        if (req.headers['content-type']?.includes('json')) { try { json = JSON.parse(body.toString() || '{}'); } catch { /* not JSON */ } }
        rec.body = req.headers['content-type']?.includes('json') ? json : null; rec.seq = json.seq ?? null; rec.run = json.run ?? (url.searchParams.get('run') ? Number(url.searchParams.get('run')) : null);
        const answer = async (status, obj) => {
            if (state.delay[opName]) await new Promise((ok) => setTimeout(ok, state.delay[opName]));
            rec.status = status; rec.answer = obj;
            // the call was processed, the answer is lost on the way
            if (state.dropAnswerOnce[opName]) { state.dropAnswerOnce[opName] = false; rec.fault = 'answer_lost'; req.socket.destroy(); return; }
            j(res, status, obj);
        };
        const version = Number(req.headers['x-scanner-protocol']);
        if (!(version === state.protocolVersion || version === state.protocolVersion - 1)) return answer(200, { ok: false, code: 'protocol_too_old', protocolVersion: state.protocolVersion });
        if (state.force[opName]) { const f = state.force[opName]; return answer(f.status, f.body); }

        if (opName === 'tick') { state.ticks.push(json); if (json.provider?.state) state.providerState = json.provider.state; return answer(200, { ok: true, mode: state.mode, providerState: state.providerState, maxRunning: state.maxRunning, reaped: 0, protocolVersion: state.protocolVersion }); }
        if (opName === 'claim') {
            const lane = json.lane;
            const none = (reason) => answer(200, { ok: true, mode: state.mode, job: null, reason });
            if (state.requireInstruments.some((i) => !(json.instruments ?? []).includes(i))) return none('scanner_too_old');
            if (state.mode !== 'on') return none(state.mode);
            if (state.providerState === 'blocked') return none('provider_blocked');
            if (state.claimReason) return none(state.claimReason);
            if (lane === 'build' && [...state.scans.values()].filter((s) => s.status === 'running').length >= Math.min(state.maxRunning, json.capacity ?? 1)) return none(null);
            const scan = [...state.scans.values()].find((s) => !s.leaseLive && s.status === (lane === 'intake' ? 'checking' : 'queued'));
            if (!scan) return none(null);
            scan.lease = randomBytes(32).toString('hex'); scan.leaseLive = true; scan.claimedAt = now;
            if (lane === 'intake') scan.intakeTries++; else { scan.run++; scan.status = 'running'; scan.candidates = []; }
            scan.lane = lane;
            return answer(200, { ok: true, mode: state.mode, job: jobOf(scan, lane) });
        }
        // ---- per-scan calls: the lease token and the run prove the caller holds the scan
        const scan = state.scans.get(m[1]);
        if (!scan) return answer(410, { ok: false, code: 'gone' });
        if (!rec.lease || rec.lease !== scan.lease) { rec.crossed = true; return answer(409, { ok: false, code: 'lease_lost' }); }
        const expected = scan.lane === 'intake' ? scan.intakeTries : scan.run;
        if (rec.run !== expected) { rec.crossed = true; return answer(409, { ok: false, code: 'stale_run' }); }
        const live = scan.leaseLive && scan.status === (scan.lane === 'intake' ? 'checking' : 'running');
        if (json.usage) scan.usage = { ...scan.usage, ...Object.fromEntries(Object.entries(json.usage).map(([k, v]) => [k, typeof v === 'number' ? Math.max(v, scan.usage[k] ?? 0) : v])) };

        if (opName === 'source') {
            if (!live) return answer(409, { ok: false, code: 'lease_lost' });
            if (state.delay.source) await new Promise((ok) => setTimeout(ok, state.delay.source));
            rec.status = 200;
            res.writeHead(200, { 'Content-Type': scan.lane === 'intake' ? 'image/png' : 'image/webp' }).end(scan.lane === 'intake' ? scan.original : scan.working);
            return;
        }
        if (opName === 'artifact') {
            if (!live || scan.lane !== 'intake') return answer(409, { ok: false, code: 'lease_lost' });
            scan.artifacts[m[3]] = body; if (m[3] === 'working') scan.working = body;
            return answer(200, { ok: true, bytes: body.length });
        }
        if (opName === 'beat') {
            scan.beats.push({ at: now, ...json });
            if (!live) return ['queued', 'checking', 'running'].includes(scan.status) ? answer(409, { ok: false, code: 'lease_lost' }) : answer(200, { ok: true, cancel: true, mode: state.mode, status: scan.status });
            scan.leaseExpiresAt = now + 120_000;
            return answer(200, { ok: true, cancel: false, mode: state.mode, leaseExpiresAt: new Date(scan.leaseExpiresAt).toISOString() });
        }
        if (opName === 'render') {
            if (!live) return answer(409, { ok: false, code: 'lease_lost' });
            const again = scan.candidates.find((c) => c.seq === json.seq);
            if (again) return answer(200, { ok: true, candidate: again.n, issues: [], width: again.width, height: again.height, image: { contentType: 'image/png', base64: again.bytes.toString('base64') } });
            if (state.renderer) { const code = state.renderer(scan, json); if (code) return answer(200, { ok: false, code }); }
            if (scan.candidates.length >= 3) return answer(200, { ok: false, code: 'candidate_limit' });
            const r = await drawer.render(json.design);
            if (!r.rendered) return answer(200, { ok: true, candidate: null, issues: r.issues });
            const entry = { n: scan.candidates.length + 1, seq: json.seq, design: json.design, bytes: r.image.data, width: json.design.canvas.width, height: json.design.canvas.height };
            scan.candidates.push(entry);
            return answer(200, { ok: true, candidate: entry.n, issues: [], width: entry.width, height: entry.height, image: { contentType: 'image/png', base64: entry.bytes.toString('base64') } });
        }
        if (opName === 'faces') {
            if (!live) return answer(409, { ok: false, code: 'lease_lost' });
            const r = await drawer.faces({ lines: json.lines, faces: json.faces ?? scan.context.fonts.map((f) => `${f.font} (${f.face})`), size: json.size ?? 64 });
            return answer(200, { ok: true, width: r.width, height: r.height, cells: r.cells, image: { contentType: 'image/png', base64: r.image.data.toString('base64') } });
        }
        if (opName === 'finish') {
            scan.finishes.push({ at: now, ...json });
            if (!live) return answer(409, { ok: false, code: 'stale_run', status: scan.status });
            scan.finish = json; scan.leaseLive = false;
            if (scan.lane === 'intake') scan.status = json.outcome === 'passed' ? 'queued' : json.outcome === 'stopped' ? 'stopped' : json.outcome === 'refused' ? 'refused' : 'failed';
            else scan.status = json.outcome === 'proposed' ? 'ready' : json.outcome === 'refused' ? 'refused' : 'failed';
            return answer(200, { ok: true, applied: true, status: scan.status, code: json.error ?? null });
        }
        if (opName === 'release') {
            scan.release = { at: now, ...json };
            if (json.reason === 'provider_blocked') state.providerState = 'blocked';
            const applied = live;
            if (live) { scan.leaseLive = false; scan.status = scan.lane === 'intake' ? 'checking' : 'queued'; }
            return answer(200, { ok: true, applied, status: scan.status });
        }
        return answer(200, { ok: false, code: 'invalid_request' });
    });
    await new Promise((ok) => server.listen(port, '127.0.0.1', ok));
    const p = server.address().port;
    return {
        url: `http://127.0.0.1:${p}`, port: p, state, addScan, token: TOKEN,
        /** the user (or an admin) ends a scan from outside: the next beat answers `cancel` */
        end(scan, status = 'cancelled') { scan.status = status; scan.leaseLive = false; },
        down(ms) { state.downUntil = Date.now() + ms; }, hang(ms) { state.hangUntil = Date.now() + ms; },
        calls: (op, scanId = null) => state.calls.filter((c) => c.op.startsWith(op) && (!scanId || c.scan === scanId)),
        close: () => new Promise((ok) => { server.closeAllConnections?.(); server.close(ok); }),
    };
}

/** Polls until `fn()` is truthy. */
export async function until(fn, { timeoutMs = 10_000, everyMs = 20, what = 'condition' } = {}) {
    const end = Date.now() + timeoutMs;
    for (;;) { const v = await fn(); if (v) return v; if (Date.now() > end) throw new Error(`timed out waiting for ${what}`); await new Promise((ok) => setTimeout(ok, everyMs)); }
}
