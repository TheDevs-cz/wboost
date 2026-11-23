// Warnings (architecture §E.12, handoff §4.8, checks C3, C8).
import assert from 'node:assert/strict';
import { layoutOpts, makeDoc } from '../helpers.mjs';

const N = '\u00a0';

export default async function (t, load) {
    const { pageWarnings, allWarnings, photoDpi } = await load('render/warnings.js');
    const { warningMessage } = await load('render/warning-text.js');
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
        assert.equal(low.length, 1);
        assert.deepEqual({ ...low[0], message: undefined }, { pageIndex: page.index, kind: 'dpi', target: { el: 'photo' }, facts: { dpi: 151, width: 1200, height: 900, zoom: 1, frameW: low[0].facts.frameW, frameH: low[0].facts.frameH, dpiAt100: 151 }, message: undefined });
        assert.equal(low[0].message, warningMessage(low[0]));
        assert.match(low[0].message, /^Fotka má na tisk malé rozlišení\. Fotka má 1\s200\s×\s900\spx — .* 151\sdpi/);
        // 1600 px: 201 dpi at 100 % — fine; at 150 % it is 134 dpi
        assert.deepEqual(pageWarnings(doc, page, optsWith({ p: asset(1600, 1200) })), []);
        const zoomed = await makeDoc(load, { content: content(1.5) });
        assert.deepEqual(pageWarnings(zoomed, first(zoomed), optsWith({ p: asset(1600, 1200) })).map((w) => [w.facts.dpi, w.facts.zoom, w.facts.dpiAt100]), [[134, 1.5, 201]]);
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
        assert.deepEqual(w.map((x) => x.kind), ['portrait']);
        assert.deepEqual([w[0].facts.width, w[0].facts.height], [3000, 4500]);
        // a 202 × 123 frame, 2 : 3 photo covering its width: 123 / (202 · 1.5) of the height stays
        const box = doc.design.layout.boxes.photo;
        assert.ok(Math.abs(w[0].facts.keep - box.h / (box.w * 1.5)) < 1e-9, String(w[0].facts.keep));
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
            { pageIndex: page.index, kind: 'icons', target: { date: '2026-12-29' }, facts: { date: '2026-12-29', total: 5, drawn: 4 }, message: `Ikony se do řádku nevejdou. Ve dni 29.${N}12. je 5 ikon, místo je jen pro 4 — 1 se nevytiskne. Uberte v tento den svoz nebo akci, nebo v návrhu zvolte mřížku ikon s více místy.` },
            { pageIndex: page.index, kind: 'icons', target: { date: '2027-01-02' }, facts: { date: '2027-01-02', total: 5, drawn: 4 }, message: `Ikony se do řádku nevejdou. Ve dni 2.${N}1. je 5 ikon, místo je jen pro 4 — 1 se nevytiskne. Uberte v tento den svoz nebo akci, nebo v návrhu zvolte mřížku ikon s více místy.` },
        ]);
        const three = await makeDoc(load, { features: { waste: true }, content, styles: (l) => { l.styles.cal.iconGrid = '2+1'; } });
        assert.deepEqual(pageWarnings(three, first(three), base).map((w) => [w.facts.date, w.facts.total, w.facts.drawn]), [['2026-12-29', 5, 3], ['2027-01-02', 5, 3]]);
        // compact rows pack the icons in a line: nothing is dropped, no warning
        const compact = await makeDoc(load, { features: { waste: true }, content, styles: (l) => { l.boxes.cal.h = 70; } });
        assert.deepEqual(pageWarnings(compact, first(compact), base), []);
    });

    t.test('C8 event text that does not fit its box', async () => {
        const long = 'Velmi dlouhý název obecní akce, který se do pole pro akce rozhodně nevejde, protože pokračuje dál a dál a ještě dál, až za okraj řádku a ještě o kus dál, a pořád nekončí';
        const one = await makeDoc(load, { features: { events: true }, content: { events: { e1: { date: '2026-12-30', name: long, club: null, icon: null }, e0: { date: '2026-12-29', name: 'Krátká', club: null, icon: null } } } });
        const page = first(one);
        assert.deepEqual(pageWarnings(one, page, base), [{ pageIndex: page.index, kind: 'event', target: { date: '2026-12-30' }, facts: { date: '2026-12-30', count: 1 }, message: `Akce se do pole nevejde. Ve dni 30.${N}12. je název akce delší, než pole pro akce pojme — text přeteče přes jeho okraj. Zkraťte název akce nebo ji přesuňte na jiný den, nebo v návrhu zvětšete pole pro akce.` }]);
        const events = {};
        for (let i = 0; i < 3; i++) events[`e${i}`] = { date: '2026-12-30', name: `Akce číslo ${i + 1} s delším popisem místa a času konání`, club: null, icon: null };
        const many = await makeDoc(load, { features: { events: true }, content: { events } });
        assert.deepEqual(pageWarnings(many, first(many), base).map((w) => w.facts), [{ date: '2026-12-30', count: 3 }]);
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

    t.test('K12 describeWarning: plain title, Czech facts, the fix and its actions per kind and role', async () => {
        const { describeWarning, czNumber, czPercent, plural, pixelsFor } = await load('render/warning-text.js');
        const ids = (d) => d.actions.map((a) => a.id);
        assert.equal(czNumber(3660), `3${N}660`);
        assert.equal(czNumber(980), '980');
        assert.equal(czNumber(1234567), `1${N}234${N}567`);
        assert.equal(czPercent(0.374), `37${N}%`);
        assert.deepEqual([1, 2, 4, 5, 0].map((n) => plural(n, ['ikona', 'ikony', 'ikon'])), ['ikona', 'ikony', 'ikony', 'ikon', 'ikon']);
        // 310 × 141 mm at 200 / 300 dpi
        assert.deepEqual(pixelsFor(310, 141, 200), { width: 2441, height: 1111 });

        const dpi = describeWarning({ kind: 'dpi', facts: { dpi: 98, width: 1200, height: 800, zoom: 1, frameW: 310, frameH: 141, dpiAt100: 98 } });
        assert.equal(dpi.title, 'Fotka má na tisk malé rozlišení');
        assert.equal(dpi.text, `Fotka má 1${N}200${N}×${N}800${N}px — v této velikosti na stránce to vychází jen na 98${N}dpi, vytiskne se neostře.`);
        assert.equal(dpi.hint, `Pro ostrý tisk nahrajte fotku aspoň 2${N}441${N}×${N}1${N}111${N}px (200${N}dpi), ideálně 3${N}662${N}×${N}1${N}666${N}px (300${N}dpi).`);
        assert.deepEqual(ids(dpi), ['photo-replace']);
        const zoomed = describeWarning({ kind: 'dpi', facts: { dpi: 134, width: 1600, height: 1200, zoom: 1.5, frameW: 202, frameH: 123, dpiAt100: 201 } });
        assert.match(zoomed.text, new RegExp(`Přiblížení na 150${N}% rozlišení ještě snižuje — při 100${N}% by měla 201${N}dpi\\.$`));
        assert.deepEqual(ids(zoomed), ['photo-replace', 'photo-zoom-reset']);

        const portrait = describeWarning({ kind: 'portrait', facts: { width: 3000, height: 4500, keep: 0.406 } });
        assert.equal(portrait.title, 'Fotka na výšku v rámečku na šířku');
        assert.equal(portrait.text, `Fotka má 3${N}000${N}×${N}4${N}500${N}px a stojí na výšku, rámeček je na šířku — vytiskne se jen 41${N}% její výšky, zbytek se ořízne.`);
        assert.deepEqual(ids(portrait), ['photo-crop', 'photo-replace']);
        assert.deepEqual(ids(describeWarning({ kind: 'portrait', facts: { width: 3000, height: 4500, keep: 0.4 } }, { designer: false })), ['photo-crop', 'photo-replace']);

        const icons = describeWarning({ kind: 'icons', facts: { date: '2027-01-02', total: 6, drawn: 3 } });
        assert.equal(icons.text, `Ve dni 2.${N}1. je 6 ikon, místo je jen pro 3 — 3 se nevytisknou.`);
        assert.deepEqual(ids(icons), ['day', 'element']);
        assert.deepEqual(icons.actions[1].selection, { el: 'cal', part: 'icon' });
        const iconsClient = describeWarning({ kind: 'icons', facts: { date: '2027-01-02', total: 3, drawn: 2 } }, { designer: false });
        assert.equal(iconsClient.text, `Ve dni 2.${N}1. jsou 3 ikony, místo je jen pro 2 — 1 se nevytiskne.`);
        assert.deepEqual(ids(iconsClient), ['day'], 'the client cannot change the design');
        assert.doesNotMatch(iconsClient.hint, /v návrhu/);

        const event = describeWarning({ kind: 'event', facts: { date: '2026-12-30', count: 5 } });
        assert.equal(event.title, 'Akce se do pole nevejdou');
        assert.equal(event.text, `5 akcí ze dne 30.${N}12. se do pole pro akce nevejde — text přeteče přes jeho okraj.`);
        assert.equal(describeWarning({ kind: 'event', facts: { date: '2026-12-30', count: 2 } }).text, `2 akce ze dne 30.${N}12. se do pole pro akce nevejdou — text přeteče přes jeho okraj.`);
        assert.deepEqual(ids(event), ['events', 'element']);
        assert.deepEqual(event.actions[1].selection, { el: 'cal', part: 'ev' });
        assert.deepEqual(ids(describeWarning({ kind: 'event', facts: { date: '2026-12-30', count: 1 } }, { designer: false })), ['events']);

        const overflow = describeWarning({ kind: 'overflow', facts: { issue: 'Kontakty se na stránku nevejdou — uberte řádky, nebo zvolte rozvržení s více sloupci' } });
        assert.deepEqual([overflow.title, overflow.text, overflow.hint], ['Text se na stranu nevejde', 'Kontakty se na stránku nevejdou. Co se nevejde, skončí třemi tečkami „…“.', 'Uberte řádky, nebo zvolte rozvržení s více sloupci.']);
        assert.deepEqual(ids(overflow), ['page-content']);
        assert.equal(describeWarning({ kind: 'overflow', facts: { issue: 'Název je příliš dlouhý a nevejde se celý' } }).hint, 'Zkraťte ho.');
    });
}
