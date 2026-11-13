// The calendar adapter: mm instruments, the candidate loop on a stand-in calendar model, caps, the report.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import { runBuild } from '../src/run.mjs';
import { definitionFiles } from '../src/drivers/file-job.mjs';
import { readDefinition, HttpScannerApi, INSTRUMENTS } from '../src/protocol.mjs';
import { ocrPython } from '../src/instruments/ocr.mjs';
import * as im from '../src/instruments/raster.mjs';
import { CalendarBuild, detectRules, rowLadder, columnLadders, detectPageBox, pictureMask, mergeDeep, withMmRulers, labelOverlaps, clearOfPicture } from '../src/adapters/calendar.mjs';
import { buildCalendarReport, shown } from '../src/finish/calendar-report.mjs';
import { Caps } from '../src/caps.mjs';
import { scriptedEngine } from './support/fakes.mjs';

const PAGE = { width: 310, height: 145 };
const PX = 4; // px per mm of the test pictures: 1240 × 580
const fill = (color) => ({ colorOn: true, color, colorOpacity: 100, gradient: { on: false, type: 'linear', angle: 90, stops: [] }, imageOn: false, asset: null, fit: 'cover', imageOpacity: 100 });
const DEFAULT = {
    v: 1,
    boxes: { photo: { x: -5, y: 27, w: 202, h: 123 }, cap: { x: 5, y: 135, w: 93, h: 5.5 }, logo: { x: 168.5, y: 31, w: 23.5, h: 23.5 }, head: { x: -5, y: -5, w: 320, h: 32 }, month: { x: 203, y: 12.5, w: 101, h: 14.5 }, cal: { x: 197, y: 27, w: 118, h: 112 }, wk: { x: 203, y: 139, w: 101, h: 4.5 }, qr: { x: 170.5, y: 116.5, w: 21, h: 21 } },
    order: ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo'],
    styles: { head: { bg: fill('#CBC7D1') }, bg: fill('#F6F5F8'), cap: { bg: fill('#D2CED8') }, month: { bg: { ...fill('#FFFFFF'), colorOn: false }, family: null, size: 15, color: '#2B2830' }, cal: { bg: { color: null, opacity: 100 }, numberSide: 'end' }, lines: { on: true, width: 1, style: 'solid', color: '#8A8690' }, num: { family: null, size: 28, color: '#2B2830' }, day: { family: null, size: 11, color: '#6E6977' }, name: { family: null, size: 9.5, color: '#2B2830' }, wk: { family: null, size: 9.5, color: '#6E6977' }, logo: { asset: null, fit: 'contain' } },
};

