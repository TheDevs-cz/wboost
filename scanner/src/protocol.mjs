// The scanner ↔ app job protocol, as an interface. Two implementations:
//   - FileJobApi (drivers/file-job.mjs): one job from a file on disk, renders through the app's MCP
//     design tools — what `scanner scan-file` and the evaluation use (step A);
//   - HttpScannerApi (below): the app's `/internal/scanner/*` endpoints — the wire contract is
//     docs/architecture/ai-scan.md § "Scanner API"; test/contract/ holds a recording of it.
//
// A JobApi is everything one claimed job may do:
//   source()                               → Buffer            original (intake lane) or working copy (build lane)
//   putArtifact(which, bytes)              → void              which: working | preview
//   beat({stage, note, noteParams, round, usage, resources}) → {cancel, mode}
//   render({seq, design})                  → {rendered, candidate, issues, image: {data, mimeType} | null, code?}
//   faces({seq, lines, faces, size})       → {image: {data, mimeType} | raster, cells: [{face, line, x, y, width, height, fontSize}], sheets?}
//   finish(payload)                        → {ok, code?}
//   release(payload)                       → {ok}
// and a ScannerApi hands jobs out:
//   claim(lane)                            → {job: {id, kind, lane, run, approximate, options, source, definition: {version, files}, context?} | null, mode, reason, api: JobApi}
//   tick(body)                             → {ok}

export const PROTOCOL_VERSION = 1;
export const INSTRUMENTS = Object.freeze(['ocr', 'crop', 'measure', 'font_rank', 'compare']);

/** The timing of architecture §4.9 the scanner needs BEFORE it has a claim (a claim's definition overrides it). Seconds. */
export const DEFAULT_TIMING = Object.freeze({
    heartbeatSeconds: 15, heartbeatAttemptSeconds: 10, selfAbortSeconds: 105, leaseSeconds: 120, intakeLeaseSeconds: 150,
    claimPollSeconds: 5, tickSeconds: 30, appCallAttemptSeconds: 30, appOutageToleranceSeconds: 60,
    intakeCapSeconds: 120, builderCapSeconds: 300, runCapSeconds: 360, providerProbeSeconds: 300,
    shutdownSeconds: 30, releaseAttemptSeconds: 20, killGraceSeconds: 5,
});
export const timingOf = (definitionTiming = {}) => ({ ...DEFAULT_TIMING, ...definitionTiming });

/** The faces a build may use, as DSL face strings — the claim context lists them as objects. */
export function contextFromClaim(context = {}) {
    const fonts = (context.fonts ?? []).map((f) => (typeof f === 'string' ? f : `${f.font} (${f.face})`));
    return { fonts: [...new Set(fonts)], brandColours: context.palette ?? context.brandColours ?? [], standInAssetId: context.standInAssetId, templateMaxLongEdgePx: context.templateMaxLongEdgePx ?? 5000 };
}

/** The definition a claim carries: files by name, `definition.json` decoded. */
export function readDefinition(files) {
    const definition = JSON.parse(files['definition.json']);
    const kind = (k) => {
        const d = definition.kinds?.[k];
        if (!d) throw new Error(`the definition has no kind "${k}"`);
        const all = JSON.parse(files['tools.json']);
        return {
            system: d.system.map((f) => files[f]).join('\n\n'),
            task: files[d.task],
            tools: d.tools.map((name) => all.find((t) => t.name === name)).filter(Boolean).map(({ name, description, input_schema }) => ({ name, description, input_schema })),
            finishSchema: JSON.parse(files[d.finish]),
        };
    };
    return { definition, files, kind };
}

export class ScannerApiError extends Error {
    /**
     * @param code    a code of architecture §5.6 (`stale_run`, `lease_lost`, `gone`, `protocol_too_old`, …) or a
     *                transport word: `network`, `timeout`, `not_scanner` (404), `unreadable`, `http_<status>`
     * @param status  the HTTP status, null when no answer arrived
     */
    constructor(code, status = null, { retryable = false, body = null } = {}) {
        super(code); this.name = 'ScannerApiError'; this.code = code; this.status = status; this.retryable = retryable; this.body = body;
    }
    /** This run is over (409 / 410): abort it, do not retry. */
    get runOver() { return this.status === 409 || this.status === 410; }
}

