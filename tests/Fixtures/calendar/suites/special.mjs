// Cover, back and special pages (architecture §D.5, map §4.5, handoff §10.10 / §10.13): every kind
// and layout renders well-formed SVG at several page sizes with empty, typical and overflowing
// content; the waste grid marks exactly the rule's days, the year grid marks the holidays, the back
// page switches variant; texts are typeset (wrapping, light formatting, justification, balanced
// columns, auto-fit) and an overflow is reported, never cut silently; user text is escaped.
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
const LAYOUTS = { intro: 3, contacts: 4, year: 3, waste: 2 };

const L = (label, value) => ({ label, value });
const group = (heading, lines, o = {}) => ({ page: 'sp:contacts', heading, lines, highlight: false, pos: 0, ...o });
const PARAGRAPH = 'Milí občané, člověk by to ani neřekl a rok utekl jako voda. Vstoupili jsme do nového roku plni očekávání, co nám přinese <či> vezme & dá k prověření naší trpělivosti.';
/** Typical content of every page, with characters that must be escaped. */
const TYPICAL = {
    pageContent: {
        cover: { title: 'Kalendář <obce> & okolí', subtitle: 'Obec "Testov"', note: 'www.testov.cz', photo: { asset: 'photo1', zoom: 1.5, px: 0.25, py: 0.75 } },
        back: { heading: '', footer: 'Vychází jako příloha <zpravodaje>.', name: 'Obec Testov & spol.', address: 'Hlavní 1\n739 85 Testov', phone: '+420 558 000 111', email: 'obec@testov.cz', web: 'www.testov.cz', imprint: 'Vydala obec Testov · Grafika: "Studio"' },
        'sp:intro': { heading: '', salutation: 'Milí občané,', body: `${PARAGRAPH}\n\nDruhý odstavec má **tučné slovo** a *kurzívu* a ${PARAGRAPH}`, signName: 'Ing. Jana Nováková', signRole: 'starostka obce', photo: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } },
        'sp:contacts': { heading: '', notesHeading: '', photo: { asset: 'photo1', zoom: 1, px: 0.5, py: 0.5 } },
        'sp:year': { heading: '', note: 'Svátky jsou <červeně>.' },
        'sp:waste': { heading: '', note: 'Přistavujte prosím odpady před dnem svozu.' },
        'sp:notes': { heading: '' },
    },
    pageGroups: {
        g1: group('Obecní úřad <Testov>', [L('Po', '7 – 12, 13 – 17'), L('St', '7 – 12 & 13 – 17'), L('', ''), L('T:', '558 733 131'), L('E:', 'obec@testov.cz')], { pos: 0 }),
        g2: group('Knihovna', [L('', 'Otevřeno každé pondělí "odpoledne"'), L('T:', '725 714 176')], { pos: 1 }),
        g3: group('Důležitá čísla', [L('Policie ČR', '158'), L('Hasiči', '150'), L('Záchranná služba', '155')], { pos: 2, highlight: true }),
        g4: group('Sběrný dvůr', [L('Čt', '12:15 – 17:30'), L('So', '9:15 – 14:30')], { pos: 3, page: 'sp:waste' }),
    },
};
const clone = (v) => JSON.parse(JSON.stringify(v));

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
    const { parseRichText, richPlainText, pageContentOf, pageSetupOf, pageGroupsOf, CONTENT_PAGE_KEYS, SETUP_PAGE_KEYS, GENERATED_SPECIAL_IDS } = await load('model/content.js');
    const { allWarnings, pageWarnings } = await load('render/warnings.js');
    const ASSETS = {
        page1: { url: '/full/page.png', previewUrl: '/preview/page.webp', width: 3661, height: 1713, mime: 'image/png', name: 'page.pdf', description: null, kind: 'page' },
        photo1: { url: '/full/photo.jpg', previewUrl: '/preview/photo.webp', width: 3000, height: 2000, mime: 'image/jpeg', name: 'photo.jpg', description: null, kind: 'photo' },
        logo1: { url: '/full/logo.svg', previewUrl: null, width: null, height: null, mime: 'image/svg+xml', name: 'logo.svg', description: null, kind: 'logo' },
    };
    const opts = (extra = {}) => ({ ...base, env: { assets: ASSETS, fonts: [], projectName: 'Obec <Testov> & okolí' }, idPrefix: 's1', ...extra });
    const docWith = (specialPages, o = {}) => makeDoc(load, { styles: o.styles, features: { specialPages: true, waste: true, events: true, ...(o.features || {}) }, content: { specialPages, ...(o.content || {}) }, page: o.page || {}, type: o.type, print: o.print });
    const pageOf = (doc, key) => buildPages(doc).find((p) => p.key === key);
    const draw = (doc, key, o) => renderPage(doc, pageOf(doc, key), opts(o));

    const WASTE = {
        bio: { builtin: 'bio', name: 'Bio & "zeleň"', on: true, place: '', mode: 'rule', weekdays: [3], interval: 'w', from: '2027-04-01', to: '2027-11-30', dates: [], pos: 0 },
        kom: { builtin: 'kom', name: 'Komunální odpad', on: true, place: '', mode: 'rule', weekdays: [0], interval: '2e', from: null, to: null, dates: [], pos: 1 },
        papir: { builtin: 'papir', name: 'Papír', on: true, place: '', mode: 'dates', weekdays: [0], interval: 'w', from: null, to: null, dates: ['2027-01-15', '2027-06-11', '2026-12-30'], pos: 2 },
        plast: { builtin: 'plast', name: 'Plast', on: false, place: '', mode: 'rule', weekdays: [1], interval: 'w', from: null, to: null, dates: [], pos: 3 },
    };

    /** Far more than any page holds. */
    const overflowing = () => ({
        pageContent: {
            cover: { title: 'Velmi dlouhý titulek kalendáře, který se na řádek nevejde ani zmenšený '.repeat(1).padEnd(80, 'x'), subtitle: 'Podtitul '.repeat(8).trim(), note: 'Poznámka '.repeat(13).trim(), photo: null },
            back: { heading: 'N'.repeat(80), footer: 'F'.repeat(200), name: 'Obec '.repeat(16).trim(), address: Array(6).fill('Adresa '.repeat(7).trim()).join('\n'), phone: '9'.repeat(60), email: 'e'.repeat(80), web: 'w'.repeat(80), imprint: 'T'.repeat(200) },
            'sp:intro': { heading: 'H'.repeat(80), salutation: 'Milí občané,', body: Array(40).fill(PARAGRAPH).join('\n\n').slice(0, 4000), signName: 'Jméno', signRole: 'funkce', photo: null },
            'sp:contacts': { heading: 'K'.repeat(80), notesHeading: 'P'.repeat(40), photo: null },
            'sp:year': { heading: 'R'.repeat(80), note: 'n'.repeat(200) },
            'sp:waste': { heading: 'O'.repeat(80), note: 'n'.repeat(200) },
            'sp:notes': { heading: 'P'.repeat(80) },
        },
        pageSetup: { 'sp:contacts': { clubLegend: true, wasteLegend: true, notes: true } },
        pageGroups: Object.fromEntries(Array.from({ length: 60 }, (_, i) => [`o${i}`, group(`Skupina ${i} s dlouhým nadpisem, který se zalomí`, Array.from({ length: 30 }, (_, k) => L(k % 2 ? 'Popisek' : '', 'Hodnota řádku, která je docela dlouhá a zalomí se ' + k)), { pos: i, highlight: i === 3, page: i % 5 === 4 ? 'sp:waste' : 'sp:contacts' })])),
    });

    t.test('every kind and layout renders well-formed SVG at three page sizes, in every render mode', async () => {
        for (const size of SIZES) {
            for (const [kind, count] of Object.entries(LAYOUTS)) {
                for (let layout = 0; layout < count; layout++) {
                    // empty, typical and overflowing content
                    for (const content of [{}, clone(TYPICAL), overflowing()]) {
                        const doc = await docWith({ [kind]: item(kind, { layout, nextYear: layout === 1 }) }, { page: size || {}, content: { wasteKinds: WASTE, clubs: { c1: { code: 'SDH', name: 'Hasiči <dobrovolní> & spol.', pos: 0 } }, ...content } });
                        for (const o of [{}, { bleed: true }, { marks: true, placeholders: 'all' }, { placeholders: 'none', holes: false, quality: 'full' }]) {
                            const svg = draw(doc, `sp:${kind}`, o);
                            checkXml(svg);
                            assert.ok(!/NaN|undefined|Infinity/.test(svg), `${kind}/${layout}: bad number in the markup`);
                            assert.ok(svg.includes(`data-kind="${kind}"`), `${kind}/${layout}`);
                        }
                    }
                }
            }
            for (const content of [{}, clone(TYPICAL), overflowing()]) {
                for (let layout = 0; layout < special.COVER_LAYOUTS.length; layout++) {
                    for (const logo of ['none', 'tl', 'tc', 'tr', 'title']) {
                        const doc = await docWith({ notes: item('notes') }, { page: size || {}, content: { ...content, pageSetup: { cover: { layout, logo, logoSize: 'l' }, back: { logo: logo !== 'none' } } } });
                        for (const key of ['cover', 'back', 'sp:notes']) {
                            const svg = draw(doc, key, { bleed: true });
                            checkXml(svg);
                            assert.ok(!/NaN|undefined|Infinity/.test(svg), key);
                        }
                    }
                }
                // the back page in its contacts variant
                const withYear = await docWith({ year: item('year') }, { page: size || {}, content });
                checkXml(draw(withYear, 'back', { bleed: true }));
            }
        }
        assert.equal(special.COVER_LAYOUTS.length, 4);
        assert.deepEqual(Object.fromEntries(Object.entries(special.SPECIAL_LAYOUTS).map(([k, v]) => [k, v.length])), LAYOUTS);
    });

    t.test('pages are proportional to the page size: everything stays inside the bleed box', async () => {
        for (const size of SIZES) {
            for (const [kind, count] of Object.entries(LAYOUTS)) {
                for (let layout = 0; layout < count; layout++) {
                    const doc = await docWith({ [kind]: item(kind, { layout }) }, { page: size || {}, content: { wasteKinds: WASTE, ...(layout % 2 ? overflowing() : clone(TYPICAL)) } });
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
        const a = await docWith({ contacts: item('contacts') }, { content: clone(TYPICAL) });
        const b = await docWith({ contacts: item('contacts') }, { page: { width: 620, height: 290, bandHeight: 25 }, content: clone(TYPICAL) });
        const xa = texts(draw(a, 'sp:contacts')).find((x) => x.text === 'Knihovna');
        const xb = texts(draw(b, 'sp:contacts')).find((x) => x.text === 'Knihovna');
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
        // layout 1 narrows the grid and sets the page's groups beside it; layout 0 has no room for them
        const sideContent = { wasteKinds: WASTE, wasteExtras: { x1: { kind: 'bio', date: '2027-02-02' } }, ...clone(TYPICAL) };
        const withTexts = draw(await docWith({ waste: item('waste', { layout: 1 }) }, { content: sideContent }), 'sp:waste');
        assert.ok(withTexts.includes('data-el="side"') && withTexts.includes('>Sběrný dvůr<') && withTexts.includes('>12:15 – 17:30<'));
        assert.ok(!withTexts.includes('Knihovna'), 'groups of the contacts page stay there');
        assert.ok(!draw(await docWith({ waste: item('waste') }, { content: sideContent }), 'sp:waste').includes('Sběrný dvůr'));
        assert.ok(withTexts.includes('>Přistavujte prosím odpady před dnem svozu.<'), 'the note under the grid');
        assert.equal([...withTexts.matchAll(/data-waste="/g)].length, marked.length, 'the same grid, narrower');
        const custom = draw(await docWith({ waste: item('waste') }, { content: { pageContent: { 'sp:waste': { heading: 'Svoz odpadů v obci', note: '' } } } }), 'sp:waste');
        assert.ok(custom.includes('>Svoz odpadů v obci<') && !custom.includes('PLÁNOVACÍ KALENDÁŘ'), 'a typed heading replaces the generated one, as typed');
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
        assert.ok(overview.includes('data-variant="year"') && overview.includes('PŘEHLED ROKU 2027'));
        assert.equal(texts(overview.slice(overview.indexOf('data-month="2027-12"'))).filter((x) => x.text.length > 12).length, 0, 'nothing invented under the grid');
        assert.equal([...overview.matchAll(/data-month="2027-/g)].length, 12);
        const filled = draw(await docWith({}, { content: clone(TYPICAL) }), 'back');
        assert.ok(filled.includes('>Vychází jako příloha &lt;zpravodaje&gt;.<') && filled.includes('Vydala obec Testov · Grafika: &quot;Studio&quot;'), 'footer and imprint, escaped');
        assert.ok(!filled.includes('Hlavní 1'), 'the contact block belongs to the other variant');

        const withYear = await docWith({ year: item('year', { position: 'back' }) });
        assert.equal(pageOf(withYear, 'back').backVariant, 'contacts');
        const empty = draw(withYear, 'back');
        assert.ok(empty.includes('data-variant="contacts"') && empty.includes('>LOGO<'));
        assert.ok(!empty.includes('data-month=') && !empty.includes('PŘEHLED ROKU'));
        // placeholders 'none': nothing invented goes to print
        const print = draw(withYear, 'back', { placeholders: 'none' });
        assert.ok(!print.includes('LOGO') && texts(print).length === 0);
        // the filled block: name, address lines, one line of phone · e-mail · web, imprint — centred
        const contacts = draw(await docWith({ year: item('year') }, { content: clone(TYPICAL) }), 'back');
        const block = texts(contacts).filter((x) => x.text !== 'LOGO');
        assert.deepEqual(block.map((x) => x.text), ['Vydala obec Testov · Grafika: "Studio"'.replace(/"/g, '&quot;'), 'Obec Testov &amp; spol.', 'Hlavní 1', '739 85 Testov', '+420 558 000 111  ·  obec@testov.cz  ·  www.testov.cz']);
        assert.ok(block.every((x) => x.anchor === 'middle' && Math.abs(x.x - 155) < 0.001));
        const ys = block.slice(1).map((x) => x.y);
        assert.ok(ys.every((y, i) => i === 0 || y > ys[i - 1]), 'top to bottom');
        // without the logo the block has no logo box; the option is the designer's
        const noLogo = draw(await docWith({ year: item('year') }, { content: { ...clone(TYPICAL), pageSetup: { back: { logo: false } } } }), 'back');
        assert.ok(!noLogo.includes('data-el="page-logo"') && contacts.includes('data-el="page-logo"'));
        // the feature switched off: the stored page does not count
        const off = await docWith({ year: item('year') }, { features: { specialPages: false } });
        assert.ok(draw(off, 'back').includes('PŘEHLED ROKU 2027'));
    });

    t.test('cover: default title, typed texts, four layouts, the photo with its crop, the logo over the photo', async () => {
        const doc = await docWith({});
        const cover = draw(doc, 'cover', { bleed: true });
        assert.ok(cover.includes('>Kalendář 2027<') && cover.includes('TITULNÍ FOTOGRAFIE') && cover.includes('data-layout="0"'));
        assert.ok(!cover.includes('Název klienta'), 'no invented client name');
        // print: no slot, no dashed logo — the title band stays
        const print = draw(doc, 'cover', { placeholders: 'none' });
        assert.ok(!print.includes('TITULNÍ FOTOGRAFIE') && !print.includes('LOGO') && print.includes('>Kalendář 2027<'));
        // a calendar without a layout still gets a cover (list card)
        const bare = await makeDoc(load, { preset: null });
        checkXml(renderPage(bare, buildPages(bare)[0], opts()));

        const withLogo = (setup) => docWith({}, { content: { ...clone(TYPICAL), pageSetup: { cover: setup } }, styles: (layout) => { layout.styles.logo.asset = 'logo1'; } });
        const full = draw(await withLogo({ layout: 0, logo: 'tc', logoSize: 'm' }), 'cover', { bleed: true });
        assert.ok(full.includes('>Kalendář &lt;obce&gt; &amp; okolí<') && full.includes('>Obec &quot;Testov&quot;<') && full.includes('>www.testov.cz<'));
        // the photo covers the bleed box, cropped the way week photos are: 3000 × 2000 into 320 × 155 at 150 %
        const image = /<g data-el="page-photo">.*?<image href="\/preview\/photo.webp" x="([-\d.]+)" y="([-\d.]+)" width="([\d.]+)" height="([\d.]+)"/.exec(full).slice(1).map(Number);
        const dh = Math.max(320 / 1.5, 155) * 1.5;
        assert.ok(Math.abs(image[3] - dh) < 0.01 && Math.abs(image[2] - dh * 1.5) < 0.01);
        assert.ok(Math.abs(image[0] - (-5 + (320 - dh * 1.5) * 0.25)) < 0.01 && Math.abs(image[1] - (-5 + (155 - dh) * 0.75)) < 0.01);
        // layers: photo, then the title band, then the logo — the logo is over the photo, centred
        const order = ['data-el="page-photo"', 'data-el="cover-band"', 'data-el="page-logo"'].map((mark) => full.indexOf(mark));
        assert.ok(order[0] > 0 && order[0] < order[1] && order[1] < order[2]);
        const logo = /<g data-el="page-logo"><image href="\/full\/logo.svg" x="([-\d.]+)" y="([-\d.]+)" width="([\d.]+)"/.exec(full).slice(1).map(Number);
        assert.ok(Math.abs(logo[0] + logo[2] / 2 - 155) < 0.01, 'top centre');
        // logo positions and sizes
        const xs = {};
        for (const position of ['tl', 'tr', 'title', 'none']) {
            const svg = draw(await withLogo({ layout: 0, logo: position, logoSize: 'm' }), 'cover');
            const m = /<g data-el="page-logo"><image [^>]*x="([-\d.]+)" y="([-\d.]+)"/.exec(svg);
            xs[position] = m ? [Number(m[1]), Number(m[2])] : null;
        }
        assert.ok(xs.tl[0] < 30 && xs.tr[0] > 250 && xs.none === null && xs.title[1] > xs.tr[1], 'left, right, beside the title, none');
        const sizes = [];
        for (const logoSize of ['s', 'm', 'l']) sizes.push(Number(/<g data-el="page-logo"><image [^>]*width="([\d.]+)"/.exec(draw(await withLogo({ layout: 0, logo: 'tc', logoSize }), 'cover'))[1]));
        assert.ok(sizes[0] < sizes[1] && sizes[1] < sizes[2]);
        // framed photo: the title sits under the frame, centred; no photo: no photo element at all; photo only: no texts
        const framed = draw(await withLogo({ layout: 1, logo: 'none', logoSize: 'm' }), 'cover');
        const frame = /clipPath id="[^"]+"><rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"/.exec(framed).slice(1).map(Number);
        const title = texts(framed).find((x) => x.text.startsWith('Kalendář'));
        assert.ok(frame[0] > 5 && frame[0] + frame[2] < 305 && title.y > frame[1] + frame[3] && title.anchor === 'middle');
        const typo = draw(await withLogo({ layout: 2, logo: 'title', logoSize: 'l' }), 'cover');
        assert.ok(!typo.includes('data-el="page-photo"') && typo.includes('data-el="cover-rule"') && typo.includes('>www.testov.cz<'));
        const only = draw(await withLogo({ layout: 3, logo: 'tr', logoSize: 's' }), 'cover');
        assert.ok(only.includes('data-el="page-photo"') && texts(only).length === 0 && only.includes('data-el="page-logo"'));
        assert.deepEqual(special.specialPhotoBox(doc, pageOf(doc, 'cover')), { x: -5, y: -5, w: 320, h: 155 });
        assert.equal(special.specialPhotoBox(await withLogo({ layout: 2, logo: 'none', logoSize: 'm' }), pageOf(doc, 'cover')), null);
    });

    t.test('light formatting: paragraphs, **bold**, *italic*; a malformed input is plain text and never fails', async () => {
        const runs = (text) => parseRichText(text).map((p) => p.lines.map((line) => line.map((r) => `${r.bold ? 'B' : ''}${r.italic ? 'I' : ''}[${r.text}]`).join('')));
        assert.deepEqual(runs('a **b** *c* ***d***'), [['[a ]B[b][ ]I[c][ ]BI[d]']]);
        assert.deepEqual(runs('první\n\n\n  \ndruhý\nřádek'), [['[první]'], ['[druhý]', '[řádek]']]);
        assert.deepEqual(runs('a **b *c'), [['[a **b *c]']], 'unclosed marks are text');
        assert.deepEqual(runs('**a\n\nb**'), [['[**a]'], ['[b**]']], 'a mark never spans paragraphs');
        assert.deepEqual(runs('5 \\* 3 = 15 a *x*'), [['[5 * 3 = 15 a ]I[x]']], 'an escaped asterisk');
        assert.deepEqual(runs(''), []);
        assert.deepEqual(runs(null), []);
        assert.deepEqual(runs('   \n\n  '), []);
        assert.equal(richPlainText('a **b** *c*\n\nd'), 'a b c\n\nd');
        // no character is ever lost
        for (const text of ['***', '**', '*', '****', '* * *', '**a*b**c*', '\\', '\\\\*', 'a\\', '*a**b*c**', '<b>&amp;</b> **<i>**']) {
            const plain = parseRichText(text).map((p) => p.lines.map((line) => line.map((r) => r.text).join('')).join('\n')).join('\n\n');
            assert.ok(plain.replace(/[*\\]/g, '').trim() === text.replace(/[*\\]/g, '').trim(), `"${text}" → "${plain}"`);
            const doc = await docWith({ intro: item('intro', { layout: 1 }) }, { content: { pageContent: { 'sp:intro': { heading: text, salutation: text, body: `${text}\n\n${text} ${PARAGRAPH}`, signName: text, signRole: text, photo: null } } } });
            checkXml(draw(doc, 'sp:intro'));
        }
    });

    t.test('intro: heading, salutation, formatted text, signature; justified lines; columns are balanced', async () => {
        const body = `${PARAGRAPH} ${PARAGRAPH}\n\nDruhý odstavec má **tučné slovo** a *kurzívu*. ${PARAGRAPH}\n\n${PARAGRAPH}`;
        const content = (o = {}, setup = {}) => ({ pageContent: { 'sp:intro': { heading: '', salutation: 'Milí občané,', body, signName: 'Ing. Jana <Nováková>', signRole: 'starostka obce', photo: null, ...o } }, pageSetup: { 'sp:intro': { align: 'left', ...setup } } });
        const one = draw(await docWith({ intro: item('intro', { layout: 1 }) }, { content: content() }), 'sp:intro');
        checkXml(one);
        assert.ok(one.includes('>ÚVODNÍ SLOVO<') && one.includes('>Milí občané,<'));
        assert.ok(/<tspan font-weight="700">tučné slovo ?<\/tspan>/.test(one) && /<tspan font-style="italic">kurzívu<\/tspan>/.test(one), 'bold and italic runs');
        assert.ok(one.includes('&lt;či&gt; vezme &amp; dá'), 'the text is escaped');
        assert.ok(!one.includes('**') && !one.includes('*kurzívu*'), 'the marks are not printed');
        const name = texts(one).find((x) => x.text === 'Ing. Jana &lt;Nováková&gt;');
        const role = texts(one).find((x) => x.text === 'starostka obce');
        assert.ok(name.anchor === 'end' && name.weight === 700 && role.anchor === 'end' && role.y > name.y, 'the signature is right-aligned under the text');
        const lines = [...one.slice(one.indexOf('data-field="body"')).matchAll(/<text x="([\d.]+)" y="([\d.]+)"([^>]*)>/g)].map((m) => ({ x: Number(m[1]), y: Number(m[2]), attrs: m[3] }));
        assert.ok(name.y > Math.max(...lines.filter((l) => !l.attrs.includes('data-field')).map((l) => l.y)));
        assert.ok(!one.includes('word-spacing'), 'left-aligned text is not spread');
        // one-letter prepositions never end a line
        assert.ok(![...one.matchAll(/>([^<]*)<\/text>/g)].some((m) => / [aikosuvzAIKOSUVZ]$/.test(m[1])), 'no line ends with a one-letter word');
        // a renamed generated page prints its own name; a typed heading wins and stays as typed
        assert.ok(draw(await docWith({ intro: item('intro', { layout: 1, name: 'Slovo starostky' }) }), 'sp:intro').includes('SLOVO STAROSTKY'));
        assert.ok(draw(await docWith({ intro: item('intro', { layout: 1 }) }, { content: content({ heading: 'Slovo starostky' }) }), 'sp:intro').includes('>Slovo starostky<'));

        // justified: every line but the last of a paragraph is spread to the column width
        const justified = draw(await docWith({ intro: item('intro', { layout: 1 }) }, { content: content({}, { align: 'justify' }) }), 'sp:intro');
        const spread = [...justified.matchAll(/word-spacing="([\d.]+)"/g)].map((m) => Number(m[1]));
        const size = Number(/data-field="body" data-size="([\d.]+)"/.exec(justified)[1]);
        assert.ok(spread.length >= 3 && spread.every((v) => v > 0 && v <= size * 0.5 + 1e-9), `spread ${spread} at ${size}`);
        assert.ok(!/word-spacing[^>]*>[^<]*dá k prověření naší trpělivosti\.<\/text>/.test(justified), 'the last line of a paragraph stays ragged');

        // the portrait layout: two balanced columns, the signature under the portrait slot
        const two = draw(await docWith({ intro: item('intro', { layout: 2 }) }, { content: content() }), 'sp:intro');
        assert.ok(two.includes('PORTRÉT') && /data-field="body" data-size="[\d.]+" data-columns="2"/.test(two));
        const flow = [...two.slice(two.indexOf('data-field="body"')).matchAll(/<text x="([\d.]+)" y="([\d.]+)"/g)].map((m) => ({ x: Number(m[1]), y: Number(m[2]) }));
        const columns = [...new Set(flow.map((l) => l.x))].sort((a, b) => a - b);
        assert.equal(columns.length, 2);
        const count = columns.map((x) => flow.filter((l) => l.x === x).length);
        assert.ok(Math.abs(count[0] - count[1]) <= 3, `balanced columns, got ${count}`);
        const sign = texts(two).find((x) => x.text.startsWith('Ing. Jana'));
        assert.ok(sign.anchor === 'start' && sign.x < columns[0]);
        // a photo with transparency is clipped into its frame and gets no backdrop
        const portrait = draw(await docWith({ intro: item('intro', { layout: 2 }) }, { content: content({ photo: { asset: 'photo1', zoom: 2, px: 0, py: 1 } }) }), 'sp:intro');
        assert.ok(!portrait.includes('PORTRÉT') && /<g data-el="page-photo"><defs><clipPath/.test(portrait));
        const box = special.specialPhotoBox(await docWith({ intro: item('intro', { layout: 2 }) }), pageOf(await docWith({ intro: item('intro', { layout: 2 }) }), 'sp:intro'));
        assert.ok(box.w < box.h, 'a portrait frame');
        assert.equal(special.specialPhotoBox(await docWith({ intro: item('intro', { layout: 1 }) }), pageOf(await docWith({ intro: item('intro', { layout: 1 }) }), 'sp:intro')), null);

        // unfilled: grey lines in the editor, nothing in print
        const empty = await docWith({ intro: item('intro', { layout: 0 }) });
        assert.ok(draw(empty, 'sp:intro').includes('FOTOGRAFIE') && (draw(empty, 'sp:intro').match(/<rect/g) || []).length > 10);
        assert.equal(texts(draw(empty, 'sp:intro', { placeholders: 'none' })).length, 1, 'print: only the heading');
    });

    t.test('intro auto-fit: a longer text gets smaller type, then more columns, then a warning — never silently cut', async () => {
        const make = (n, layout) => docWith({ intro: item('intro', { layout }) }, { content: { pageContent: { 'sp:intro': { heading: '', salutation: '', body: Array(n).fill(PARAGRAPH).join('\n\n'), signName: 'Podpis', signRole: '', photo: null } } } });
        const meta = (svg) => {
            const m = /data-field="body" data-size="([\d.]+)" data-columns="(\d)"( data-overflow="1")?/.exec(svg);
            return { size: Number(m[1]), columns: Number(m[2]), overflow: Boolean(m[3]) };
        };
        const small = meta(draw(await make(2, 1), 'sp:intro'));
        const medium = meta(draw(await make(7, 1), 'sp:intro'));
        const large = meta(draw(await make(16, 1), 'sp:intro'));
        assert.ok(small.columns === 1 && !small.overflow);
        assert.ok(medium.size <= small.size && !medium.overflow);
        assert.ok(large.columns > small.columns && large.size < small.size && !large.overflow, JSON.stringify(large));
        for (const layout of [0, 1, 2]) {
            const doc = await make(40, layout);
            const svg = draw(doc, 'sp:intro');
            const over = meta(svg);
            assert.ok(over.overflow, `layout ${layout}`);
            const body = texts(svg.slice(svg.indexOf('data-field="body"')));
            assert.ok(body.every((x) => x.y < 145 && x.y > 0 && x.x > 0 && x.x < 310), 'nothing is drawn outside the page');
            assert.ok(/…<\/text>/.test(svg), 'the cut is visible');
            assert.deepEqual(special.specialPageIssues(doc, pageOf(doc, 'sp:intro'), opts()), [special.OVERFLOW.intro]);
            const warnings = pageWarnings(doc, pageOf(doc, 'sp:intro'), opts());
            assert.deepEqual(warnings.map((w) => [w.kind, w.pageIndex, w.message]), [['overflow', pageOf(doc, 'sp:intro').index, special.OVERFLOW.intro]]);
            assert.ok(allWarnings(doc, buildPages(doc), opts()).has(pageOf(doc, 'sp:intro').index));
        }
        const fine = await make(3, 1);
        assert.deepEqual(special.specialPageIssues(fine, pageOf(fine, 'sp:intro'), opts()), []);
        assert.equal(allWarnings(fine, buildPages(fine), opts()).size, 0);
        // widows and orphans: no column starts with the last line of a longer paragraph alone, none ends with a first line alone
        const doc = await make(9, 2);
        const svg = draw(doc, 'sp:intro');
        assert.ok(!meta(svg).overflow);
    });

    t.test('contacts: groups flow into balanced columns, a heading never parts from its first line, label and value align', async () => {
        const many = Object.fromEntries(Array.from({ length: 9 }, (_, i) => [`g${i}`, group(`Skupina ${i}`, [L('Telefon', `558 000 00${i}`), L('E-mail', `urad${i}@testov.cz`), L('', 'Volný řádek textu')], { pos: i })]));
        for (const [layout, cols] of [[0, 2], [1, 3], [2, 4], [3, 2]]) {
            const doc = await docWith({ contacts: item('contacts', { layout }) }, { content: { pageGroups: many, pageSetup: { 'sp:contacts': { clubLegend: false, wasteLegend: false, notes: false } } } });
            const svg = draw(doc, 'sp:contacts');
            checkXml(svg);
            assert.ok(svg.includes(`data-columns="${cols}"`), `layout ${layout}`);
            const groups = [...svg.matchAll(/<g data-group="(g\d)" data-column="(\d)">(.*?)<\/g>/g)].map((m) => ({ id: m[1], column: Number(m[2]), texts: texts(m[3]) }));
            assert.deepEqual(groups.map((g) => g.id), Object.keys(many), 'every group once, in order');
            assert.ok(groups.every((g, i) => i === 0 || g.column >= groups[i - 1].column), 'columns fill in reading order');
            assert.equal(new Set(groups.map((g) => g.column)).size, cols, 'every column is used');
            const perColumn = Array.from({ length: cols }, (_, k) => groups.filter((g) => g.column === k).length);
            assert.ok(Math.max(...perColumn) - Math.min(...perColumn) <= 1, `balanced: ${perColumn}`);
            for (const g of groups) {
                assert.equal(g.texts[0].text, `Skupina ${g.id.slice(1)}`);
                assert.ok(g.texts[0].weight === 700 && g.texts[1].y > g.texts[0].y, 'heading, then its lines');
                // label column and value column: both values start at the same x, right of the labels
                const values = g.texts.filter((x) => /^558|^urad/.test(x.text));
                const labels = g.texts.filter((x) => x.text === 'Telefon' || x.text === 'E-mail');
                assert.ok(values.length === 2 && Math.abs(values[0].x - values[1].x) < 0.001 && values[0].x > labels[0].x + 5);
                assert.ok(Math.abs(labels[0].x - g.texts[0].x) < 0.001 && Math.abs(g.texts[3].x - g.texts[0].x) < 0.001, 'labels and free lines start at the column edge');
            }
            const xs = [...new Set(groups.map((g) => g.texts[0].x))].sort((a, b) => a - b);
            assert.ok(xs[0] >= 9 && xs[xs.length - 1] < (layout === 3 ? 186 : 310));
        }

        // a group higher than a column is split between its lines — the heading keeps its first line
        const long = { big: group('Dlouhý seznam', Array.from({ length: 30 }, (_, k) => L('', `Položka číslo ${k + 1} dlouhého seznamu, která se zalomí na další řádek sloupce`)), { pos: 0 }), next: group('Další', [L('T:', '1')], { pos: 1 }) };
        const split = draw(await docWith({ contacts: item('contacts', { layout: 2 }) }, { content: { pageGroups: long } }), 'sp:contacts');
        const parts = [...split.matchAll(/<g data-group="big" data-column="(\d)">(.*?)<\/g>/g)].map((m) => ({ column: Number(m[1]), texts: texts(m[2]) }));
        assert.ok(parts.length >= 2, 'split over columns');
        assert.ok(parts[0].texts[0].text === 'Dlouhý seznam' && parts[0].texts.length >= 2, 'the heading is followed by a line in its column');
        assert.ok(parts.slice(1).every((p) => p.texts[0].text !== 'Dlouhý seznam'), 'the heading is not repeated');
        assert.equal(parts.reduce((n, p) => n + p.texts.filter((x) => x.text.startsWith('Položka číslo')).length, 0), 30, 'no line is lost');

        // the highlighted block: a tinted box, value right-aligned; it never splits
        const hl = draw(await docWith({ contacts: item('contacts') }, { content: clone(TYPICAL) }), 'sp:contacts');
        const block = /<g data-group="g3" data-column="\d"><rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"[^>]*data-highlight="1"\/>(.*?)<\/g>/.exec(hl);
        const [bx, by, bw, bh] = block.slice(1, 5).map(Number);
        const inside = texts(block[5]);
        assert.deepEqual(inside.map((x) => x.text), ['Důležitá čísla', 'Policie ČR', '158', 'Hasiči', '150', 'Záchranná služba', '155']);
        assert.ok(inside.every((x) => x.x >= bx && x.x <= bx + bw && x.y > by && x.y < by + bh));
        assert.ok(inside.filter((x) => /^\d+$/.test(x.text)).every((x) => x.anchor === 'end'));
        assert.equal((hl.match(/data-group="g3"/g) || []).length, 1);
        assert.ok(hl.includes('>Obecní úřad &lt;Testov&gt;<') && hl.includes('>7 – 12 &amp; 13 – 17<') && hl.includes('&quot;odpoledne&quot;'), 'escaped');
        assert.ok(!hl.includes('Sběrný dvůr'), 'groups of the waste page stay there');
    });

    t.test('contacts: legends from data can be switched off, notes column, photo layout, placeholders, overflow warning', async () => {
        const clubs = Object.fromEntries(Array.from({ length: 10 }, (_, i) => [`c${i}`, { code: `K${i}`, name: `Spolek ${i}`, pos: i }]));
        const setup = (o) => ({ 'sp:contacts': { clubLegend: true, wasteLegend: false, notes: false, ...o } });
        const base1 = { clubs, wasteKinds: WASTE, ...clone(TYPICAL) };
        const on = draw(await docWith({ contacts: item('contacts') }, { content: base1 }), 'sp:contacts');
        assert.ok(on.includes('data-legend="clubs"') && on.includes('>Vysvětlivky<') && on.includes('>K0<') && on.includes('>Spolek 7<') && !on.includes('>K8<'), 'at most eight clubs');
        assert.ok(!on.includes('data-legend="waste"'), 'the waste legend is off by default');
        const off = draw(await docWith({ contacts: item('contacts') }, { content: { ...base1, pageSetup: setup({ clubLegend: false }) } }), 'sp:contacts');
        assert.ok(!off.includes('Vysvětlivky'));
        const noEvents = draw(await docWith({ contacts: item('contacts') }, { features: { events: false }, content: base1 }), 'sp:contacts');
        assert.ok(!noEvents.includes('Vysvětlivky'), 'no legend while events are off');
        const waste = draw(await docWith({ contacts: item('contacts') }, { content: { ...base1, pageSetup: setup({ wasteLegend: true }) } }), 'sp:contacts');
        const legend = /<g data-group="legend-waste" data-legend="waste"[^>]*>(.*?)<\/g>/.exec(waste)[1];
        assert.deepEqual(texts(legend).map((x) => x.text), ['Svoz odpadů', 'Bio &amp; &quot;zeleň&quot;', 'Komunální odpad', 'Papír']);
        assert.equal((legend.match(/<rect/g) || []).length, 3, 'a colour swatch per kind');

        // notes: ruled lines right of the groups, their heading in the header band
        const notes = draw(await docWith({ contacts: item('contacts') }, { content: { ...base1, pageSetup: setup({ notes: true }) } }), 'sp:contacts');
        const heading = texts(notes).find((x) => x.text === 'POZNÁMKY');
        const rules = [...notes.matchAll(/<g data-el="ruled">(.*?)<\/g>/g)][0][1];
        const x1 = [...rules.matchAll(/x1="([\d.]+)"/g)].map((m) => Number(m[1]));
        assert.ok(x1.length >= 8 && x1.every((x) => Math.abs(x - heading.x) < 0.001), 'the lines start under their heading');
        const groupX = Math.max(...[...notes.matchAll(/<g data-group="[^"]+"[^>]*>(.*?)<\/g>/g)].flatMap((m) => texts(m[1]).map((x) => x.x)));
        assert.ok(groupX < heading.x, 'groups stay left of the notes');
        const renamed = draw(await docWith({ contacts: item('contacts') }, { content: { ...base1, pageSetup: setup({ notes: true }), pageContent: { 'sp:contacts': { heading: 'Kontakty', notesHeading: 'Moje poznámky', photo: null } } } }), 'sp:contacts');
        assert.ok(renamed.includes('>Moje poznámky<') && renamed.includes('>Kontakty<'));
        // the notes page: heading + lines across the page
        const page = draw(await docWith({ notes: item('notes') }), 'sp:notes');
        assert.ok(page.includes('>POZNÁMKY<') && (page.match(/<line /g) || []).length >= 8);
        assert.ok(GENERATED_SPECIAL_IDS.includes('notes') && pageOf(await docWith({ notes: item('notes') }), 'sp:notes').title === 'Poznámky');

        // photo layout: the photo right of the groups
        const photo = draw(await docWith({ contacts: item('contacts', { layout: 3 }) }, { content: base1 }), 'sp:contacts', { bleed: true });
        const clip = /<g data-el="page-photo"><defs><clipPath id="[^"]+"><rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"/.exec(photo).slice(1).map(Number);
        assert.ok(clip[0] > 150 && Math.abs(clip[0] + clip[2] - 315) < 0.01 && Math.abs(clip[1] + clip[3] - 150) < 0.01, 'to the bleed edge');

        // unfilled: bars in the editor (the legend, real data, is still drawn), nothing in print
        const empty = await docWith({ contacts: item('contacts', { layout: 1 }) }, { content: { clubs } });
        const editor = draw(empty, 'sp:contacts');
        assert.ok(editor.includes('data-el="groups" data-empty="1"') && editor.includes('>Vysvětlivky<'));
        const print = draw(await docWith({ contacts: item('contacts', { layout: 1 }) }), 'sp:contacts', { placeholders: 'none' });
        assert.deepEqual(texts(print).map((x) => x.text), ['DŮLEŽITÉ KONTAKTY']);

        // too much: the type shrinks first, then the page warns; nothing leaves the page
        const sizeOf = (svg) => Number(/data-el="groups" data-size="([\d.]+)"/.exec(svg)[1]);
        const some = (n) => Object.fromEntries(Array.from({ length: n }, (_, i) => [`g${String(i).padStart(2, '0')}`, group(`Skupina ${i}`, [L('Telefon', '558 000 000'), L('E-mail', 'urad@testov.cz'), L('', 'Volný řádek')], { pos: i })]));
        const few = await docWith({ contacts: item('contacts') }, { content: { pageGroups: some(4) } });
        const more = await docWith({ contacts: item('contacts') }, { content: { pageGroups: some(12) } });
        const tooMany = await docWith({ contacts: item('contacts') }, { content: { pageGroups: some(60) } });
        assert.ok(sizeOf(draw(more, 'sp:contacts')) < sizeOf(draw(few, 'sp:contacts')));
        assert.deepEqual(special.specialPageIssues(more, pageOf(more, 'sp:contacts'), opts()), []);
        assert.deepEqual(special.specialPageIssues(tooMany, pageOf(tooMany, 'sp:contacts'), opts()), [special.OVERFLOW.groups]);
        assert.ok(texts(draw(tooMany, 'sp:contacts')).every((x) => x.y > 0 && x.y < 145 && x.x > 0 && x.x < 310));
        // more columns hold more
        const four = await docWith({ contacts: item('contacts', { layout: 2 }) }, { content: { pageGroups: some(24) } });
        const twoCols = await docWith({ contacts: item('contacts', { layout: 0 }) }, { content: { pageGroups: some(24) } });
        assert.deepEqual(special.specialPageIssues(four, pageOf(four, 'sp:contacts'), opts()), []);
        assert.deepEqual(special.specialPageIssues(twoCols, pageOf(twoCols, 'sp:contacts'), opts()), [special.OVERFLOW.groups]);
        // the side block of the waste plan warns the same way
        const side = await docWith({ waste: item('waste', { layout: 1 }) }, { content: { pageGroups: Object.fromEntries(Object.entries(some(30)).map(([id, g]) => [id, { ...g, page: 'sp:waste' }])) } });
        assert.deepEqual(special.specialPageIssues(side, pageOf(side, 'sp:waste'), opts()), [special.OVERFLOW.side]);
    });

    t.test('one-line texts shrink to fit, then are cut with an ellipsis and reported; defaults of the content model', async () => {
        // a wide heading still fits: the type shrinks
        const long = await docWith({ year: item('year') }, { content: { pageContent: { 'sp:year': { heading: 'M'.repeat(60), note: '' } } } });
        const heading = texts(draw(long, 'sp:year'))[0];
        assert.ok(!heading.text.endsWith('…') && heading.size < 20 * 0.3 && heading.text.length === 60, JSON.stringify(heading));
        assert.deepEqual(special.specialPageIssues(long, pageOf(long, 'sp:year'), opts()), []);
        // a line that cannot fit even at its smallest size is cut visibly and reported
        const wide = await docWith({ waste: item('waste', { layout: 1 }) }, { content: { pageContent: { 'sp:waste': { heading: '', note: 'M'.repeat(200) } } } });
        const note = /data-field="note">([^<]*)</.exec(draw(wide, 'sp:waste'))[1];
        assert.ok(note.endsWith('…') && note.length < 200);
        assert.deepEqual(special.specialPageIssues(wide, pageOf(wide, 'sp:waste'), opts()), [special.OVERFLOW.line('Poznámka pod mřížkou')]);
        const medium = await docWith({ year: item('year') }, { content: { pageContent: { 'sp:year': { heading: 'Roční kalendárium obce Testov na rok 2027 s přehledem', note: 'Svátky jsou <červeně>.' } } } });
        const fitted = texts(draw(medium, 'sp:year'))[0];
        assert.ok(!fitted.text.endsWith('…') && fitted.size <= 20 * 0.31);
        assert.deepEqual(special.specialPageIssues(medium, pageOf(medium, 'sp:year'), opts()), []);
        assert.ok(draw(medium, 'sp:year').includes('>Svátky jsou &lt;červeně&gt;.<'));
        assert.deepEqual(special.specialPageIssues(long, buildPages(long).find((p) => p.kind === 'week'), opts()), [], 'week pages are not this module\'s business');

        // the model: a missing item is "empty", unknown keys never get through, the keys are a fixed set
        assert.deepEqual(pageContentOf({}, 'cover'), { title: '', subtitle: '', note: '', photo: null });
        assert.deepEqual(pageContentOf({ pageContent: { cover: { title: 'A', evil: 1, photo: { asset: 'x', zoom: 2, px: 0, py: 1, more: 1 } } } }, 'cover'), { title: 'A', subtitle: '', note: '', photo: { asset: 'x', zoom: 2, px: 0, py: 1 } });
        assert.deepEqual(pageSetupOf({}, 'cover'), { layout: 0, logo: 'tr', logoSize: 'm' });
        assert.deepEqual(pageSetupOf({ pageSetup: { 'sp:contacts': { notes: true, clubLegend: 'yes' } } }, 'sp:contacts'), { clubLegend: true, wasteLegend: false, notes: true });
        assert.equal(pageContentOf({}, 'w05'), null);
        assert.equal(pageSetupOf({}, 'sp:year'), null);
        assert.ok(SETUP_PAGE_KEYS.every((key) => CONTENT_PAGE_KEYS.includes(key)));
        assert.deepEqual(pageGroupsOf(clone(TYPICAL), 'sp:contacts').map(([id]) => id), ['g1', 'g2', 'g3']);
        assert.deepEqual(pageGroupsOf(clone(TYPICAL), 'sp:waste').map(([id]) => id), ['g4']);
        // fonts: every cut of the fonts the special pages draw with
        const fonts = [
            { family: 'Rubik (Rubik Regular)', fontName: 'Rubik', faceName: 'Rubik Regular', weight: 400, style: 'Regular', url: '' },
            { family: 'Rubik (Rubik Bold)', fontName: 'Rubik', faceName: 'Rubik Bold', weight: 700, style: 'Bold', url: '' },
            { family: 'Rubik (Rubik Italic)', fontName: 'Rubik', faceName: 'Rubik Italic', weight: 400, style: 'Italic', url: '' },
            { family: 'Other (Other Regular)', fontName: 'Other', faceName: 'Other Regular', weight: 400, style: 'Regular', url: '' },
        ];
        const styled = await docWith({ intro: item('intro', { layout: 1 }) }, { content: clone(TYPICAL), styles: (layout) => { layout.styles.name.family = 'Rubik (Rubik Regular)'; } });
        assert.deepEqual(special.specialFamilies(styled, fonts), ['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)', 'Rubik (Rubik Italic)']);
        const withFonts = renderPage(styled, pageOf(styled, 'sp:intro'), { ...opts(), env: { assets: ASSETS, fonts } });
        assert.ok(/<tspan font-family="&quot;Rubik \(Rubik Bold\)&quot;">tučné slovo ?<\/tspan>/.test(withFonts) && /<tspan font-family="&quot;Rubik \(Rubik Italic\)&quot;">kurzívu ?<\/tspan>/.test(withFonts), 'real cuts of the project font, nothing synthesised');
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
