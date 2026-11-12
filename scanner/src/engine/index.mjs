// One interface, two backends. `run(options)` → {outcome, finish, text, usage, turns, apiMs, wallMs, error, facts}
//   outcome: finished | aborted | max_turns | max_budget | refusal | provider | error
import { runSdk } from './sdk.mjs';
import { runMessages } from './messages.mjs';

export const ENGINES = { sdk: runSdk, messages: runMessages };

export function engine(name = process.env.SCANNER_ENGINE ?? 'sdk') {
    const run = ENGINES[name];
    if (!run) throw new Error(`unknown engine "${name}" (sdk | messages)`);
    return { name, run };
}

/** User content blocks (Messages-API shape) from text and prepared images. */
export function userContent(parts) {
    return parts.map((p) => (typeof p === 'string'
        ? { type: 'text', text: p }
        : { type: 'image', source: { type: 'base64', media_type: p.mimeType, data: p.data.toString('base64') } }));
}

/** A 1-token request that tells "the provider is blocked" from "this scan failed" (architecture §7). */
export async function probe({ run, model, scratchDir, settings, signal }) {
    const r = await run({ model, effort: 'low', system: 'Answer with the single word: ok', content: [{ type: 'text', text: 'ok?' }], tools: [], dispatch: async () => ({ content: [], isError: true }), maxTurns: 1, scratchDir, settings, signal });
    return { ok: r.outcome === 'finished', outcome: r.outcome, error: r.error };
}