/** A stand-in for the app's calendar model + renderer: preset default → patch → a picture of boxes and rules. */
function fakeCalendarApp({ features: current = {} } = {}) {
    const app = {
        renders: 0, last: null,
        materialise(commands) {
            let layout = structuredClone(DEFAULT); const features = { captions: false, weekNumbers: false, moon: false, events: false, qr: false, ...current }; let preset = 'photoLeft';
            for (const c of commands) {
                if (c.op === 'preset') { preset = c.preset; if (c.preset === 'photoRight') for (const b of Object.values(layout.boxes)) b.x = PAGE.width - b.x - b.w; }
                if (c.op === 'configure') for (const [k, v] of Object.entries(c.features)) { if (v !== true) return { error: 'a scan switches features ON only' }; features[k] = true; }
                if (c.op === 'layout') {
                    for (const k of Object.keys(c.styles ?? {})) if (!(k in layout.styles)) return { error: `styles.${k} is not a style key. Keys of styles: ${Object.keys(layout.styles).join(', ')}` };
                    for (const k of Object.keys(c.boxes ?? {})) if (!(k in layout.boxes)) return { error: `"${k}" is not an element box of this calendar` };
                    layout = { ...layout, boxes: mergeDeep(layout.boxes, c.boxes ?? {}), styles: mergeDeep(layout.styles, c.styles ?? {}), order: c.order ?? layout.order };
                }
            }
            return { layout, features, preset };
        },
        async draw(commands) {
            const m = app.materialise(commands);
            const { layout, features } = m;
            const W = PAGE.width * PX, H = PAGE.height * PX;
            const rect = (b, colour) => `<rect x="${b.x * PX}" y="${b.y * PX}" width="${b.w * PX}" height="${b.h * PX}" fill="${colour}"/>`;
            let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><rect width="100%" height="100%" fill="${layout.styles.bg.color}"/>`;
            for (const el of [...layout.order].reverse()) {
                const b = layout.boxes[el];
                if (b.hidden) continue;
                if (el === 'photo') svg += rect(b, '#9aa0a6');
                if (el === 'head') svg += rect(b, layout.styles.head.bg.color);
                if (el === 'logo') svg += rect(b, '#dddddd');
                if (el === 'cap' && features.captions) svg += rect(b, layout.styles.cap.bg.color);
                if (el === 'month') svg += rect({ x: b.x + b.w - 40, y: b.y + b.h / 2 - layout.styles.month.size * 0.127, w: 40, h: layout.styles.month.size * 0.253 }, layout.styles.month.color);
                if (el === 'cal' && layout.styles.lines.on) { const pitch = (b.h - 2) / 7; for (let i = 1; i <= 7; i++) svg += `<rect x="${(b.x + 5) * PX}" y="${Math.round((b.y + i * pitch) * PX)}" width="${(Math.min(b.x + b.w, PAGE.width) - b.x - 10) * PX}" height="2" fill="${layout.styles.lines.color}"/>`; }
            }
            return { ...m, png: await sharp(Buffer.from(svg + '</svg>')).png().toBuffer() };
        },
        async renderCommands(commands) {
            const m = app.materialise(commands);
            if (m.error) return { rendered: false, status: 'rejected', issues: [{ severity: 'error', stage: 'model', code: 'unknown_style_key', message: m.error }], image: null };
            app.renders++; app.last = commands;
            const d = await app.draw(commands);
            return { rendered: true, issues: [{ severity: 'info', stage: 'model', code: 'note', message: 'boxes.cal.w: kept inside the page' }], image: { data: d.png, mimeType: 'image/png' }, view: { x: 0, y: 0, w: PAGE.width, h: PAGE.height }, page: { key: 'w02' }, preset: d.preset, layout: d.layout, features: d.features };
        },
        async faces() { throw new Error('no faces in this test'); },
    };
    return app;
}

// the "source": a page whose Kalendárium is 20 mm narrower and 3 mm lower than the default, header dark blue, no logo
const TRUTH = [{ op: 'preset', preset: 'photoLeft' }, { op: 'configure', features: { captions: true } }, { op: 'layout', boxes: { cal: { x: 217, y: 30, w: 98, h: 107 }, photo: { w: 222 }, logo: { hidden: true } }, styles: { head: { bg: { color: '#1F3A5F' } }, lines: { color: '#40454C' } } }];
const CONTEXT = { fonts: [], brandColours: [], calendar: { supported: true, type: 'week', year: 2027, page: PAGE, bleedMm: 5, features: {}, hasLayout: false, presets: ['photoLeft', 'photoRight'], samplePage: { key: 'w02' }, defaultLayout: { preset: 'photoLeft', layout: DEFAULT } } };

async function sourceRaster(margin = 0) {
    const app = fakeCalendarApp();
    let png = (await app.draw(TRUTH)).png;
    if (margin) png = await sharp(png).extend({ top: margin, bottom: margin, left: margin, right: margin, background: '#ffffff' }).png().toBuffer();
    return { png, raster: await im.loadRgb(png) };
}
async function build({ margin = 0, context = CONTEXT, caps = new Caps() } = {}) {
    const { png, raster } = await sourceRaster(margin);
    const app = fakeCalendarApp();
    const b = new CalendarBuild({ source: raster, sourcePath: null, context, app, caps });
    return { b, app, png, tools: b.tools() };
}

test('the scanner offers the mm instrument; the definition asks for it for calendars only', () => {
    assert.ok(INSTRUMENTS.includes('mm'));
    const { definition, kind } = readDefinition(definitionFiles());
    assert.deepEqual(definition.kinds.calendar.requiresInstruments, ['mm']);
    assert.ok(!definition.requiresInstruments.includes('mm'), 'a scanner without mm still serves templates');
    assert.equal(definition.effort.calendar, 'high');
    const cal = kind('calendar'), tpl = kind('template');
    assert.deepEqual(cal.tools.map((t) => t.name), ['scan_source', 'scan_crop', 'scan_measure', 'font_rank', 'compare_region', 'calendar_preview', 'set_page_box']);
    assert.match(cal.tools[1].description, /page millimetres/);
    // the template kind keeps its own wording (and what an older scanner finds by name)
    assert.deepEqual(tpl.tools.map((t) => t.name), ['scan_source', 'scan_crop', 'scan_measure', 'font_rank', 'compare_region', 'design_preview', 'design_patch']);
    assert.match(tpl.tools[1].description, /canvas coordinates/);
    const byName = JSON.parse(definitionFiles()['tools.json']);
    assert.equal(byName.find((t) => t.name === 'scan_crop').id, undefined, 'the first entry of a name is the template\'s');
    assert.deepEqual(Object.keys(cal.finishSchema.properties), cal.finishSchema.required);
});

test('row ladder: seven rules of one pitch and the Kalendárium frame they imply, in mm', async () => {
    const { b } = await build();
    b.ocrSource = []; // no OCR in this test
    const rules = detectRules(b.page);
    const ladder = rowLadder(rules, { height: b.H });
    assert.equal(ladder.rules.length, 7);
    assert.ok(Math.abs(ladder.pitch / PX - 15) < 0.2, `pitch ${ladder.pitch / PX} mm`);
    assert.ok(Math.abs(ladder.x0 / PX - 222) <= 0.5 && Math.abs(ladder.x1 / PX - 305) <= 0.5, `${ladder.x0 / PX} … ${ladder.x1 / PX}`);
    assert.equal(rowLadder([{ y: 10, x0: 0, x1: 100, thickness: 1 }, { y: 50, x0: 0, x1: 100, thickness: 1 }], { height: 400 }), null, 'two rules are not a ladder');
});

test('page box: a plain margin around a page of the right ratio is detected; a full-bleed page is not', async () => {
    const { raster } = await sourceRaster(40);
    const box = detectPageBox(raster, PAGE.width / PAGE.height);
    assert.ok(box && Math.abs(box.x - 40) <= 1 && Math.abs(box.y - 40) <= 1 && Math.abs(box.w - 1240) <= 2 && Math.abs(box.h - 580) <= 2, JSON.stringify(box));
    assert.equal(detectPageBox((await sourceRaster()).raster, PAGE.width / PAGE.height), null);
});

test('picture mask: photo, logo and QR are the user\'s; an opaque header above the photo clears its box', () => {
    const toPx = (b) => ({ x: Math.max(0, Math.round(b.x)), y: Math.max(0, Math.round(b.y)), w: Math.round(b.w), h: Math.round(b.h) });
    const layout = { boxes: { photo: { x: 0, y: 0, w: 50, h: 100 }, head: { x: 0, y: 0, w: 100, h: 20 }, logo: { x: 60, y: 60, w: 10, h: 10 }, qr: { x: 80, y: 80, w: 10, h: 10 } }, order: ['qr', 'logo', 'head', 'photo'], styles: { head: { bg: fill('#000000') } } };
    const m = pictureMask(layout, { qr: false }, toPx, 100, 100);
    assert.equal(m[10 * 100 + 10], 0, 'the header covers the photo there');
    assert.equal(m[50 * 100 + 10], 1); assert.equal(m[65 * 100 + 65], 1, 'logo');
    assert.equal(m[85 * 100 + 85], 0, 'the QR switch is off');
    const over = pictureMask({ ...layout, order: ['qr', 'logo', 'photo', 'head'] }, { qr: true }, toPx, 100, 100);
    assert.equal(over[10 * 100 + 10], 1, 'the photo drawn over the header'); assert.equal(over[85 * 100 + 85], 1);
});

test('tools answer in page millimetres; set_page_box moves every coordinate and is refused after a candidate', async () => {
    const { b, tools } = await build({ margin: 40 });
    b.ocrSource = [];
    const before = await tools.scan_measure({ x: 0, y: 0, w: 50, h: 20 });
    assert.equal(before.json.region[2], 50);
    const set = await tools.set_page_box({ x: 40, y: 40, w: 1240, h: 580 });
    assert.equal(set.json.rules.count, 7); assert.deepEqual(set.json.page.pageBoxPx, [40, 40, 1240, 580]);
    assert.ok(Math.abs(set.json.rules.calBox.x - 217) <= 0.6 && Math.abs(set.json.rules.calBox.y - 30) <= 0.6 && Math.abs(set.json.rules.calBox.h - 107) <= 0.8, JSON.stringify(set.json.rules.calBox));
    const head = await tools.scan_measure({ x: 100, y: 2, w: 60, h: 10 });
    assert.equal(head.json.background, '#1f3a5f');
    const crop = await tools.scan_crop({ x: 200, y: 20, w: 60, h: 40 });
    assert.equal(crop.images.length, 1); assert.deepEqual(crop.json.region, [200, 20, 60, 40]);
    await tools.calendar_preview({ preset: 'photoLeft' });
    await assert.rejects(() => tools.set_page_box({ x: 0, y: 0, w: 1240, h: 580 }), /cannot move/);
    const ruled = await withMmRulers(await im.toPng(b.page), { x: 0, y: 0, w: 310, h: 145 }, { x: PX, y: PX });
    assert.equal(ruled.step, 10);
});

test('calendar_preview: preset default + one patch → commands; feedback names the rule delta in mm; "patch" merges over the last candidate', async () => {
    const { b, app, tools } = await build();
    b.ocrSource = []; await (async () => { b.prepassResult = {}; })();
    b.ladder = rowLadder(detectRules(b.page), { height: b.H });
    const first = await tools.calendar_preview({ preset: 'photoLeft', features: ['captions'], boxes: { logo: { hidden: true }, photo: { w: 222 } }, styles: { head: { bg: { color: '#1F3A5F' } } } });
    assert.equal(first.json.candidate, 1); assert.equal(first.images.length, 1);
    assert.deepEqual(app.last.map((c) => c.op), ['preset', 'configure', 'layout']);
    assert.deepEqual(app.last[1], { op: 'configure', features: { captions: true } });
    assert.deepEqual(first.json.notes, ['boxes.cal.w: kept inside the page']);
    const d = first.json.rules.delta;
    assert.ok(Math.abs(d.firstYMm - 2.3) <= 0.5 && Math.abs(d.x0Mm - 20) <= 0.5 && Math.abs(d.pitchMm + 0.71) <= 0.15, JSON.stringify(d));
    assert.deepEqual(first.json.boxes.logo, { hidden: true });
    const second = await tools.calendar_preview({ patch: true, boxes: { cal: { x: 217, y: 30, w: 98, h: 107 } }, styles: { lines: { color: '#40454C' } } });
    assert.equal(second.json.candidate, 2);
    assert.equal(app.last[2].boxes.logo.hidden, true, 'the patch kept what the first candidate set');
    assert.equal(app.last[2].styles.head.bg.color, '#1F3A5F');
    assert.ok(second.json.mae < first.json.mae && second.json.mae < 0.002, `mae ${first.json.mae} → ${second.json.mae}`);
    assert.ok(Math.abs(second.json.rules.delta.firstYMm) <= 0.3);
    const cmp = await tools.compare_region({ x: 200, y: 20, w: 110, h: 60 });
    assert.equal(cmp.json.candidate, 2); assert.equal(cmp.images.length, 1);
});

test('caps: a refused patch is an answer and uses no candidate; the 4th candidate and the 4th refusal are refused', async () => {
    const { b, app, tools } = await build();
    b.ocrSource = [];
    const bad = await tools.calendar_preview({ preset: 'photoLeft', styles: { bogus: { x: 1 } } });
    assert.equal(bad.isError, true); assert.equal(bad.json.rendered, false); assert.match(bad.json.issues[0].message, /bogus is not a style key/);
    assert.equal(b.candidates.length, 0); assert.equal(app.renders, 0);
    await assert.rejects(() => tools.calendar_preview({ patch: true, boxes: {} }), /no candidate to patch/);
    await assert.rejects(() => tools.calendar_preview({ preset: 'twoSided' }), /preset/);
    await assert.rejects(() => tools.calendar_preview({ preset: 'photoLeft', features: ['nonsense'] }), /not a feature switch/);
    for (let i = 0; i < 3; i++) await tools.calendar_preview({ preset: 'photoLeft' });
    await assert.rejects(() => tools.calendar_preview({ preset: 'photoLeft' }), /candidate budget is used/);
    const { b: c, tools: t2 } = await build();
    c.ocrSource = [];
    for (let i = 0; i < 3; i++) await t2.calendar_preview({ preset: 'photoLeft', styles: { bogus: 1 } });
    await assert.rejects(() => t2.calendar_preview({ preset: 'photoLeft' }), /validation budget is used/);
});

test('a project without fonts: font_rank says so, nothing fails', async () => {
    const { b, tools } = await build();
    b.ocrSource = [];
    await assert.rejects(() => tools.font_rank({ text: 'LEDEN 2027', x: 200, y: 10, w: 100, h: 14, group: 'month' }), /no text found|no font/);
});

test('report: closed lists, measured boxes, the kept candidate by measurement; absent = what the kept layout hides', async () => {
    const { b, tools } = await build({ margin: 40 });
    b.ocrSource = [];
    await tools.set_page_box({ x: 40, y: 40, w: 1240, h: 580 });
    await tools.calendar_preview({ preset: 'photoLeft', features: ['captions'], boxes: { cal: { x: 217, y: 30, w: 98, h: 107 }, photo: { w: 222 }, logo: { hidden: true } }, styles: { head: { bg: { color: '#1F3A5F' } }, lines: { color: '#40454C' } } });
    await tools.calendar_preview({ patch: true, boxes: { cal: { x: 190 } } }); // a correction that made it worse
    const report = await buildCalendarReport({ build: b, finish: {
        preferredCandidate: 2, fortnight: false,
        recognised: [{ element: 'photo', state: 'found' }, { element: 'logo', state: 'found' }, { element: 'head', state: 'approximate' }, { element: 'wk', state: 'nonsense' }, { element: 'sticker', state: 'found' }],
        needsUpload: [{ slot: 'logo' }, { slot: 'header' }, { slot: 'sticker' }],
        featuresOffered: [{ key: 'captions' }, { key: 'nonsense' }],
        notExpressible: [{ code: 'ornament', x: 300, y: 0, w: 10, h: 145 }, { code: 'free text from the picture', x: 0, y: 0, w: 1, h: 1 }],
    } });
    assert.equal(report.status, 'proposed'); assert.equal(report.candidate, 1, 'the better-measured candidate is kept');
    assert.equal(report.measures.keepBest.agrees, false);
    assert.deepEqual(report.pageBox, [40, 40, 1240, 580]);
    assert.deepEqual(report.photoBox, [40, 148 + 0, 868, 472], 'the photo box of the kept layout, clipped to the page, in source px');
    const state = Object.fromEntries(report.recognised.map((r) => [r.element, r.state]));
    assert.deepEqual(Object.keys(state), ['photo', 'cap', 'cal', 'month', 'wk', 'head', 'logo', 'qr', 'bg']);
    assert.equal(state.logo, 'absent', 'the kept layout hides the logo, whatever the model said');
    assert.equal(state.head, 'approximate'); assert.equal(state.wk, 'absent'); assert.equal(state.cap, 'found'); assert.equal(state.qr, 'absent');
    assert.deepEqual(report.needsUpload, [{ slot: 'header' }], 'no slot for a logo that is not shown; unknown slots dropped');
    assert.deepEqual(report.featuresOffered, [{ key: 'captions' }]);
    assert.deepEqual(report.notExpressible, [{ code: 'ornament', box: [40 + 1200, 40, 40, 580] }]);
    assert.deepEqual(report.notes, [{ code: 'page_box_set' }]);
    assert.deepEqual(report.fonts, [], 'a project without fonts has no font rows');
    // the app's contract (AiScanReportValidator::CALENDAR_SCALARS / CALENDAR_LISTS)
    for (const k of ['status', 'candidate', 'unfinished', 'type', 'pageBox', 'photoBox', 'recognised', 'needsUpload', 'featuresOffered', 'fonts', 'notExpressible', 'notes']) assert.ok(k in report, k);
    const text = JSON.stringify(report);
    assert.ok(!/free text from the picture|sticker|nonsense/.test(text), 'nothing outside the closed lists reaches the report');
    // alone: no finish at all
    const alone = await buildCalendarReport({ build: b, finish: null, unfinished: true, stopReason: 'turns' });
    assert.equal(alone.unfinished, true); assert.equal(alone.stopReason, 'turns');
    assert.equal(Object.fromEntries(alone.recognised.map((r) => [r.element, r.state])).logo, 'absent');
    assert.equal(shown({ boxes: { wk: { x: 0 } } }, { weekNumbers: false }, 'wk'), false);
});

const skip = !existsSync(ocrPython()) && 'the build runs the OCR pre-pass: needs scanner/.venv';

async function jobFixture({ context = CONTEXT } = {}) {
    const files = definitionFiles();
    const { png } = await sourceRaster();
    const working = await sharp(png).webp({ lossless: true }).toBuffer();
    const app = fakeCalendarApp();
    const sent = { finish: null, renders: [] };
    const api = {
        source: async () => working, putArtifact: async () => {}, beat: async () => ({ cancel: false, mode: 'on' }),
        render: async (body) => { sent.renders.push(body); return app.renderCommands(body.commands); }, faces: async () => { throw new Error('no fonts'); },
        finish: async (p) => { sent.finish = p; return { ok: true }; }, release: async () => ({ ok: true }),
    };
    const job = { id: 'scan-c', kind: 'calendar', lane: 'build', run: 1, definition: { version: 't', files }, context: { fonts: [], palette: [], calendar: context.calendar } };
    return { api, job, sent, app, scratchDir: mkdtempSync(join(tmpdir(), 'scan-cal-')) };
}

test('build (calendar): pre-pass → candidates through `commands` → keep-best → report → finish(proposed), no fonts needed', { skip }, async () => {
    const f = await jobFixture();
    let first = null;
    const engine = scriptedEngine(async (ctx) => {
        first = ctx.options.content;
        assert.equal(ctx.options.effort, 'high');
        assert.deepEqual(ctx.options.tools.map((t) => t.name), ['scan_source', 'scan_crop', 'scan_measure', 'font_rank', 'compare_region', 'calendar_preview', 'set_page_box']);
        await ctx.tool('calendar_preview', { preset: 'photoLeft', features: ['captions'], boxes: { logo: { hidden: true }, photo: { w: 222 } }, styles: { head: { bg: { color: '#1F3A5F' } }, lines: { color: '#40454C' } } });
        const r = await ctx.tool('calendar_preview', { patch: true, boxes: { cal: { x: 217, y: 30, w: 98, h: 107 } } });
        assert.equal(JSON.parse(r.content[0].text).candidate, 2);
        return { status: 'done', preferredCandidate: 2, fortnight: false, recognised: [{ element: 'logo', state: 'absent' }], needsUpload: [], featuresOffered: [{ key: 'captions' }], notExpressible: [] };
    });
    const out = await runBuild({ job: f.job, api: f.api, engine, scratchDir: f.scratchDir });
    assert.equal(out.outcome, 'proposed'); assert.equal(out.candidate, 2);
    assert.equal(f.sent.finish.outcome, 'proposed'); assert.equal(f.sent.finish.boxes, undefined, 'a calendar finish carries no boxes');
    assert.equal(f.sent.finish.report.type, 'week'); assert.equal(f.sent.finish.report.unfinished, false);
    assert.ok(f.sent.renders.every((r) => Array.isArray(r.commands) && r.design === undefined && Number.isInteger(r.seq)), 'candidates travel as model commands');
    assert.deepEqual(f.sent.renders.map((r) => r.seq), [1, 2]);
    assert.match(first[1].text, /page mm/); assert.match(first[1].text, /"rules":\{"count":7/); assert.match(first[1].text, /"defaultLayout":/);
    assert.equal(out.stats.prepass.rows, 7);
});

test('build (calendar): a calendar that cannot take a scanned layout fails the build; a cap hit is reported alone', { skip }, async () => {
    const f = await jobFixture({ context: { ...CONTEXT, calendar: { supported: false, type: 'fortnight' } } });
    const out = await runBuild({ job: f.job, api: f.api, engine: scriptedEngine(async () => ({})), scratchDir: f.scratchDir });
    assert.deepEqual([out.outcome, out.error, out.detail], ['failed', 'build', 'calendar_unsupported']);
    const g = await jobFixture();
    const files = g.job.definition.files; const def = JSON.parse(files['definition.json']); def.caps.turns = 2; files['definition.json'] = JSON.stringify(def);
    const engine = scriptedEngine(async (ctx) => { await ctx.tool('calendar_preview', { preset: 'photoLeft' }); for (let i = 0; i < 6; i++) await ctx.tool('scan_measure', { x: 0, y: 0, w: 50, h: 20 }); return {}; });
    const capped = await runBuild({ job: g.job, api: g.api, engine, scratchDir: g.scratchDir });
    assert.equal(capped.outcome, 'proposed'); assert.equal(capped.report.unfinished, true); assert.equal(capped.report.stopReason, 'turns');
});

test('protocol: a calendar render sends `commands` and hands back view, page, preset, layout and features', async () => {
    let sentBody = null;
    const fetchImpl = async (url, init) => { sentBody = JSON.parse(init.body); return new Response(JSON.stringify({ ok: true, candidate: 1, issues: [], width: 1600, height: 748, image: { contentType: 'image/webp', base64: Buffer.from('x').toString('base64') }, view: { x: 0, y: 0, w: 310, h: 145 }, page: { key: 'w02' }, preset: 'photoLeft', layout: { v: 1 }, features: { moon: true } }), { status: 200, headers: { 'content-type': 'application/json' } }); };
    const api = new HttpScannerApi({ baseUrl: 'http://app', token: 't'.repeat(30), scannerVersion: 'x', fetchImpl }).forJob({ id: 'a', run: 3, leaseToken: 'l', lane: 'build' });
    const r = await api.render({ seq: 7, commands: [{ op: 'preset', preset: 'photoLeft' }] });
    assert.deepEqual(sentBody, { run: 3, seq: 7, commands: [{ op: 'preset', preset: 'photoLeft' }] });
    assert.equal(r.rendered, true); assert.deepEqual(r.view, { x: 0, y: 0, w: 310, h: 145 }); assert.equal(r.preset, 'photoLeft'); assert.deepEqual(r.features, { moon: true }); assert.deepEqual(r.layout, { v: 1 });
    await api.render({ seq: 8, design: { canvas: {} } });
    assert.deepEqual(Object.keys(sentBody), ['run', 'seq', 'design'], 'a template render is unchanged');
});

// ---------------------------------------------------------------------------- 14-day calendars

const DEFAULT14 = {
    v: 1,
    boxes: { photo: { x: 84, y: 27, w: 142, h: 112 }, cap: { x: 89, y: 139.5, w: 132, h: 4.5 }, logo: { x: 143, y: 15, w: 24, h: 24 }, head: { x: -5, y: -5, w: 320, h: 32 }, month: { x: 6, y: 12.5, w: 120, h: 14.5 }, month2: { x: 184, y: 12.5, w: 120, h: 14.5 }, cal: { x: -5, y: 27, w: 89, h: 112 }, cal2: { x: 226, y: 27, w: 89, h: 112 }, wk: { x: 6, y: 139, w: 72, h: 4.5 }, wk2: { x: 232, y: 139, w: 72, h: 4.5 }, qr: { x: 199.5, y: 113, w: 21, h: 21, hidden: true } },
    order: ['qr', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo'],
    styles: { ...DEFAULT.styles },
};
const CONTEXT14 = { fonts: [], brandColours: [], calendar: { supported: true, type: 'fortnight', year: 2027, page: PAGE, bleedMm: 5, features: {}, hasLayout: true, presets: ['twoSided'], samplePage: { key: 'w02' }, defaultLayout: { preset: 'twoSided', layout: DEFAULT14 } } };

/** The stand-in model + renderer of a 14-day calendar: two columns of rules, the photo between. */
function fakeFortnightApp() {
    const app = {
        renders: 0, last: null,
        materialise(commands) {
            let layout = structuredClone(DEFAULT14); const features = { captions: false, weekNumbers: false };
            for (const c of commands) {
                if (c.op === 'preset' && c.preset !== 'twoSided') return { error: `The preset "${c.preset}" does not fit a fortnight calendar` };
                if (c.op === 'configure') for (const k of Object.keys(c.features)) features[k] = true;
                if (c.op === 'layout') {
                    for (const [k, b] of Object.entries(c.boxes ?? {})) { if (!(k in layout.boxes)) return { error: `"${k}" is not an element box of this calendar` }; if (/2$/.test(k) && 'hidden' in b) return { error: `boxes.${k}.hidden: a second frame has no flag` }; }
                    layout = { ...layout, boxes: mergeDeep(layout.boxes, c.boxes ?? {}), styles: mergeDeep(layout.styles, c.styles ?? {}), order: c.order ?? layout.order };
                }
            }
            return { layout, features, preset: 'twoSided' };
        },
        async draw(commands) {
            const { layout, features } = app.materialise(commands);
            const W = PAGE.width * PX, H = PAGE.height * PX;
            const rect = (b, colour) => `<rect x="${b.x * PX}" y="${b.y * PX}" width="${b.w * PX}" height="${b.h * PX}" fill="${colour}"/>`;
            let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><rect width="100%" height="100%" fill="${layout.styles.bg.color}"/>${rect(layout.boxes.head, layout.styles.head.bg.color)}${rect(layout.boxes.photo, '#9aa0a6')}`;
            for (const k of ['cal', 'cal2']) { const b = layout.boxes[k], pitch = (b.h - 2) / 7, x0 = Math.max(b.x, 0) + 5, x1 = Math.min(b.x + b.w, PAGE.width) - 5; for (let i = 1; i <= 7; i++) svg += `<rect x="${x0 * PX}" y="${Math.round((b.y + i * pitch) * PX)}" width="${(x1 - x0) * PX}" height="2" fill="${layout.styles.lines.color}"/>`; }
            return { layout, features, png: await sharp(Buffer.from(svg + '</svg>')).png().toBuffer() };
        },
        async renderCommands(commands) {
            const m = app.materialise(commands);
            if (m.error) return { rendered: false, status: 'rejected', issues: [{ severity: 'error', stage: 'model', code: 'refused', message: m.error }], image: null };
            app.renders++; app.last = commands;
            const d = await app.draw(commands);
            return { rendered: true, issues: [], image: { data: d.png, mimeType: 'image/png' }, view: { x: 0, y: 0, w: PAGE.width, h: PAGE.height }, page: { key: 'w02' }, preset: 'twoSided', layout: d.layout, features: d.features };
        },
        async faces() { throw new Error('no faces in this test'); },
    };
    return app;
}
// the "source": both columns 10 mm narrower and 4 mm lower than the default — mirror images of each other
const TRUTH14 = [{ op: 'preset', preset: 'twoSided' }, { op: 'layout', boxes: { cal: { x: -5, y: 31, w: 79, h: 107 }, cal2: { x: 236, y: 31, w: 79, h: 107 }, photo: { x: 74, w: 162 } }, styles: { head: { bg: { color: '#1F3A5F' } }, lines: { color: '#40454C' } } }];
async function build14(truth = TRUTH14) {
    const app = fakeFortnightApp();
    const raster = await im.loadRgb((await app.draw(truth)).png);
    const b = new CalendarBuild({ source: raster, sourcePath: null, context: CONTEXT14, app, caps: new Caps() });
    b.ocrSource = []; b.lines = []; b.ladders = columnLadders(b.page); // what the pre-pass measures, without the OCR
    return { b, app, tools: b.tools(), raster };
}

