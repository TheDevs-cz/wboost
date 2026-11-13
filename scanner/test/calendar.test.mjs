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
import { CalendarBuild, detectRules, rowLadder, detectPageBox, pictureMask, mergeDeep, withMmRulers } from '../src/adapters/calendar.mjs';
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
