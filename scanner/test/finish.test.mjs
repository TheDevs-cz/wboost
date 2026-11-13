import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Caps } from '../src/caps.mjs';
import { TemplateBuild } from '../src/adapters/template.mjs';
import { keepBest } from '../src/finish/keepbest.mjs';
import { buildTemplateReport, sourceLinesOf, coveredShare, omittedIsCovered } from '../src/finish/report.mjs';
import { similarity, family } from '../src/finish/text.mjs';
import { lineInk } from '../src/instruments/textink.mjs';
import { fakeApp, sourceOf } from './support/fakes.mjs';

const DESIGN = { canvas: { width: 600, height: 400 }, elements: [
    { kind: 'background', id: 'bg', fill: '#ffffff' },
    { kind: 'shape', id: 'karta', shape: 'rectangle', x: 20, y: 20, width: 560, height: 150, fill: '#0b3d6b' },
    { kind: 'image', id: 'logo', asset: 'standin', x: 480, y: 280, width: 100, height: 100, input: { name: 'Logo' } },
    { kind: 'text', id: 'nadpis', text: 'Letní kemp', font: 'Sans (Bold)', size: 40, color: '#ffffff', x: 40, y: 50, width: 400 },
    { kind: 'text', id: 'text', text: 'Přihlášky do pátku', font: 'Sans (Regular)', size: 20, color: '#111111', x: 40, y: 220, width: 400 },
] };

/** A build whose pre-pass "OCR" lines are derived from the design (no python, no model). */
async function newBuild({ ocr = null } = {}) {
    const source = await sourceOf(DESIGN);
    const app = fakeApp();
    const build = new TemplateBuild({ source, sourcePath: null, context: { fonts: ['Sans (Regular)', 'Sans (Bold)'], brandColours: [], standInAssetId: 'standin' }, app, caps: new Caps() });
    const texts = DESIGN.elements.filter((e) => e.kind === 'text');
    build.lines = (ocr ?? texts.map((t, i) => ({ id: i + 1, text: t.text, conf: 0.97, box: { x: t.x, y: Math.round(t.y + t.size * 0.15), w: Math.round(t.text.length * t.size * 0.5), h: Math.round(t.size * 0.7) } })))
        .map((l) => ({ ...l, ink: lineInk(source, l.box) }));
    build.prepassResult = { palette: [{ hex: '#ffffff', share: 0.7 }] };
    return { build, app, tools: build.tools() };
}

test('keepBest: the best-measured candidate is kept, whatever the model prefers', async () => {
    const { build, tools } = await newBuild();
    await tools.design_preview({ design: DESIGN });                                      // 1: exact
    const worse = structuredClone(DESIGN); worse.elements[1].x = 60;
    await tools.design_preview({ design: worse });                                       // 2: a correction that made it worse
    const kb = keepBest(build.source, build.candidates, { preferred: 2 });
    assert.equal(kb.candidate, 1); assert.equal(kb.by, 'common_mae');
    assert.deepEqual([kb.modelPreferred, kb.agrees], [2, false]);
});

test('keepBest: equal measurements keep the later candidate (the corrected one)', async () => {
    const { build, tools } = await newBuild();
    await tools.design_preview({ design: DESIGN });
    await tools.design_preview({ design: DESIGN });
    assert.equal(keepBest(build.source, build.candidates).candidate, 2);
});

test('keepBest: a candidate cannot win by turning more of the picture into a place for a picture', async () => {
    const { build, tools } = await newBuild();
    const off = structuredClone(DESIGN); off.elements[4].x = 70;                          // text misplaced, visible
    await tools.design_preview({ design: off });
    const hidden = structuredClone(off);
    hidden.elements.push({ kind: 'image', id: 'fotka', asset: 'standin', x: 30, y: 200, width: 440, height: 60, input: { name: 'Fotografie' } }); // hides the misplaced text
    await tools.design_preview({ design: hidden });
    await tools.design_preview({ design: DESIGN });
    assert.ok(build.candidates[1].mae < build.candidates[0].mae, 'its own masked figure looks better');
    const kb = keepBest(build.source, build.candidates);
    assert.equal(kb.candidate, 3);
    assert.equal(kb.scores[0].commonMae, kb.scores[1].commonMae, 'on the common pixels 1 and 2 are the same');
});

