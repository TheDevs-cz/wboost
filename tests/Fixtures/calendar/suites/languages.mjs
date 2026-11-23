// Multilingual names (K15b, docs/calendar/languages-plan.md): month / weekday / week-number words in
// Czech + `design.languages`, the two-line Měsíc, „Názvy dnů“ full / short / auto, the Měsíc frame
// growing in height, and a Czech-only calendar printing exactly what it printed before.
import assert from 'node:assert/strict';
import { copy, layoutOpts, makeDoc, near } from '../helpers.mjs';

export default async function (t, load) {
    const L = await load('generate/languages.js');
    const { monthText, weekText, buildPages, weekPages } = await load('generate/pages.js');
    const { dayInfo } = await load('generate/day.js');
    const { normalizeDocument } = await load('model/normalize.js');
    const { layoutWeekPage, monthLabel, monthLines, placeMonthText, monthTextSpec, MONTH_LINE_HEIGHT } = await load('render/week-layout.js');
    const { renderPage } = await load('render/page.js');
    const A = await load('editor/autogrow.js');
    const { K } = await load('model/defaults.js');
    const { createEstimatorMeasurer } = await load('render/measure.js');
    const opts = await layoutOpts(load);
    const m = createEstimatorMeasurer();
    const ENV = { fonts: [], accent: '#C84B55', assets: {}, currentYear: 2026 };
    const page = (doc, key) => weekPages(buildPages(doc)).find((p) => p.key === key);
    const grownDoc = (doc) => {
        const layout = A.autoGrow(doc.design.layout, A.maxTextWidths(doc, buildPages(doc), opts), doc.design);
        return layout ? { ...doc, design: { ...doc.design, layout } } : doc;
    };

    t.test('the table: every language names 12 months, 7 weekdays and 7 short ones; the extras in print order', () => {
        assert.deepEqual([...L.EXTRA_LANGUAGES], ['sk', 'pl', 'de', 'en']);
        for (const code of ['cs', ...L.EXTRA_LANGUAGES]) {
            const l = L.LANGUAGES[code];
            assert.equal(l.months.length, 12, code);
            assert.equal(l.weekdays.length, 7, code);
            assert.equal(l.weekdaysShort.length, 7, code);
            assert.ok(l.label && l.week && l.weekCap, code);
        }
        assert.equal(L.LANGUAGES.pl.months[11], 'grudzień', 'nominative, not „grudnia“');
    });

    t.test('languages: known codes only, unique, in the fixed order cs → sk → pl → de → en', () => {
        assert.deepEqual(L.normalizeLanguages(['en', 'pl', 'en', 'xx', 'cs', 3]), ['pl', 'en']);
        assert.deepEqual(L.normalizeLanguages(['en', 'de', 'pl', 'sk']), ['sk', 'pl', 'de', 'en']);
        assert.deepEqual(L.normalizeLanguages('pl'), []);
        assert.deepEqual(L.normalizeLanguages(undefined), []);
        assert.deepEqual(L.printLanguages({ languages: ['en', 'pl'] }), ['cs', 'pl', 'en']);
        const doc = normalizeDocument({ year: 2027, languages: ['en', 'bogus', 'pl'] }, ENV);
        assert.deepEqual(doc.design.languages, ['pl', 'en']);
        assert.deepEqual(normalizeDocument({ year: 2027 }, ENV).design.languages, []);
    });

    t.test('Czech only: month and week texts are exactly the old ones', () => {
        assert.equal(monthText('2027-01-04', '2027-01-10'), 'LEDEN 2027');
        assert.equal(monthText('2027-01-25', '2027-01-31'), 'LEDEN 2027');
        assert.equal(monthText('2027-03-29', '2027-04-04'), 'BŘEZEN / DUBEN 2027');
        assert.equal(monthText('2026-12-28', '2027-01-03'), 'PROSINEC 2026 / LEDEN 2027');
        assert.equal(monthText('2026-12-28', '2027-01-03', ['cs']), 'PROSINEC 2026 / LEDEN 2027');
        assert.equal(weekText('a', [3]), '3. týden');
        assert.equal(weekText('b', [3]), 'Týden 3');
        assert.equal(weekText('c', [3]), 'T3');
        assert.equal(weekText('a', [3, 4]), '3. a 4. týden');
    });

    t.test('extra languages: every month in all of them, a week across months on two lines', () => {
        const langs = ['cs', 'pl', 'en'];
        assert.equal(monthText('2027-01-04', '2027-01-10', langs), 'LEDEN / STYCZEŃ / JANUARY 2027');
        assert.equal(monthText('2027-03-29', '2027-04-04', langs), 'BŘEZEN / MARZEC / MARCH\nDUBEN / KWIECIEŃ / APRIL 2027');
        assert.equal(monthText('2026-12-28', '2027-01-03', langs), 'PROSINEC / GRUDZIEŃ / DECEMBER 2026\nLEDEN / STYCZEŃ / JANUARY 2027');
        assert.equal(monthText('2027-05-03', '2027-05-09', ['cs', 'sk', 'pl', 'de', 'en']), 'KVĚTEN / MÁJ / MAJ / MAI / MAY 2027');
        assert.equal(weekText('a', [3], langs), '3. týden / tydzień / week');
        assert.equal(weekText('b', [3], langs), 'Týden / Tydzień / Week 3');
        assert.equal(weekText('c', [3], langs), 'T3');
        assert.equal(weekText('a', [3], ['cs', 'de']), '3. týden / Woche');
    });

    t.test('buildPages and dayInfo use design.languages', async () => {
        const doc = await makeDoc(load, { languages: ['en', 'pl'] });
        const first = page(doc, 'w01');
        assert.equal(first.week.weekTexts[0], '1. týden / tydzień / week');
        assert.ok(first.week.monthTexts[0].startsWith('LEDEN / STYCZEŃ / JANUARY'));
        const info = dayInfo(doc, '2027-01-04');
        assert.equal(info.weekday, 'Pondělí / Poniedziałek / Monday');
        assert.equal(info.weekdayShort, 'Po / Pn / Mo');
        assert.equal(info.names, dayInfo(await makeDoc(load), '2027-01-04').names, 'name days stay Czech');
        assert.equal(dayInfo(await makeDoc(load), '2027-01-04').weekday, 'Pondělí');
    });

    t.test('monthLabel: lower case capitalises every month of every line', () => {
        assert.equal(monthLabel('PROSINEC / GRUDZIEŃ / DECEMBER 2026\nLEDEN / STYCZEŃ / JANUARY 2027', false), 'Prosinec / Grudzień / December 2026\nLeden / Styczeń / January 2027');
        assert.equal(monthLabel('PROSINEC 2026 / LEDEN 2027', false), 'Prosinec 2026 / Leden 2027');
    });

    t.test('monthLines: a line per month; with extra languages a too wide line breaks after „ / “, a Czech one never', async () => {
        const doc = await makeDoc(load);
        const spec = monthTextSpec(doc.design.layout.styles, [], 'ZÁŘÍ / WRZESIEŃ / SEPTEMBER 2027');
        const full = m.width(spec.text, spec.font);
        assert.deepEqual(monthLines(spec.text, spec.font, full + 1, m, true), [spec.text]);
        assert.deepEqual(monthLines(spec.text, spec.font, full - 1, m, true), ['ZÁŘÍ / WRZESIEŃ', 'SEPTEMBER 2027']);
        assert.deepEqual(monthLines(spec.text, spec.font, 10, m, false), [spec.text], 'no wrap without extra languages');
        assert.deepEqual(monthLines('A\nB', spec.font, 100, m, true), ['A', 'B']);
        assert.deepEqual(monthLines('VERYLONGWORD', spec.font, 1, m, true), ['VERYLONGWORD'], 'a single name is never broken');
    });

    t.test('placeMonthText: one line sits where it always did, several lines are a centred block', async () => {
        const doc = await makeDoc(load);
        const styles = doc.design.layout.styles;
        const box = { x: 10, y: 12.5, w: 120, h: 14.5 };
        const one = placeMonthText(styles, [], 'LEDEN 2027', box, 'end', m, false);
        assert.equal(one.lines.length, 1);
        assert.equal(one.lines[0].y, box.y + box.h / 2 + 0.36 * one.font.sizeMm);
        assert.equal(one.lines[0].x, box.x + box.w - one.pad);
        assert.equal(one.overflow, false);
        const two = placeMonthText(styles, [], 'PROSINEC / GRUDZIEŃ / DECEMBER 2026\nLEDEN / STYCZEŃ / JANUARY 2027', box, 'start', m, true);
        assert.equal(two.lines.length, 2);
        const lh = MONTH_LINE_HEIGHT * two.font.sizeMm;
        near(assert, two.lines[1].y - two.lines[0].y, lh, 'line pitch', 1e-9);
        near(assert, (two.lines[0].y + two.lines[1].y) / 2, box.y + box.h / 2 + 0.36 * two.font.sizeMm, 'block centred', 1e-9);
        assert.equal(two.overflow, false, 'two lines of 15 pt fit the default 14.5 mm frame');
        const tight = placeMonthText(styles, [], 'A\nB\nC', { ...box, h: 10 }, 'start', m, true);
        assert.equal(tight.overflow, true, 'a block taller than the frame is an overflow');
    });

    t.test('Názvy dnů: auto keeps the full names when they fit, takes the short ones when not, short is short', async () => {
        const day = (g) => g.frames.map((f) => f.dayFormat);
        const geo = (doc) => layoutWeekPage(doc, weekPages(buildPages(doc))[0], opts);
        const cs = await makeDoc(load, { styles: (l) => { l.styles.cal.dayFormat = 'auto'; } });
        assert.deepEqual(day(geo(cs)), ['full']);
        // three languages on a 14-day column with the moon icons: the full names do not fit
        const ml = (o = {}) => makeDoc(load, { type: 'fortnight', features: { moon: true }, languages: ['pl', 'en'], ...o });
        const auto = geo(await ml({ styles: (l) => { l.styles.cal.dayFormat = 'auto'; } }));
        assert.deepEqual(day(auto), ['short', 'short']);
        assert.ok(auto.frames.every((f) => !f.dayOverflow));
        const row = auto.frames[0].rows[0].items.find((i) => i.part === 'day');
        assert.equal(row.text, 'Po / Pn / Mo');
        const full = geo(await ml({ styles: (l) => { l.styles.cal.dayFormat = 'full'; } }));
        assert.deepEqual(day(full), ['full', 'full']);
        assert.ok(full.frames.every((f) => f.dayOverflow), 'full names that do not fit are drawn and flagged');
        assert.equal(full.frames[0].rows[0].items.find((i) => i.part === 'day').text, 'Pondělí / Poniedziałek / Monday');
        const short = geo(await makeDoc(load, { styles: (l) => { l.styles.cal.dayFormat = 'short'; } }));
        assert.deepEqual(day(short), ['short']);
        // „Nad číslem“: the label may widen the number column to half the row at most
        const above = geo(await ml({ styles: (l) => { l.styles.cal.dayFormat = 'auto'; l.styles.cal.dayPosition = 'above'; } }));
        assert.deepEqual(day(above), ['short', 'short']);
        const aboveFull = geo(await ml({ styles: (l) => { l.styles.cal.dayFormat = 'full'; l.styles.cal.dayPosition = 'above'; } }));
        assert.ok(aboveFull.frames.every((f) => f.dayOverflow));
    });

    t.test('a Czech-only calendar keeps its full names under auto, even where they overflow', async () => {
        const doc = await makeDoc(load, { type: 'fortnight', page: { width: 148, height: 105 }, features: { moon: true }, styles: (l) => { l.styles.cal.dayFormat = 'auto'; } });
        const g = layoutWeekPage(doc, weekPages(buildPages(doc))[0], opts);
        assert.deepEqual(g.frames.map((f) => f.dayFormat), ['full', 'full']);
    });

    t.test('new layouts default to „Automaticky“', async () => {
        const doc = await makeDoc(load);
        assert.equal(doc.design.layout.styles.cal.dayFormat, 'auto');
        const bad = normalizeDocument({ ...doc, design: { ...doc.design, layout: { ...doc.design.layout, styles: { ...doc.design.layout.styles, cal: { ...doc.design.layout.styles.cal, dayFormat: 'weird' } } } } }, ENV);
        assert.equal(bad.design.layout.styles.cal.dayFormat, 'auto');
    });

    t.test('autogrow, 14 dní: the Měsíc frames stay on their own half; a wider text wraps', async () => {
        const doc = grownDoc(await makeDoc(load, { type: 'fortnight', languages: ['sk', 'pl', 'de', 'en'] }));
        const { month, month2 } = doc.design.layout.boxes;
        assert.ok(month.x + month.w <= 155 - K.SECOND_FRAME_GAP + 1e-9, `month ends ${month.x + month.w}`);
        assert.ok(month2.x >= 155 + K.SECOND_FRAME_GAP - 1e-9, `month2 starts ${month2.x}`);
        assert.equal(month.x, 6, 'never shifted into the bleed');
        // five languages do not fit one line of the half: the cross-month text wraps
        const p = weekPages(buildPages(doc))[0];
        const placed = placeMonthText(doc.design.layout.styles, [], p.week.monthTexts[0], month, 'start', m, true);
        assert.ok(placed.lines.length > 2, `lines ${placed.lines.length}`);
        // … and the frame does not grow over the Kalendárium below it: the overflow is reported
        assert.ok(month.y + month.h <= doc.design.layout.boxes.cal.y + 1e-9);
        assert.equal(placed.overflow, true);
    });

    t.test('autogrow: two lines with a filled Měsíc grow the frame towards the page middle, up to the Kalendárium', async () => {
        const fill = (l) => {
            l.styles.month.bg.colorOn = true;
            l.boxes.month = { ...l.boxes.month, y: 5, h: 12 };
        };
        const doc = await makeDoc(load, { languages: ['pl', 'en'], styles: fill });
        const before = copy(doc.design.layout.boxes.month);
        const grown = grownDoc(doc).design.layout.boxes.month;
        assert.equal(grown.y, before.y, 'the top edge stays');
        assert.ok(grown.h > before.h, `h ${grown.h}`);
        const pad = doc.design.layout.styles.month.pad;
        const lh = MONTH_LINE_HEIGHT * monthTextSpec(doc.design.layout.styles, [], '').font.sizeMm;
        assert.ok(grown.h >= Math.min(2 * lh + 2 * pad, doc.design.layout.boxes.cal.y - before.y) - 0.1);
        assert.ok(grown.y + grown.h <= doc.design.layout.boxes.cal.y + 1e-9, 'stops at the Kalendárium');
        // Czech only: never a height change
        const cs = await makeDoc(load, { styles: fill });
        assert.equal(grownDoc(cs).design.layout.boxes.month.h, 12);
    });

    t.test('render: a week across months draws two Měsíc lines, a Czech one a single line', async () => {
        const count = (svg, f) => {
            const g = svg.match(new RegExp(`<g data-el="month" data-frame="${f}">([\\s\\S]*?)</g>`));
            return (g[1].match(/<text/g) || []).length;
        };
        const ml = await makeDoc(load, { languages: ['pl', 'en'] });
        const svgMl = renderPage(ml, page(ml, 'w13'), { ...opts, idPrefix: 'p' });
        assert.equal(count(svgMl, 0), 2, 'BŘEZEN … / DUBEN … ');
        assert.ok(svgMl.includes('KWIECIEŃ / APRIL 2027'));
        const cs = await makeDoc(load);
        assert.equal(count(renderPage(cs, page(cs, 'w13'), { ...opts, idPrefix: 'p' }), 0), 1);
    });
}
