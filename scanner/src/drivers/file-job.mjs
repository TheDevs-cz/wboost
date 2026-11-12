// The local "file job" driver: one scan job made from a picture on disk. It implements the same
// JobApi as the app's scanner endpoints (protocol.mjs), rendering through the app's MCP design tools
// and writing every artefact into an output directory instead of the app's storage.
import { readFileSync, writeFileSync, appendFileSync, mkdirSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import sharp from 'sharp';
import { McpApp } from './mcp-app.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
export const DEFINITION_DIR = resolve(HERE, '..', '..', '..', 'resources', 'ai-scan', 'definition');
export const LOCAL_STAND_IN = '00000000-0000-4000-8000-0000005ca900';

/** The definition as a claim would carry it: every file of the directory, name → content. */
export function definitionFiles(dir = DEFINITION_DIR) {
    return Object.fromEntries(readdirSync(dir).sort().map((f) => [f, readFileSync(join(dir, f), 'utf8')]));
}

export class FileJobApi {
    constructor({ image, kind = 'template', projectId, variantId = null, outDir, token, mcpUrl, definitionDir = DEFINITION_DIR, approximate = false, slot = 0, container }) {
        this.image = resolve(image); this.kind = kind; this.outDir = resolve(outDir); this.approximate = approximate;
        mkdirSync(join(this.outDir, 'candidates'), { recursive: true });
        this.files = definitionFiles(definitionDir);
        this.app = new McpApp({ url: mcpUrl, token, projectId, variantId, reservedStandIn: LOCAL_STAND_IN, slot, container });
        this.id = randomUUID(); this.run = 0; this.state = 'checking'; this.candidates = []; this.beats = 0; this.result = {};
    }

    #job(lane) {
        return { id: this.id, kind: this.kind, lane, run: ++this.run, try: 1, approximate: this.approximate, leaseToken: 'local', options: {}, source: {}, definition: { version: 'local', files: this.files } };
    }

    /** → {job, mode, reason, api}: the intake job first, then — once intake passed — the build job. */
    async claim(lane) {
        if (lane === 'intake' && this.state === 'checking') { this.state = 'intake'; return { job: this.#job('intake'), mode: 'on', reason: null, api: this.#api('intake') }; }
        if (lane === 'build' && this.state === 'queued') {
            this.state = 'running';
            const job = this.#job('build');
            const meta = await sharp(join(this.outDir, 'working.webp')).metadata();
            await this.app.ensureVariant(meta.width, meta.height);
            job.context = await this.app.context();
            return { job, mode: 'on', reason: null, api: this.#api('build') };
        }
        return { job: null, mode: 'on', reason: null, api: null };
    }
    async tick() { return { ok: true }; }

    #api(lane) {
        const self = this;
        return {
            async source() { return readFileSync(lane === 'intake' ? self.image : join(self.outDir, 'working.webp')); },
            async putArtifact(which, bytes) { writeFileSync(join(self.outDir, `${which}.webp`), bytes); },
            async beat(b) { self.beats++; appendFileSync(join(self.outDir, 'beats.jsonl'), JSON.stringify({ t: new Date().toISOString(), lane, ...b }) + '\n'); return { ok: true, cancel: false, mode: 'on' }; },
            async render({ seq, design }) {
                const res = await self.app.render(design);
                if (res.rendered) {
                    const n = self.candidates.length + 1, tag = String(n).padStart(2, '0');
                    self.candidates.push({ n, seq });
                    writeFileSync(join(self.outDir, 'candidates', `${tag}.design.json`), JSON.stringify(design, null, 2));
                    writeFileSync(join(self.outDir, 'candidates', `${tag}.${res.image.mimeType === 'image/png' ? 'png' : 'webp'}`), res.image.data);
                    return { ...res, candidate: n };
                }
                return { ...res, candidate: null };
            },
            async faces(req) { return self.app.faces(req); },
            async finish(payload) {
                self.result[lane] = payload;
                writeFileSync(join(self.outDir, `finish.${lane}.json`), JSON.stringify(payload, null, 2));
                if (lane === 'intake') self.state = payload.outcome === 'passed' ? 'queued' : 'stopped';
                else self.state = payload.outcome === 'proposed' ? 'ready' : payload.outcome;
                return { ok: true };
            },
            async release(payload) { self.state = lane === 'intake' ? 'checking' : 'queued'; writeFileSync(join(self.outDir, `release.${lane}.json`), JSON.stringify(payload, null, 2)); return { ok: true }; },
        };
    }
}