test('14 days: the scanner announces mm14; the definition asks for it for the fortnight variant only, which has its own instructions and candidate tool', () => {
    assert.ok(INSTRUMENTS.includes('mm14'));
    const { definition, kind } = readDefinition(definitionFiles());
    assert.deepEqual(definition.kinds.calendar.requiresInstruments, ['mm'], 'an image without mm14 keeps building 7-day calendars');
    assert.deepEqual(definition.kinds.calendar.variants.fortnight.requiresInstruments, ['mm14']);
    const week = kind('calendar'), fort = kind('calendar', 'fortnight');
    assert.doesNotMatch(week.system, /prints two weeks on a page/); assert.match(fort.system, /prints two weeks on a page/);
    assert.ok(fort.system.startsWith(week.system), 'the variant adds to the kind\'s instructions');
    assert.deepEqual(fort.tools.map((t) => t.name), week.tools.map((t) => t.name));
    const preview = (spec) => spec.tools.find((t) => t.name === 'calendar_preview');
    assert.deepEqual(preview(week).input_schema.properties.preset.enum, ['photoLeft', 'photoRight']);
    assert.deepEqual(preview(fort).input_schema.properties.preset.enum, ['twoSided']);
    assert.match(preview(fort).description, /cal2/);
    assert.equal(fort.task, week.task); assert.deepEqual(fort.finishSchema, week.finishSchema);
    assert.throws(() => kind('calendar', 'monthly'), /no variant/);
});

