// Own layers (feedback K20, docs/calendar/custom-layers-plan.md): the layer item, the invariant
// between `layers`, `boxes` and `order`, the page set, the markup per kind and its print rules,
// the editor (hit test, snap, never link), the layout operations that keep or replace them.
import assert from 'node:assert/strict';
import { copy, layoutOpts, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const L = await load('model/layers.js');
    const { normalizeLayout, normalizeDocument, parseLayoutFile, exportLayoutFile } = await load('model/normalize.js');
    const { mirrorLayout, rebaseLayoutForType, defaultLayout } = await load('model/layouts.js');
    const { DEFAULT_PAGE, K, bleedOf } = await load('model/defaults.js');
    const { isElementVisible, visibleBoxKeys, linkBoxKeys, elementLabel, boxKeysOf } = await load('model/elements.js');
    const { renderPage } = await load('render/page.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const { hitTest, pageBounds } = await load('editor/geometry.js');
    const Dg = await load('editor/drag.js');
    const { adaptBoxesToBleed } = await load('editor/bleed.js');
    const { createStore } = await load('state/store.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const base = await layoutOpts(load);
    const PAGE = copy(DEFAULT_PAGE);
    const ENV = { fonts: [], accent: '#C84B55', assets: {}, currentYear: 2026 };
    const ASSETS = {
        pic: { url: '/full/pic.jpg', previewUrl: '/preview/pic.jpg', width: 2000, height: 1000, mime: 'image/jpeg', name: 'pic.jpg', kind: 'image' },
        logo: { url: '/full/partner.svg', previewUrl: null, width: null, height: null, mime: 'image/svg+xml', name: 'partner.svg', kind: 'image' },
    };
    const opts = (extra = {}) => ({ ...base, env: { assets: ASSETS, fonts: [] }, idPrefix: 'p1', ...extra });

    /** A default 7-day doc with layers added through the pure operations. */
    async function withLayers(add, o = {}) {
        return makeDoc(load, { ...o, styles: (layout) => add({ ...layout, layers: {} }) });
    }
    const stripe = (layout) => L.addLayer(layout, 'stripe', L.newLayer('rect', '#E5DDF7'), { x: -5, y: 60, w: 320, h: 10 });

    t.test('a stored layout without layers gets an empty map and renders byte for byte as before', async () => {
        const doc = await makeDoc(load);
        assert.deepEqual(doc.design.layout.layers, {});
        assert.ok(!doc.design.layout.order.some(L.isLayerKey));
        const page = weekPages(buildPages(doc))[0];
        const withMap = renderPage(doc, page, opts());
        // the same document whose layout never had the key
        const bare = copy(doc);
        delete bare.design.layout.layers;
        assert.equal(renderPage(bare, page, opts()), withMap);
        assert.ok(!withMap.includes('data-el="layer"'));
    });

    t.test('normalizeLayer: defaults, clamps, unknown keys dropped, idempotent; the key sets per kind', () => {
        for (const kind of L.LAYER_KINDS) {
            const layer = L.newLayer(kind, '#c84b55');
            assert.deepEqual(Object.keys(layer), L.LAYER_KEYS[kind], kind);
            assert.deepEqual(L.normalizeLayer(layer), layer, `${kind} idempotent`);
            assert.equal(layer.pages, 'weeks', 'D2: a new layer is on every week page');
        }
        assert.equal(L.newLayer('rect', '#c84b55').fill.color, '#C84B55');
        const odd = L.normalizeLayer({ kind: 'rect', name: '  Pruh\n přes  stránku ', foo: 1, opacity: 137, blend: 'dissolve', radius: -3, fill: { on: 'x', color: 'red', opacity: 12 }, stroke: { on: true, width: 99, style: 'wavy', color: '#00ff00' } });
        assert.equal(odd.name, 'Pruh přes stránku');
        assert.equal(odd.foo, undefined);
        assert.equal(odd.opacity, 100);
        assert.equal(odd.blend, 'normal');
        assert.equal(odd.radius, 0);
        assert.deepEqual(odd.fill, { on: true, color: '#CBC7D1', opacity: 10 });
        assert.deepEqual(odd.stroke, { on: true, color: '#00FF00', width: 20, style: 'solid' });
        assert.equal(L.normalizeLayer({ kind: 'text' }), null);
        assert.equal(L.normalizeLayer('rect'), null);
        assert.equal(L.normalizeLayer({ kind: 'image', name: 'x'.repeat(80) }).name.length, L.LAYER_NAME_MAX);
        const pages = L.normalizeLayer({ kind: 'line', pages: ['w05', 'w05', 'cover', 'nope', 'sp:intro'], except: ['w01'] });
        assert.deepEqual(pages.pages, ['w05', 'cover', 'sp:intro']);
        assert.deepEqual(pages.except, [], 'except only beside a page set');
        assert.deepEqual(L.normalizeLayer({ kind: 'line', pages: 'all', except: ['w01', 'x'] }).except, ['w01']);
    });

    t.test('invariant: each layer has one u: box and one u: order entry; strays are dropped, missing ones repaired', async () => {
        const doc = await makeDoc(load);
        const raw = copy(doc.design.layout);
        raw.layers = { a: { kind: 'rect' }, b: { kind: 'ellipse' }, 'bad id!': { kind: 'rect' }, c: { kind: 'unknown' }, __proto__x: { kind: 'line' } };
        raw.boxes['u:a'] = { x: 10, y: 20, w: 30, h: 40, hidden: true };
        raw.boxes['u:zombie'] = { x: 1, y: 1, w: 10, h: 10 };
        raw.order = ['u:zombie', 'qr', 'u:a', 'u:a', ...raw.order.filter((k) => k !== 'qr')];
        const layout = normalizeLayout(raw, 'week', PAGE, ENV);
        assert.deepEqual(Object.keys(layout.layers).sort(), ['__proto__x', 'a', 'b']);
        assert.deepEqual(layout.boxes['u:a'], { x: 10, y: 20, w: 30, h: 40, hidden: true }, 'hidden is allowed on own boxes');
        assert.ok(layout.boxes['u:b'], 'a missing box gets the default one');
        assert.equal(layout.boxes['u:zombie'], undefined);
        assert.equal(layout.order.filter((k) => k === 'u:a').length, 1);
        assert.ok(!layout.order.includes('u:zombie'));
        assert.ok(layout.order.indexOf('u:b') < layout.order.indexOf('qr'), 'a missing order entry goes on top');
        assert.ok(layout.order.indexOf('qr') < layout.order.indexOf('u:a'), 'the stored place is kept');
        assert.deepEqual(normalizeLayout(layout, 'week', PAGE, ENV), layout, 'idempotent');
        const many = { ...copy(doc.design.layout), layers: Object.fromEntries(Array.from({ length: 60 }, (_, i) => [`l${i}`, { kind: 'rect' }])) };
        assert.equal(Object.keys(normalizeLayout(many, 'week', PAGE, ENV).layers).length, L.MAX_LAYERS);
    });

    t.test('layerOnPage: weeks (default), all, a list, except — keys without a page are simply not shown', async () => {
        const doc = await makeDoc(load, { features: { specialPages: true } });
        const pages = buildPages(doc);
        const week = weekPages(pages)[3];
        const cover = pages.find((p) => p.kind === 'cover');
        const on = (layer, page) => L.layerOnPage(L.normalizeLayer({ kind: 'rect', ...layer }), page);
        assert.equal(on({}, week), true);
        assert.equal(on({}, cover), false);
        assert.equal(on({ pages: 'all' }, cover), true);
        assert.equal(on({ pages: [week.key] }, week), true);
        assert.equal(on({ pages: [week.key] }, weekPages(pages)[4]), false);
        assert.equal(on({ pages: ['w53'] }, week), false);
        assert.equal(on({ pages: 'weeks', except: [week.key] }, week), false);
        assert.equal(on({ pages: 'all', except: ['cover'] }, cover), false);
    });

    t.test('markup per kind: inside strokes, ellipse, line through the middle, dashes, opacity + blend on one group', async () => {
        const doc = await withLayers((layout) => {
            let l = L.addLayer(layout, 'r', { ...L.newLayer('rect', '#E5DDF7'), radius: 3, opacity: 60, blend: 'multiply', stroke: { on: true, color: '#2B2830', width: 2, style: 'dash' } }, { x: 10, y: 20, w: 40, h: 30 });
            l = L.addLayer(l, 'e', { ...L.newLayer('ellipse', '#C84B55'), fill: { on: false, color: '#C84B55', opacity: 100 }, stroke: { on: true, color: '#C84B55', width: 1, style: 'dot' } }, { x: 60, y: 20, w: 40, h: 20 });
            l = L.addLayer(l, 'h', L.newLayer('line', '#112233'), { x: 100, y: 50, w: 100, h: 4 });
            l = L.addLayer(l, 'v', { ...L.newLayer('line', '#112233'), stroke: { color: '#112233', width: 2, style: 'solid', cap: 'round' } }, { x: 250, y: 20, w: 6, h: 80 });
            return l;
        });
        const page = weekPages(buildPages(doc))[0];
        const svg = renderPage(doc, page, opts());
        const group = (id) => svg.match(new RegExp(`<g data-el="layer" data-layer="${id}"[^>]*>(.*?)</g>`))[0];
        const mm = (pt) => pt * 25.4 / 72;
        const r = group('r');
        assert.match(r, /opacity="0\.6" style="mix-blend-mode:multiply"/);
        assert.match(r, /<rect x="10" y="20" width="40" height="30" rx="3" fill="#E5DDF7"\/>/);
        const sw = mm(2);
        assert.ok(r.includes(`x="${(10 + sw / 2).toFixed(3).replace(/0+$/, '')}"`), 'the stroke sits inside the box');
        assert.ok(r.includes(`stroke-dasharray="${(sw * 4).toFixed(3).replace(/0+$/, '')} ${(sw * 3).toFixed(3).replace(/0+$/, '')}"`));
        const e = group('e');
        assert.ok(!/fill="#C84B55"/.test(e), 'fill off');
        assert.match(e, /<ellipse cx="80" cy="30" /);
        assert.match(e, /stroke-linecap="round"/, 'dots are round');
        assert.match(group('h'), /<line x1="100" y1="52" x2="200" y2="52" stroke="#112233"/);
        assert.match(group('v'), /<line x1="253" y1="20" x2="253" y2="100"[^>]*stroke-linecap="round"/);
        assert.ok(svg.indexOf('data-layer="r"') < svg.indexOf('data-layer="v"'), 'drawn bottom first (v was added last = top)');
    });

    t.test('a picture layer: its own bytes, clipped, never a filter or a mask; an empty one only in the editor', async () => {
        const doc = await withLayers((layout) => {
            let l = L.addLayer(layout, 'cov', { ...L.newLayer('image'), asset: 'pic', fit: 'cover', radius: 4 }, { x: 10, y: 20, w: 40, h: 40 });
            l = L.addLayer(l, 'con', { ...L.newLayer('image'), asset: 'logo' }, { x: 60, y: 20, w: 40, h: 20 });
            l = L.addLayer(l, 'nil', L.newLayer('image'), { x: 110, y: 20, w: 40, h: 20 });
            return l;
        });
        const page = weekPages(buildPages(doc))[0];
        const svg = renderPage(doc, page, opts({ placeholders: 'none' }));
        const cov = svg.match(/<g data-el="layer" data-layer="cov".*?<\/g><\/g>/)[0];
        assert.match(cov, /<clipPath id="p1-Lcov-c"><rect x="10" y="20" width="40" height="40" rx="4"\/><\/clipPath>/);
        assert.match(cov, /<image href="\/preview\/pic\.jpg"[^>]*preserveAspectRatio="xMidYMid slice"\/>/);
        assert.match(svg, /<g data-el="layer" data-layer="con"><image href="\/full\/partner\.svg"[^>]*meet"\/><\/g>/, 'contain without a radius needs no clip');
        assert.ok(!svg.includes('data-layer="nil"'), 'an empty picture prints nothing');
        for (const m of svg.matchAll(/<image[^>]*>/g)) assert.ok(!/filter=|mask=/.test(m[0]), m[0]);
        assert.match(renderPage(doc, page, opts({ placeholders: 'all' })), /data-layer="nil"><rect[^>]*fill="#E2DFE6"/, 'the editor shows the empty place');
        const full = renderPage(doc, page, opts({ quality: 'full', placeholders: 'none' }));
        assert.match(full, /href="\/full\/pic\.jpg"/, 'print uses the uploaded file');
    });

    t.test('the eye hides a layer everywhere; a page outside the set does not draw it', async () => {
        const doc = await withLayers((layout) => {
            let l = stripe(layout);
            l = L.addLayer(l, 'only5', { ...L.newLayer('ellipse'), pages: ['w05'] }, { x: 10, y: 20, w: 20, h: 20 });
            l.boxes['u:stripe'] = { ...l.boxes['u:stripe'], hidden: true };
            return l;
        });
        const pages = weekPages(buildPages(doc));
        assert.equal(isElementVisible(doc.design, 'u:stripe'), false);
        assert.equal(isElementVisible(doc.design, 'u:only5'), true);
        assert.equal(isElementVisible(doc.design, 'u:nothing'), false);
        assert.ok(!renderPage(doc, pages[0], opts()).includes('data-layer='));
        assert.ok(renderPage(doc, pages.find((p) => p.key === 'w05'), opts()).includes('data-layer="only5"'));
    });

    t.test('editor: hit test in stacking order, snap to and from layers, never a link', async () => {
        const doc = await withLayers((layout) => {
            const l = L.addLayer(layout, 'frame', L.newLayer('rect'), { x: 100, y: 40, w: 50, h: 30 });
            return { ...l, order: [...l.order.filter((k) => k !== 'u:frame'), 'u:frame'] };
        });
        const d = doc.design;
        assert.deepEqual(boxKeysOf('u:frame', d), ['u:frame']);
        assert.deepEqual(hitTest({ x: 120, y: 50 }, d, null), { el: 'photo' }, 'under the photo: the photo wins');
        const top = { ...d, layout: { ...d.layout, order: ['u:frame', ...d.layout.order.filter((k) => k !== 'u:frame')] } };
        assert.deepEqual(hitTest({ x: 120, y: 50 }, top, null), { el: 'u:frame' });
        assert.ok(visibleBoxKeys(d).includes('u:frame'));
        assert.ok(!linkBoxKeys(d).includes('u:frame'));
        assert.equal(elementLabel('u:frame', d.layout), 'Obdélník 1');
        // a resize of the photo with Propojit prvky never takes the layer along, even when an edge coincides
        const photo = d.layout.boxes.photo;
        const aligned = { ...d, layout: { ...d.layout, boxes: { ...d.layout.boxes, 'u:frame': { x: photo.x + photo.w - 30, y: photo.y, w: 30, h: 30 } } } };
        const session = Dg.beginDrag({ boxes: aligned.layout.boxes, key: 'photo', mode: 'e', page: d.page, bleedMm: bleedOf(d.print), visibleKeys: visibleBoxKeys(aligned), linkKeys: linkBoxKeys(aligned), link: true });
        const moved = Dg.dragTo(session, 10, 0, { snap: false });
        assert.deepEqual(moved.boxes['u:frame'], aligned.layout.boxes['u:frame']);
        assert.notEqual(moved.boxes.cal.w, aligned.layout.boxes.cal.w, 'the Kalendárium still follows');
        // a resize of the layer never links either
        const own = Dg.beginDrag({ boxes: aligned.layout.boxes, key: 'u:frame', mode: 'e', page: d.page, bleedMm: bleedOf(d.print), visibleKeys: visibleBoxKeys(aligned), linkKeys: linkBoxKeys(aligned), link: true });
        const grown = Dg.dragTo(own, 5, 0, { snap: false });
        assert.deepEqual(grown.boxes.cal, aligned.layout.boxes.cal);
        // the logo snaps to the layer's edge
        const logo = d.layout.boxes.logo;
        const snapping = Dg.beginDrag({ boxes: d.layout.boxes, key: 'logo', mode: 'move', page: d.page, bleedMm: bleedOf(d.print), visibleKeys: visibleBoxKeys(d), linkKeys: linkBoxKeys(d), link: false });
        const near = Dg.dragTo(snapping, 100 + 50 - logo.x + 0.4, 0, { snap: true, thresholdMm: 1 });
        assert.equal(near.boxes.logo.x, 150);
    });

    t.test('operations: add on top, duplicate right above (shifted, inside the page), remove, labels numbered bottom first', async () => {
        const doc = await makeDoc(load);
        let l = { ...doc.design.layout, layers: {} };
        l = L.addLayer(l, 'a', L.newLayer('rect'), { x: 300, y: 140, w: 10, h: 5 });
        assert.equal(l.order[0], 'u:a');
        l = L.addLayer(l, 'b', L.newLayer('rect'), { x: 10, y: 10, w: 10, h: 10 });
        const labels = L.layerLabels(l);
        assert.equal(labels['u:a'], 'Obdélník 1');
        assert.equal(labels['u:b'], 'Obdélník 2');
        const bounds = pageBounds(PAGE, 5);
        const dup = L.duplicateLayer(l, 'a', 'c', bounds);
        assert.deepEqual(L.duplicateLayer(l, 'a', 'c', pageBounds(PAGE, 0)).boxes['u:c'], { x: 297, y: 137, w: 10, h: 5 }, 'back inside when the shift would leave the page');
        assert.deepEqual(dup.order.slice(0, 3), ['u:b', 'u:c', 'u:a']);
        assert.deepEqual(dup.boxes['u:c'], { x: 303, y: 143, w: 10, h: 5 }, 'shifted 3 mm, still inside the bleed');
        assert.deepEqual(dup.layers.c, l.layers.a);
        assert.notEqual(dup.layers.c, l.layers.a, 'a copy, not the same object');
        const gone = L.removeLayer(dup, 'a');
        assert.equal(gone.layers.a, undefined);
        assert.equal(gone.boxes['u:a'], undefined);
        assert.ok(!gone.order.includes('u:a'));
        assert.equal(L.layerLabels({ ...gone, layers: { ...gone.layers, b: { ...gone.layers.b, name: 'Pruh' } } })['u:b'], 'Pruh');
        const box = L.defaultLayerBox('image', PAGE, 2);
        assert.ok(Math.abs(box.w / box.h - 2) < 0.02, 'a picture keeps its aspect');
        assert.ok(Math.abs(box.x + box.w / 2 - PAGE.width / 2) < 0.06, 'centred');
        assert.equal(L.defaultLayerBox('line', PAGE).h, K.MIN_H);
    });

    t.test('layout operations: mirror and bleed take the boxes along, a type change and a preset over a scan keep the layers, a layout file carries them', async () => {
        const doc = await withLayers(stripe);
        const layout = doc.design.layout;
        const mirrored = mirrorLayout(layout, PAGE);
        assert.deepEqual(mirrored.layers, layout.layers);
        assert.equal(mirrored.boxes['u:stripe'].x, PAGE.width - layout.boxes['u:stripe'].x - layout.boxes['u:stripe'].w);
        const adapted = adaptBoxesToBleed(layout.boxes, PAGE, 5, 3);
        assert.equal(adapted['u:stripe'].x, -3, 'an edge in the old bleed follows the new one');
        const rebased = rebaseLayoutForType(layout, 'fortnight', PAGE, 5);
        assert.deepEqual(rebased.layout.layers, layout.layers);
        assert.deepEqual(rebased.layout.boxes['u:stripe'], layout.boxes['u:stripe']);
        assert.equal(rebased.layout.order.indexOf('u:stripe'), layout.order.indexOf('u:stripe'));
        const carried = L.carryLayers(defaultLayout('photoRight', PAGE, 5, ENV), layout);
        assert.deepEqual(carried.layers, layout.layers);
        assert.equal(carried.order[0], 'u:stripe');
        const file = exportLayoutFile(doc);
        const parsed = parseLayoutFile(JSON.stringify(file), { ...doc.design, page: { ...PAGE, width: 620, height: 290 } });
        assert.ok(parsed.ok);
        assert.deepEqual(parsed.layout.layers, layout.layers);
        assert.ok(parsed.layout.boxes['u:stripe'].w > 600, 'scaled with the page');
    });

    t.test('store: the layer ops are one undo step each; a client cannot change them; replaceLayout replaces or keeps them', async () => {
        const doc = await makeDoc(load);
        const env = { ...ENV, assets: {}, mode: 'designer' };
        const api = { save: () => Promise.reject(new Error('no')) };
        const store = createStore({ doc: normalizeDocument(doc, env), env, api, measurer: createEstimatorMeasurer() });
        store.change((layout) => L.addLayer(layout, 'x', L.newLayer('ellipse'), L.defaultLayerBox('ellipse', PAGE)));
        assert.ok(store.getState().doc.design.layout.layers.x);
        store.undo();
        assert.deepEqual(store.getState().doc.design.layout.layers, {});
        store.redo();
        assert.ok(store.getState().doc.design.layout.layers.x);
        const scanned = defaultLayout('photoRight', PAGE, 5, ENV);
        store.replaceLayout(scanned, {}, { keepLayers: true });
        assert.ok(store.getState().doc.design.layout.layers.x, 'the AI scan keeps the own layers');
        store.replaceLayout(scanned, {});
        assert.deepEqual(store.getState().doc.design.layout.layers, {}, 'a layout file is the design');
        const client = createStore({ doc: normalizeDocument(doc, env), env: { ...env, mode: 'client' }, api, measurer: createEstimatorMeasurer() });
        assert.throws(() => client.change((layout) => L.addLayer(layout, 'x', L.newLayer('rect'), { x: 1, y: 1, w: 10, h: 10 })));
    });
}
