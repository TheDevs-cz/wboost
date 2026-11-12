// The finish report of a template scan (architecture §4.7). Every field marked ⚙ there is written
// here from measurements; what the model says is kept only where the kept design backs it up.
// When the model never finished (a cap), the scanner writes the report alone: `unfinished: true`.
import * as im from '../instruments/raster.mjs';
import { elementBox } from '../adapters/template.mjs';
import { similarity, containment, family, normalise } from './text.mjs';
import { keepBest } from './keepbest.mjs';

export const ROLES = ['background', 'photo', 'logo', 'icon', 'pattern', 'illustration', 'other'];
export const SOURCE_CLASSES = ['sans', 'serif', 'slab', 'script', 'display', 'mono', 'unknown'];
export const APPROXIMATION_KINDS = ['rotation', 'shadow', 'outline', 'letter_spacing', 'mixed_styles', 'mask', 'blur', 'blend', 'gradient', 'curved_text', 'vector_path', 'corner_radius', 'opacity_estimate', 'other'];
export const OMITTED_KINDS = ['text', 'picture', 'shape', 'effect', 'other'];
export const LOW_CONFIDENCE_REASONS = ['small', 'low_contrast', 'stylised', 'partly_hidden', 'guessed'];

const plainName = (s, fallback) => (String(s ?? '').replace(/[\u0000-\u001f<>]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 40) || fallback);
const arr = (b) => (b ? [Math.round(b.x), Math.round(b.y), Math.round(b.w), Math.round(b.h)] : null);
const median = (v) => (v.length ? [...v].sort((a, b) => a - b)[v.length >> 1] : null);
const list = (v) => (Array.isArray(v) ? v : []);

/** OCR lines that are the source of one text element: by wording first, by place second. */
export function sourceLinesOf(element, ocrLines) {
    const eb = elementBox(element);
    const own = String(element.text ?? '').split('\n').map((s) => s.trim()).filter(Boolean);
    const out = [];
    for (const l of ocrLines) {
        const box = l.ink?.box ?? l.box;
        const sim = Math.max(...own.map((t) => Math.max(similarity(t, l.text), normalise(t).includes(normalise(l.text)) && normalise(l.text).length >= 3 ? 0.9 : 0)), 0);
        const near = eb ? im.coverage(box, im.grow(eb, 30)) : 0;
        if (sim >= 0.8 || (sim >= 0.5 && near >= 0.5)) out.push({ line: l, sim, near });
    }
    return out;
}

/**
 * @param build   TemplateBuild after the run (candidates, rankings, lines, paletteSnaps)
 * @param finish  the model's final report (validated against the finish schema) or null
 * @param rank    optional async (lines) → rankings, used to rank text groups the run left unranked
 */
