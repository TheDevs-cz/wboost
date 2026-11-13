import { test } from 'node:test';
import assert from 'node:assert/strict';
import sharp from 'sharp';
import { Caps } from '../src/caps.mjs';
import { ToolServer, ToolUserError } from '../src/tools/server.mjs';
import { prepareImage } from '../src/tools/images.mjs';
import { TemplateBuild, elementBox, pickLinesToRank } from '../src/adapters/template.mjs';
import { placeholderMask, maskShare } from '../src/adapters/mask.mjs';
import { fakeApp, sourceOf } from './support/fakes.mjs';

const defs = (...names) => names.map((name) => ({ name, description: name, input_schema: { type: 'object', properties: {} } }));
const png = (w, h, noise = false) => {
    if (!noise) return sharp({ create: { width: w, height: h, channels: 3, background: '#336699' } }).png().toBuffer();
    const data = Buffer.alloc(w * h * 3); let s = 1;
    for (let i = 0; i < data.length; i++) { s = (s * 1103515245 + 12345) & 0x7fffffff; data[i] = s & 255; }
    return sharp(data, { raw: { width: w, height: h, channels: 3 } }).png({ compressionLevel: 0 }).toBuffer();
};
const parse = (r) => JSON.parse(r.content[0].text);

test('image rule: 25 images asked, 18 given, the rest answered "work from the numbers"', async () => {
    const caps = new Caps();
    const img = await png(200, 100);
    const server = new ToolServer({ definitions: defs('look'), impl: { look: async () => ({ json: { ok: true }, images: [img] }) }, caps });
    const results = [];
    for (let i = 0; i < 25; i++) results.push(await server.dispatch('look', {}));
    const withImage = results.filter((r) => r.content.some((b) => b.type === 'image'));
    assert.equal(withImage.length, 18);
    assert.equal(parse(results[17]).imageBudget, undefined);
    assert.match(parse(results[18]).imageBudget, /image budget used/);
    assert.equal(parse(results[24]).ok, true, 'the numbers still come');
    assert.equal(parse(results[24]).left.images, 0);
    assert.equal(results[24].isError, false);
});

test('image rule: an A3 render at 300 dpi is given at ≤ 2576 px and ≤ 3 MB, with its scale', async () => {
    const a3 = await png(3508, 4961);
    const p = await prepareImage(a3);
    assert.ok(Math.max(p.width, p.height) <= 2576, `${p.width}×${p.height}`);
    assert.equal(p.height, 2576);
    assert.ok(p.data.length <= 3_000_000);
    assert.ok(Math.abs(p.scale - 2576 / 4961) < 1e-6);
    const meta = await sharp(p.data).metadata();
    assert.deepEqual([meta.width, meta.height], [p.width, p.height]);
});

test('image rule: a 9 MB noisy PNG is re-encoded under 3 MB', async () => {
    const big = await png(1800, 1700, true);
    assert.ok(big.length > 9_000_000, String(big.length));
    const p = await prepareImage(big);
    assert.ok(p.data.length <= 3_000_000, String(p.data.length));
    assert.ok(['image/png', 'image/webp'].includes(p.mimeType));
});

test('image rule: a small picture passes through 1:1', async () => {
    const p = await prepareImage(await png(1080, 1350));
    assert.deepEqual([p.width, p.height, p.scale, p.mimeType], [1080, 1350, 1, 'image/png']);
});

test('a hung tool is a tool error, not silence; an unknown tool and a user error are answers too', async () => {
    const caps = new Caps();
    const server = new ToolServer({ definitions: defs('slow', 'bad', 'boom'), caps, timeouts: { localMs: 40, appMs: 80 }, appTools: ['boom'],
        impl: { slow: () => new Promise(() => {}), bad: async () => { throw new ToolUserError('"x" must be a number'); }, boom: async () => { throw new Error('internal'); } } });
    const t0 = Date.now();
    const slow = await server.dispatch('slow', {});
    assert.ok(Date.now() - t0 < 1000);
    assert.equal(slow.isError, true); assert.equal(parse(slow).code, 'tool_timeout');
    assert.match(parse(await server.dispatch('bad', {})).error, /must be a number/);
    assert.equal((await server.dispatch('nope', {})).isError, true);
    assert.equal((await server.dispatch('boom', {})).isError, true);
    assert.deepEqual(server.calls.map((c) => [c.tool, c.isError, c.internal]), [['slow', true, true], ['bad', true, false], ['nope', true, false], ['boom', true, true]]);
});

test('tool calls run one at a time, in order', async () => {
    const order = [];
    const server = new ToolServer({ definitions: defs('a'), caps: new Caps(), impl: { a: async ({ n, ms }) => { order.push(`start ${n}`); await new Promise((ok) => setTimeout(ok, ms)); order.push(`end ${n}`); return { json: {} }; } } });
    await Promise.all([server.dispatch('a', { n: 1, ms: 30 }), server.dispatch('a', { n: 2, ms: 1 })]);
    assert.deepEqual(order, ['start 1', 'end 1', 'start 2', 'end 2']);
});

