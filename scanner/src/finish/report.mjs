// The finish report of a template scan (architecture §4.7). Every field marked ⚙ there is written
// here from measurements; what the model says is kept only where the kept design backs it up.
// When the model never finished (a cap), the scanner writes the report alone: `unfinished: true`.
import * as im from '../instruments/raster.mjs';
import { elementBox } from '../adapters/template.mjs';
import { similarity, containment, family, normalise } from './text.mjs';
import { keepBest } from './keepbest.mjs';
import { verifiedMatch, verificationThresholds } from '../instruments/fonts.mjs';

export const ROLES = ['background', 'photo', 'logo', 'icon', 'pattern', 'illustration', 'other'];
export const SOURCE_CLASSES = ['sans', 'serif', 'slab', 'script', 'display', 'mono', 'unknown'];
export const APPROXIMATION_KINDS = ['rotation', 'shadow', 'outline', 'letter_spacing', 'mixed_styles', 'mask', 'blur', 'blend', 'gradient', 'curved_text', 'vector_path', 'corner_radius', 'opacity_estimate', 'other'];
export const OMITTED_KINDS = ['text', 'picture', 'shape', 'effect', 'other'];
export const FONT_ROLES = ['headline', 'subheadline', 'body', 'button', 'caption', 'other'];
export const FONT_PATTERNS = ['measured_match', 'nearest_available', 'undetermined'];
/** Simplifications the review names (architecture §5.6): what the model reports as an approximation → the shared code. */
export const SIMPLIFICATION_OF = { shadow: 'shadow_omitted', rotation: 'rotation_straightened', outline: 'outline_filled', letter_spacing: 'letter_spacing_approximate', mixed_styles: 'mixed_styles', gradient: 'gradient_approximate' };
export const SIMPLIFICATION_CODES = [...Object.values(SIMPLIFICATION_OF), 'pattern_as_place'];
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
 * The lines a text group is verified on: its own source lines (OCR lines matched to its texts by wording and
 * place), the longest first, at most `max` — each as {text, ink, face, size}: the wording of the design where
 * a design line is that source line (the model corrected what OCR misread), the face and size the design gives
 * the text. The verification render compares exactly what the template would print with what the picture shows.
 */
export function verificationLines(texts, srcOf, max = 2) {
    const count = (t) => [...String(t).replace(/\s/g, '')].length;
    const usable = [];
    for (const t of texts) {
        // a tracked-out text is not verified: the face sheet sets the line without letter spacing
        if (!t || t.kind !== 'text' || !t.font || !(t.size > 0) || (t.letterSpacing ?? 0) !== 0) continue;
        const own = String(t.text ?? '').split('\n').map((l) => l.trim()).filter(Boolean);
        for (const s of srcOf(t.id)) {
            if (!s.line?.ink || s.line.ink.n < 30) continue;
            const exact = own.map((line) => ({ line, sim: similarity(line, s.line.text) })).sort((p, q) => q.sim - p.sim)[0];
            // a design line that IS this source line → its wording; else the OCR wording when OCR was sure (a wrapped paragraph)
            const text = exact && exact.sim >= 0.9 ? exact.line : (s.sim >= 0.8 && s.line.conf >= 0.9 ? s.line.text : null);
            if (!text || count(text) < 2) continue;
            usable.push({ text, ink: s.line.ink, face: t.font, size: t.size, glyphs: count(text) });
        }
    }
    const seen = new Set();
    return usable.sort((a, b) => b.glyphs - a.glyphs).filter((u) => { const k = `${u.face}|${u.text}`; if (seen.has(k)) return false; seen.add(k); return true; }).slice(0, max);
}

/**
 * @param build   TemplateBuild after the run (candidates, rankings, lines, paletteSnaps)
 * @param finish  the model's final report (validated against the finish schema) or null
 * @param rank    optional async (lines) → rankings, used to rank text groups the run left unranked
 * @param verify  optional async ([{text, ink, face, size}]) → [{score} | null]: the verification render — the
 *                text in the chosen face at the fitted size against the source line, 1 : 1. With it,
 *                `measured_match` / `nearest_available` is decided by that measurement
 */
