// The finish report of a calendar scan (architecture §4.7, the app's AiScanReportValidator
// CALENDAR_SCALARS / CALENDAR_LISTS). What the model says is kept only inside the closed lists and
// where the kept candidate backs it up; boxes and font facts are measured. When the model never
// finished (a cap), the scanner writes the report alone: `unfinished: true`.
import { keepBest } from './keepbest.mjs';
import { ELEMENTS, FEATURES } from '../adapters/calendar.mjs';
import { verifiedMatch } from '../instruments/fonts.mjs';
import { normalise } from './text.mjs';

export const STATES = ['found', 'approximate', 'absent'];
export const SLOTS = ['logo', 'header', 'background'];
export const NOT_EXPRESSIBLE = ['ornament', 'header_text', 'second_photo', 'more_logos', 'days_in_grid', 'rotated_text', 'month_colours', 'multilingual_names'];
/** Text styles the review names a face for, in the order it lists them. */
export const FONT_GROUPS = ['num', 'day', 'name', 'month', 'wk', 'cap'];

const WEEKDAYS = ['pondeli', 'utery', 'streda', 'ctvrtek', 'patek', 'sobota', 'nedele'];
const list = (v) => (Array.isArray(v) ? v.filter((x) => x && typeof x === 'object') : []);
const centreIn = (b, box) => b && box && b.x + b.w / 2 >= box.x && b.x + b.w / 2 < box.x + box.w && b.y + b.h / 2 >= box.y && b.y + b.h / 2 < box.y + box.h;

/** Is the element drawn by this layout with these switches? */
export function shown(layout, features, el) {
    const b = layout?.boxes?.[el];
    if (el === 'bg') return true;
    if (!b || b.hidden) return false;
    if (el === 'cap') return features?.captions !== false;
    if (el === 'wk') return features?.weekNumbers !== false;
    if (el === 'qr') return features?.qr === true;
    return true;
}

/**
 * Which text style a ranked source line belongs to: the tool's own `group`, else by where the line
 * sits in the kept layout (month / week label / caption boxes; a line of digits inside the Kalendárium
 * is a day number).
 */
export function groupOf(ranking, build, layout) {
    if (FONT_GROUPS.includes(ranking.group)) return ranking.group;
    const b = ranking.sourceBox ? { x: ranking.sourceBox.x - build.pageBox.x, y: ranking.sourceBox.y - build.pageBox.y, w: ranking.sourceBox.w, h: ranking.sourceBox.h } : null;
    if (!b) return null;
    const px = (el) => (layout?.boxes?.[el] && !layout.boxes[el].hidden ? build.toPx(layout.boxes[el]) : null);
    for (const el of ['month', 'wk', 'cap']) if (centreIn(b, px(el))) return el;
    if (centreIn(b, px('cal')) && /^\d{1,2}$/.test(String(ranking.text).trim())) return 'num';
    if (centreIn(b, px('cal')) && WEEKDAYS.includes(normalise(String(ranking.text).split(/[\s/]/)[0]))) return 'day';
    return null;
}

const glyphCount = (t) => [...String(t).replace(/\s/g, '')].length;

/**
 * Source lines the run did not rank, by text style — found by what the calendar itself prints: a line
 * inside the month / week-label / caption box, a weekday name (the one wording a scan knows), the line
 * right under a weekday name (the name day), a line of two digits inside the Kalendárium (a day number).
 * → [{group, text, ink}] — the longest usable line of each style that has no ranking yet.
 */
export function unrankedLines(build, layout, features, have) {
    const px = (el) => (shown(layout, features, el) ? build.toPx(layout.boxes[el]) : null);
    const local = (l) => (l.pageInk ?? l.pageBox);
    const cal = px('cal');
    const out = new Map();
    const offer = (group, l) => { if (have.has(group) || !l.ink || l.ink.n < 30) return; const cur = out.get(group); if (!cur || glyphCount(l.text) > glyphCount(cur.text)) out.set(group, { group, text: l.text, ink: l.ink }); };
    const days = [];
    for (const l of build.lines) {
        const b = local(l);
        if (!b) continue;
        for (const el of ['month', 'wk', 'cap']) if (centreIn(b, px(el)) && glyphCount(l.text) >= 3) offer(el, l);
        if (!centreIn(b, cal)) continue;
        const first = normalise(String(l.text).split(/[\s/]/)[0]);
        if (WEEKDAYS.includes(first) && !/[\/]/.test(l.text)) { offer('day', l); days.push(b); } else if (/^\d{2}$/.test(String(l.text).trim())) offer('num', l);
    }
    for (const l of build.lines) {
        const b = local(l);
        if (!b || !centreIn(b, cal) || glyphCount(l.text) < 3 || /\d/.test(l.text)) continue;
        if (days.some((d) => b.y >= d.y + d.h * 0.6 && b.y <= d.y + d.h * 2.2 && b.x < d.x + d.w && d.x < b.x + b.w && b.h < d.h * 1.05)) offer('name', l);
    }
    return [...out.values()];
}

