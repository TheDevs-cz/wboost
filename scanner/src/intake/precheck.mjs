// Intake, step 2: the pre-check. One short Haiku call that looks at the preview and says what the
// upload is; the scanner maps the verdict onto the stop reasons of architecture §5.6.
import { prepareImage } from '../tools/images.mjs';
import { userContent } from '../engine/index.mjs';

export const VERDICTS = ['design', 'calendar_page', 'photo_of_print', 'several_designs', 'blurry_or_small', 'not_a_design'];

/** verdict × expected kind → {pass} | {stop, continuable} */
export function decide(verdict, kind) {
    switch (verdict) {
        case 'design': return kind === 'calendar' ? { stop: 'not_expected_kind', continuable: false } : { pass: true };
        case 'calendar_page': return { pass: true }; // a calendar page is also a design a template can be built from
        case 'photo_of_print': return { stop: 'photo_of_print', continuable: true };
        case 'several_designs': return { stop: 'several_designs', continuable: false };
        case 'blurry_or_small': return { stop: 'blurry_or_small', continuable: false };
        case 'not_a_design': return { stop: 'not_expected_kind', continuable: false };
        default: return { stop: 'unusable_input', continuable: false };
    }
}

/**
 * @param run     an engine's run()
 * @param files   the definition files (name → content)
 * → {verdict, confidence, decision, engine: {outcome, error, usage, wallMs, apiMs, turns}}
 * A pre-check that could not be made (provider error) returns verdict null and decision {error}.
 */
export async function precheck({ run, definition, files, preview, kind, scratchDir, signal = null, prices, approximate = false }) {
    // the pre-check has its own request settings (§4.9: 18 s, 1 retry after 2 s) and a hard bound of 38 s
    const pcs = definition.preCheck ?? {};
    const settings = { requestTimeoutSeconds: pcs.requestTimeoutSeconds ?? 18, retries: pcs.retries ?? 1, retryDelaysSeconds: pcs.retryDelaysSeconds ?? [2], streamIdleSeconds: pcs.requestTimeoutSeconds ?? 18, maxOutputTokens: 1024 };
    const bound = new AbortController();
    const timer = setTimeout(() => bound.abort(), (pcs.maxSeconds ?? 38) * 1000); timer.unref?.();
    if (signal?.aborted) bound.abort(); else signal?.addEventListener('abort', () => bound.abort(), { once: true });
    const img = await prepareImage(preview, { longEdgePx: definition.preCheck?.longEdgePx ?? 1568, maxBytes: 1_500_000 });
    const r = await run({
        model: definition.models.preCheck, effort: definition.effort?.preCheck ?? 'low',
        system: files[definition.preCheck?.system ?? 'precheck.system.md'],
        content: userContent([img, `Expected: ${kind === 'calendar' ? 'one page of a weekly desk calendar' : 'one finished graphic design'}. Classify the picture.`]),
        tools: [], dispatch: async () => ({ content: [], isError: true }),
        finishSchema: JSON.parse(files[definition.preCheck?.schema ?? 'precheck.schema.json']),
        maxTurns: 3, maxBudgetUsd: 0.05, scratchDir, signal: bound.signal, settings, prices,
    }).finally(() => { clearTimeout(timer); });
    const engine = { outcome: r.outcome, error: r.error, usage: r.usage, wallMs: r.wallMs, apiMs: r.apiMs, turns: r.turns, facts: r.facts };
    if (r.outcome === 'refusal') return { verdict: null, confidence: null, decision: { refused: true }, engine };
    if (r.outcome !== 'finished' || !VERDICTS.includes(r.finish?.verdict)) return { verdict: null, confidence: null, decision: { error: r.error ?? { class: 'transient', code: r.outcome === 'aborted' ? 'precheck_timeout' : 'precheck_unreadable' } }, engine };
    let decision = decide(r.finish.verdict, kind);
    // "Zkusit přesto": the user continues after a photo-of-a-print stop — the scan runs and is marked approximate
    if (approximate && decision.stop === 'photo_of_print') decision = { pass: true, approximate: true };
    return { verdict: r.finish.verdict, confidence: r.finish.confidence ?? null, decision, engine };
}