export async function buildTemplateReport({ build, finish = null, unfinished = false, stopReason = null, rank = null, verify = null }) {
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
    for (const l of lowConfidenceTexts) l.box = arr(perText.get(l.id).src[0]?.line.ink?.box ?? perText.get(l.id).src[0]?.line.box ?? elementBox(byId.get(l.id)));
    for (const l of list(finish?.lowConfidenceTexts)) {
        if (byId.get(l.id)?.kind === 'text' && LOW_CONFIDENCE_REASONS.includes(l.reason) && !lowConfidenceTexts.some((x) => x.id === l.id)) lowConfidenceTexts.push({ id: l.id, reason: l.reason, by: 'model', box: arr(perText.get(l.id).src[0]?.line.ink?.box ?? elementBox(byId.get(l.id))) });
    }

    // ---- fonts: groups come from the model (or from the faces used); every number is measured
    const groups = [];
    const grouped = new Set();
    for (const g of list(finish?.fonts)) {
        const ids = list(g.texts).filter((id) => byId.get(id)?.kind === 'text' && !grouped.has(id));
        if (!ids.length) continue;
        ids.forEach((id) => grouped.add(id));
        groups.push({ group: plainName(g.group, 'text'), role: FONT_ROLES.includes(g.role) ? g.role : 'other', texts: ids, sourceClass: SOURCE_CLASSES.includes(g.sourceClass) ? g.sourceClass : 'unknown', sourceLooksLike: plainName(g.sourceLooksLike, ''), by: 'model' });
    }
    const rest = texts.filter((t) => !grouped.has(t.id));
    for (const face of new Set(rest.map((t) => t.font))) {
        groups.push({ group: plainName(face, 'text'), role: 'other', texts: rest.filter((t) => t.font === face).map((t) => t.id), sourceClass: 'unknown', sourceLooksLike: '', by: 'scanner' });
    }
    // a ranking belongs to a text when it ranked one of the text's source lines, or — for a line the model
    // ranked itself — when the wording is that text's AND the line lies where the text is (the same words in
    // another style elsewhere on the page are another group's evidence)
    const rankingFor = (t) => {
        const { src } = perText.get(t.id);
        const own = String(t.text ?? '').split('\n');
        const eb = elementBox(t);
        return build.rankings.filter((r) => r.candidates?.length && (
            src.some((s) => im.iou(r.box, s.line.ink?.box ?? s.line.box) > 0.5)
            || (own.some((line) => similarity(line, r.text) >= 0.8) && (!eb || im.coverage(r.box, im.grow(eb, 30)) >= 0.5))));
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
                missing.forEach((l, i) => build.rankings.push({ text: l.text, box: l.ink.box, ink: l.ink, group: null, origin: 'finish', line: l.id, ...results[i] }));
            } catch { /* the budget is used or the renderer is away: those groups stay unranked */ }
        }
    }
    // the group that holds the headline text is the headline group, whatever the model called it
    const headlineId = byId.get(finish?.headline)?.kind === 'text' ? finish.headline : ([...texts].sort((a, b) => (b.size ?? 0) - (a.size ?? 0))[0]?.id ?? null);
    for (const g of groups) if (g.texts.includes(headlineId)) g.role = 'headline';
    // ---- the verification render: the group's own source lines, set in the face the kept design uses at the
    //      size the design gives them, compared with the source pixels (instruments/fonts.mjs verifyFaces)
    const verification = new Map(); // group → the best verified line {score, glyphs, size, scale}
    const thresholds = verificationThresholds(build.settings);
    const stats = { groups: groups.length, asked: 0, verified: 0, failed: null };
    if (verify) {
        const asks = groups.flatMap((g) => verificationLines(g.texts.map((id) => byId.get(id)), (id) => perText.get(id)?.src ?? []).map((line) => ({ g, ...line })));
        stats.asked = asks.length;
        if (asks.length) {
            try {
                const results = await verify(asks.map(({ text, ink, face, size }) => ({ text, ink, face, size })));
                asks.forEach((a, i) => {
                    const r = results[i];
                    if (!r || r.wrapped) return;
                    const best = verification.get(a.g);
                    // any line of the group that IS the face proves the face is there; otherwise the best line speaks
                    if (!best || (verifiedMatch(r, thresholds) && !verifiedMatch(best, thresholds)) || (verifiedMatch(r, thresholds) === verifiedMatch(best, thresholds) && r.score > best.score)) verification.set(a.g, r);
                });
                stats.verified = verification.size;
            } catch (e) { stats.failed = e?.code ?? 'error'; /* the renderer is away or the run is over: those groups are decided by the ranking score */ }
        }
    }
    const textFits = new Map(); // text id → {fitted, ratio}
    const fonts = groups.map((g) => {
        const faces = g.texts.map((id) => byId.get(id).font);
        const face = [...new Set(faces)].sort((a, b) => faces.filter((f) => f === b).length - faces.filter((f) => f === a).length)[0];
        const pooled = new Map(); let ranked = 0; const chosen = []; const ratios = []; const changes = [];
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
                    // (not for a tracked-out text: its size is read from the letter height, the width is the spacing's)
                    if (String(t.text).split('\n').some((line) => similarity(line, r.text) >= 0.8) && own.size > 0 && !own.wrapped && (t.letterSpacing ?? 0) === 0) {
                        const ratio = +(t.size / own.size).toFixed(3);
                        ratios.push(ratio); textFits.set(id, { fitted: own.size, ratio });
                        // the size change that keeps the line as wide as the source: against the size at which this face is as TALL as the source
                        if (own.sizeByHeight > 0) changes.push(t.size / own.sizeByHeight - 1);
                    }
                }
            }
        }
        const top = [...pooled.entries()].map(([f, p]) => ({ face: f, score: p.sum / p.n })).sort((a, b) => b.score - a.score)[0] ?? null;
        const score = chosen.length ? +(chosen.reduce((a, b) => a + b, 0) / chosen.length).toFixed(3) : null;
        // the three patterns the review words a font with — decided by measurement alone. With a verification
        // render its 1 : 1 score decides; without one (nothing to render, the renderer away) the ranking score does
        const v = verification.get(g) ?? null;
        const decisive = v ? v.score : score;
        const matched = v ? verifiedMatch(v, thresholds) : (score !== null && score >= available);
        const undetermined = !v && (score === null || (top && top.score < (build.settings.undeterminedScore ?? 0.35)));
        const pattern = undetermined ? 'undetermined' : matched ? 'measured_match' : 'nearest_available';
        const change = median(changes);
        return {
            group: g.group, role: g.role, texts: g.texts, pattern, closeness: decisive, mismatch: decisive === null ? null : +(1 - decisive).toFixed(3),
            verified: !!v, verifiedScore: v?.score ?? null, verifiedSize: v?.size ?? null, verifiedGlyphs: v?.glyphs ?? null, verifiedInkHeight: v?.inkHeight ?? null,
            sizeChangePercent: pattern === 'nearest_available' && change !== null ? Math.round(change * 100) : 0,
            sourceClass: g.sourceClass, sourceLooksLike: g.sourceLooksLike,
            guessSupported: matched && !!g.sourceLooksLike && (normalise(g.sourceLooksLike).startsWith(family(face)) || family(face).startsWith(normalise(g.sourceLooksLike))),
            face, score, matched, sizeRatio: median(ratios),
            bestFace: top?.face ?? null, bestScore: top ? +top.score.toFixed(3) : null,
            mixedFaces: new Set(faces).size > 1, ranked, by: g.by,
        };
    });

    // ---- approximated and left-out things: the model names them with a closed code, the scanner adds the
    //      box (source pixels — the review shows the crop, never a description) and the measured difference
    const whole = { x: 0, y: 0, w: build.W, h: build.H };
    const boxOfElement = (e) => (e.kind === 'background' ? whole : elementBox(e));
    const approximations = list(finish?.approximations).filter((a) => byId.has(a.element)).map((a) => {
        const kind = APPROXIMATION_KINDS.includes(a.kind) ? a.kind : 'other';
        const b = im.clipBox(build.source, boxOfElement(byId.get(a.element)) ?? whole);
        return { element: a.element, kind, box: arr(b), diff: +im.mae(build.source, kept.masked, b).toFixed(4) };
    });
    // the simplifications the review lists: the approximations that have a shared code, and every place that stands for a pattern
    const simplifications = [
        ...approximations.filter((a) => SIMPLIFICATION_OF[a.kind]).map((a) => ({ code: SIMPLIFICATION_OF[a.kind], element: a.element, box: a.box, n: 1 })),
        ...placeholders.filter((p) => p.role === 'pattern').map((p) => ({ code: 'pattern_as_place', element: p.id, box: p.box, n: 1 })),
    ];
    const clampBox = (o) => im.clipBox(build.source, { x: +o.x || 0, y: +o.y || 0, w: Math.max(1, +o.w || 1), h: Math.max(1, +o.h || 1) });
    const omitted = list(finish?.omitted).map((o) => { const b = clampBox(o); return { code: OMITTED_KINDS.includes(o.kind) ? o.kind : 'other', box: arr(b), diff: +im.mae(build.source, kept.masked, b).toFixed(4), by: 'model' }; });
    // what nobody listed: neighbouring cells with a large measured difference, merged into one box each
    const explained = [...omitted.map((o) => ({ x: o.box[0], y: o.box[1], w: o.box[2], h: o.box[3] })), ...approximations.map((a) => ({ x: a.box[0], y: a.box[1], w: a.box[2], h: a.box[3] }))];
    const loud = kept.feedback.worstCells.map((c) => ({ x: c.box[0], y: c.box[1], w: c.box[2], h: c.box[3], mae: c.mae })).filter((b) => b.mae >= 0.08 && !explained.some((e) => im.intersects(e, b)));
    const merged = [];
    for (const b of loud) {
        const host = merged.find((m) => im.intersects(im.grow(m, 2), b));
        if (host) Object.assign(host, im.union([host, b]), { mae: Math.max(host.mae, b.mae) }); else merged.push({ ...b });
    }
    for (const m of merged.slice(0, 4)) omitted.push({ code: 'other', box: arr(m), diff: m.mae, by: 'scanner' });

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

    const headline = headlineId;
    return {
        status: 'proposed', unfinished, stopReason, candidate: kept.n, headline,
        placeholders, fonts, simplifications, omitted, approximations, lowConfidenceTexts, shrunk, paletteSnaps: build.paletteSnaps,
        notes: [...(texts.length === 0 ? [{ code: 'no_texts' }] : [])],
        counts: { texts: texts.length, shapes: elements.filter((e) => e.kind === 'shape').length, places: placeholders.length, background: elements.some((e) => e.kind === 'background') },
        boxes,
        measures: { mae: kept.mae, textFit: kept.textFit, placeholderShare: kept.placeholderShare, keepBest: kb, unlistedPlaceholders, warnings: kept.issues.filter((i) => i.severity !== 'error').length, fontVerification: { ...stats, renders: build.stats.verifyRenders ?? 0, ms: build.stats.verifyMs ?? 0, ...thresholds } },
        originalColours: build.prepassResult?.palette ?? [],
    };
}
