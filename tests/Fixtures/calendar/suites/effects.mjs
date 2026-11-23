// Layer effects of the pictures (model/effects.js, render/effects.js; docs/calendar/effects-plan.md).
import assert from 'node:assert/strict';
import { ENV, copy, layoutOpts, makeDoc, near } from '../helpers.mjs';

const ids = (svg) => [...svg.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
const refs = (svg) => [...svg.matchAll(/url\(#([^)]+)\)/g)].map((m) => m[1]);

const SHADOW = { kind: 'dropShadow', on: true, blend: 'multiply', opacity: 60, color: '#000000', angle: 120, distance: 2, spread: 50, size: 4 };
const CORNER = { kind: 'gradient', on: true, blend: 'multiply', opacity: 80, type: 'radial', angle: 90, scale: 100, x: 100, y: 0, stops: [{ color: '#000000', opacity: 70, pos: 0 }, { color: '#000000', opacity: 0, pos: 100 }] };
const FADE = { kind: 'gradient', on: true, blend: 'normal', opacity: 100, type: 'linear', angle: 0, scale: 100, x: 50, y: 50, stops: [{ color: '#FFFFFF', opacity: 0, pos: 0 }, { color: '#FFFFFF', opacity: 100, pos: 100 }] };

export default async function (t, load) {
    const E = await load('model/effects.js');
    const R = await load('render/effects.js');
    const N = await load('model/normalize.js');
    const { renderPage } = await load('render/page.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const base = await layoutOpts(load);
    const ASSETS = {
        photo1: { url: '/full/photo.jpg', previewUrl: '/preview/photo.jpg', width: 4000, height: 3000, mime: 'image/jpeg', kind: 'photo' },
        logo1: { url: '/full/logo.png', previewUrl: null, width: 400, height: 200, mime: 'image/png', kind: 'logo' },
        head1: { url: '/full/head.jpg', previewUrl: null, width: 3000, height: 400, mime: 'image/jpeg', kind: 'image' },
        bg1: { url: '/full/bg.png', previewUrl: null, width: 3000, height: 1500, mime: 'image/png', kind: 'image' },
        portrait: { url: '/full/portrait.png', previewUrl: null, width: 800, height: 1000, mime: 'image/png', kind: 'photo' },
    };
    const opts = (extra = {}) => ({ ...base, env: { assets: ASSETS, fonts: [] }, idPrefix: 'p1', ...extra });

    const everywhere = () =>
        makeDoc(load, {
            features: { specialPages: true },
            content: {
                pagePhotos: { pre: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } },
                pageImages: { pre: { head: null, bg: 'bg1' } },
                specialPages: { intro: { kind: 'intro', on: true, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 0 }, contacts: { kind: 'contacts', on: true, layout: 3, position: 'back', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 1 } },
                pageContent: { cover: { title: '', subtitle: '', note: '', photo: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } }, 'sp:intro': { heading: '', salutation: '', body: '', signName: '', signRole: '', photo: { asset: 'portrait', zoom: 1, px: 0.5, py: 0.5 } } },
            },
            styles: (l) => {
                l.styles.photo.effects = [CORNER, SHADOW, copy(CORNER)];
                l.styles.logo.asset = 'logo1';
                l.styles.logo.effects = [SHADOW, FADE];
                l.styles.head.bg = { ...l.styles.head.bg, imageOn: true, asset: 'head1', effects: [FADE] };
                l.styles.bg = { ...l.styles.bg, imageOn: true, asset: null, effects: [{ ...CORNER, blend: 'screen' }] };
                l.styles.specialPhoto.effects = [SHADOW, CORNER];
            },
        });

    t.test('an effect list is normalised: kinds, blends, ranges, caps, colours', () => {
        const list = E.normalizeEffects([
            { kind: 'dropShadow', blend: 'dissolve', opacity: 140, color: '#abcdef', angle: 300, distance: -2, spread: 120, size: 99 },
            { kind: 'satin' },
            null,
            { kind: 'gradient', type: 'diamond', angle: -90, scale: 5, x: 900, y: -900, stops: [{ color: 'red', opacity: 50, pos: 33.4 }] },
        ]);
        assert.equal(list.length, 2, 'unknown kinds are dropped');
        assert.deepEqual(list[0], { kind: 'dropShadow', on: true, blend: 'multiply', opacity: 100, color: '#ABCDEF', angle: -60, distance: 0, spread: 100, size: 30 });
        assert.deepEqual([list[1].type, list[1].angle, list[1].scale, list[1].x, list[1].y], ['linear', 270, 10, 150, -50]);
        assert.deepEqual(list[1].stops, E.newEffect('gradient').stops, 'fewer than two stops → the default stops');
        assert.equal(E.normalizeEffects(Array.from({ length: 30 }, () => SHADOW)).length, E.MAX_EFFECTS);
        assert.deepEqual(E.normalizeEffects('x'), []);
        assert.deepEqual(E.normalizeEffects(E.normalizeEffects([SHADOW, CORNER])), E.normalizeEffects([SHADOW, CORNER]), 'idempotent');
    });

    t.test('exactly the 16 PDF-native blend modes, in Photoshop order', () => {
        assert.deepEqual(E.BLEND_VALUES, ['normal', 'darken', 'multiply', 'color-burn', 'lighten', 'screen', 'color-dodge', 'overlay', 'soft-light', 'hard-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity']);
        for (const absent of ['plus-lighter', 'plus-darker', 'dissolve', 'linear-burn']) assert.ok(!E.BLEND_VALUES.includes(absent), absent);
    });

    t.test('the old photo „Stín“ becomes a drop shadow that draws the same', () => {
        const old = { on: true, blur: 3, opacity: 35, dx: 1.5, dy: 2, color: '#112233' };
        const [fx] = E.shadowToEffects(old);
        assert.equal(fx.kind, 'dropShadow');
        assert.equal(fx.blend, 'normal');
        assert.equal(fx.size, 3);
        assert.equal(fx.spread, 0);
        assert.equal(fx.opacity, 35);
        const { dx, dy } = E.shadowOffset(fx);
        near(assert, dx, 1.5, 'dx', 0.03);
        near(assert, dy, 2, 'dy', 0.03);
        assert.deepEqual(E.shadowToEffects({ ...old, on: false }), []);

        const stored = (photo) => ({ id: 'c', revision: 1, year: 2027, type: 'week', preset: 'photoLeft', layout: { v: 1, boxes: {}, order: [], styles: { photo } }, content: {} });
        const doc = N.normalizeDocument(stored({ source: 'client', radius: 0, shadow: old }), ENV);
        assert.equal(doc.design.layout.styles.photo.shadow, undefined, 'the old key is gone');
        assert.deepEqual(doc.design.layout.styles.photo.effects, E.shadowToEffects(old));
        assert.deepEqual(N.normalizeDocument(copy(doc), ENV), doc, 'idempotent');
        // a stored list wins over a leftover shadow
        const kept = N.normalizeDocument(stored({ shadow: old, effects: [CORNER] }), ENV);
        assert.deepEqual(kept.design.layout.styles.photo.effects, E.normalizeEffects([CORNER]));
    });

    t.test('panel order: Photoshop kinds, list order inside a kind; moving stays inside a kind', () => {
        const list = [SHADOW, CORNER, { ...SHADOW, size: 1 }, FADE];
        assert.deepEqual(E.displayOrder(list), [1, 3, 0, 2]);
        assert.deepEqual(E.moveEffect(list, 2, -1).map((e) => e.size || e.type), [1, 'radial', 4, 'linear']);
        assert.equal(E.moveEffect(list, 0, -1), list, 'the first of its kind does not move up');
        assert.deepEqual(E.moveEffect(list, 1, 1).map((e) => e.type || e.kind), ['dropShadow', 'linear', 'dropShadow', 'radial']);
    });

    t.test('gradient geometry is in real mm: a radial circle, a linear line edge to edge, a reflected one mirrored', () => {
        const box = { x: 10, y: 20, w: 100, h: 50 };
        assert.deepEqual(R.gradientGeometry(CORNER, box), { cx: 110, cy: 20, r: 50 });
        const g = R.gradientGeometry(FADE, box);
        assert.deepEqual([g.x1, g.y1, g.x2, g.y2], [10, 45, 110, 45]);
        const diag = R.gradientGeometry({ ...FADE, angle: 90, scale: 50 }, box);
        near(assert, diag.y1, 32.5, 'y1');
        near(assert, diag.y2, 57.5, 'y2');
        const reflected = R.gradientMarkup('r', { ...FADE, type: 'reflected' }, box);
        assert.deepEqual([...reflected.matchAll(/offset="([\d.]+)%"/g)].map((m) => Number(m[1])), [0, 50, 50, 100]);
        assert.match(reflected, /stop-opacity="1".*stop-opacity="0".*stop-opacity="0".*stop-opacity="1"/);
    });

    t.test('a drop shadow of a rectangle: offset by the light angle, grown by the spread, blurred by the rest', () => {
        const svg = R.withEffects([{ ...SHADOW, angle: 90, distance: 2, spread: 50, size: 4 }], { type: 'rect', box: { x: 0, y: 0, w: 10, h: 10 }, radius: 1 }, '<image href="x"/>', 'f');
        assert.match(svg, /<rect x="-2" y="0" width="14" height="14" rx="3" fill="#000000" fill-opacity="0.6" filter="url\(#f0\)" style="mix-blend-mode:multiply" data-fx="dropShadow"\/>/);
        assert.match(svg, /feGaussianBlur stdDeviation="1"/);
        assert.ok(svg.indexOf('data-fx') < svg.indexOf('<image'), 'under the picture');
        assert.ok(!svg.includes('isolation'), 'no overlay, no isolation group');
    });

    t.test('switched-off effects draw nothing; no effects = the picture unchanged', () => {
        assert.equal(R.withEffects([], { type: 'rect', box: { x: 0, y: 0, w: 1, h: 1 } }, '<image/>', 'f'), '<image/>');
        assert.equal(R.withEffects([{ ...SHADOW, on: false }], { type: 'rect', box: { x: 0, y: 0, w: 1, h: 1 } }, '<image/>', 'f'), '<image/>');
    });

    t.test('every picture gets its effects; the pictures themselves never carry a filter or a mask', async () => {
        const doc = await everywhere();
        const pages = buildPages(doc);
        const week = weekPages(pages).find((p) => p.key === 'pre');
        const svg = renderPage(doc, week, opts({ quality: 'full', placeholders: 'none' }));
        assert.ok(!/<mask\b|\smask="/.test(svg), 'no SVG masks (the CMYK pass refuses them)');
        for (const m of svg.matchAll(/<image\b[^>]*>/g)) assert.ok(!/\sfilter=|\smask=/.test(m[0]), `a picture with a filter: ${m[0].slice(0, 80)}`);
        // the photo: two overlays + a shadow; JPEG → rectangle shapes (no copy of the photo)
        const photo = svg.slice(svg.indexOf('data-el="photo"'), svg.indexOf('data-el="cal"'));
        assert.equal((photo.match(/data-fx="gradient"/g) || []).length, 2);
        assert.equal((photo.match(/data-fx="dropShadow"/g) || []).length, 1);
        assert.equal((photo.match(/\/full\/photo\.jpg/g) || []).length, 1, 'the photo is painted once');
        assert.ok(photo.includes('style="isolation:isolate"'));
        // the logo (PNG): its outline drives the effects — copies inside filters, an feImage gradient
        const logo = svg.slice(svg.indexOf('data-el="logo"'));
        assert.ok((logo.match(/\/full\/logo\.png/g) || []).length >= 3, 'the logo + a copy per effect');
        assert.match(logo, /<feImage href="data:image\/svg\+xml,/);
        assert.match(logo, /feMorphology in="SourceAlpha" operator="dilate" radius="2"/);
        // header picture (style) and the page's own background picture
        assert.ok(svg.slice(svg.indexOf('data-el="head"')).includes('data-fx="gradient"'));
        assert.ok(svg.slice(svg.indexOf('data-el="bg"'), svg.indexOf('data-el="bg"') + 4000).includes('mix-blend-mode:screen'));
        // the special pages: cover photo (JPEG) and the intro portrait (PNG → outline)
        const cover = renderPage(doc, pages.find((p) => p.kind === 'cover'), opts());
        assert.ok(cover.includes('data-fx="dropShadow"') && cover.includes('data-fx="gradient"'));
        assert.ok(cover.slice(cover.indexOf('data-el="page-logo"')).includes('data-fx'), 'the cover logo has the logo effects');
        const intro = renderPage(doc, pages.find((p) => p.special && p.special.id === 'intro'), opts());
        assert.match(intro, /<filter[^>]*>(?:(?!<\/filter>).)*feMorphology/, 'the transparent portrait casts its shadow from its outline');
        for (const page of pages) {
            const out = renderPage(doc, page, opts({ idPrefix: `x${page.index}` }));
            const list = ids(out);
            assert.equal(new Set(list).size, list.length, `${page.key}: unique ids`);
            for (const ref of refs(out)) assert.ok(list.includes(ref), `${page.key}: dangling #${ref}`);
            for (const m of out.matchAll(/mix-blend-mode:([a-z-]+)/g)) assert.ok(E.BLEND_VALUES.includes(m[1]), m[1]);
        }
    });

    t.test('an empty photo frame shows the effects on screen, nothing goes on paper', async () => {
        const doc = await makeDoc(load, { styles: (l) => void (l.styles.photo.effects = [SHADOW]) });
        const page = weekPages(buildPages(doc))[3];
        assert.ok(renderPage(doc, page, opts({ placeholders: 'all' })).includes('data-fx="dropShadow"'));
        assert.ok(!renderPage(doc, page, opts({ placeholders: 'none' })).includes('data-fx'));
    });

    t.test('effects render on any page size', async () => {
        const doc = await makeDoc(load, { page: { width: 420, height: 200 }, styles: (l) => void (l.styles.photo.effects = [SHADOW, CORNER]) });
        const svg = renderPage(doc, weekPages(buildPages(doc))[0], opts({ placeholders: 'all' }));
        const box = doc.design.layout.boxes.photo;
        assert.ok(svg.includes(`cx="${Math.round((box.x + box.w) * 1000) / 1000}"`), 'the corner of the photo on a 420 mm page');
    });
    t.test('a page replaces the style list of one picture; other pictures and pages keep the style', async () => {
        const C = await load('model/content.js');
        const doc = await makeDoc(load, {
            features: { specialPages: true },
            content: {
                pagePhotos: { w02: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 }, w03: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } },
                pageEffects: { w02: { photo: [CORNER, { ...CORNER, x: 0 }], logo: [], head: null, bg: null }, cover: { photo: [FADE], logo: null, head: null, bg: null } },
                pageContent: { cover: { title: '', subtitle: '', note: '', photo: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } } },
            },
            styles: (l) => {
                l.styles.photo.effects = [SHADOW];
                l.styles.logo.asset = 'logo1';
                l.styles.logo.effects = [SHADOW];
                l.styles.specialPhoto.effects = [SHADOW];
            },
        });
        const pages = buildPages(doc);
        const w02 = pages.find((p) => p.key === 'w02');
        const w03 = pages.find((p) => p.key === 'w03');
        const cover = pages.find((p) => p.kind === 'cover');
        assert.deepEqual(E.effectsFor(doc, w02, 'photo').map((e) => e.x), [100, 0], 'the page list');
        assert.deepEqual(E.effectsFor(doc, w02, 'logo'), [], 'an empty own list = no effects on this page');
        assert.equal(E.effectsFor(doc, w03, 'photo')[0].kind, 'dropShadow', 'another page keeps the style');
        assert.equal(E.effectsFor(doc, cover, 'photo')[0].type, 'linear', 'a special page: its own list over specialPhoto');
        assert.equal(E.styleEffectPath(cover, 'photo'), 'specialPhoto.effects');
        const svg2 = renderPage(doc, w02, opts());
        const svg3 = renderPage(doc, w03, opts());
        const photo = (svg) => svg.slice(svg.indexOf('data-el="photo"'), svg.indexOf('data-el="cal"'));
        assert.equal((photo(svg2).match(/data-fx="gradient"/g) || []).length, 2);
        assert.ok(!photo(svg2).includes('dropShadow'));
        assert.ok(photo(svg3).includes('data-fx="dropShadow"') && !photo(svg3).includes('data-fx="gradient"'));
        assert.ok(!svg2.slice(svg2.indexOf('data-el="logo"')).includes('data-fx'), 'the logo of w02 has none');

        // the item: null back to the style; all null → no item
        const item = E.pageEffectsItem(doc.content, 'w02', 'photo', null);
        assert.deepEqual(item, { photo: null, logo: [], head: null, bg: null });
        assert.equal(E.pageEffectsItem({ pageEffects: {} }, 'w05', 'photo', null), null);
        // page-keyed: „Vrátit na styl“ removes it, „Upraveno“ sees it
        assert.ok(C.pageHasOverrides(doc.content, 'cover'));
        assert.ok(C.clearPageOverrides(doc.content, 'w02').some((op) => op.c === 'pageEffects'));
        assert.equal(E.pagesOverriding(doc.content, pages, 'photo.effects'), 1);
        assert.equal(E.pagesOverriding(doc.content, pages, 'specialPhoto.effects'), 1);
        assert.equal(E.pagesOverriding(doc.content, pages, 'logo.effects'), 1);
    });
    t.test('P3: inner shadow, outer and inner glow — Photoshop stacking, a rectangle and an outline', () => {
        const GLOW = { kind: 'outerGlow', on: true, blend: 'screen', opacity: 75, color: '#FFFFBE', spread: 20, size: 4 };
        const IN_GLOW = { ...GLOW, kind: 'innerGlow' };
        const IN_SHADOW = { ...SHADOW, kind: 'innerShadow', spread: 25 };
        assert.deepEqual(E.normalizeEffect({ kind: 'outerGlow', angle: 90 }), { kind: 'outerGlow', on: true, blend: 'screen', opacity: 75, color: '#FFFFBE', spread: 0, size: 3 }, 'a glow has no angle / distance');
        assert.deepEqual(E.effectKeys('innerShadow'), E.effectKeys('dropShadow').map((k) => k));
        assert.equal(E.mirrorEffects([IN_SHADOW])[0].angle, 60);
        const list = [IN_SHADOW, FADE, GLOW, IN_GLOW, SHADOW];
        const rect = R.withEffects(list, { type: 'rect', box: { x: 0, y: 0, w: 20, h: 10 }, radius: 2 }, '<image href="p"/>', 'f');
        const order = [...rect.matchAll(/data-fx="(\w+)"|<image/g)].map((m) => m[1] || 'IMAGE');
        assert.deepEqual(order, ['dropShadow', 'outerGlow', 'IMAGE', 'gradient', 'innerGlow', 'innerShadow'], 'Photoshop stacking, bottom → top');
        assert.match(rect, /<g clip-path="url\(#f0-c\)" style="mix-blend-mode:multiply" data-fx="innerShadow"><path d="[^"]+" fill-rule="evenodd"/, 'an inner shadow of a rectangle: an even-odd frame, clipped');
        assert.ok(!/<mask\b/.test(rect));
        const alpha = R.withEffects([IN_SHADOW, IN_GLOW, GLOW], { type: 'alpha', box: { x: 0, y: 0, w: 20, h: 10 }, copy: '<image href="logo.png"/>' }, '<image href="logo.png"/>', 'a');
        assert.equal((alpha.match(/tableValues="1 0"/g) || []).length, 2, 'inner effects invert the outline');
        assert.match(alpha, /data-fx="outerGlow"/);
        for (const m of alpha.matchAll(/<image\b[^>]*>/g)) assert.ok(!/filter=/.test(m[0]));
    });
}