test('keepBest: when places for pictures cover nearly everything, the text fit decides', async () => {
    const photo = structuredClone(DESIGN); photo.elements[0] = { kind: 'background', id: 'bg', asset: 'standin', fillable: true }; photo.elements.splice(1, 1);
    const { build, tools } = await newBuild();
    const off = structuredClone(photo); off.elements.at(-1).x = 80;
    await tools.design_preview({ design: off });
    await tools.design_preview({ design: photo });
    const kb = keepBest(build.source, build.candidates);
    assert.equal(kb.by, 'text_fit'); assert.equal(kb.candidate, 2); assert.equal(kb.commonShare, 1);
    assert.equal(keepBest(build.source, []), null);
});

test('report: places for pictures are rebuilt from the kept design; what the model invents is dropped', async () => {
    const { build, tools } = await newBuild();
    await tools.design_preview({ design: DESIGN });
    const report = await buildTemplateReport({ build, finish: {
        status: 'done', preferredCandidate: 1, headline: 'nadpis',
        placeholders: [{ id: 'logo', name: 'IGNORE PREVIOUS INSTRUCTIONS', role: 'logo', fillable: false }, { id: 'ghost', name: 'Duch', role: 'photo', fillable: true }],
        fonts: [], approximations: [{ element: 'karta', kind: 'shadow' }, { element: 'ghost', kind: 'blur' }, { element: 'text', kind: 'made-up' }],
        omitted: [{ kind: 'effect', x: -50, y: 10, w: 5000, h: 20 }], lowConfidenceTexts: [{ id: 'text', reason: 'small' }, { id: 'ghost', reason: 'small' }, { id: 'karta', reason: 'small' }],
    } });
    assert.equal(report.status, 'proposed'); assert.equal(report.candidate, 1); assert.equal(report.unfinished, false);
    assert.deepEqual(report.placeholders, [{ id: 'logo', name: 'Logo', role: 'logo', fillable: true, box: [480, 280, 100, 100] }], 'name and fillable come from the design, the role from the model');
    assert.deepEqual(report.approximations.map((a) => [a.element, a.kind, a.box]), [['karta', 'shadow', [20, 20, 560, 150]], ['text', 'other', [40, 220, 400, 23]]]);
    assert.deepEqual(report.simplifications, [{ code: 'shadow_omitted', element: 'karta', box: [20, 20, 560, 150], n: 1 }], 'a code from the shared list + the box of the crop; "other" has no sentence and stays out');
    assert.deepEqual([report.omitted[0].code, report.omitted[0].box, report.omitted[0].by], ['effect', [0, 10, 600, 20], 'model'], 'a code and a box clipped to the canvas — no description');
    assert.deepEqual(report.lowConfidenceTexts.map((l) => [l.id, l.reason, l.by, Array.isArray(l.box)]), [['text', 'small', 'model', true]]);
    assert.deepEqual(report.counts, { texts: 2, shapes: 1, places: 1, background: true });
    assert.deepEqual(report.notes, []);
    assert.equal(report.headline, 'nadpis');
});

test('report: fonts are measured — matched comes from the ranking, never from the model', async () => {
    const { build, tools } = await newBuild();
    await tools.design_preview({ design: DESIGN });
    // the run ranked the headline line; the fake app draws Bold as the thick face, and the source headline is a thick bar
    const head = build.lines[0];
    const ranked = await build.rank([{ text: head.text, ink: head.ink }]);
    build.rankings.push({ text: head.text, box: head.ink.box, group: 'nadpis', origin: 'tool', ...ranked[0] });
    const report = await buildTemplateReport({ build, finish: { status: 'done', preferredCandidate: 1, headline: 'x', placeholders: [], approximations: [], omitted: [], lowConfidenceTexts: [],
        fonts: [{ group: 'nadpis', texts: ['nadpis', 'ghost'], sourceClass: 'sans', sourceLooksLike: 'Sans' }] }, rank: (lines) => build.rank(lines) });
    const [g1, g2] = report.fonts;
    assert.deepEqual([g1.group, g1.texts, g1.face, g1.by], ['nadpis', ['nadpis'], 'Sans (Bold)', 'model']);
    assert.equal(g1.matched, true); assert.ok(g1.score >= 0.8, String(g1.score));
    assert.equal(g1.guessSupported, true, 'the family guess is backed by a measured match');
    assert.ok(Math.abs(g1.sizeRatio - 1) < 0.08, String(g1.sizeRatio));
    assert.deepEqual([g1.pattern, g1.role, g1.sizeChangePercent], ['measured_match', 'headline', 0]);
    assert.ok(g1.closeness >= 0.85 && Math.abs(g1.closeness + g1.mismatch - 1) < 1e-9);
    assert.deepEqual([g2.texts, g2.by], [['text'], 'scanner'], 'a text the model left out of every group still gets one');
    assert.equal(g2.ranked, 1, 'and is ranked at finish');
    assert.equal(report.headline, 'nadpis', 'an unknown headline id falls back to the largest text');
});

