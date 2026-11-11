// Page renderer (architecture §D.5): well-formed SVG, viewBox variants, asset URLs, unique ids, no editor chrome.
import assert from 'node:assert/strict';
import { layoutOpts, makeDoc } from '../helpers.mjs';

/** Tiny well-formedness check: balanced tags, quoted attributes, escaped text. Returns the element names. */
function checkXml(xml) {
    const stack = [];
    const names = [];
    let pos = 0;
    const tag = /<(\/?)([A-Za-z][\w:.-]*)((?:\s+[\w:.-]+="[^"<]*")*)\s*(\/?)>/y;
    while (pos < xml.length) {
        const lt = xml.indexOf('<', pos);
        const text = xml.slice(pos, lt < 0 ? xml.length : lt);
        assert.ok(!/>/.test(text), `stray ">" in text: ${text.slice(0, 40)}`);
        assert.ok(!/&(?!(amp|lt|gt|quot|#39|#\d+);)/.test(text), `unescaped "&" in text: ${text.slice(0, 40)}`);
        if (lt < 0) break;
        tag.lastIndex = lt;
        const m = tag.exec(xml);
        assert.ok(m, `malformed tag at ${lt}: ${xml.slice(lt, lt + 80)}`);
        const [, closing, name, attributes, selfClosing] = m;
        assert.ok(!/&(?!(amp|lt|gt|quot|#39|#\d+);)/.test(attributes), `unescaped "&" in attributes of <${name}>`);
        const seen = new Set();
        for (const a of attributes.matchAll(/([\w:.-]+)="/g)) {
            assert.ok(!seen.has(a[1]), `duplicate attribute ${a[1]} on <${name}>`);
            seen.add(a[1]);
        }
        if (closing) {
            assert.equal(stack.pop(), name, `closing </${name}>`);
        } else {
            names.push(name);
            if (!selfClosing) stack.push(name);
        }
        pos = tag.lastIndex;
    }
    assert.deepEqual(stack, [], 'unclosed elements');
    return names;
}

const ids = (svg) => [...svg.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
const refs = (svg) => [...svg.matchAll(/url\(#([^)]+)\)/g)].map((m) => m[1]);

export default async function (t, load) {
    const { renderPage, pageViewBox, holesMarkup, cropMarks } = await load('render/page.js');
    const { buildPages, weekPages } = await load('generate/pages.js');
    const svgMod = await load('render/svg.js');
    const icons = await load('render/icons.js');
    const { MARKS } = await load('model/defaults.js');
    const base = await layoutOpts(load);
    const ASSETS = {
        photo1: { url: '/full/photo.jpg', previewUrl: '/preview/photo.jpg', width: 4000, height: 3000, mime: 'image/jpeg', name: 'photo.jpg', description: null, kind: 'photo' },
        small: { url: '/full/logo.svg', previewUrl: null, width: null, height: null, mime: 'image/svg+xml', name: 'logo.svg', description: null, kind: 'logo' },
        head1: { url: '/full/head.png', previewUrl: '/preview/head.jpg', width: 3000, height: 400, mime: 'image/png', name: 'head.png', description: null, kind: 'image' },
        bin1: { url: '/full/bin.svg', previewUrl: null, width: null, height: null, mime: 'image/svg+xml', name: 'bin.svg', description: null, kind: 'icon' },
    };
    const opts = (extra = {}) => ({ ...base, env: { assets: ASSETS, fonts: [], projectName: 'Obec <Testov> & okolí' }, idPrefix: 'p1', ...extra });
    const first = (doc) => weekPages(buildPages(doc))[0];

    const rich = () => makeDoc(load, {
        type: 'fortnight',
        features: { captions: true, weekNumbers: true, moon: true, timeChange: true, seasons: true, waste: true, events: true, shifts: true, qr: true, specialPages: true },
        content: {
            pagePhotos: { pre: { asset: 'photo1', zoom: 1.5, px: 0.2, py: 0.8 } },
            pageCaptions: { pre: { text: 'Kostel <sv. Jiří> & "náves", foto: O\'Neil' } },
            pageImages: { pre: { head: 'head1', bg: null } },
            events: { e1: { date: '2026-12-31', name: 'Ples <SDH> & hostů', club: null, icon: null } },
            wasteKinds: { bio: { builtin: 'bio', name: 'Bio & "zeleň"', on: true, place: 'U <školy>', mode: 'rule', weekdays: [0, 3], interval: 'w', from: '2026-12-01', to: null, dates: [], pos: 0 } },
            shiftOps: { s1: { name: 'Provoz 1', shifts: [{ letter: 'R', from: '06:00', to: '14:00' }], crews: ['A', 'B', '<', '&'], pos: 0 } },
            specialPages: { intro: { kind: 'intro', on: true, layout: 0, position: 'front', nextYear: false, name: 'Slovo <starosty>', asset: null, text: null, fileName: null, pos: 0 } },
        },
        styles: (l) => {
            l.styles.photo.shadow.on = true;
            l.styles.photo.border.on = true;
            l.styles.photo.radius = 4;
            l.styles.head.bg.gradient.on = true;
            l.styles.head.bg.imageOn = true;
            l.styles.month.bg.gradient.on = true;
            l.styles.month.bg.gradient.type = 'radial';
            l.styles.logo.asset = 'small';
            l.styles.cal.bg = { color: '#FFFFFF', opacity: 60 };
            l.styles.cal.placeLabels = true;
            l.styles.lines.style = 'dot';
            l.styles.icons.order = ['waste', 'moon', 'ev', 'time', 'season'];
            l.styles.waste.kinds = { bio: { color: null, icon: 'bin1' } };
        },
    });

    t.test('every page kind renders well-formed XML, with user text escaped', async () => {
        const doc = await rich();
        const pages = buildPages(doc);
        const kinds = new Set();
        for (const page of pages) {
            for (const o of [{}, { bleed: true }, { marks: true, placeholders: 'all' }, { placeholders: 'none', holes: false, quality: 'full' }]) {
                const svg = renderPage(doc, page, opts(o));
                const names = checkXml(svg);
                assert.equal(names[0], 'svg');
                assert.ok(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="'), page.key);
                assert.ok(svg.includes(`data-page="${page.key}"`));
                assert.ok(!/NaN|undefined|Infinity/.test(svg), `${page.key}: bad number in the markup`);
            }
            kinds.add(page.kind);
        }
        assert.deepEqual([...kinds].sort(), ['back', 'cover', 'special', 'week']);
        const svg = renderPage(doc, pages.find((p) => p.key === 'pre'), opts());
        assert.ok(svg.includes('Kostel &lt;sv. Jiří&gt; &amp; &quot;náves&quot;, foto: O&#39;Neil'));
        assert.ok(svg.includes('&lt;SDH&gt;') && svg.includes('hostů'), 'event text (wrapped) is escaped');
        assert.ok(svg.includes('<title>Bio &amp; &quot;zeleň&quot;</title>'));
        assert.ok(svg.includes('U &lt;školy&gt;'));
        assert.ok(svg.includes('aria-label="53. a 1. týden"'));
        const special = renderPage(doc, pages[1], opts());
        assert.ok(special.includes('SLOVO &lt;STAROSTY&gt;'));
        assert.ok(special.includes('aria-label="Slovo &lt;starosty&gt;"'));
        assert.ok(renderPage(doc, pages[0], opts()).includes('Obec &lt;Testov&gt; &amp; okolí'));
        assert.ok(renderPage(doc, pages[0], opts()).includes(`KALENDÁŘ ${doc.design.year}`));
    });

    t.test('viewBox: trim, bleed, crop marks', async () => {
        const doc = await makeDoc(load);
        const page = first(doc);
        const vb = (o) => renderPage(doc, page, opts(o)).match(/viewBox="([^"]+)"/)[1];
        assert.equal(vb({}), '0 0 310 145');
        assert.equal(vb({ bleed: true }), '-5 -5 320 155');
        const M = 5 + MARKS.gapMm + MARKS.lengthMm + 1;
        assert.equal(vb({ marks: true }), `${-M} ${-M} ${310 + 2 * M} ${145 + 2 * M}`);
        assert.deepEqual(pageViewBox(doc.design, {}), { x: 0, y: 0, w: 310, h: 145 });
        assert.deepEqual(pageViewBox(doc.design, { bleed: true }), { x: -5, y: -5, w: 320, h: 155 });
        const flat = await makeDoc(load, { print: { bleed: false, bleedMm: 5, marks: true } });
        assert.deepEqual(pageViewBox(flat.design, { bleed: true }), { x: 0, y: 0, w: 310, h: 145 });
        assert.deepEqual(pageViewBox(flat.design, { marks: true }), { x: -8, y: -8, w: 326, h: 161 });
    });

    t.test('crop marks: eight lines starting 2 mm outside the bleed, 5 mm long, only with marks', async () => {
        const doc = await makeDoc(load);
        const marks = cropMarks(doc.design);
        const lines = [...marks.matchAll(/<line x1="([-\d.]+)" y1="([-\d.]+)" x2="([-\d.]+)" y2="([-\d.]+)"\/>/g)].map((m) => m.slice(1).map(Number));
        assert.equal(lines.length, 8);
        for (const [x1, y1, x2, y2] of lines) {
            assert.ok(x1 === x2 || y1 === y2);
            assert.equal(Math.abs(x2 - x1) + Math.abs(y2 - y1), 5);
            if (y1 === y2) {
                assert.ok(y1 === 0 || y1 === 145, 'horizontal marks lie on the trim lines');
                assert.ok(Math.max(x1, x2) === -7 || Math.min(x1, x2) === 317);
            } else {
                assert.ok(x1 === 0 || x1 === 310);
                assert.ok(Math.max(y1, y2) === -7 || Math.min(y1, y2) === 152);
            }
        }
        const page = first(doc);
        assert.ok(renderPage(doc, page, opts({ marks: true })).includes('data-el="marks"'));
        assert.ok(!renderPage(doc, page, opts({ bleed: true })).includes('data-el="marks"'));
        // with marks the page is clipped to the bleed box on a white margin
        const svg = renderPage(doc, page, opts({ marks: true }));
        assert.ok(svg.includes('<clipPath id="p1-page"><rect x="-5" y="-5" width="320" height="155"/></clipPath>'));
        assert.ok(svg.includes('<rect x="-13" y="-13" width="336" height="171" fill="#FFFFFF"/>'));
    });

    t.test('binding holes: 38 squares centred on the default page, on top, optional', async () => {
        const doc = await makeDoc(load);
        const holes = holesMarkup(doc.design.page);
        const xs = [...holes.matchAll(/<rect x="([-\d.]+)" y="4" width="4.3" height="4.3" rx="0.6"\/>/g)].map((m) => Number(m[1]));
        assert.equal(xs.length, 38);
        assert.ok(Math.abs(xs[0] - (310 - (xs[37] + 4.3))) < 1e-9, 'centred');
        assert.ok(Math.abs(xs[1] - xs[0] - 8) < 1e-9);
        for (const page of buildPages(doc)) {
            const svg = renderPage(doc, page, opts());
            assert.ok(svg.endsWith('</g></svg>') && svg.lastIndexOf('data-el="holes"') > svg.lastIndexOf('data-el="cal"'), `${page.key}: holes are painted last`);
            assert.ok(!renderPage(doc, page, opts({ holes: false })).includes('data-el="holes"'));
        }
    });

    t.test('images use the preview URL by default and the full file on request', async () => {
        const doc = await rich();
        const page = first(doc);
        const preview = renderPage(doc, page, opts());
        assert.ok(preview.includes('href="/preview/photo.jpg"'));
        assert.ok(preview.includes('href="/preview/head.jpg"'));
        assert.ok(preview.includes('href="/full/logo.svg"'), 'no preview → the file itself');
        assert.ok(!preview.includes('/full/photo.jpg'));
        const full = renderPage(doc, page, opts({ quality: 'full' }));
        assert.ok(full.includes('href="/full/photo.jpg"'));
        assert.ok(full.includes('href="/full/head.png"'));
        assert.ok(!full.includes('/preview/'));
        assert.equal(svgMod.assetUrl(ASSETS.photo1, 'preview'), '/preview/photo.jpg');
        assert.equal(svgMod.assetUrl(ASSETS.photo1, 'full'), '/full/photo.jpg');
        assert.equal(svgMod.assetUrl(null, 'full'), '');
        // uploaded waste icon replaces the built-in bin
        assert.ok(preview.includes('href="/full/bin.svg"'));
    });

    t.test('the photo is cropped by zoom and position and clipped to the corner radius', async () => {
        const doc = await rich();
        const svg = renderPage(doc, first(doc), opts());
        const box = doc.design.layout.boxes.photo; // 142 × 112, photo 4:3 → cover height-driven
        const dh = Math.max(box.w / (4000 / 3000), box.h) * 1.5;
        const dw = dh * (4000 / 3000);
        const image = svg.match(/<image href="\/preview\/photo\.jpg" x="([-\d.]+)" y="([-\d.]+)" width="([-\d.]+)" height="([-\d.]+)"/).slice(1).map(Number);
        assert.ok(Math.abs(image[2] - dw) < 0.001 && Math.abs(image[3] - dh) < 0.001);
        assert.ok(Math.abs(image[0] - (box.x + (box.w - dw) * 0.2)) < 0.001);
        assert.ok(Math.abs(image[1] - (box.y + (box.h - dh) * 0.8)) < 0.001);
        assert.ok(svg.includes(`<clipPath id="p1-photo-p"><rect x="${box.x}" y="${box.y}" width="${box.w}" height="${box.h}" rx="4"/></clipPath>`));
        assert.ok(svg.includes('feGaussianBlur stdDeviation="1.5"'));
        assert.ok(!svg.includes('FOTKU DOPLNÍ KLIENT'));
    });

    t.test('ids are prefixed, unique and every reference resolves — several pages can share one DOM', async () => {
        const doc = await rich();
        const pages = buildPages(doc);
        const a = renderPage(doc, pages.find((p) => p.key === 'pre'), opts({ idPrefix: 'a', marks: true }));
        const b = renderPage(doc, pages.find((p) => p.key === 'pre'), opts({ idPrefix: 'b', marks: true }));
        const idsA = ids(a);
        const idsB = ids(b);
        assert.ok(idsA.length >= 6, `expected gradients, clips and filters, got ${idsA}`);
        assert.equal(new Set(idsA).size, idsA.length, 'unique inside one page');
        assert.ok(idsA.every((id) => id.startsWith('a-')));
        assert.ok(idsB.every((id) => id.startsWith('b-')));
        assert.equal(new Set([...idsA, ...idsB]).size, idsA.length + idsB.length, 'unique across pages');
        for (const ref of refs(a)) assert.ok(idsA.includes(ref), `dangling reference #${ref}`);
        for (const page of pages) {
            const svg = renderPage(doc, page, opts({ idPrefix: `x${page.index}` }));
            const list = ids(svg);
            assert.equal(new Set(list).size, list.length, page.key);
            for (const ref of refs(svg)) assert.ok(list.includes(ref), `${page.key}: dangling #${ref}`);
        }
    });

    t.test('the page SVG contains no editor chrome', async () => {
        const doc = await rich();
        for (const o of [{ placeholders: 'all', bleed: true }, { marks: true }]) {
            const svg = renderPage(doc, first(doc), opts(o));
            assert.ok(!/<(pattern|foreignObject|script|style)\b/.test(svg), 'no hatch pattern, HTML, scripts or styles');
            assert.ok(!/\bclass="/.test(svg), 'no CSS classes');
            assert.ok(!/data-(evbox|icgrid|handle|cp|guide)|cal-ovl|#E0708A|#D9486A|#e5484d|#8d7fd6/i.test(svg), 'no handles, guides, event boxes or bleed hatch');
            assert.ok(!/\son[a-z]+="/.test(svg), 'no event handlers');
            const dataAttrs = new Set([...svg.matchAll(/\s(data-[\w-]+)="/g)].map((m) => m[1]));
            assert.deepEqual([...dataAttrs].sort(), ['data-date', 'data-el', 'data-frame', 'data-page', 'data-part']);
        }
    });

    t.test('elements are painted bottom layer first and only when visible', async () => {
        const doc = await makeDoc(load, { features: { qr: true } });
        const page = first(doc);
        const order = (svg) => [...svg.matchAll(/data-el="(\w+)"/g)].map((m) => m[1]).filter((v, i, a) => a.indexOf(v) === i);
        assert.deepEqual(order(renderPage(doc, page, opts())), ['paper', 'bg', 'photo', 'cal', 'head', 'month', 'wk', 'cap', 'logo', 'qr', 'holes']);
        const changed = await makeDoc(load, { features: { qr: false, captions: false }, styles: (l) => { l.order = ['photo', 'logo', 'cap', 'wk', 'month', 'head', 'cal', 'qr']; l.boxes.month.hidden = true; } });
        assert.deepEqual(order(renderPage(changed, first(changed), opts())), ['paper', 'bg', 'cal', 'head', 'wk', 'logo', 'photo', 'holes']);
        const off = await makeDoc(load, { features: { weekNumbers: false } });
        assert.ok(!renderPage(off, first(off), opts()).includes('data-el="wk"'));
        // a calendar without a layout still renders its pages
        const bare = await makeDoc(load, { preset: null });
        for (const page of buildPages(bare).slice(0, 2)) checkXml(renderPage(bare, page, opts()));
    });

    t.test('QR: a page\'s own code wins in both modes; the shared code is the fallback only while it is shared', async () => {
        const assets = { shared: { url: '/a/shared.png', previewUrl: '/a/shared.png', width: 400, height: 400 }, own: { url: '/a/own.png', previewUrl: '/a/own.png', width: 400, height: 400 } };
        const make = (perPage, asset) => makeDoc(load, { features: { qr: true }, styles: (l) => { l.styles.qr = { perPage, asset }; } });
        const qr = (svg) => (svg.match(/<g data-el="qr">(.*?)<\/g>/s) || [])[1];
        const draw = async (doc, page) => qr(renderPage(doc, page, await layoutOpts(load, { env: { assets } })));
        // shared mode
        const sharedDoc = await make(false, 'shared');
        const [a, b] = buildPages(sharedDoc).filter((p) => p.kind === 'week');
        sharedDoc.content.pageQr = { [a.key]: { asset: 'own' } };
        assert.ok((await draw(sharedDoc, a)).includes('/a/own.png'), 'own code over the shared one');
        assert.ok(!(await draw(sharedDoc, a)).includes('/a/shared.png'));
        assert.ok((await draw(sharedDoc, b)).includes('/a/shared.png'), 'pages without their own code show the shared one');
        // shared mode without a shared picture: only the page with its own code has one
        const bare = await make(false, null);
        bare.content.pageQr = { [a.key]: { asset: 'own' } };
        assert.ok((await draw(bare, a)).includes('/a/own.png'));
        assert.equal(await draw(bare, b), '');
        // an own code whose asset is gone falls back like no own code
        const gone = await make(false, 'shared');
        gone.content.pageQr = { [a.key]: { asset: 'missing' } };
        assert.ok((await draw(gone, a)).includes('/a/shared.png'));
        // per-page mode: a page without a code shows none, the shared picture is ignored
        const each = await make(true, 'shared');
        each.content.pageQr = { [a.key]: { asset: 'own' } };
        assert.ok((await draw(each, a)).includes('/a/own.png'));
        assert.equal(await draw(each, b), '');
    });

    t.test('placeholders: sample texts only on designer surfaces', async () => {
        const doc = await makeDoc(load, { features: { qr: true } });
        const page = first(doc);
        const all = renderPage(doc, page, opts({ placeholders: 'all' }));
        const frames = renderPage(doc, page, opts());
        const none = renderPage(doc, page, opts({ placeholders: 'none' }));
        assert.ok(all.includes('Popisek fotografie, autor') && all.includes('>QR<') && all.includes('>LOGO<') && all.includes('FOTKU DOPLNÍ KLIENT'));
        assert.ok(!frames.includes('Popisek fotografie, autor') && !frames.includes('>QR<'));
        assert.ok(frames.includes('>LOGO<') && frames.includes('FOTKU DOPLNÍ KLIENT'));
        assert.ok(!none.includes('>LOGO<') && !none.includes('FOTKU DOPLNÍ KLIENT') && !none.includes('stroke-dasharray="1 0.8"'));
        const self = await makeDoc(load, { styles: (l) => { l.styles.photo.source = 'self'; } });
        assert.ok(renderPage(self, first(self), opts()).includes('>FOTOGRAFIE<'));
        const headSlot = await makeDoc(load, { styles: (l) => { l.styles.head.bg.imageOn = true; } });
        assert.ok(renderPage(headSlot, first(headSlot), opts()).includes('OBRÁZEK HLAVIČKY'));
        assert.ok(!renderPage(headSlot, first(headSlot), opts({ placeholders: 'none' })).includes('OBRÁZEK HLAVIČKY'));
    });

    t.test('texts: month and week number anchored by alignment, sizes in mm', async () => {
        const doc = await makeDoc(load);
        const svg = renderPage(doc, first(doc), opts());
        const month = svg.match(/<g data-el="month" data-frame="0"><text x="([-\d.]+)" y="([-\d.]+)"[^>]*font-size="([-\d.]+)"[^>]*text-anchor="(\w+)"[^>]*>([^<]+)<\/text>/);
        assert.equal(month[5], 'PROSINEC 2026 / LEDEN 2027');
        assert.equal(Number(month[1]), 304, 'align end, no padding without a background');
        assert.equal(month[4], 'end');
        assert.ok(Math.abs(Number(month[3]) - 15 * 25.4 / 72) < 0.001, '15 pt in mm');
        assert.ok(Math.abs(Number(month[2]) - (12.5 + 14.5 / 2 + 0.36 * 15 * 25.4 / 72)) < 0.001, 'baseline');
        assert.ok(svg.includes('letter-spacing="0.09"'));
        const wk = svg.match(/<g data-el="wk" data-frame="0"><text x="([-\d.]+)"[^>]*>([^<]+)</);
        assert.deepEqual([Number(wk[1]), wk[2]], [304, '53. týden']);
        const lower = await makeDoc(load, { preset: 'photoRight', styles: (l) => { l.styles.month.upper = false; l.styles.month.bg.colorOn = true; } });
        const s2 = renderPage(lower, first(lower), opts());
        assert.ok(s2.includes('>Prosinec 2026 / Leden 2027<'));
        assert.ok(/<g data-el="month" data-frame="0"><rect[^>]*\/><text x="9"/.test(s2), 'align start with the 3 mm padding of a filled background');
        assert.ok(!/<g data-el="month"[^>]*>.*?letter-spacing/.test(s2.slice(s2.indexOf('data-el="month"'), s2.indexOf('data-el="month"') + 400)));
        assert.equal((svg.match(/data-el="month"/g) || []).length, 1, 'one frame on a 7-day page');
        assert.equal((svg.match(/data-el="wk"/g) || []).length, 1);
    });

    t.test('14 dní: month and week number once per column, the second frame mirrored', async () => {
        const texts = (svg, el) => [...svg.matchAll(new RegExp(`<g data-el="${el}" data-frame="(\\d)">(?:<rect[^>]*/>)?<text x="([-\\d.]+)"([^>]*)>([^<]+)</text>`, 'g'))].map((m) => ({ frame: Number(m[1]), x: Number(m[2]), anchor: (m[3].match(/text-anchor="(\w+)"/) || [0, 'start'])[1], text: m[4] }));
        const doc = await makeDoc(load, { type: 'fortnight' });
        const pages = weekPages(buildPages(doc));
        const svg = renderPage(doc, pages[0], opts());
        checkXml(svg);
        // first page: 28 Dec 2026 – 3 Jan 2027 | 4 – 10 Jan 2027
        assert.deepEqual(texts(svg, 'month'), [
            { frame: 0, x: 6, anchor: 'start', text: 'PROSINEC 2026 / LEDEN 2027' },
            { frame: 1, x: 304, anchor: 'end', text: 'LEDEN 2027' },
        ]);
        assert.deepEqual(texts(svg, 'wk'), [
            { frame: 0, x: 6, anchor: 'start', text: '53. týden' },
            { frame: 1, x: 304, anchor: 'end', text: '1. týden' },
        ]);
        assert.ok(!svg.includes('>53. a 1. týden<'), 'no printed text for the whole fortnight (the page name keeps both weeks)');
        // a mid-year page where only the SECOND week crosses a month boundary
        const crossing = pages.find((p) => p.week.frames[1][0] === '2027-03-29');
        assert.deepEqual(texts(renderPage(doc, crossing, opts()), 'month').map((m) => m.text), ['BŘEZEN 2027', 'BŘEZEN / DUBEN 2027']);
        // shared style: form, colour, upper case, background + padding apply to both frames
        const styled = await makeDoc(load, { type: 'fortnight', styles: (l) => {
            l.styles.wk.form = 'c';
            l.styles.wk.color = '#123456';
            l.styles.month.upper = false;
            l.styles.month.bg.colorOn = true;
            l.styles.month.bg.color = '#ABCDEF';
        } });
        const s2 = renderPage(styled, weekPages(buildPages(styled))[0], opts());
        checkXml(s2);
        assert.deepEqual(texts(s2, 'wk').map((m) => m.text), ['T53', 'T1']);
        assert.equal((s2.match(/fill="#123456"/g) || []).length, 2);
        assert.deepEqual(texts(s2, 'month'), [
            { frame: 0, x: 9, anchor: 'start', text: 'Prosinec 2026 / Leden 2027' },
            { frame: 1, x: 301, anchor: 'end', text: 'Leden 2027' },
        ]);
        assert.equal((s2.match(/<g data-el="month" data-frame="\d"><rect[^>]*fill="#ABCDEF"/g) || []).length, 2, 'both frames carry the background');
        // alignment: the stored one is the first frame's, the second is its mirror; middle stays
        const flipped = await makeDoc(load, { type: 'fortnight', styles: (l) => { l.styles.month.align = 'end'; l.styles.wk.align = 'middle'; } });
        const s3 = renderPage(flipped, weekPages(buildPages(flipped))[0], opts());
        assert.deepEqual(texts(s3, 'month').map((m) => [m.x, m.anchor]), [[126, 'end'], [184, 'start']]);
        assert.deepEqual(texts(s3, 'wk').map((m) => [m.x, m.anchor]), [[42, 'middle'], [268, 'middle']]);
        // the eye hides both frames, „Zobrazení týdnů“ hides both week numbers
        const hidden = await makeDoc(load, { type: 'fortnight', features: { weekNumbers: false }, styles: (l) => { l.boxes.month.hidden = true; } });
        const s4 = renderPage(hidden, weekPages(buildPages(hidden))[0], opts());
        assert.ok(!s4.includes('data-el="month"') && !s4.includes('data-el="wk"'));
    });

    t.test('svg helpers', () => {
        assert.equal(svgMod.esc('<a href="x">&\'</a>'), '&lt;a href=&quot;x&quot;&gt;&amp;&#39;&lt;/a&gt;');
        assert.equal(svgMod.esc(null), '');
        assert.deepEqual([svgMod.num(1), svgMod.num(1.23456), svgMod.num(-0.0001), svgMod.num(2.5), svgMod.num(10), svgMod.num(NaN)], ['1', '1.235', '0', '2.5', '10', '0']);
        assert.equal(svgMod.rect({ x: 1, y: 2, w: 3, h: 4 }, { fill: '#000000', rx: null }), '<rect x="1" y="2" width="3" height="4" fill="#000000"/>');
        const linear = svgMod.gradientDef('g1', { type: 'linear', angle: 90, stops: [{ color: '#000000', opacity: 50, pos: 100 }, { color: '#FFFFFF', opacity: 100, pos: 0 }] }, {});
        assert.ok(linear.includes('x1="0.5" y1="0" x2="0.5" y2="1"'), '90° runs top → bottom');
        assert.ok(linear.indexOf('offset="0%"') < linear.indexOf('offset="100%"'), 'stops sorted');
        assert.ok(linear.includes('stop-opacity="0.5"'));
        assert.ok(svgMod.gradientDef('g2', { type: 'linear', angle: 0, stops: [] }, {}).includes('x1="0" y1="0.5" x2="1" y2="0.5"'), '0° runs left → right');
        assert.ok(svgMod.gradientDef('g3', { type: 'radial', angle: 0, stops: [] }, {}).startsWith('<radialGradient id="g3" cx="50%" cy="50%" r="50%">'));
        assert.equal(svgMod.fillLayers({ x: 0, y: 0, w: 1, h: 1 }, { colorOn: false, gradient: { on: false }, imageOn: false }, null, {}, 'x'), '');
        for (const fragment of [icons.binIcon({ x: 0, y: 0, w: 6, h: 7 }, '#112233', 'Bio & spol.'), icons.clockIcon({ x: 0, y: 0, w: 6, h: 6 }, '#112233', 'x'), icons.starIcon({ x: 0, y: 0, w: 6, h: 7 }, '#112233', 'x'), ...[0, 1, 2, 3].flatMap((k) => [icons.moonIcon({ x: 0, y: 0, w: 5, h: 5 }, k, '#112233'), icons.seasonIcon({ x: 0, y: 0, w: 5, h: 5 }, k, '#112233', 'x')])]) {
            checkXml(fragment);
            assert.ok(!/NaN|undefined/.test(fragment));
        }
        assert.ok(icons.moonIcon({ x: 0, y: 0, w: 5, h: 5 }, 1, '#000000').includes('A2.5 2.5 0 0 1'), 'first quarter: right half');
        assert.ok(icons.moonIcon({ x: 0, y: 0, w: 5, h: 5 }, 3, '#000000').includes('A2.5 2.5 0 0 0'), 'last quarter: left half');
        assert.ok(icons.moonIcon({ x: 0, y: 0, w: 5, h: 5 }, 2, '#000000').includes('<title>Úplněk</title>'));
    });
}
