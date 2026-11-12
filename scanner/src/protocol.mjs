// The scanner ↔ app job protocol, as an interface. Two implementations:
//   - FileJobApi (drivers/file-job.mjs): one job from a file on disk, renders through the app's MCP
//     design tools — what `scanner scan-file` and the evaluation use (step A);
//   - HttpScannerApi (below): the app's `/internal/scanner/*` endpoints (step B — NOT exercised yet;
//     the shapes follow src/Services/AiScan/ScannerApi.php as it stood on 2026-11-12).
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
    constructor(code, status, message) { super(message ?? code); this.code = code; this.status = status; }
}

/**
 * Step B: the HTTP client of the app's scanner endpoints. Kept thin on purpose — retries inside the
 * app-outage tolerance R, the heartbeat timer and the lanes live in the caller.
 */
export class HttpScannerApi {
    constructor({ baseUrl, token, scannerVersion, instruments, fetchImpl = fetch, attemptMs = 30_000, headers = {} }) {
        Object.assign(this, { baseUrl: baseUrl.replace(/\/$/, ''), token, scannerVersion, instruments, fetchImpl, attemptMs });
        this.headers = { lease: 'X-Scan-Lease', protocol: 'X-Scanner-Protocol', version: 'X-Scanner-Version', ...headers };
    }
    async call(method, path, { body = null, lease = null, raw = null, contentType = null } = {}) {
        const res = await this.fetchImpl(`${this.baseUrl}/internal/scanner/${path}`, {
            method, signal: AbortSignal.timeout(this.attemptMs),
            headers: { Authorization: `Bearer ${this.token}`, [this.headers.protocol]: String(PROTOCOL_VERSION), [this.headers.version]: this.scannerVersion, ...(lease ? { [this.headers.lease]: lease } : {}), ...(raw ? { 'Content-Type': contentType ?? 'application/octet-stream' } : body ? { 'Content-Type': 'application/json' } : {}) },
            body: raw ?? (body ? JSON.stringify({ protocolVersion: PROTOCOL_VERSION, scannerVersion: this.scannerVersion, ...body }) : undefined),
        });
        return res;
    }
    async json(method, path, o) {
        const res = await this.call(method, path, o);
        const j = await res.json().catch(() => null);
        if (!j) throw new ScannerApiError('unreadable', res.status);
        if (j.ok === false && res.status >= 400) throw new ScannerApiError(j.code ?? 'error', res.status);
        return j;
    }
    async claim(lane, capacity = 1) {
        const j = await this.json('POST', 'claim', { body: { lane, instruments: this.instruments, capacity } });
        return { job: j.job ?? null, mode: j.mode, reason: j.reason ?? null, api: j.job ? this.forJob(j.job) : null };
    }
    tick(body = {}) { return this.json('POST', 'tick', { body }); }
    forJob(job) {
        const lease = job.leaseToken, base = { run: job.run }, id = job.id;
        const image = (i) => (i ? { data: Buffer.from(i.base64, 'base64'), mimeType: i.contentType } : null);
        return {
            source: async () => Buffer.from(await (await this.call('GET', `${id}/source?run=${job.run}`, { lease })).arrayBuffer()),
            putArtifact: (which, bytes) => this.call('PUT', `${id}/artifact/${which}?run=${job.run}`, { lease, raw: bytes, contentType: 'image/webp' }),
            beat: (b) => this.json('POST', `${id}/beat`, { lease, body: { ...base, ...b } }),
            render: async ({ seq, design }) => { const j = await this.json('POST', `${id}/render`, { lease, body: { ...base, seq, design } }); return { rendered: j.ok && j.candidate !== null && j.candidate !== undefined, candidate: j.candidate ?? null, issues: j.issues ?? [], image: image(j.image), code: j.code ?? null }; },
            faces: async ({ seq, lines, faces, size }) => { const j = await this.json('POST', `${id}/faces`, { lease, body: { ...base, seq, lines, faces, size } }); return { image: image(j.image), cells: j.cells ?? [], width: j.width, height: j.height, code: j.code ?? null }; },
            finish: (payload) => this.json('POST', `${id}/finish`, { lease, body: { ...base, ...payload } }),
            release: (payload) => this.json('POST', `${id}/release`, { lease, body: { ...base, ...payload } }),
        };
    }
}
