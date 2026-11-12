// The local app behind `scan-file` and the evaluation: the app's own MCP design tools on /_mcp.
// It renders a candidate with `preview_design` (nothing is saved) and renders face sheets the same
// way. This driver exists for development; production talks to /internal/scanner/* (protocol.mjs).
import sharp from 'sharp';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadRgb } from '../instruments/raster.mjs';

const run = promisify(execFile);
const HERE = dirname(fileURLToPath(import.meta.url));
const SCRATCH = join(HERE, '..', '..', '.scratch');

/** Minimal streamable-HTTP MCP client. */
export class McpClient {
    constructor(url, token) { this.url = url; this.token = token; this.session = null; this.id = 1; this.ready = false; }
    async rpc(method, params, notification = false) {
        const body = { jsonrpc: '2.0', method, ...(params === undefined ? {} : { params }) };
        if (!notification) body.id = this.id++;
        const res = await fetch(this.url, {
            method: 'POST', signal: AbortSignal.timeout(60_000),
            headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...(this.session ? { 'Mcp-Session-Id': this.session } : {}) },
            body: JSON.stringify(body),
        });
        this.session = res.headers.get('mcp-session-id') ?? this.session;
        const text = await res.text();
        if (notification) return null;
        if (!res.ok) throw Object.assign(new Error(`app MCP HTTP ${res.status}: ${text.slice(0, 200)}`), { code: 'renderer_unavailable', status: res.status });
        const payload = (res.headers.get('content-type') ?? '').includes('text/event-stream')
            ? text.split('\n').filter((l) => l.startsWith('data:')).map((l) => l.slice(5).trim()).filter(Boolean).pop() : text;
        const msg = JSON.parse(payload);
        if (msg.error) throw new Error(`app MCP error ${msg.error.code}: ${msg.error.message}`);
        return msg.result;
    }
    /** → {json (first text block parsed), image {data, mimeType} | null, isError} */
    async call(name, args) {
        if (!this.ready) {
            await this.rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'wboost-scanner', version: '0' } });
            await this.rpc('notifications/initialized', undefined, true);
            this.ready = true;
        }
        const result = await this.rpc('tools/call', { name, arguments: args });
        let json = null, image = null;
        for (const b of result.content ?? []) {
            if (b.type === 'text' && json === null) { try { json = JSON.parse(b.text); } catch { json = { text: b.text }; } }
            if (b.type === 'image') image = { data: Buffer.from(b.data, 'base64'), mimeType: b.mimeType };
        }
        return { json, image, isError: !!result.isError };
    }
}

const cache = (name) => {
    const file = join(SCRATCH, name);
    const read = () => (existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {});
    return { read, write: (v) => { mkdirSync(SCRATCH, { recursive: true }); writeFileSync(file, JSON.stringify(v, null, 2)); } };
};

/** A neutral stand-in picture: grey with a cross. */
export async function standInPng(size = 800) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"><rect width="100%" height="100%" fill="#c9ccd1"/><path d="M0 0L${size} ${size}M${size} 0L0 ${size}" stroke="#a9adb4" stroke-width="${size / 100}"/></svg>`;
    return sharp(Buffer.from(svg)).png().toBuffer();
}

/** Lays (line × face) cells out on pages of the canvas size, one font size for the call. */
export function layoutFaceSheets(lines, faces, size, { width, height }) {
    const pad = 10;
    const pages = [];
    let cells = [], y = pad;
    const flush = () => { if (cells.length) pages.push(cells); cells = []; y = pad; };
    lines.forEach((text, li) => {
        const len = Math.max(1, [...text].length);
        let s = size;
        const need = (v) => Math.ceil(0.74 * v * len) + 2 * pad;
        if (need(s) > width - 2 * pad) s = Math.max(8, Math.floor((width - 4 * pad) / (0.74 * len)));
        const cellW = Math.min(width - 2 * pad, need(s));
        const cols = Math.max(1, Math.floor((width - 2 * pad) / cellW));
        const rowH = Math.ceil(s * 1.5);
        faces.forEach((face, fi) => {
            const col = fi % cols;
            if (col === 0 && fi > 0) y += rowH;
            if (y + rowH > height - pad) flush();
            cells.push({ face, line: li, x: pad + col * cellW, y, width: cellW, height: rowH, fontSize: s, text });
        });
        y += rowH;
        if (y + rowH > height - pad) flush();
    });
    flush();
    return pages;
}

const FACE_SHEET = { width: 1200, height: 1200 };

export class McpApp {
    /**
     * @param reservedStandIn  the asset id the scanner writes into designs; replaced by a real gallery
     *                         picture of the project before the design reaches the app
     */
    constructor({ url = process.env.WBOOST_MCP_URL ?? 'http://localhost:8099/_mcp', token, projectId, variantId = null, reservedStandIn, container = process.env.SCANNER_DEV_CONTAINER ?? null, slot = 0 }) {
        Object.assign(this, { url, projectId, variantId, reservedStandIn, container, slot });
        this.mcp = new McpClient(url, token);
        this.standIn = null; this.canvas = null; this.renders = 0; this.renderMs = 0;
    }