const sleep = (ms, signal) => new Promise((ok) => {
    if (signal?.aborted) return ok();
    const t = setTimeout(done, ms);
    function done() { clearTimeout(t); signal?.removeEventListener('abort', done); ok(); }
    signal?.addEventListener('abort', done, { once: true });
});

/**
 * The HTTP client of the app's scanner endpoints.
 *
 * - every call: `Authorization: Bearer`, `X-Scanner-Protocol`; per-scan calls: `X-Scan-Lease` + the claim's `run`;
 * - ONE ATTEMPT is cut at 30 s (a heartbeat: 10 s, a release: 20 s) — the app's own limit, so the scanner never
 *   gives up on a call the app is still answering;
 * - a failing call (no answer, a timeout, 5xx, 404 while the app is being replaced) is retried until the
 *   app-outage tolerance R (60 s) has passed since its first attempt; attempts START only inside R;
 * - 409 / 410 are never retried: that run is over;
 * - `claim`, `tick`, `beat` and `release` are single attempts — their timers repeat them (or, for a release,
 *   a lost one becomes a lost lease).
 */
export class HttpScannerApi {
    constructor({ baseUrl, token, scannerVersion, instruments = INSTRUMENTS, timing = {}, fetchImpl = globalThis.fetch, record = null, now = () => Date.now() }) {
        if (!baseUrl) throw new Error('HttpScannerApi: baseUrl is missing');
        if (!token) throw new Error('HttpScannerApi: token is missing');
        this.baseUrl = String(baseUrl).replace(/\/+$/, ''); this.token = token; this.scannerVersion = scannerVersion; this.instruments = [...instruments];
        this.timing = timingOf(timing); this.fetchImpl = fetchImpl; this.record = record; this.now = now;
        this.lastOkAt = null; this.lastError = null;
        /** What the app last said about this scanner's protocol: null (unknown) | true | false (too old). */
        this.protocolAccepted = null;
    }