test('14 days: one row ladder per week column, each with the frame it implies', async () => {
    const { b, raster } = await build14();
    const ladders = columnLadders(raster);
    assert.deepEqual(ladders.map((l) => l.rules.length), [7, 7]);
    assert.ok(ladders[0].x1 < raster.width / 2 && ladders[1].x0 > raster.width / 2);
    // one ladder of the whole page would see one column only: the rules share their rows
    assert.equal(rowLadder(detectRules(raster), { height: raster.height }).rules.length, 7);
    await b.tools().set_page_box({ x: 0, y: 0, w: raster.width, h: raster.height }).then((r) => {
        assert.equal(r.json.rules.count, 7); assert.equal(r.json.rules2.count, 7); assert.equal(r.json.rules2.frame, 'cal2');
        const near = (a, e, tol = 0.6) => assert.ok(Math.abs(a - e) <= tol, `${a} ≈ ${e}`);
        // the frames as the rules show them — clipped to the page (the bleed is not in the picture)
        near(r.json.rules.calBox.x, 0); near(r.json.rules.calBox.y, 31); near(r.json.rules.calBox.w, 74); near(r.json.rules.calBox.h, 107);
        near(r.json.rules2.calBox.x, 236); near(r.json.rules2.calBox.w, 74);
    });
    assert.equal(b.rowsFound(), 14);
});

