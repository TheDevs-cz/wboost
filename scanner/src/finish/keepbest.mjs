// Scanner-side keep-best: the kept candidate is chosen by measurement, never by the model's say-so
// (the prototype agent saved a non-best preview in 12 of 24 hard runs).
import { mae } from '../instruments/raster.mjs';
import { unionMask, maskShare } from '../adapters/mask.mjs';

/**
 * Candidates are compared on the SAME pixels: those no candidate covers with a placeholder (a
 * candidate must not win by turning more of the picture into a slot). When that common area is too
 * small to judge (photo-backed designs), the text-fit score decides instead.
 * → {candidate, by, scores: [{candidate, mae, commonMae, textFit}], commonShare, modelPreferred, agrees} | null
 */
export function keepBest(source, candidates, { preferred = null, blindShare = 0.8, epsilon = { common_mae: 0.0015, text_fit: 0.01 } } = {}) {
    if (!candidates.length) return null;
    const common = unionMask(candidates.map((c) => c.mask));
    const commonShare = maskShare(common);
    const scores = candidates.map((c) => ({ candidate: c.n, mae: c.mae, commonMae: +mae(source, c.raster, null, common).toFixed(5), textFit: c.textFit }));
    const by = commonShare < blindShare || scores.some((s) => s.textFit === null) ? 'common_mae' : 'text_fit';
    const value = (s) => (by === 'common_mae' ? s.commonMae : s.textFit);
    const best = Math.min(...scores.map(value));
    // the latest candidate that measures as good as the best: corrections are kept unless they made things worse
    const kept = [...scores].reverse().find((s) => value(s) <= best + epsilon[by]);
    return { candidate: kept.candidate, by, scores, commonShare: +commonShare.toFixed(3), modelPreferred: preferred, agrees: preferred === null ? null : preferred === kept.candidate };
}