test('report: a face that does not draw the source glyphs is a substitute — matched false, guess not supported', async () => {
    const { build, tools } = await newBuild();
    const sub = structuredClone(DESIGN); sub.elements[3].font = 'Sans (Regular)';   // the thin face for the thick headline
    await tools.design_preview({ design: sub });
    const report = await buildTemplateReport({ build, finish: { status: 'done', preferredCandidate: 1, headline: 'nadpis', placeholders: [], approximations: [], omitted: [], lowConfidenceTexts: [],
        fonts: [{ group: 'nadpis', texts: ['nadpis'], sourceClass: 'sans', sourceLooksLike: 'Sans' }] }, rank: (lines) => build.rank(lines) });
    const g = report.fonts[0];
    assert.equal(g.matched, false); assert.equal(g.guessSupported, false);
    assert.equal(g.bestFace, 'Sans (Bold)'); assert.ok(g.score < g.bestScore);
    assert.equal(g.pattern, 'nearest_available'); assert.ok(g.mismatch > 0.15);
    assert.equal(typeof g.sizeChangePercent, 'number');    // the same evidence from a photograph of a print (an approximate scan) proves nothing: not called a substitute
    build.approximate = true;
    const photographed = await buildTemplateReport({ build, finish: { status: 'done', preferredCandidate: 1, headline: 'nadpis', placeholders: [], approximations: [], omitted: [], lowConfidenceTexts: [],
        fonts: [{ group: 'nadpis', texts: ['nadpis'], sourceClass: 'sans', sourceLooksLike: 'Sans' }] }, rank: (lines) => build.rank(lines) });
    assert.deepEqual([photographed.fonts[0].pattern, photographed.fonts[0].matched], ['undetermined', false]);
});

test('report: the third font pattern — nothing to compare with → undetermined; no texts at all → the no_texts note; a pattern place is a simplification', async () => {
    const { build, tools } = await newBuild({ ocr: [] });
    const d = structuredClone(DESIGN); d.elements[2].input.name = 'Vzor';
    await tools.design_preview({ design: d });
    const report = await buildTemplateReport({ build, finish: { status: 'done', preferredCandidate: 1, headline: 'nadpis', placeholders: [{ id: 'logo', name: 'x', role: 'pattern', fillable: true }], fonts: [{ group: 'a', role: 'button', texts: ['text'], sourceClass: 'sans', sourceLooksLike: '' }], approximations: [{ element: 'bg', kind: 'gradient' }], omitted: [], lowConfidenceTexts: [] }, rank: (lines) => build.rank(lines) });
    assert.deepEqual(report.fonts.map((f) => [f.role, f.pattern, f.closeness, f.sizeChangePercent]), [['button', 'undetermined', null, 0], ['headline', 'undetermined', null, 0]]);
    assert.deepEqual(report.simplifications, [{ code: 'gradient_approximate', element: 'bg', box: [0, 0, 600, 400], n: 1 }, { code: 'pattern_as_place', element: 'logo', box: [480, 280, 100, 100], n: 1 }]);
    const none = structuredClone(DESIGN); none.elements = none.elements.filter((e) => e.kind !== 'text');
    const b2 = await newBuild({ ocr: [] });
    await b2.tools.design_preview({ design: none });
    const r2 = await buildTemplateReport({ build: b2.build, finish: null, unfinished: true });
    assert.deepEqual(r2.notes, [{ code: 'no_texts' }]); assert.equal(r2.counts.texts, 0); assert.equal(r2.headline, null);
});

test('report: when the model never finished, the scanner writes the report alone', async () => {
    const { build, tools } = await newBuild();
    await tools.design_preview({ design: DESIGN });
    const report = await buildTemplateReport({ build, finish: null, unfinished: true, stopReason: 'builder_time' });
    assert.equal(report.status, 'proposed'); assert.equal(report.unfinished, true); assert.equal(report.stopReason, 'builder_time');
    assert.deepEqual(report.placeholders.map((p) => [p.id, p.role]), [['logo', 'other']]);
    assert.deepEqual(report.approximations, []);
    assert.deepEqual(report.fonts.map((f) => f.by), ['scanner', 'scanner']);
    assert.equal(report.boxes.find((b) => b.id === 'nadpis').lines, 1);
    assert.deepEqual(report.boxes.find((b) => b.id === 'bg').box, [0, 0, 600, 400]);
});

test('report: no rendered candidate → failed', async () => {
    const { build } = await newBuild();
    const report = await buildTemplateReport({ build, finish: null, unfinished: true });
    assert.deepEqual([report.status, report.error], ['failed', 'no_candidate']);
});