test('14 days: second frames the model does not send are the mirror of the first; what it sends about one wins; the preset need not be named', async () => {
    const { b, app, tools } = await build14();
    const first = await tools.calendar_preview({ boxes: { cal: { y: 31, w: 79, h: 107 }, photo: { x: 74, w: 162 }, month: { x: 10 }, wk: { hidden: true } }, styles: { head: { bg: { color: '#1F3A5F' } }, lines: { color: '#40454C' } } });
    assert.equal(first.json.rendered, true, JSON.stringify(first.json.issues));
    assert.deepEqual(app.last[0], { op: 'preset', preset: 'twoSided' });
    const sent = app.last.find((c) => c.op === 'layout').boxes;
    assert.deepEqual(sent.cal2, { x: 236, y: 31, w: 79, h: 107 }, 'cal.x came from the default layout');
    assert.deepEqual(sent.month2, { x: 180, y: 12.5, w: 120, h: 14.5 });
    assert.equal(sent.wk2, undefined, 'a hidden element sends no second frame');
    assert.equal(first.json.mae < 0.002, true, `the mirrored candidate matches the mirrored source (${first.json.mae})`);
    assert.equal(first.json.rules.source.count, 7); assert.equal(first.json.rules2.source.count, 7);
    assert.deepEqual(first.json.rules2.delta, { firstYMm: 0, pitchMm: 0, x0Mm: 0, x1Mm: 0 });
    assert.ok(first.json.elements.some((e) => e.element === 'cal2') && first.json.elements.some((e) => e.element === 'month2'));
    // a patch of the first frame moves the mirrored second frame with it; an explicit side of the second frame stays
    await tools.calendar_preview({ patch: true, boxes: { cal: { w: 69 }, cal2: { y: 40 } } });
    const patched = app.last.find((c) => c.op === 'layout').boxes;
    assert.deepEqual(patched.cal2, { x: 246, y: 40, w: 69, h: 107 });
    assert.equal(b.candidates[1].feedback.rules2.delta.x0Mm, -10, 'the feedback of the second column is its own');
    await assert.rejects(() => tools.calendar_preview({ preset: 'photoLeft' }), /twoSided/);
    assert.equal(b.candidates.length, 2);
});

