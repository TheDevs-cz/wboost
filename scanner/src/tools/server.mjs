// The tool server of one run: the only thing the model can reach. It executes a tool by name,
// enforces the image rule and the per-tool timeouts, and never throws — a hung or failing tool is a
// tool error the model reads, not silence (architecture §4.5).
import { prepareImage, DEFAULT_IMAGE_RULE } from './images.mjs';

/** An error whose message is meant for the model (wrong arguments, a refused action). */
export class ToolUserError extends Error {}

const text = (o) => ({ type: 'text', text: typeof o === 'string' ? o : JSON.stringify(o) });

function withTimeout(promise, ms, name) {
    let timer;
    const timeout = new Promise((_, reject) => { timer = setTimeout(() => reject(Object.assign(new Error(`${name} did not answer within ${Math.round(ms / 1000)} s`), { code: 'tool_timeout' })), ms); });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

export class ToolServer {
    /**
     * @param definitions  tool definitions in Messages-API shape: [{name, description, input_schema}]
     * @param impl         {name: async (args) => {json, images?: [Buffer | raster], isError?}}
     * @param appTools     names of tools that call the app (longer timeout)
     */
    constructor({ definitions, impl, caps, imageRule = DEFAULT_IMAGE_RULE, timeouts = { localMs: 30_000, appMs: 95_000 }, appTools = [], log = null, finishHintMs = 45_000 }) {
        this.definitions = definitions.filter((d) => impl[d.name]);
        this.impl = impl; this.caps = caps; this.imageRule = imageRule; this.timeouts = timeouts;
        this.appTools = new Set(appTools); this.log = log; this.finishHintMs = finishHintMs;
        this.chain = Promise.resolve();
        this.calls = [];
    }

    /** Executes one tool call → MCP `{content, isError}`. Calls run one at a time: previews mutate the working copy. */
    dispatch(name, args) {
        const run = this.chain.then(() => this.#run(name, args ?? {}));
        this.chain = run.catch(() => {});
        return run;
    }

    async #run(name, args) {
        const started = Date.now();
        let out, internal = false, images = 0, dropped = 0;
        try {
            const fn = this.impl[name];
            if (!fn || !this.definitions.some((d) => d.name === name)) throw new ToolUserError(`unknown tool ${name}`);
            const r = await withTimeout(Promise.resolve().then(() => fn(args)), this.appTools.has(name) ? this.timeouts.appMs : this.timeouts.localMs, name);
            const json = { ...(r.json ?? {}) };
            const blocks = [];
            for (const img of r.images ?? []) {
                if (this.caps.takeImages(1) < 1) { dropped++; continue; }
                const p = await prepareImage(img, this.imageRule);
                blocks.push({ type: 'image', data: p.data.toString('base64'), mimeType: p.mimeType });
                if (p.scale < 1) json.imageScale = +p.scale.toFixed(4);
                images++;
            }
            if (dropped) json.imageBudget = 'image budget used — work from the numbers';
            json.left = this.#left();
            out = { content: [text(json), ...blocks], isError: !!r.isError };
        } catch (e) {
            internal = !(e instanceof ToolUserError);
            out = { content: [text({ error: e.message, ...(e.code ? { code: e.code } : {}), left: this.#left() })], isError: true };
        }
        const record = { t: new Date().toISOString(), tool: name, ms: Date.now() - started, isError: out.isError, internal, images, dropped };
        this.calls.push(record);
        this.log?.(record, args, out);
        return out;
    }

    #left() {
        const s = Math.max(0, Math.round(this.caps.builderLeftMs() / 1000));
        const left = { candidates: this.caps.candidatesLeft(), images: this.caps.imagesLeft(), seconds: s };
        if (this.caps.builderLeftMs() < this.finishHintMs) left.note = 'time is nearly up — return the final report now';
        return left;
    }
}
