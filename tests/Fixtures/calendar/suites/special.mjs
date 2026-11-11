// Cover, back and special pages (architecture §D.5, map §4.5, handoff §10.10 / §10.13): every kind
// and layout renders well-formed SVG at two page sizes, the waste grid marks exactly the rule's
// days, the year grid marks the holidays, the back page switches variant.
import assert from 'node:assert/strict';
import { layoutOpts, makeDoc } from '../helpers.mjs';

/** Balanced tags, quoted attributes, escaped text. */
function checkXml(xml) {
    const stack = [];
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
        if (m[1]) assert.equal(stack.pop(), m[2], `closing </${m[2]}>`);
        else if (!m[4]) stack.push(m[2]);
        pos = tag.lastIndex;
    }
    assert.deepEqual(stack, [], 'unclosed elements');
}

const item = (kind, o = {}) => ({ kind, on: true, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 0, ...o });
const SIZES = [null, { width: 297, height: 210 }, { width: 200, height: 100, bandHeight: 9 }];
const LAYOUTS = { intro: 3, contacts: 3, year: 3, waste: 2 };

/** Every `<text>` of a fragment as `{ x, y, fill, weight, text }`. */
function texts(svg) {
    return [...svg.matchAll(/<text([^>]*)>([^<]*)<\/text>/g)].map((m) => {
        const a = Object.fromEntries([...m[1].matchAll(/([\w-]+)="([^"]*)"/g)].map((x) => [x[1], x[2]]));
        return { x: Number(a.x), y: Number(a.y), fill: a.fill, weight: Number(a['font-weight']), anchor: a['text-anchor'] || 'start', size: Number(a['font-size']), text: m[2] };
    });
}

export default async function (t, load) {
    const { renderPage } = await load('render/page.js');
    const special = await load('render/special.js');
    const { buildPages } = await load('generate/pages.js');
    const { wasteRuleOn } = await load('generate/waste.js');
    const { holidayName } = await load('generate/czech.js');
    const { daysInMonth, iso, weekday } = await load('generate/dates.js');
    const { COLORS } = await load('model/defaults.js');
    const base = await layoutOpts(load);
    const ASSETS = { page1: { url: '/full/page.png', previewUrl: '/preview/page.webp', width: 3661, height: 1713, mime: 'image/png', name: 'page.pdf', description: null, kind: 'page' } };
    const opts = (extra = {}) => ({ ...base, env: { assets: ASSETS, fonts: [], projectName: 'Obec <Testov> & okolí' }, idPrefix: 's1', ...extra });
    const docWith = (specialPages, o = {}) => makeDoc(load, { features: { specialPages: true, waste: true, events: true, ...(o.features || {}) }, content: { specialPages, ...(o.content || {}) }, page: o.page || {}, type: o.type, print: o.print });
    const pageOf = (doc, key) => buildPages(doc).find((p) => p.key === key);
    const draw = (doc, key, o) => renderPage(doc, pageOf(doc, key), opts(o));

    const WASTE = {
        bio: { builtin: 'bio', name: 'Bio & "zeleň"', on: true, place: '', mode: 'rule', weekdays: [3], interval: 'w', from: '2027-04-01', to: '2027-11-30', dates: [], pos: 0 },
        kom: { builtin: 'kom', name: 'Komunální odpad', on: true, place: '', mode: 'rule', weekdays: [0], interval: '2e', from: null, to: null, dates: [], pos: 1 },
        papir: { builtin: 'papir', name: 'Papír', on: true, place: '', mode: 'dates', weekdays: [0], interval: 'w', from: null, to: null, dates: ['2027-01-15', '2027-06-11', '2026-12-30'], pos: 2 },
        plast: { builtin: 'plast', name: 'Plast', on: false, place: '', mode: 'rule', weekdays: [1], interval: 'w', from: null, to: null, dates: [], pos: 3 },
    };

    t.test('every kind and layout renders well-formed SVG at three page sizes, in every render mode', async () => {
        for (const size of SIZES) {
            for (const [kind, count] of Object.entries(LAYOUTS)) {
                for (let layout = 0; layout < count; layout++) {
                    const doc = await docWith({ [kind]: item(kind, { layout, nextYear: layout === 1 }) }, { page: size || {}, content: { wasteKinds: WASTE, clubs: { c1: { code: 'SDH', name: 'Hasiči <dobrovolní> & spol.', pos: 0 } } } });
                    for (const o of [{}, { bleed: true }, { marks: true, placeholders: 'all' }, { placeholders: 'none', holes: false, quality: 'full' }]) {
                        const svg = draw(doc, `sp:${kind}`, o);
                        checkXml(svg);
                        assert.ok(!/NaN|undefined|Infinity/.test(svg), `${kind}/${layout}: bad number in the markup`);
                        assert.ok(svg.includes(`data-kind="${kind}"`), `${kind}/${layout}`);
                    }
                }
            }
            const doc = await docWith({}, { page: size || {} });
            for (const key of ['cover', 'back']) {
                const svg = draw(doc, key, { bleed: true });
                checkXml(svg);
                assert.ok(!/NaN|undefined|Infinity/.test(svg), key);
            }
        }
        assert.deepEqual(Object.fromEntries(Object.entries(special.SPECIAL_LAYOUTS).map(([k, v]) => [k, v.length])), LAYOUTS);
    });

    t.test('pages are proportional to the page size: everything stays inside the bleed box', async () => {
        for (const size of SIZES) {
            for (const [kind, count] of Object.entries(LAYOUTS)) {
                for (let layout = 0; layout < count; layout++) {
                    const doc = await docWith({ [kind]: item(kind, { layout }) }, { page: size || {}, content: { wasteKinds: WASTE } });
                    const { width: W, height: H } = doc.design.page;
                    const svg = draw(doc, `sp:${kind}`, { bleed: true });
                    for (const m of svg.matchAll(/<rect x="([-\d.]+)" y="([-\d.]+)" width="([\d.]+)" height="([\d.]+)"/g)) {
                        const [x, y, w, h] = m.slice(1).map(Number);
                        assert.ok(x >= -5.001 && y >= -5.001 && x + w <= W + 5.001 && y + h <= H + 5.001, `${kind}/${layout} at ${W}×${H}: rect ${m[0]} leaves the page`);
                    }
                    for (const text of texts(svg)) assert.ok(text.x >= 0 && text.x <= W && text.y > 0 && text.y < H, `${kind}/${layout} at ${W}×${H}: text "${text.text}" at ${text.x}, ${text.y}`);
                }
            }
        }
        // the same design position lands on the same fraction of the width
        const a = await docWith({ contacts: item('contacts') });
        const b = await docWith({ contacts: item('contacts') }, { page: { width: 620, height: 290, bandHeight: 25 } });
        const xa = texts(draw(a, 'sp:contacts')).find((x) => x.text === 'Lékárna');
        const xb = texts(draw(b, 'sp:contacts')).find((x) => x.text === 'Lékárna');
        assert.ok(Math.abs(xa.x * 2 - xb.x) < 0.01 && Math.abs(xa.y * 2 - xb.y) < 0.01 && Math.abs(xa.size * 2 - xb.size) < 0.01, 'a page twice the size is the same page drawn twice as large');
    });

    t.test('waste grid marks exactly the days of the rules, of the listed dates and of single extras', async () => {
        const doc = await docWith({ waste: item('waste') }, { content: { wasteKinds: WASTE, wasteExtras: { x1: { kind: 'bio', date: '2027-02-02' }, x2: { kind: 'plast', date: '2027-02-03' } } } });
        const svg = draw(doc, 'sp:waste');
        const marked = [...svg.matchAll(/data-waste="([^"]+)"/g)].map((m) => m[1]).sort();
        const expected = [];
        for (let m = 1; m <= 12; m++) {
            for (let d = 1; d <= daysInMonth(2027, m); d++) {
                const date = iso(2027, m, d);
                for (const id of ['bio', 'kom', 'papir']) {
                    if (wasteRuleOn(WASTE[id], date, 2027) || (id === 'bio' && date === '2027-02-02')) expected.push(`${date}:${id}`);
                }
            }
        }
        assert.deepEqual(marked, expected.sort());
        // Bio: every Thursday from 1 April to 30 November, nothing outside the range (+ the extra)
        const bio = marked.filter((m) => m.endsWith(':bio')).map((m) => m.slice(0, 10));
        assert.equal(bio.length, 35 + 1);
        assert.ok(bio.filter((d) => d !== '2027-02-02').every((d) => weekday(d) === 3 && d >= '2027-04-01' && d <= '2027-11-30'));
        assert.ok(bio.includes('2027-04-01') && bio.includes('2027-11-25') && !bio.includes('2027-03-25') && !bio.includes('2027-12-02'));
        assert.deepEqual(marked.filter((m) => m.endsWith(':papir')), ['2027-01-15:papir', '2027-06-11:papir'], 'a listed date of another year is not on the grid');
        assert.ok(!marked.some((m) => m.endsWith(':plast')), 'a kind that is switched off is never drawn');
        // legend: the active kinds, escaped, in order
        const legend = svg.slice(svg.indexOf('data-legend="waste"'));
        assert.ok(legend.indexOf('Bio &amp; &quot;zeleň&quot;') > 0 && legend.indexOf('Bio &amp;') < legend.indexOf('Komunální odpad') && legend.indexOf('Komunální odpad') < legend.indexOf('Papír'));
        assert.ok(!legend.includes('Plast'));
        assert.ok(svg.includes('PLÁNOVACÍ KALENDÁŘ SVOZU ODPADŮ 2027'));
        // two kinds on one day share the cell: 2027-04-22 is a Thursday (bio) … find a day with two marks
        const both = expected.map((e) => e.slice(0, 10)).find((d, i, all) => all.indexOf(d) !== i);
        if (both) {
            const heights = [...svg.matchAll(new RegExp(`height="([\\d.]+)"[^>]*data-waste="${both}:`, 'g'))].map((m) => Number(m[1]));
            assert.equal(heights.length, 2);
            assert.ok(Math.abs(heights[0] - heights[1]) < 0.01);
        }
        // layout 1 adds the three tables
        const withTables = draw(await docWith({ waste: item('waste', { layout: 1 }) }, { content: { wasteKinds: WASTE, wasteExtras: { x1: { kind: 'bio', date: '2027-02-02' } } } }), 'sp:waste');
        for (const title of ['VELKOOBJEMOVÝ ODPAD', 'NEBEZPEČNÝ ODPAD', 'SBĚRNÝ DVŮR — PROVOZNÍ DOBA']) assert.ok(withTables.includes(title), title);
        assert.equal([...withTables.matchAll(/data-waste="/g)].length, marked.length, 'the same grid, narrower');
        // feature off: an empty grid, no legend entries
        const off = draw(await docWith({ waste: item('waste') }, { features: { waste: false }, content: { wasteKinds: WASTE } }), 'sp:waste');
        assert.equal([...off.matchAll(/data-waste="/g)].length, 0);
    });

    t.test('year grid: every day once, weekends and holidays coloured, holidays bold', async () => {
        const doc = await docWith({ year: item('year') });
        const svg = draw(doc, 'sp:year');
        assert.ok(svg.includes('ROČNÍ KALENDÁRIUM 2027'));
        const months = [...svg.matchAll(/<g data-month="(\d{4})-(\d\d)">(.*?)<\/g>/g)];
        assert.equal(months.length, 12);
        const HOLIDAYS = ['2027-01-01', '2027-03-26', '2027-03-29', '2027-05-01', '2027-05-08', '2027-07-05', '2027-07-06', '2027-09-28', '2027-10-28', '2027-11-17', '2027-12-24', '2027-12-25', '2027-12-26'];
        const bold = [];
        for (const [, y, mm, body] of months) {
            const m = Number(mm);
            const all = texts(body);
            assert.equal(all[0].text, ['LEDEN', 'ÚNOR', 'BŘEZEN', 'DUBEN', 'KVĚTEN', 'ČERVEN', 'ČERVENEC', 'SRPEN', 'ZÁŘÍ', 'ŘÍJEN', 'LISTOPAD', 'PROSINEC'][m - 1]);
            assert.deepEqual(all.slice(1, 8).map((x) => x.text), ['P', 'Ú', 'S', 'Č', 'P', 'S', 'N']);
            const days = all.slice(8);
            assert.deepEqual(days.map((x) => Number(x.text)), Array.from({ length: daysInMonth(Number(y), m) }, (_, i) => i + 1));
            const columns = all.slice(1, 8).map((x) => x.x);
            days.forEach((day, i) => {
                const date = iso(Number(y), m, i + 1);
                const wd = weekday(date);
                assert.ok(Math.abs(day.x - columns[wd]) < 0.001, `${date} sits in the column of its weekday`);
                const holiday = holidayName(date) !== '';
                assert.equal(day.fill, holiday || wd === 6 ? COLORS.red : wd === 5 ? COLORS.sat : COLORS.ink, date);
                if (day.weight === 700) bold.push(date);
            });
        }
        assert.deepEqual(bold, HOLIDAYS);
    });

    t.test('year page: layouts, this / next year, two years side by side', async () => {
        const next = draw(await docWith({ year: item('year', { layout: 1, nextYear: true }) }), 'sp:year');
        assert.ok(next.includes('ROČNÍ KALENDÁRIUM 2028'));
        assert.equal([...next.matchAll(/data-month="2028-/g)].length, 12);
        assert.ok(next.includes('data-month="2028-02"') && /data-month="2028-02">.*?>29<\/text>/.test(next), 'leap day of 2028');
        const two = draw(await docWith({ year: item('year', { layout: 2 }) }), 'sp:year');
        assert.ok(two.includes('ROČNÍ KALENDÁRIUM 2027 / 2028'));
        assert.equal([...two.matchAll(/data-month="2027-/g)].length, 12);
        assert.equal([...two.matchAll(/data-month="2028-/g)].length, 12);
        const twoNext = draw(await docWith({ year: item('year', { layout: 2, nextYear: true }) }), 'sp:year');
        assert.ok(twoNext.includes('ROČNÍ KALENDÁRIUM 2028 / 2029'));
    });

    t.test('back page: year overview by default, client contacts while Roční kalendárium is on', async () => {
        const plain = await docWith({});
        const overview = draw(plain, 'back');
        assert.ok(overview.includes('data-variant="year"') && overview.includes('PŘEHLED ROKU 2027') && overview.includes('Kontakty, web a logo klienta'));
        assert.equal([...overview.matchAll(/data-month="2027-/g)].length, 12);
        const withYear = await docWith({ year: item('year', { position: 'back' }) });
        assert.equal(pageOf(withYear, 'back').backVariant, 'contacts');
        const contacts = draw(withYear, 'back');
        assert.ok(contacts.includes('data-variant="contacts"') && contacts.includes('LOGO') && contacts.includes('Obec &lt;Testov&gt; &amp; okolí'));
        assert.ok(!contacts.includes('data-month=') && !contacts.includes('PŘEHLED ROKU'));
        // the feature switched off: the stored page does not count
        const off = await docWith({ year: item('year') }, { features: { specialPages: false } });
        assert.ok(draw(off, 'back').includes('PŘEHLED ROKU 2027'));
        // placeholders 'none' drops the wireframe logo box, a logo of the style replaces it
        assert.ok(!draw(withYear, 'back', { placeholders: 'none' }).includes('LOGO'));
    });

    t.test('cover: year, client name, photo slot', async () => {
        const doc = await docWith({});
        const cover = draw(doc, 'cover', { bleed: true });
        assert.ok(cover.includes('KALENDÁŘ 2027') && cover.includes('TITULNÍ FOTOGRAFIE') && cover.includes('Obec &lt;Testov&gt; &amp; okolí'));
        assert.ok(renderPage(doc, pageOf(doc, 'cover'), { ...base, idPrefix: 'c' }).includes('Název klienta'));
        // a calendar without a layout still gets a cover (list card)
        const bare = await makeDoc(load, { preset: null });
        checkXml(renderPage(bare, buildPages(bare)[0], opts()));
    });

    t.test('intro and contacts: titles, placeholder rows, legend of clubs only while events are on', async () => {
        const clubs = Object.fromEntries(Array.from({ length: 10 }, (_, i) => [`c${i}`, { code: `K${i}`, name: `Spolek ${i}`, pos: i }]));
        const doc = await docWith({ intro: item('intro', { layout: 1 }), contacts: item('contacts', { pos: 1 }) }, { content: { clubs } });
        const intro = draw(doc, 'sp:intro');
        assert.ok(intro.includes('ÚVODNÍ SLOVO') && intro.includes('Jméno Příjmení') && intro.includes('starosta obce'));
        assert.ok(draw(await docWith({ intro: item('intro', { layout: 2 }) }), 'sp:intro').includes('PORTRÉT'));
        const contacts = draw(doc, 'sp:contacts');
        for (const row of special.CONTACT_ROWS) assert.ok(contacts.includes(`>${row}<`), row);
        assert.ok(contacts.includes('VYSVĚTLIVKY') && contacts.includes('>K0<') && contacts.includes('>Spolek 7<') && !contacts.includes('>K8<'), 'at most eight clubs');
        const noEvents = draw(await docWith({ contacts: item('contacts') }, { features: { events: false }, content: { clubs } }), 'sp:contacts');
        assert.ok(!noEvents.includes('VYSVĚTLIVKY'));
        const table = draw(await docWith({ contacts: item('contacts', { layout: 1 }) }), 'sp:contacts');
        for (const head of ['INSTITUCE', 'TELEFON', 'E-MAIL', 'ÚŘEDNÍ HODINY']) assert.ok(table.includes(head), head);
        // a renamed generated page prints its own name
        assert.ok(draw(await docWith({ intro: item('intro', { layout: 1, name: 'Slovo starostky' }) }), 'sp:intro').includes('SLOVO STAROSTKY'));
    });

    t.test('uploaded graphic covers the page including the bleed; without the file a neutral placeholder', async () => {
        const doc = await docWith({ Img1Img1: item('image', { name: 'Mapa obce', asset: 'page1', fileName: 'mapa.pdf' }) });
        const page = pageOf(doc, 'sp:Img1Img1');
        assert.equal(page.subtitle, 'Vlastní strana');
        const svg = draw(doc, 'sp:Img1Img1', { bleed: true });
        assert.ok(svg.includes('<image href="/preview/page.webp" x="-5" y="-5" width="320" height="155" preserveAspectRatio="xMidYMid slice"'));
        assert.ok(draw(doc, 'sp:Img1Img1', { quality: 'full' }).includes('href="/full/page.png"'));
        const flat = await docWith({ Img1Img1: item('image', { asset: 'page1' }) }, { print: { bleed: false, bleedMm: 5, marks: false } });
        assert.ok(draw(flat, 'sp:Img1Img1').includes('x="0" y="0" width="310" height="145"'));
        const missing = draw(await docWith({ Img1Img1: item('image', { name: 'Mapa', asset: 'gone', fileName: 'mapa <1>.pdf' }) }), 'sp:Img1Img1');
        assert.ok(missing.includes('PDF: mapa &lt;1&gt;.pdf'));
        checkXml(missing);
    });

    t.test('text page: heading in the header style, paragraphs wrapped inside the page, long texts shrink into two columns', async () => {
        const paragraph = 'Milí občané, člověk by to ani neřekl a rok utekl jako voda. Vstoupili jsme do nového roku plni očekávání, co nám přinese <či> vezme & dá.';
        const short = await docWith({ Txt1Txt1: item('text', { name: 'Slovo starosty', text: `${paragraph} ${paragraph}\n\n${paragraph} ${paragraph}\nIng. David Harok`, fileName: 'slovo.docx' }) });
        const svg = draw(short, 'sp:Txt1Txt1');
        checkXml(svg);
        assert.ok(svg.includes('SLOVO STAROSTY'));
        const body = texts(svg.slice(svg.indexOf('data-el="special-text"')));
        assert.ok(body.length >= 5 && body[body.length - 1].text === 'Ing. David Harok');
        assert.ok(svg.includes('&lt;či&gt; vezme &amp; dá.'), 'document text is escaped');
        assert.ok(new Set(body.map((x) => x.x)).size === 1, 'one column');
        assert.ok(body.every((x) => x.y < 145 && x.x >= 0));
        // the gap between paragraphs is larger than the line step
        const ys = [...new Set(body.map((x) => x.y))];
        const steps = ys.slice(1).map((y, i) => y - ys[i]);
        assert.ok(Math.max(...steps) > Math.min(...steps) * 1.3);

        const long = await docWith({ Txt1Txt1: item('text', { name: 'Historie', text: Array(24).fill(paragraph).join('\n') }) });
        const longBody = texts(draw(long, 'sp:Txt1Txt1').split('data-el="special-text"')[1]);
        assert.ok(new Set(longBody.map((x) => x.x)).size >= 2, 'columns');
        assert.ok(longBody[0].size < body[0].size, 'smaller type');
        assert.ok(longBody.every((x) => x.y < 145));
        assert.ok(!longBody[longBody.length - 1].text.endsWith('…'), 'everything fits');

        const huge = await docWith({ Txt1Txt1: item('text', { name: 'Kronika', text: Array(90).fill(paragraph).join('\n') }) });
        const hugeBody = texts(draw(huge, 'sp:Txt1Txt1').split('data-el="special-text"')[1]);
        assert.ok(hugeBody.every((x) => x.y < 145), 'an over-long text is cut at the page end, never drawn outside');
        assert.ok(hugeBody[hugeBody.length - 1].text.endsWith('…'));

        const pending = draw(await docWith({ Txt1Txt1: item('text', { name: 'Dokument', text: null, fileName: 'slovo.docx' }) }), 'sp:Txt1Txt1');
        assert.ok(pending.includes('Text z dokumentu „slovo.docx“ se vysází do písma a barev kalendáře.'));
        checkXml(draw(await docWith({ Txt1Txt1: item('text', { name: '', text: '' }) }), 'sp:Txt1Txt1'));
    });
}