test('labels under a picture: which ones, and the part of the frame beside the picture', () => {
    const boxes = { logo: { x: 262, y: 14, w: 28, h: 11 }, qr: { x: 199.5, y: 113, w: 21, h: 21, hidden: true }, month: { x: 6, y: 12.5, w: 120, h: 14.5 }, month2: { x: 184, y: 12.5, w: 120, h: 14.5 }, wk: { x: 6, y: 139, w: 72, h: 4.5 }, wk2: { x: 232, y: 139, w: 72, h: 4.5 } };
    assert.deepEqual(labelOverlaps(boxes), [{ label: 'month2', picture: 'logo' }]);
    assert.deepEqual(labelOverlaps({ ...boxes, logo: { ...boxes.logo, hidden: true } }), [], 'a hidden logo covers nothing');
    assert.deepEqual(labelOverlaps({ ...boxes, month: { ...boxes.month, hidden: true } }), [], 'the flag of the first frame hides both');
    // a QR code counts only when it is switched on; a week label only while the switch is not off
    const qr = { ...boxes, qr: { x: 240, y: 136, w: 20, h: 8 } };
    assert.deepEqual(labelOverlaps(qr, {}), [{ label: 'month2', picture: 'logo' }]);
    assert.deepEqual(labelOverlaps(qr, { qr: true }), [{ label: 'month2', picture: 'logo' }, { label: 'wk2', picture: 'qr' }]);
    assert.deepEqual(labelOverlaps(qr, { qr: true, weekNumbers: false }), [{ label: 'month2', picture: 'logo' }]);
    // a picture beside the frame, nearer than the gap, counts when a gap is asked for
    assert.equal(labelOverlaps({ ...boxes, logo: { x: 127, y: 14, w: 20, h: 11 } }).length, 0);
    assert.deepEqual(labelOverlaps({ ...boxes, logo: { x: 127, y: 14, w: 20, h: 11 } }, {}, 1.5), [{ label: 'month', picture: 'logo' }]);

    // text flush right (a mirrored second frame) grows to the left: the part LEFT of the picture
    assert.deepEqual(clearOfPicture(boxes.month2, boxes.logo, 'end', 40), { x: 184, y: 12.5, w: 76.5, h: 14.5 });
    // text flush left grows to the right: the part RIGHT of the picture — when it can hold the label
    assert.deepEqual(clearOfPicture({ x: 6, y: 12.5, w: 120, h: 14.5 }, { x: 6, y: 14, w: 24, h: 11 }, 'start', 40), { x: 31.5, y: 12.5, w: 94.5, h: 14.5 });
    // … else the other part (the label keeps its place, the frame ends before the picture)
    assert.deepEqual(clearOfPicture({ x: 6, y: 12.5, w: 120, h: 14.5 }, { x: 100, y: 14, w: 24, h: 11 }, 'start', 40), { x: 6, y: 12.5, w: 92.5, h: 14.5 });
    // a picture across the whole frame leaves nothing
    assert.equal(clearOfPicture({ x: 6, y: 12.5, w: 60, h: 14.5 }, { x: 20, y: 10, w: 40, h: 20 }, 'start', 40), null);
});

