// Usage accounting: token counts per model, priced by the definition's table (USD per million tokens).
// The engine's own cost figure is kept next to it, never instead of it.

/** Prices used when a definition carries none (list prices of 2026-11-03, USD / MTok). */
export const LIST_PRICES = Object.freeze({
    version: '2026-11-03',
    models: {
        'claude-opus-5-5': { input: 4, output: 20, cacheRead: 0.2, cacheWrite: 5, cacheWrite1h: 8 },
        'claude-opus-4-8': { input: 5, output: 25, cacheRead: 0.5, cacheWrite: 6.25, cacheWrite1h: 10 }, // the harness may re-run a refused turn on it
        'claude-sonnet-5-5': { input: 2, output: 10, cacheRead: 0.1, cacheWrite: 2.5, cacheWrite1h: 4 },
        'claude-haiku-5-5': { input: 0.1, output: 0.5, cacheRead: 0.01, cacheWrite: 0.125, cacheWrite1h: 0.2 },
    },
});

export const emptyUsage = () => ({ input: 0, output: 0, cacheRead: 0, cacheWrite5m: 0, cacheWrite1h: 0 });

/** Messages-API usage object → the scanner's shape. Cache writes of unknown lifetime count as 5-minute. */
export function fromApiUsage(u = {}) {
    const total = u.cache_creation_input_tokens ?? 0;
    const h = u.cache_creation?.ephemeral_1h_input_tokens ?? 0;
    return { input: u.input_tokens ?? 0, output: u.output_tokens ?? 0, cacheRead: u.cache_read_input_tokens ?? 0, cacheWrite5m: Math.max(0, total - h), cacheWrite1h: h };
}
/** Agent SDK `modelUsage` entry → the scanner's shape. */
export function fromModelUsage(m = {}) {
    const total = m.cacheCreationInputTokens ?? 0;
    const h = m.cacheCreationEphemeral1hInputTokens ?? 0;
    return { input: m.inputTokens ?? 0, output: m.outputTokens ?? 0, cacheRead: m.cacheReadInputTokens ?? 0, cacheWrite5m: Math.max(0, total - h), cacheWrite1h: h };
}
export function addUsage(a, b) {
    return { input: a.input + b.input, output: a.output + b.output, cacheRead: a.cacheRead + b.cacheRead, cacheWrite5m: a.cacheWrite5m + b.cacheWrite5m, cacheWrite1h: a.cacheWrite1h + b.cacheWrite1h };
}

const stripDate = (model) => String(model).replace(/-\d{8}$/, '').replace(/\[.*\]$/, '');

/**
 * → {costUsd, costUsd5m, unknownModels}. `costUsd` prices cache writes by their real lifetime;
 * `costUsd5m` prices every cache write at the 5-minute rate (what a key run without the 1-hour
 * request would pay) — the prototypes' easy-set figures used that basis.
 */
export function priceUsage(byModel, prices = LIST_PRICES) {
    let costUsd = 0, costUsd5m = 0; const unknownModels = [];
    for (const [model, u] of Object.entries(byModel)) {
        const p = prices.models?.[model] ?? prices.models?.[stripDate(model)];
        if (!p) { unknownModels.push(model); continue; }
        const base = u.input * p.input + u.output * p.output + u.cacheRead * (p.cacheRead ?? 0);
        costUsd += base + u.cacheWrite5m * (p.cacheWrite ?? 0) + u.cacheWrite1h * (p.cacheWrite1h ?? p.cacheWrite ?? 0);
        costUsd5m += base + (u.cacheWrite5m + u.cacheWrite1h) * (p.cacheWrite ?? 0);
    }
    return { costUsd: +(costUsd / 1e6).toFixed(6), costUsd5m: +(costUsd5m / 1e6).toFixed(6), unknownModels };
}

/**
 * Running usage while a run is in flight: the engine reports usage per API message, several times
 * while it streams, so the latest figure per message id replaces the earlier one.
 */
export class UsageMeter {
    constructor(prices = LIST_PRICES) { this.prices = prices; this.messages = new Map(); this.final = null; }
    note(messageId, model, apiUsage) {
        if (!messageId || !apiUsage) return;
        const u = fromApiUsage(apiUsage);
        const prev = this.messages.get(messageId);
        // while streaming, output grows; keep the larger figure
        if (!prev || u.output >= prev.usage.output) this.messages.set(messageId, { model: stripDate(model ?? prev?.model ?? 'unknown'), usage: u });
    }
    /** The engine's authoritative totals, when it gave them (the SDK's result message). */
    setFinal(byModel) { this.final = byModel; }
    byModel() {
        if (this.final) return this.final;
        const out = {};
        for (const { model, usage } of this.messages.values()) out[model] = addUsage(out[model] ?? emptyUsage(), usage);
        return out;
    }
    estimate() {
        const out = {};
        for (const { model, usage } of this.messages.values()) out[model] = addUsage(out[model] ?? emptyUsage(), usage);
        return out;
    }
    turns() { return this.messages.size; }
    snapshot() {
        const byModel = this.byModel();
        return { byModel, ...priceUsage(byModel, this.prices), priceVersion: this.prices.version, requests: this.messages.size, final: !!this.final };
    }
}
