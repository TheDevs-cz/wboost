// Warnings (architecture §E.12, handoff §4.8, checks C3, C8).
import assert from 'node:assert/strict';
import { layoutOpts, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const { pageWarnings, allWarnings, photoDpi } = await load('render/warnings.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const base = await layoutOpts(load);
    const asset = (width, height) => ({ url: '/a.jpg', previewUrl: null, width, height, mime: 'image/jpeg', name: 'a.jpg', description: null, kind: 'photo' });
    const optsWith = (assets) => ({ ...base, env: { assets, fonts: [] } });
    const first = (doc) => weekPages(buildPages(doc))[0];

    t.test('dpi formula: cover scale of the real frame in mm, width- or height-driven, divided by the zoom', () => {
        const frame = { x: -5, y: 27, w: 202, h: 123 };
        // 4:3 photo in a 202 × 123 frame: the width drives the cover scale
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame, 1), Math.round(25.4 / (202 / 4000)));
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame, 1), 503);
        // a wide panorama: the height drives it (the prototype's width-only formula would say 1006 dpi)
        assert.equal(photoDpi({ width: 8000, height: 2000 }, frame, 1), Math.round(25.4 / (123 / 2000)));
        assert.equal(photoDpi({ width: 8000, height: 2000 }, frame, 1), 413);
        // zooming in lowers the effective resolution
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame, 2), 251);
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame, 3), 168);
        assert.equal(photoDpi({ width: 4000, height: 3000 }, frame), 503);
    });

    t.test('C3 a small photo warns below 200 dpi, also once zoomed in', async () => {
        const content = (zoom) => ({ pagePhotos: { pre: { asset: 'p', zoom, px: 0.5, py: 0.5 } } });
        const doc = await makeDoc(load, { content: content(1) });
        const page = first(doc);
        assert.deepEqual(pageWarnings(doc, page, optsWith({ p: asset(4000, 3000) })), []);
        const low = pageWarnings(doc, page, optsWith({ p: asset(1200, 900) }));
        assert.deepEqual(low, [{ pageIndex: page.index, kind: 'dpi', target: { el: 'photo' }, message: 'Nízká kvalita fotky — 151 dpi (pro tisk aspoň 300 dpi)' }]);
        // 1600 px: 201 dpi at 100 % — fine; at 150 % it is 134 dpi
        assert.deepEqual(pageWarnings(doc, page, optsWith({ p: asset(1600, 1200) })), []);
        const zoomed = await makeDoc(load, { content: content(1.5) });
        assert.deepEqual(pageWarnings(zoomed, first(zoomed), optsWith({ p: asset(1600, 1200) })).map((w) => w.message), ['Nízká kvalita fotky — 134 dpi (pro tisk aspoň 300 dpi)']);
        // unknown pixel size or a missing asset: no photo warnings
        assert.deepEqual(pageWarnings(doc, page, optsWith({ p: asset(null, null) })), []);
        assert.deepEqual(pageWarnings(doc, page, optsWith({})), []);
        // other pages are not affected
        assert.deepEqual(pageWarnings(doc, weekPages(buildPages(doc))[1], optsWith({ p: asset(1200, 900) })), []);
    });

    t.test('C3 a portrait photo in a landscape frame', async () => {
        const doc = await makeDoc(load, { content: { pagePhotos: { pre: { asset: 'p', zoom: 1, px: 0.5, py: 0.5 } } } });
        const page = first(doc);
        const w = pageWarnings(doc, page, optsWith({ p: asset(3000, 4500) }));
        assert.deepEqual(w.map((x) => [x.kind, x.message]), [['portrait', 'Fotka je na výšku — do rámečku na šířku se ořízne'], ['dpi', 'Nízká kvalita fotky — 377 dpi (pro tisk aspoň 300 dpi)']].slice(0, 1));
        assert.deepEqual(w[0].target, { el: 'photo' });
        // a portrait frame takes a portrait photo without complaint
        const tall = await makeDoc(load, { content: { pagePhotos: { pre: { asset: 'p', zoom: 1, px: 0.5, py: 0.5 } } }, styles: (l) => { l.boxes.photo = { x: 20, y: 30, w: 60, h: 100 }; } });
        assert.deepEqual(pageWarnings(tall, first(tall), optsWith({ p: asset(3000, 4500) })), []);
        // square is not portrait
        assert.deepEqual(pageWarnings(doc, page, optsWith({ p: asset(4000, 4000) })), []);
    });

    t.test('too many icons for the grid', async () => {
        const kind = (id, pos) => ({ builtin: id, name: id, on: true, place: '', mode: 'dates', weekdays: [0], interval: 'w', from: null, to: null, dates: ['2026-12-29', '2027-01-02'], pos });
        const content = { wasteKinds: Object.fromEntries(['kom', 'bio', 'plast', 'papir', 'sklo'].map((id, i) => [id, kind(id, i)])) };
        const doc = await makeDoc(load, { features: { waste: true }, content });
        const page = first(doc);
        assert.deepEqual(pageWarnings(doc, page, base), [
            { pageIndex: page.index, kind: 'icons', target: { date: '2026-12-29' }, message: '29. 12. — 5 ikony, vejdou se 4' },
            { pageIndex: page.index, kind: 'icons', target: { date: '2027-01-02' }, message: '2. 1. — 5 ikony, vejdou se 4' },
        ]);
        const three = await makeDoc(load, { features: { waste: true }, content, styles: (l) => { l.styles.cal.iconGrid = '2+1'; } });
        assert.deepEqual(pageWarnings(three, first(three), base).map((w) => w.message), ['29. 12. — 5 ikony, vejdou se 3', '2. 1. — 5 ikony, vejdou se 3']);
        // compact rows pack the icons in a line: nothing is dropped, no warning
        const compact = await makeDoc(load, { features: { waste: true }, content, styles: (l) => { l.boxes.cal.h = 70; } });
        assert.deepEqual(pageWarnings(compact, first(compact), base), []);
    });

    t.test('C8 event text that does not fit its box', async () => {
        const long = 'Velmi dlouhý název obecní akce, který se do pole pro akce rozhodně nevejde, protože pokračuje dál a dál a ještě dál, až za okraj řádku a ještě o kus dál, a pořád nekončí';
        const one = await makeDoc(load, { features: { events: true }, content: { events: { e1: { date: '2026-12-30', name: long, club: null, icon: null }, e0: { date: '2026-12-29', name: 'Krátká', club: null, icon: null } } } });
        const page = first(one);
        assert.deepEqual(pageWarnings(one, page, base), [{ pageIndex: page.index, kind: 'event', target: { date: '2026-12-30' }, message: '30. 12. — text akce se nevejde do pole pro akce' }]);
        const events = {};
        for (let i = 0; i < 3; i++) events[`e${i}`] = { date: '2026-12-30', name: `Akce číslo ${i + 1} s delším popisem místa a času konání`, club: null, icon: null };
        const many = await makeDoc(load, { features: { events: true }, content: { events } });
        assert.deepEqual(pageWarnings(many, first(many), base).map((w) => w.message), ['30. 12. — 3 akce se nevejde do pole pro akce']);
        // a taller box takes them
        const roomy = await makeDoc(load, { features: { events: true }, content: { events }, styles: (l) => { l.styles.ev.box = { x: 0, y: 0.5, w: 110, h: 14.5 }; } });
        assert.deepEqual(pageWarnings(roomy, first(roomy), base), []);
        // the feature switched off: stored events neither print nor warn
        const off = await makeDoc(load, { features: { events: false }, content: { events } });
        assert.deepEqual(pageWarnings(off, first(off), base), []);
    });

    t.test('allWarnings: only pages with warnings, keyed by page index; nothing for non-week pages', async () => {
        const content = {
            pagePhotos: { w03: { asset: 'p', zoom: 1, px: 0.5, py: 0.5 }, w10: { asset: 'p', zoom: 1, px: 0.5, py: 0.5 }, gone: { asset: 'p', zoom: 1, px: 0.5, py: 0.5 } },
            events: { e1: { date: '2027-06-01', name: 'Velmi dlouhý název obecní akce, který se do pole pro akce rozhodně nevejde, protože pokračuje dál a dál a ještě dál, až za okraj řádku a ještě o kus dál, a pořád nekončí', club: null, icon: null } },
        };
        const doc = await makeDoc(load, { features: { events: true }, content });
        const pages = buildPages(doc);
        const map = allWarnings(doc, pages, optsWith({ p: asset(800, 600) }));
        const index = (key) => pages.find((p) => p.key === key).index;
        assert.deepEqual([...map.keys()].sort((a, b) => a - b), [index('w03'), index('w10'), index('w22')]);
        assert.equal(map.get(index('w03'))[0].kind, 'dpi');
        assert.equal(map.get(index('w22'))[0].kind, 'event');
        assert.equal(map.get(index('w22'))[0].pageIndex, index('w22'));
        assert.deepEqual(pageWarnings(doc, pages[0], base), []);
        assert.deepEqual(pageWarnings(doc, pages[pages.length - 1], base), []);
        const bare = await makeDoc(load, { preset: null });
        assert.equal(allWarnings(bare, buildPages(bare), base).size, 0);
        assert.equal(allWarnings(await makeDoc(load), pages, base).size, 0, 'a fresh calendar has no warnings');
    });
}