test('14 days: a logo in the top right corner does not cover the second month line — the mirrored frame ends before it; a frame the model sends under the logo is cut as well', async () => {
    const { b, app, tools } = await build14();
    // the acceptance case: the month line top left, the logo top right — `month2` mirrors to x 184…304, under the logo
    const first = await tools.calendar_preview({ boxes: { logo: { x: 262, y: 14, w: 28, h: 11 } } });
    assert.equal(first.json.rendered, true, JSON.stringify(first.json.issues));
    const sent = app.last.find((c) => c.op === 'layout').boxes;
    assert.deepEqual(sent.month2, { x: 184, y: 12.5, w: 76.5, h: 14.5 }, 'the frame ends 1.5 mm left of the logo; its text is flush right there');
    assert.equal(sent.month, undefined, 'the first frame was not touched');
    assert.deepEqual(first.json.adjusted, [{ box: 'month2', picture: 'logo', to: [184, 12.5, 76.5, 14.5] }]);
    assert.match(first.json.adjustedNote, /beside the picture/);
    assert.equal(first.json.overlaps, undefined, 'nothing stands under a picture in the rendered layout');
    assert.deepEqual(b.candidates[0].overlaps, []);
    // what the model said stays what "patch" builds on: the cut is the scanner's, made again for every candidate
    assert.equal(b.working.boxes.month2, undefined);

    // the model moves the logo away: the mirror image is whole again
    await tools.calendar_preview({ patch: true, boxes: { logo: { x: 143, y: 15, w: 24, h: 24 } } });
    assert.equal(app.last.find((c) => c.op === 'layout').boxes.month2, undefined, 'nothing is sent for it: the default second frame applies');
    assert.equal(app.renders, 2);

    // a second frame the model sends itself under the logo, and the first frame under a logo at the top left
    const third = await tools.calendar_preview({ boxes: { logo: { x: 262, y: 14, w: 28, h: 11 }, month2: { x: 200, w: 104 } } });
    assert.deepEqual(app.last.find((c) => c.op === 'layout').boxes.month2, { x: 200, y: 12.5, w: 60.5, h: 14.5 });
    assert.deepEqual(third.json.adjusted.map((x) => x.box), ['month2']);
    const { app: app2, tools: tools2 } = await build14();
    await tools2.calendar_preview({ boxes: { logo: { x: 8, y: 14, w: 24, h: 11 } } });
    const left = app2.last.find((c) => c.op === 'layout').boxes;
    assert.deepEqual(left.month, { x: 33.5, y: 12.5, w: 92.5, h: 14.5 }, 'text flush left starts right of the logo');
    assert.equal(left.month2, undefined, 'the second frame is not a mirror of the cut');
});