/**
 * @param build   the CalendarBuild after the run
 * @param finish  the model's final object, or null
 * @param rank    async (lines) → rankings: ranks text styles the run left unranked (optional — skipped near the run cap)
 * @param verify  async (items) → results: the verification render that decides measured_match / nearest_available (optional)
 * → the report {status: 'proposed', candidate, …} or {status: 'failed', error}
 */
export async function buildCalendarReport({ build, finish = null, unfinished = false, stopReason = null, rank = null, verify = null }) {
    if (!build.candidates.length) return { status: 'failed', error: 'no_candidate' };
    const preferred = Number.isInteger(finish?.preferredCandidate) && build.candidates.some((c) => c.n === finish.preferredCandidate) ? finish.preferredCandidate : null;
    const kb = keepBest(build.page, build.candidates, { preferred });
    const kept = build.candidates.find((c) => c.n === kb.candidate);
    const { layout, features } = kept;

    // ---- recognised: the model's word inside the closed lists; an element it did not name is read off the kept layout
    const said = new Map(list(finish?.recognised).filter((r) => ELEMENTS.includes(r.element) && STATES.includes(r.state)).map((r) => [r.element, r.state]));
    const recognised = ELEMENTS.map((element) => {
        let state = said.get(element) ?? (shown(layout, features, element) ? 'found' : 'absent');
        // what the kept layout does not draw cannot have been rebuilt
        if (state !== 'absent' && element !== 'bg' && !shown(layout, features, element)) state = 'absent';
        return { element, state };
    });

    const needsUpload = [...new Set(list(finish?.needsUpload).map((n) => n.slot).filter((s) => SLOTS.includes(s)))]
        .filter((slot) => slot !== 'logo' || shown(layout, features, 'logo'))
        .map((slot) => ({ slot }));

    const featuresOffered = [...new Set([...list(finish?.featuresOffered).map((f) => f.key), ...(kept.candidate.features ?? [])].filter((k) => FEATURES.includes(k)))].map((key) => ({ key }));

    // ---- fonts: one row per text style the page draws, from measurements — never from the model's say-so.
    //      1. the rankings of the run (the tool's `group`, or where the ranked line sits in the kept layout);
    //      2. styles without one are ranked now, on a source line the calendar itself would print there;
    //      3. the verification render: the source line set in the CHOSEN face at its fitted size, compared with
    //         the source pixels word by word — that score says "this is the face" (measured_match) or "the nearest
    //         one" (nearest_available). A line of ≤ 3 glyphs, or one under 16 px tall, that does not verify proves nothing: undetermined.
    const fonts = [];
    const fontStats = { ranked: 0, rankedAtFinish: 0, asked: 0, verified: 0, failed: null };
    if (build.context.fonts.length) {
        const byGroup = new Map();
        for (const r of build.rankings) { const g = groupOf(r, build, layout); if (g && r.best) byGroup.set(g, r); } // the latest ranking of a group wins
        const wanted = FONT_GROUPS.filter((g) => !((g === 'wk' || g === 'cap' || g === 'month') && !shown(layout, features, g)));
        if (rank) {
            const missing = unrankedLines(build, layout, features, new Set([...byGroup.keys()])).filter((l) => wanted.includes(l.group) && layout?.styles?.[l.group]?.family);
            if (missing.length) {
                try { for (const r of await rank(missing)) if (r.best) { byGroup.set(r.group, r); fontStats.rankedAtFinish++; } } catch (e) { fontStats.failed = e?.code ?? 'rank'; }
            }
        }
        // a style left on the built-in face (family null) has no project face to speak of: no row
        const rows = wanted.filter((group) => layout?.styles?.[group]?.family).map((group) => {
            const face = layout.styles[group].family;
            const r = byGroup.get(group);
            const own = r?.candidates?.find((c) => c.face === face) ?? null;
            return { group, face, r, own };
        });
        fontStats.ranked = rows.filter((x) => x.own).length;
        const asks = rows.filter((x) => x.own && x.r.ink && x.own.size > 0 && !x.own.wrapped);
        const verified = new Map();
        if (verify && asks.length) {
            fontStats.asked = asks.length;
            try {
                const results = await verify(asks.map((x) => ({ text: x.r.text, ink: x.r.ink, face: x.face, size: x.own.size })));
                asks.forEach((x, i) => { if (results[i] && !results[i].wrapped) verified.set(x.group, results[i]); });
                fontStats.verified = verified.size;
            } catch (e) { fontStats.failed = e?.code ?? 'verify'; }
        }
        const thresholds = { verifiedScore: build.settings.verifiedScore ?? 0.9, verifiedScoreLong: build.settings.verifiedScoreLong ?? 0.83, verifiedLongGlyphs: build.settings.verifiedLongGlyphs ?? 12 };
        for (const { group, face, r, own } of rows) {
            if (!r || !own) { fonts.push({ group, pattern: 'undetermined', face, closeness: null }); continue; }
            const v = verified.get(group) ?? null;
            // too little to judge by: a line of ≤ 3 glyphs, or glyphs under 16 px tall in the picture (a 9 pt name day on a 1600 px page)
            const short = glyphCount(r.text) <= 3 || (r.ink?.h ?? 0) < 16;
            const matched = v ? verifiedMatch(v, thresholds) : (r.available && r.best.face === face);
            const pattern = matched ? 'measured_match' : (short || (!v && !r.available && own.score < (build.settings.undeterminedScore ?? 0.35)) ? 'undetermined' : 'nearest_available');
            const closeness = v ? v.score : own.score;
            fonts.push({ group, pattern, face, closeness: pattern === 'undetermined' ? null : closeness, mismatch: pattern === 'undetermined' ? null : +(1 - closeness).toFixed(3), verified: !!v, rankScore: own.score, bestFace: r.best.face, bestScore: r.best.score, glyphs: glyphCount(r.text), origin: r.origin });
        }
    }

    // ---- "Tohle kalendář neumí": the model's code + where (page mm → source px)
    const notExpressible = list(finish?.notExpressible).filter((n) => NOT_EXPRESSIBLE.includes(n.code) && ['x', 'y', 'w', 'h'].every((k) => typeof n[k] === 'number' && Number.isFinite(n[k])) && n.w > 0 && n.h > 0)
        .slice(0, 12).map((n) => ({ code: n.code, box: build.toSourcePx(build.toPx({ x: n.x, y: n.y, w: n.w, h: n.h })) }));

    const notes = [];
    if (finish?.fortnight === true) notes.push({ code: 'fortnight_left_half' });
    if (build.ratioError() > 0.02) notes.push({ code: 'ratio_adapted', w: Math.round(build.mm.w), h: Math.round(build.mm.w * build.H / build.W) });
    if (build.approximate) notes.push({ code: 'colours_estimated' });
    if (build.pageBoxSet) notes.push({ code: 'page_box_set' });

    const photo = shown(layout, features, 'photo') ? build.toSourcePx(build.toPx(layout.boxes.photo)) : null;

    return {
        status: 'proposed', candidate: kept.n, unfinished: !!unfinished, stopReason: unfinished ? (typeof stopReason === 'string' ? stopReason.replace(/[^a-z0-9_]/gi, '_').slice(0, 40) : null) : null,
        type: 'week',
        pageBox: [build.pageBox.x, build.pageBox.y, build.pageBox.w, build.pageBox.h],
        photoBox: photo,
        preset: kept.preset,
        recognised, needsUpload, featuresOffered, fonts, notExpressible, notes,
        measures: {
            keepBest: { by: kb.by, agrees: kb.agrees, modelPreferred: kb.modelPreferred, commonShare: kb.commonShare, scores: kb.scores.map((s) => ({ candidate: s.candidate, mae: s.mae, commonMae: s.commonMae })) },
            mae: kept.mae, pictureShare: kept.pictureShare, candidates: build.candidates.length, rejections: build.attempts.filter((a) => !a.rendered).length,
            fontVerification: fontStats, pageBox: build.pageBoxSet ?? 'whole', rows: build.ladder ? build.ladder.rules.length : 0, texts: build.lines.length, faceSheets: build.stats.faceSheets,
            rules: kept.feedback.rules?.delta ?? null,
        },
    };
}