export async function buildTemplateReport({ build, finish = null, unfinished = false, stopReason = null, rank = null }) {
    const preferred = Number.isInteger(finish?.preferredCandidate) ? finish.preferredCandidate : null;
    const kb = keepBest(build.source, build.candidates, { preferred });
    if (!kb) return { status: 'failed', error: 'no_candidate', unfinished, stopReason, candidate: null };
    const kept = build.candidates.find((c) => c.n === kb.candidate);
    const design = kept.design;
    const elements = list(design.elements);
    const byId = new Map(elements.map((e) => [e.id, e]));
    const texts = elements.filter((e) => e.kind === 'text');
    const ocrLines = build.lines ?? [];
    const available = build.settings.availableScore;

    // ---- places for pictures: rebuilt from the kept design; the model contributes the role only
    const roleOf = new Map(list(finish?.placeholders).filter((p) => byId.has(p.id)).map((p) => [p.id, ROLES.includes(p.role) ? p.role : 'other']));
    const placeholders = elements.filter((e) => e.kind === 'image' || (e.kind === 'background' && e.asset)).map((e) => ({
        id: e.id,
        name: plainName(e.input?.name ?? e.name, e.kind === 'background' ? 'Pozadí' : 'Obrázek'),
        role: e.kind === 'background' ? 'background' : (roleOf.get(e.id) ?? 'other'),
        fillable: e.kind === 'background' ? e.fillable !== false : !!e.input,
        box: e.kind === 'background' ? [0, 0, build.W, build.H] : arr(elementBox(e)),
    }));
    const unlistedPlaceholders = placeholders.filter((p) => !roleOf.has(p.id) && p.role !== 'background').map((p) => p.id);

    // ---- texts ↔ source lines
    const perText = new Map();
    for (const t of texts) perText.set(t.id, { element: t, src: sourceLinesOf(t, ocrLines) });

    // ---- low-confidence texts: measured reasons first, the model's own doubts added
    const lowConfidenceTexts = [];
    for (const [id, { element, src }] of perText) {
        if (!src.length) { lowConfidenceTexts.push({ id, reason: 'not_found_by_ocr', by: 'scanner' }); continue; }
        const conf = Math.min(...src.map((s) => s.line.conf));
        const joined = src.sort((a, b) => a.line.box.y - b.line.box.y || a.line.box.x - b.line.box.x).map((s) => s.line.text).join(' ');
        if (similarity(joined, element.text) < 0.9 && containment(joined, element.text) < 0.9) lowConfidenceTexts.push({ id, reason: 'differs_from_ocr', by: 'scanner' });
        else if (conf < 0.85) lowConfidenceTexts.push({ id, reason: 'ocr_low_confidence', by: 'scanner' });
    }
    for (const l of list(finish?.lowConfidenceTexts)) {
        if (byId.get(l.id)?.kind === 'text' && LOW_CONFIDENCE_REASONS.includes(l.reason) && !lowConfidenceTexts.some((x) => x.id === l.id)) lowConfidenceTexts.push({ id: l.id, reason: l.reason, by: 'model' });
    }

    // ---- fonts: groups come from the model (or from the faces used); every number is measured
    const groups = [];
    const grouped = new Set();
    for (const g of list(finish?.fonts)) {
        const ids = list(g.texts).filter((id) => byId.get(id)?.kind === 'text' && !grouped.has(id));
        if (!ids.length) continue;
        ids.forEach((id) => grouped.add(id));
        groups.push({ group: plainName(g.group, 'text'), texts: ids, sourceClass: SOURCE_CLASSES.includes(g.sourceClass) ? g.sourceClass : 'unknown', sourceLooksLike: plainName(g.sourceLooksLike, ''), by: 'model' });
    }
    const rest = texts.filter((t) => !grouped.has(t.id));
    for (const face of new Set(rest.map((t) => t.font))) {
        groups.push({ group: plainName(face, 'text'), texts: rest.filter((t) => t.font === face).map((t) => t.id), sourceClass: 'unknown', sourceLooksLike: '', by: 'scanner' });
    }
    const rankingFor = (t) => {
        const { src } = perText.get(t.id);
        const own = String(t.text ?? '').split('\n');
        return build.rankings.filter((r) => r.candidates?.length && (
            src.some((s) => im.iou(r.box, s.line.ink?.box ?? s.line.box) > 0.5)
            || own.some((line) => similarity(line, r.text) >= 0.8)));
    };
    // rank what the run left unranked (bounded by the scan's face-sheet budget)
    if (rank) {
        const missing = [];
        for (const g of groups) {
            if (g.texts.some((id) => rankingFor(byId.get(id)).length)) continue;
            const withInk = g.texts.map((id) => perText.get(id)).flatMap((p) => p.src.filter((s) => s.line.ink && s.sim >= 0.8)).sort((a, b) => b.line.text.length - a.line.text.length)[0];
            if (withInk) missing.push(withInk.line);
        }
        if (missing.length) {
            try {
                const results = await rank(missing.map((l) => ({ text: l.text, ink: l.ink })));
                missing.forEach((l, i) => build.rankings.push({ text: l.text, box: l.ink.box, group: null, origin: 'finish', line: l.id, ...results[i] }));
            } catch { /* the budget is used or the renderer is away: those groups stay unranked */ }
        }
    }
    const textFits = new Map(); // text id → {fitted, ratio}
    const fonts = groups.map((g) => {
        const faces = g.texts.map((id) => byId.get(id).font);
        const face = [...new Set(faces)].sort((a, b) => faces.filter((f) => f === b).length - faces.filter((f) => f === a).length)[0];
        const pooled = new Map(); let ranked = 0; const chosen = []; const ratios = [];
        for (const id of g.texts) {
            const t = byId.get(id);
            const rs = rankingFor(t);
            if (rs.length) ranked++;
            for (const r of rs) {
                for (const c of r.candidates) { const p = pooled.get(c.face) ?? { sum: 0, n: 0 }; p.sum += c.score; p.n++; pooled.set(c.face, p); }
                const own = r.candidates.find((c) => c.face === t.font);
                if (own) {
                    chosen.push(own.score);
                    // the fitted size belongs to the ranked LINE: compare only when this text is that line
                    if (String(t.text).split('\n').some((line) => similarity(line, r.text) >= 0.8) && own.size > 0 && !own.wrapped) {
                        const ratio = +(t.size / own.size).toFixed(3);
                        ratios.push(ratio); textFits.set(id, { fitted: own.size, ratio });
                    }
                }
            }
        }
        const top = [...pooled.entries()].map(([f, p]) => ({ face: f, score: p.sum / p.n })).sort((a, b) => b.score - a.score)[0] ?? null;
        const score = chosen.length ? +(chosen.reduce((a, b) => a + b, 0) / chosen.length).toFixed(3) : null;
        const matched = score !== null && score >= available;
        return {
            group: g.group, texts: g.texts, sourceClass: g.sourceClass, sourceLooksLike: g.sourceLooksLike,
            guessSupported: matched && !!g.sourceLooksLike && (normalise(g.sourceLooksLike).startsWith(family(face)) || family(face).startsWith(normalise(g.sourceLooksLike))),
            face, score, matched, sizeRatio: median(ratios),
            bestFace: top?.face ?? null, bestScore: top ? +top.score.toFixed(3) : null,
            mixedFaces: new Set(faces).size > 1, ranked, by: g.by,
        };
    });

    // ---- approximated and left-out elements: the model names them, the scanner measures the place
    const approximations = list(finish?.approximations).filter((a) => byId.has(a.element)).map((a) => ({ element: a.element, kind: APPROXIMATION_KINDS.includes(a.kind) ? a.kind : 'other' }));
    const clampBox = (o) => im.clipBox(build.source, { x: +o.x || 0, y: +o.y || 0, w: Math.max(1, +o.w || 1), h: Math.max(1, +o.h || 1) });
    const omitted = list(finish?.omitted).map((o) => { const b = clampBox(o); return { kind: OMITTED_KINDS.includes(o.kind) ? o.kind : 'other', x: b.x, y: b.y, w: b.w, h: b.h, diff: +im.mae(build.source, kept.masked, b).toFixed(4), by: 'model' }; });
    // an approximated background explains a difference anywhere
    const whole = { x: 0, y: 0, w: build.W, h: build.H };
    const explained = [...omitted.map((o) => o), ...approximations.map((a) => (byId.get(a.element).kind === 'background' ? whole : elementBox(byId.get(a.element)))).filter(Boolean)];
    for (const cell of kept.feedback.worstCells) {
        const b = { x: cell.box[0], y: cell.box[1], w: cell.box[2], h: cell.box[3] };
        if (cell.mae < 0.05 || explained.some((e) => im.intersects(e, b))) continue;
        omitted.push({ kind: 'other', x: b.x, y: b.y, w: b.w, h: b.h, diff: cell.mae, by: 'scanner' });
    }

    // ---- shrunk texts and the per-element boxes the review reads
    const shrunk = [...textFits.entries()].filter(([, f]) => f.ratio < 0.93).map(([id]) => ({ id }));
    const fb = new Map(kept.feedback.elements.map((r) => [r.id, r]));
    const boxes = elements.filter((e) => e.kind !== 'container').map((e) => {
        const row = fb.get(e.id) ?? {};
        const src = perText.get(e.id)?.src ?? [];
        return {
            id: e.id, kind: e.kind, box: e.kind === 'background' ? [0, 0, build.W, build.H] : arr(elementBox(e)),
            ...(e.kind === 'text' ? {
                ink: row.renderInk ?? null, sourceInk: row.sourceInk ?? null, colour: e.color ?? '#000000',
                lines: String(e.text ?? '').split('\n').length,
                sourceLineWidths: src.map((s) => s.line.ink?.w ?? s.line.box.w),
                fittedSize: textFits.get(e.id)?.fitted ?? null,
            } : {}),
        };
    });

    const headline = byId.get(finish?.headline)?.kind === 'text' ? finish.headline : ([...texts].sort((a, b) => (b.size ?? 0) - (a.size ?? 0))[0]?.id ?? null);
    return {
        status: 'proposed', unfinished, stopReason, candidate: kept.n, headline,
        placeholders, fonts, approximations, omitted, lowConfidenceTexts, shrunk, paletteSnaps: build.paletteSnaps,
        boxes,
        measures: { mae: kept.mae, textFit: kept.textFit, placeholderShare: kept.placeholderShare, keepBest: kb, unlistedPlaceholders, warnings: kept.issues.filter((i) => i.severity !== 'error').length },
        originalColours: build.prepassResult?.palette ?? [],
    };
}