test('every answer says what is left, and to finish when time is nearly up', async () => {
    let now = 0;
    const caps = new Caps({ builderMs: 300_000 }, () => now);
    caps.startBuilder();
    const server = new ToolServer({ definitions: defs('a'), caps, impl: { a: async () => ({ json: {} }) } });
    assert.deepEqual(parse(await server.dispatch('a', {})).left, { candidates: 3, images: 18, seconds: 300 });
    now = 270_000;
    assert.match(parse(await server.dispatch('a', {})).left.note, /return the final report now/);
});

// ---- the template adapter's tools

const DESIGN = { canvas: { width: 400, height: 300 }, elements: [
    { kind: 'background', id: 'bg', fill: '#ffffff' },
    { kind: 'shape', id: 'karta', shape: 'rectangle', x: 20, y: 20, width: 360, height: 120, fill: '#0b3d6b' },
    { kind: 'image', id: 'logo', asset: 'standin', x: 300, y: 180, width: 80, height: 80, input: { name: 'Logo' } },
    { kind: 'text', id: 'nadpis', text: 'Nadpis sem', font: 'Sans (Bold)', size: 40, color: '#ffffff', x: 40, y: 40, width: 300 },
] };

async function newBuild(over = {}) {
    const source = await sourceOf(DESIGN);
    const app = fakeApp(over.app);
    const caps = new Caps(over.limits);
    const build = new TemplateBuild({ source, sourcePath: null, context: { fonts: ['Sans (Regular)', 'Sans (Bold)'], brandColours: over.brand ?? [], standInAssetId: 'standin' }, app, caps });
    return { build, app, caps, tools: build.tools() };
}

test('design_preview: a rendered candidate gets a number, measured feedback and the render', async () => {
    const { tools, build } = await newBuild();
    const shifted = structuredClone(DESIGN);
    shifted.elements[3].x = 52; shifted.elements[3].y = 46;
    const r = await tools.design_preview({ design: shifted });
    assert.equal(r.json.candidate, 1); assert.equal(r.json.rendered, true); assert.equal(r.images.length, 1);
    const t = r.json.elements.find((e) => e.id === 'nadpis');
    assert.deepEqual(t.move, { dx: -12, dy: -6 }, 'how far the text must move to sit where the source has it');
    assert.equal(t.widthRatio, 1);
    assert.ok(r.json.mae > 0);
    const exact = await tools.design_preview({ design: DESIGN });
    assert.equal(exact.json.candidate, 2); assert.equal(exact.json.mae, 0);
    assert.deepEqual(exact.json.history.map((h) => h.candidate), [1, 2]);
    assert.equal(build.candidates.length, 2);
});

test('letter spacing is kept only where it was measured: an estimate of the model is removed and named', async () => {
    const { tools, build } = await newBuild();
    const tracked = structuredClone(DESIGN);
    const text = tracked.elements[3];
    text.letterSpacing = 240;
    // nothing measured tracking for that face: removed, the candidate still renders
    const invented = await tools.design_preview({ design: tracked });
    assert.equal(invented.json.rendered, true);
    assert.deepEqual(invented.json.letterSpacingRemoved, [text.id]);
    assert.equal(build.candidates[0].design.elements[3].letterSpacing, undefined);
    // a ranking that measured 250 for that face (this text's line, or another line of the same style): 240 stays, 80 does not
    build.rankings.push({ text: 'another line of the style', candidates: [{ face: text.font, score: 0.5, size: 40, spaced: { size: 30, letterSpacing: 250 } }] });
    text.letterSpacing = 240;
    const kept = await tools.design_preview({ design: structuredClone(tracked) });
    assert.equal(kept.json.letterSpacingRemoved, undefined);
    assert.equal(build.candidates[1].design.elements[3].letterSpacing, 240);
    const far = structuredClone(tracked); far.elements[3].letterSpacing = 80;
    assert.deepEqual((await tools.design_preview({ design: far })).json.letterSpacingRemoved, [text.id]);
});

test('the feedback ignores what lies inside a place for a picture', async () => {
    const { tools } = await newBuild();
    const moved = structuredClone(DESIGN);
    // the source shows the stand-in grey box where the logo is; a design whose slot covers more hides more — never less
    const r = await tools.design_preview({ design: moved });
    assert.ok(Math.abs(r.json.placeholderShare - (80 * 80) / (400 * 300)) < 0.001);
    assert.equal(r.json.mae, 0);
});