    /** One attempt. → {status, json} | {status, bytes}. Throws ScannerApiError for everything that is not an answer to act on. */
    async attempt(method, path, { body = null, lease = null, raw = null, contentType = null, attemptMs = null, expect = 'json', signal = null } = {}) {
        const headers = { Authorization: `Bearer ${this.token}`, 'X-Scanner-Protocol': String(PROTOCOL_VERSION), Accept: expect === 'bytes' ? '*/*' : 'application/json' };
        if (lease) headers['X-Scan-Lease'] = lease;
        if (raw) headers['Content-Type'] = contentType ?? 'application/octet-stream'; else if (body) headers['Content-Type'] = 'application/json';
        const cut = AbortSignal.timeout(attemptMs ?? this.timing.appCallAttemptSeconds * 1000);
        const entry = this.record ? { method, path, lease: !!lease, leaseToken: lease, body, rawBytes: raw ? raw.length : null } : null;
        let res;
        try {
            res = await this.fetchImpl(`${this.baseUrl}/internal/scanner/${path}`, { method, headers, body: raw ?? (body ? JSON.stringify(body) : undefined), signal: signal ? AbortSignal.any([cut, signal]) : cut });
        } catch (e) {
            const aborted = signal?.aborted && !cut.aborted;
            const err = new ScannerApiError(aborted ? 'aborted' : cut.aborted ? 'timeout' : 'network', null, { retryable: !aborted });
            this.lastError = { code: err.code, at: this.now(), path: path.replace(/^[0-9a-f-]{36}\//, '') };
            if (entry) this.record({ ...entry, status: null, error: err.code });
            throw err;
        }
        const type = res.headers.get('content-type') ?? '';
        let json = null, bytes = null;
        try {
            if (expect === 'bytes' && res.status === 200 && !type.includes('json')) bytes = Buffer.from(await res.arrayBuffer());
            else { const text = await res.text(); json = text ? JSON.parse(text) : null; }
        } catch { /* an unreadable body is handled below */ }
        if (entry) this.record({ ...entry, status: res.status, contentType: type, response: json, responseBytes: bytes ? bytes.length : null });
        const fail = (code, o) => { const err = new ScannerApiError(code, res.status, { body: json, ...o }); this.lastError = { code, status: res.status, at: this.now(), path: path.replace(/^[0-9a-f-]{36}\//, '') }; return err; };
        // 404 = "not the scanner" (source network or token) — also what a half-started app answers: retried inside R, then named
        if (res.status === 404) throw fail('not_scanner', { retryable: true });
        if (res.status === 409 || res.status === 410) throw fail(json?.code ?? (res.status === 410 ? 'gone' : 'stale_run'));
        if (res.status === 429 || res.status >= 500) throw fail(json?.code ?? `http_${res.status}`, { retryable: true });
        if (res.status !== 200) throw fail(json?.code ?? `http_${res.status}`);
        if (bytes === null && (json === null || typeof json !== 'object')) throw fail('unreadable', { retryable: true });
        this.lastOkAt = this.now();
        if (json?.ok === false && json.code === 'protocol_too_old') { this.protocolAccepted = false; throw fail('protocol_too_old'); }
        this.protocolAccepted = true;
        return { status: res.status, json, bytes };
    }

    /**
     * Attempts until an answer, a final error, or the app-outage tolerance R has passed since the first attempt.
     * `opts.startBy` (a time of `now()`): no attempt starts after it — a caller that already used part of R on
     * earlier calls of the same work (a busy renderer answered, then the app went away) passes what is left, so
     * that the whole sequence stays inside R + one attempt.
     */
    async call(method, path, opts = {}) {
        const until = Math.min(this.now() + this.timing.appOutageToleranceSeconds * 1000, opts.startBy ?? Infinity);
        for (let n = 0; ; n++) {
            try { return await this.attempt(method, path, opts); } catch (e) {
                if (!(e instanceof ScannerApiError) || !e.retryable || opts.signal?.aborted) throw e;
                const wait = Math.min(5000, 1000 * (n + 1), this.timing.appOutageToleranceSeconds * 100);
                if (this.now() + wait >= until) throw Object.assign(e, { outage: true });
                await sleep(wait, opts.signal);
                if (opts.signal?.aborted) throw new ScannerApiError('aborted', null);
            }
        }
    }

    // ---- scanner-level calls (single attempts: their timers repeat them)

    /** → {job, mode, reason, api}. `reason`: null | paused | off | provider_blocked | scanner_too_old | spend_ceiling. */
    async claim(lane, capacity = 1, { signal = null } = {}) {
        const { json } = await this.attempt('POST', 'claim', { body: { lane, scannerVersion: this.scannerVersion, instruments: this.instruments, capacity }, signal });
        if (json.ok === false) throw new ScannerApiError(json.code ?? 'error', 200, { body: json });
        return { job: json.job ?? null, mode: json.mode ?? null, reason: json.reason ?? null, api: json.job ? this.forJob(json.job) : null };
    }

    /** → {mode, providerState, maxRunning, reaped, protocolVersion}. `provider`: {state: ok | blocked, error?} after a probe. */
    async tick({ provider = null, signal = null } = {}) {
        const { json } = await this.attempt('POST', 'tick', { body: { scannerVersion: this.scannerVersion, ...(provider ? { provider } : {}) }, signal });
        if (json.ok === false) throw new ScannerApiError(json.code ?? 'error', 200, { body: json });
        return json;
    }

    // ---- the calls of one claimed job

    /**
     * @param job     a claim answer's `job` ({id, run, leaseToken, …})
     * @param signal  aborts the retries of this job's calls when the run is aborted (never the finish)
     */
    forJob(job, { signal = null } = {}) {
        const lease = job.leaseToken, id = job.id, run = job.run, base = { run };
        const image = (i) => (i?.base64 ? { data: Buffer.from(i.base64, 'base64'), mimeType: i.contentType ?? 'image/webp' } : null);
        const refused = (json) => { if (json?.ok === false) throw new ScannerApiError(json.code ?? 'error', 200, { body: json, retryable: ['renderer_busy', 'renderer_unavailable'].includes(json.code) }); return json; };
        return {
            id, run, lane: job.lane,
            source: async () => {
                const r = await this.call('GET', `${id}/source?run=${run}`, { lease, expect: 'bytes', signal });
                if (!r.bytes) { refused(r.json); throw new ScannerApiError('unreadable', 200); }
                return r.bytes;
            },
            putArtifact: async (which, bytes, contentType = 'image/webp') => refused((await this.call('PUT', `${id}/artifact/${which}?run=${run}`, { lease, raw: bytes, contentType, signal })).json),
            /** One attempt, cut at 10 s. → {cancel, mode, status?, leaseExpiresAt}; 409 / 410 throw (runOver). */
            beat: async (b) => (await this.attempt('POST', `${id}/beat`, { lease, body: { ...base, ...b }, attemptMs: this.timing.heartbeatAttemptSeconds * 1000 })).json,
            /** Idempotent by (run, seq): a retry sends the SAME seq. A busy / absent renderer comes back as `code`. */
            render: async ({ seq, design }, { startBy = null } = {}) => {
                const { json } = await this.call('POST', `${id}/render`, { lease, body: { ...base, seq, design }, signal, startBy });
                if (json.ok === false && !['renderer_busy', 'renderer_unavailable'].includes(json.code)) refused(json);
                return { rendered: json.ok === true && Number.isInteger(json.candidate), candidate: json.candidate ?? null, issues: json.issues ?? [], width: json.width ?? null, height: json.height ?? null, image: image(json.image), code: json.ok === false ? json.code : null, status: json.ok === true && !Number.isInteger(json.candidate) ? 'rejected' : null };
            },
            faces: async ({ seq, lines, faces = null, size = null }, { startBy = null } = {}) => {
                const { json } = await this.call('POST', `${id}/faces`, { lease, body: { ...base, seq, lines, ...(faces ? { faces } : {}), ...(size ? { size: Math.round(size) } : {}) }, signal, startBy });
                if (json.ok === false && !['renderer_busy', 'renderer_unavailable'].includes(json.code)) refused(json);
                return { image: image(json.image), cells: json.cells ?? [], width: json.width ?? null, height: json.height ?? null, code: json.ok === false ? json.code : null };
            },
            /**
             * Idempotent per (scan, run); retried inside R like every call, protected by the lease (never by the run's
             * abort signal). Never throws for an answer: a finish that did not apply (the scan was cancelled,
             * interrupted, or already finished — a lost answer of an earlier attempt) comes back as
             * {applied: false, late: true, status, code} — usage was recorded either way.
             */
            finish: async (payload) => {
                try {
                    const { json } = await this.call('POST', `${id}/finish`, { lease, body: { ...base, ...payload } });
                    if (json.ok === false) return { ok: false, applied: false, late: false, status: null, code: json.code ?? 'error' };
                    return { ok: true, applied: json.applied !== false, late: false, status: json.status ?? null, code: json.code ?? null };
                } catch (e) {
                    if (e instanceof ScannerApiError && e.runOver) return { ok: false, applied: false, late: true, status: e.body?.status ?? null, code: e.code, errorCode: e.body?.errorCode ?? null };
                    throw e;
                }
            },
            /** ONE attempt, cut at 20 s (§4.9 shutdown): a release that does not arrive becomes a lost lease. */
            release: async (payload) => (await this.attempt('POST', `${id}/release`, { lease, body: { ...base, ...payload }, attemptMs: this.timing.releaseAttemptSeconds * 1000 })).json,
        };
    }
}