test('a label that stands under a picture in the rendered layout is named in the feedback (7 days: nothing is moved)', async () => {
    const { b, app, tools } = await build();
    b.ocrSource = [];
    const r = await tools.calendar_preview({ preset: 'photoLeft', boxes: { logo: { x: 270, y: 14, w: 30, h: 11 } } });
    assert.deepEqual(r.json.overlaps, [{ label: 'month', picture: 'logo' }]);
    assert.match(r.json.overlapsNote, /UNDER a picture/);
    assert.equal(r.json.adjusted, undefined);
    assert.deepEqual(app.last.find((c) => c.op === 'layout').boxes, { logo: { x: 270, y: 14, w: 30, h: 11 } });
    const clean = await tools.calendar_preview({ preset: 'photoLeft' });
    assert.equal(clean.json.overlaps, undefined);
    // keep-best: the measurement cannot tell the two apart (a logo is left out of every number) and would keep
    // the later one — the candidate with the covered month line loses to the clean one
    const finish = { status: 'done', recognised: [], needsUpload: [], featuresOffered: [], notExpressible: [] };
    await tools.calendar_preview({ preset: 'photoLeft', boxes: { logo: { x: 270, y: 14, w: 30, h: 11 } } });
    assert.deepEqual(b.candidates.map((c) => c.overlaps.length), [1, 0, 1]);
    assert.equal((await buildCalendarReport({ build: b, finish: { ...finish, preferredCandidate: 3 } })).candidate, 2);
    // … and is kept when every candidate has one
    b.candidates.splice(1, 1);
    assert.equal((await buildCalendarReport({ build: b, finish: { ...finish, preferredCandidate: 1 } })).candidate, 3);
});

test('14 days: the report says the calendar\'s type and when the picture shows one week only; a 7-day calendar keeps its own note', async () => {
    const { b, tools } = await build14();
    await tools.calendar_preview({ boxes: { cal: { y: 31, w: 79, h: 107 }, photo: { x: 74, w: 162 } } });
    const base = { preferredCandidate: 1, recognised: [], needsUpload: [], featuresOffered: [], notExpressible: [] };
    const two = await buildCalendarReport({ build: b, finish: { ...base, fortnight: true } });
    assert.equal(two.type, 'fortnight'); assert.equal(two.preset, 'twoSided'); assert.deepEqual(two.notes, []);
    assert.equal(two.measures.rows, 14); assert.deepEqual(two.measures.rules2, { firstYMm: 0, pitchMm: 0, x0Mm: 0, x1Mm: 0 });
    const one = await buildCalendarReport({ build: b, finish: { ...base, fortnight: false } });
    assert.deepEqual(one.notes, [{ code: 'week_page_on_fortnight' }]);
    const alone = await buildCalendarReport({ build: b, finish: null, unfinished: true, stopReason: 'turns' });
    assert.deepEqual(alone.notes, [], 'without the model\'s word nothing is claimed');
    const week = await build();
    week.b.ocrSource = [];
    await week.tools.calendar_preview({ preset: 'photoLeft' });
    const left = await buildCalendarReport({ build: week.b, finish: { ...base, fortnight: true } });
    assert.equal(left.type, 'week'); assert.deepEqual(left.notes, [{ code: 'fortnight_left_half' }]);
});

test('build (14 days): the fortnight variant\'s instructions and tool reach the engine; the finish carries type fortnight', { skip }, async () => {
    const app = fakeFortnightApp();
    const working = await sharp((await app.draw(TRUTH14)).png).webp({ lossless: true }).toBuffer();
    const sent = { finish: null, renders: [] };
    const api = { source: async () => working, putArtifact: async () => {}, beat: async () => ({ cancel: false, mode: 'on' }), render: async (body) => { sent.renders.push(body); return app.renderCommands(body.commands); }, faces: async () => { throw new Error('no fonts'); }, finish: async (p) => { sent.finish = p; return { ok: true }; }, release: async () => ({ ok: true }) };
    const job = { id: 'scan-f', kind: 'calendar', lane: 'build', run: 1, definition: { version: 't', files: definitionFiles() }, context: { fonts: [], palette: [], calendar: CONTEXT14.calendar } };
    let options = null;
    const engine = scriptedEngine(async (ctx) => {
        options = ctx.options;
        await ctx.tool('calendar_preview', { boxes: { cal: { y: 31, w: 79, h: 107 }, photo: { x: 74, w: 162 } }, styles: { lines: { color: '#40454C' } } });
        return { status: 'done', preferredCandidate: 1, fortnight: true, recognised: [], needsUpload: [], featuresOffered: [], notExpressible: [] };
    });
    const out = await runBuild({ job, api, engine, scratchDir: mkdtempSync(join(tmpdir(), 'scan-cal14-')) });
    assert.equal(out.outcome, 'proposed');
    assert.match(options.system, /prints two weeks on a page/);
    assert.match(options.tools.find((t) => t.name === 'calendar_preview').description, /cal2/);
    assert.match(options.content[1].text, /"rules2":\{"count":7/);
    assert.equal(sent.finish.report.type, 'fortnight'); assert.equal(sent.finish.report.preset, 'twoSided');
    assert.equal(out.stats.prepass.rows, 14);
    assert.ok(sent.renders[0].commands.find((c) => c.op === 'layout').boxes.cal2, 'the second frame travels in the commands');
});