test('at most 3 rendered candidates: the 4th preview is refused with a message to finish', async () => {
    const { tools, caps, app } = await newBuild();
    for (let i = 0; i < 3; i++) assert.equal((await tools.design_preview({ design: DESIGN })).json.candidate, i + 1);
    await assert.rejects(tools.design_preview({ design: DESIGN }), (e) => e instanceof ToolUserError && /candidate budget is used \(3 of 3/.test(e.message));
    await assert.rejects(tools.design_patch({ set: [{ id: 'nadpis', props: { x: 41 } }] }), ToolUserError);
    assert.equal(caps.candidates, 3); assert.equal(app.renders, 3, 'nothing was rendered for the refused calls');
});

test('at most 3 validation rejections; a rejection is an answer with the issues and does not use a candidate', async () => {
    const { tools, caps } = await newBuild();
    const bad = structuredClone(DESIGN); bad.elements[3].font = 'Comic (Regular)';
    const r = await tools.design_preview({ design: bad });
    assert.equal(r.isError, true); assert.equal(r.json.rendered, false); assert.equal(r.json.validationAttemptsLeft, 2);
    assert.match(r.json.issues[0].message, /no such font/);
    await tools.design_preview({ design: bad }); await tools.design_preview({ design: bad });
    await assert.rejects(tools.design_preview({ design: DESIGN }), /validation budget is used/);
    assert.equal(caps.candidates, 0);
});

test('design_patch edits the working copy by id; unknown ids are named', async () => {
    const { tools, build } = await newBuild();
    await assert.rejects(tools.design_patch({ set: [] }), /no working copy yet/);
    await tools.design_preview({ design: DESIGN });
    const r = await tools.design_patch({ set: [{ id: 'nadpis', props: { x: 44, color: null } }], remove: ['logo'], add: [{ element: { kind: 'shape', id: 'linka', shape: 'line', x: 20, y: 200, width: 100, height: 4, fill: '#000000' }, before: 'nadpis' }] });
    assert.equal(r.json.candidate, 2);
    const d = build.working;
    assert.deepEqual(d.elements.map((e) => e.id), ['bg', 'karta', 'linka', 'nadpis']);
    assert.equal(d.elements[3].x, 44); assert.equal('color' in d.elements[3], false);
    await assert.rejects(tools.design_patch({ remove: ['ghost'] }), /unknown element id\(s\): ghost/);
});

test('compare_region, scan_crop, scan_measure answer in canvas pixels', async () => {
    const { tools } = await newBuild();
    await assert.rejects(tools.compare_region({ x: 0, y: 0, w: 100, h: 100 }), /no rendered candidate/);
    const m = await tools.scan_measure({ x: 0, y: 0, w: 400, h: 160 });
    assert.deepEqual(m.json.ink, [20, 20, 360, 120]); assert.equal(m.json.background, '#ffffff'); assert.equal(m.images, undefined);
    const c = await tools.scan_crop({ x: 280, y: 160, w: 120, h: 120 });
    assert.deepEqual(c.json.region, [280, 160, 120, 120]); assert.equal(c.images.length, 1);
    const shifted = structuredClone(DESIGN); shifted.elements[1].x = 30;
    await tools.design_preview({ design: shifted });
    const cmp = await tools.compare_region({ x: 0, y: 0, w: 400, h: 160 });
    assert.equal(cmp.json.shift.dx, -10); assert.ok(cmp.json.mae > 0); assert.equal(cmp.images.length, 1);
    await assert.rejects(tools.scan_crop({ x: 'a', y: 0, w: 1, h: 1 }), /"x" must be a number/);
});

test('brand colours: a colour within ΔE 2 is snapped before the render and recorded', async () => {
    const { tools, build } = await newBuild({ brand: ['#0b3d6b'] });
    const d = structuredClone(DESIGN); d.elements[1].fill = '#0c3e6c';
    await tools.design_preview({ design: d });
    assert.equal(build.working.elements[1].fill, '#0b3d6b');
    assert.deepEqual(build.paletteSnaps, [{ from: '#0c3e6c', to: '#0b3d6b' }]);
});

test('placeholderMask: a picture background masks everything, an opaque shape above clears its box', () => {
    const d = { elements: [{ kind: 'background', id: 'bg', asset: 's', fillable: true }, { kind: 'shape', id: 'p', shape: 'rectangle', x: 0, y: 0, width: 50, height: 100, fill: '#fff' }, { kind: 'shape', id: 'tint', shape: 'rectangle', x: 50, y: 0, width: 50, height: 50, fill: '#000', opacity: 0.5 }] };
    assert.equal(maskShare(placeholderMask(d, 100, 100)), 0.5);
});

test('elementBox / pickLinesToRank', () => {
    assert.deepEqual(elementBox({ kind: 'text', text: 'a\nb', size: 20, lineHeight: 1.5, x: 1, y: 2, width: 100 }), { x: 1, y: 2, w: 100, h: 60 });
    assert.equal(elementBox({ kind: 'background' }), null);
    const line = (id, h, text, colour = [0, 0, 0]) => ({ id, text, ink: { w: text.length * h / 2, h, colour } });
    const picked = pickLinesToRank([line(1, 30, 'body text one'), line(2, 30, 'body text two, longer'), line(3, 80, 'HEAD'), line(4, 30, 'red body', [200, 0, 0]), line(5, 30, 'ab'), { id: 6, text: 'no ink', ink: null }], 6);
    assert.deepEqual(picked.map((l) => l.id).sort(), [2, 3, 4], 'one line per distinct size / colour — the longest of each');
    assert.equal(pickLinesToRank([line(1, 30, 'aaaa'), line(2, 60, 'bbbb'), line(3, 90, 'cccc')], 2).length, 2);
});