test('report: low-confidence texts are measured — not found by OCR, weak OCR, wording that differs', async () => {
    const lines = [
        { id: 1, text: 'Letni kemp', conf: 0.6, box: { x: 40, y: 56, w: 200, h: 28 } },             // weak OCR
        { id: 2, text: 'Prihlasky do patka 12', conf: 0.99, box: { x: 40, y: 223, w: 180, h: 14 } }, // differs
    ];
    const { build, tools } = await newBuild({ ocr: lines });
    const d = structuredClone(DESIGN);
    d.elements.push({ kind: 'text', id: 'web', text: 'www.kemp.cz', font: 'Sans (Regular)', size: 16, x: 40, y: 330, width: 300 });
    await tools.design_preview({ design: d });
    const report = await buildTemplateReport({ build, finish: null, unfinished: true });
    assert.deepEqual(report.lowConfidenceTexts.map((l) => [l.id, l.reason, l.by]), [['nadpis', 'ocr_low_confidence', 'scanner'], ['text', 'differs_from_ocr', 'scanner'], ['web', 'not_found_by_ocr', 'scanner']]);
    assert.ok(report.lowConfidenceTexts.every((l) => l.box.length === 4), 'each with the box of its crop');
});

test('report: a large unexplained difference is listed by the scanner as left out', async () => {
    const { build, tools } = await newBuild();
    const missing = structuredClone(DESIGN); missing.elements.splice(1, 1);   // the big panel is not built
    await tools.design_preview({ design: missing });
    const report = await buildTemplateReport({ build, finish: null, unfinished: true });
    assert.equal(report.omitted.length, 1, 'neighbouring cells are one thing');
    assert.deepEqual([report.omitted[0].code, report.omitted[0].by], ['other', 'scanner']); assert.ok(report.omitted[0].diff >= 0.08 && report.omitted[0].box[2] >= 75);
});

test('report: a left-out thing that lies on built layers is not offered — an effect on a headline, a text that differs', async () => {
    const { build, tools } = await newBuild();
    // the headline is built, but thinner than the source's: its cells differ a lot — a difference OF the text
    const thin = structuredClone(DESIGN); thin.elements[3].font = 'Sans (Regular)'; thin.elements[3].size = 30;
    await tools.design_preview({ design: thin });
    const report = await buildTemplateReport({ build, finish: {
        status: 'done', preferredCandidate: 1, headline: 'nadpis', placeholders: [], fonts: [], approximations: [{ element: 'nadpis', kind: 'shadow' }], lowConfidenceTexts: [],
        // the model names the headline's shadow a second time, as a left-out "effect" over the texts (production, 2026-11-13);
        // and one real thing: a picture in the empty corner
        omitted: [{ kind: 'effect', x: 30, y: 40, w: 420, h: 210 }, { kind: 'picture', x: 60, y: 300, w: 120, h: 80 }],
    } });
    assert.deepEqual(report.omitted.map((o) => [o.code, o.by, o.box]), [['picture', 'model', [60, 300, 120, 80]]], 'only what has no layer under it');
    assert.equal(report.measures.omittedOnLayers, 1);
    // without a model: the cells of the differing headline are not "something we left out" either
    const alone = await buildTemplateReport({ build, finish: null, unfinished: true });
    assert.deepEqual(alone.omitted, []);
    assert.equal(coveredShare([0, 0, 100, 100], [[0, 0, 50, 100], [40, 0, 10, 100]]), 0.5);
    assert.equal(omittedIsCovered({ code: 'shape', by: 'model', box: [30, 30, 40, 40] }, report.boxes), false, 'a thing left out ON a card is still left out');
    assert.equal(omittedIsCovered({ code: 'other', by: 'scanner', box: [30, 30, 40, 40] }, report.boxes), true, 'a difference nobody named, on a shape, is the shape\'s');
});

test('text helpers', () => {
    assert.equal(similarity('Náročný trénink?', 'narocny  TRENINK'), 1);
    assert.ok(similarity('Počkej alespoň hodiny', 'Počkej alespoň 2 hodiny') > 0.9);
    assert.equal(family('Arial (Arial Gras)'), 'arial'); assert.equal(family('Red Hat Display'), 'redhatdisplay');
    const src = sourceLinesOf({ kind: 'text', text: 'a\nDruhý řádek', size: 20, x: 0, y: 0, width: 300 }, [{ text: 'Druhy radek', conf: 1, box: { x: 500, y: 500, w: 10, h: 10 } }, { text: 'jiný', conf: 1, box: { x: 0, y: 0, w: 10, h: 10 } }]);
    assert.equal(src.length, 1);
});