    /** An empty variant of the canvas size in the project (cached per project, size and slot). */
    async ensureVariant(width, height) {
        this.canvas = { width, height };
        // face sheets are drawn on a variant of their own: 1200 px is the largest picture preview_design returns 1:1
        this.facesVariantId = await this.#variant(FACE_SHEET.width, FACE_SHEET.height);
        if (this.variantId) return this.variantId;
        return (this.variantId = await this.#variant(width, height));
    }

    async #variant(width, height) {
        const store = cache('variants.json'), all = store.read();
        const key = `${this.url}|${this.projectId}|${width}x${height}|${this.slot}`;
        if (!all[key]) {
            if (!this.container) throw new Error('no variant given: pass --variant, or set SCANNER_DEV_CONTAINER so the dev helper can create one');
            const script = readFileSync(join(HERE, '..', '..', 'dev', 'new-variant.php'));
            const child = run('docker', ['exec', '-i', this.container, 'sh', '-c', `cat > /tmp/scanner-new-variant.php && php /tmp/scanner-new-variant.php ${this.projectId} "AI scan scratch ${width}x${height} #${this.slot}" ${width} ${height}`], { maxBuffer: 8 << 20 });
            child.child.stdin.end(script);
            const { stdout } = await child;
            all[key] = JSON.parse(stdout.trim().split('\n').pop()).variantId;
            store.write(all);
        }
        return all[key];
    }

    async #standIn() {
        if (this.standIn) return this.standIn;
        const store = cache('standins.json'), all = store.read();
        const key = `${this.url}|${this.projectId}`;
        if (!all[key]) {
            const up = await this.mcp.call('upload_image', { projectId: this.projectId, imageBase64: (await standInPng()).toString('base64'), filename: 'zastupny-obrazek.png' });
            const id = up.json?.imageId ?? up.json?.id;
            if (!id) throw new Error(`the stand-in picture could not be uploaded: ${JSON.stringify(up.json).slice(0, 200)}`);
            all[key] = id; store.write(all);
        }
        return (this.standIn = all[key]);
    }

    /** Fonts and brand colours of the project, in the shape of a claim's `context`. */
    async context() {
        const r = await this.mcp.call('get_context', {});
        const project = (r.json?.projects ?? []).find((p) => p.id === this.projectId) ?? r.json?.project ?? null;
        const fonts = project?.fonts ?? r.json?.fonts ?? [];
        const faces = fonts.flatMap((f) => (typeof f === 'string' ? [f] : (f.faces ?? []).map((face) => (typeof face === 'string' ? face : face.family ?? `${f.name} (${face.name})`))));
        const colours = (project?.colors ?? project?.palette ?? []).map((c) => (typeof c === 'string' ? c : c.hex ?? c.color)).filter((c) => /^#[0-9a-f]{6}$/i.test(String(c)));
        return { fonts: faces, palette: colours, standInAssetId: this.reservedStandIn };
    }

    async #withStandIn(design) {
        const text = JSON.stringify(design);
        if (!this.reservedStandIn || !text.includes(this.reservedStandIn)) return design;
        return JSON.parse(text.split(this.reservedStandIn).join(await this.#standIn()));
    }

    /** One candidate render: validate + render, nothing saved. */
    async render(design, variantId = this.variantId) {
        const t0 = Date.now();
        const res = await this.mcp.call('preview_design', { variantId, design: await this.#withStandIn(design) });
        this.renders++; this.renderMs += Date.now() - t0;
        const j = res.json ?? {};
        return { rendered: !!j.rendered && !!res.image, status: j.status ?? null, issues: j.issues ?? [], image: res.image, code: null };
    }

    /** The "faces" call: the lines in each face, as one stitched raster with its cells. */
    async faces({ lines, faces, size }) {
        const { width, height } = FACE_SHEET;
        const pages = layoutFaceSheets(lines, faces, size, FACE_SHEET);
        const rasters = [];
        for (const cells of pages) {
            const design = { canvas: { width, height }, elements: [{ kind: 'background', id: 'bg', fill: '#ffffff' },
                ...cells.map((c, i) => ({ kind: 'text', id: `p${i}`, text: c.text, font: c.face, size: c.fontSize, color: '#000000', lineHeight: 1, x: c.x, y: c.y + Math.round(c.fontSize * 0.12), width: c.width - 4 }))] };
            const res = await this.render(design, this.facesVariantId);
            if (!res.rendered) throw Object.assign(new Error(`face sheet did not render: ${JSON.stringify(res.issues).slice(0, 300)}`), { code: 'renderer_unavailable' });
            rasters.push(await loadRgb(res.image.data, width, height));
        }
        const data = Buffer.concat(rasters.map((r) => r.data));
        return {
            raster: { data, width, height: height * pages.length }, sheets: pages.length,
            cells: pages.flatMap((cells, p) => cells.map(({ text, ...c }) => ({ ...c, y: c.y + p * height }))),
        };
    }
}
