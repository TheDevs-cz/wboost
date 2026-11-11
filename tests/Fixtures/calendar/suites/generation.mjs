// Handoff §9.1 G1–G12, G14 (pure parts): pages, dates, texts, holidays, name days, time changes.
import assert from 'node:assert/strict';
import { ENV, makeDoc } from '../helpers.mjs';

export default async function (t, load) {
    const dates = await load('generate/dates.js');
    const czech = await load('generate/czech.js');
    const pagesMod = await load('generate/pages.js');
    const time = await load('generate/time.js');
    const { createDayContext, dayInfo } = await load('generate/day.js');
    const { shiftContentYear } = await load('model/content.js');
    const { buildPages, weekPages } = pagesMod;

    const week = await makeDoc(load);
    const fortnight = await makeDoc(load, { type: 'fortnight' });
    const everyDay = (year) => {
        const out = [];
        for (let d = dates.iso(year, 1, 1); d <= dates.iso(year, 12, 31); d = dates.addDays(d, 1)) out.push(d);
        return out;
    };

    t.test('G1 55 pages for 7 dní: cover, 53 week pages, back', () => {
        const pages = buildPages(week);
        assert.equal(pages.length, 55);
        assert.equal(pages[0].kind, 'cover');
        assert.equal(pages[0].title, 'Titulní strana');
        assert.equal(pages[54].kind, 'back');
        assert.equal(pages[54].title, 'Zadní strana');
        assert.equal(pages[54].subtitle, 'Přehled roku');
        assert.equal(weekPages(pages).length, 53);
        assert.deepEqual(pages.map((p) => p.index), pages.map((_, i) => i));
    });

    t.test('G2 29 pages for 14 dní: cover, 27 week pages, back', () => {
        const pages = buildPages(fortnight);
        assert.equal(pages.length, 29);
        assert.equal(weekPages(pages).length, 27);
    });

    t.test('G3 first week page: 28 Dec 2026 – 3 Jan 2027, PROSINEC 2026 / LEDEN 2027, week 53', () => {
        const page = weekPages(buildPages(week))[0];
        assert.deepEqual(page.week.days, ['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03']);
        assert.equal(page.week.monthText, 'PROSINEC 2026 / LEDEN 2027');
        assert.deepEqual(page.week.weeks, [53]);
        assert.equal(page.week.weekText, '53. týden');
        assert.equal(page.title, '53. týden');
        assert.equal(page.subtitle, '28. 12. – 3. 1.');
        assert.equal(page.key, 'pre');
        assert.deepEqual(page.week.frames, [page.week.days]);
    });

    t.test('G4 second week page: 4–10 Jan 2027, LEDEN 2027, week 1', () => {
        const page = weekPages(buildPages(week))[1];
        assert.equal(page.week.days[0], '2027-01-04');
        assert.equal(page.week.days[6], '2027-01-10');
        assert.equal(page.week.monthText, 'LEDEN 2027');
        assert.deepEqual(page.week.weeks, [1]);
        assert.equal(page.key, 'w01');
    });

    t.test('G5 last week page: 27 Dec 2027 – 2 Jan 2028, PROSINEC 2027 / LEDEN 2028, week 52', () => {
        const all = weekPages(buildPages(week));
        const page = all[all.length - 1];
        assert.equal(page.week.days[0], '2027-12-27');
        assert.equal(page.week.days[6], '2028-01-02');
        assert.equal(page.week.monthText, 'PROSINEC 2027 / LEDEN 2028');
        assert.deepEqual(page.week.weeks, [52]);
        assert.equal(page.key, 'w52');
    });

    t.test('G6 first 14-day page: 28 Dec 2026 – 10 Jan 2027 in two frames, weeks 53 and 1', () => {
        const all = weekPages(buildPages(fortnight));
        const page = all[0];
        assert.equal(page.week.days.length, 14);
        assert.equal(page.week.days[0], '2026-12-28');
        assert.equal(page.week.days[13], '2027-01-10');
        assert.equal(page.week.frames.length, 2);
        assert.equal(page.week.frames[0][0], '2026-12-28');
        assert.equal(page.week.frames[0][6], '2027-01-03');
        assert.equal(page.week.frames[1][0], '2027-01-04');
        assert.equal(page.week.frames[1][6], '2027-01-10');
        assert.deepEqual(page.week.weeks, [53, 1]);
        assert.equal(page.week.weekText, '53. a 1. týden');
        assert.equal(page.title, '53. a 1. týden');
        // the last 14-day page runs past the first week of January
        const last = all[all.length - 1];
        assert.equal(last.week.days[0], '2027-12-27');
        assert.equal(last.week.days[13], '2028-01-09');
        assert.deepEqual(all.map((p) => p.key), ['pre', ...Array.from({ length: 26 }, (_, i) => `w${String(2 * i + 2).padStart(2, '0')}`)]);
    });

    t.test('month text within one year spanning two months', () => {
        assert.equal(pagesMod.monthText('2027-01-25', '2027-01-31'), 'LEDEN 2027');
        assert.equal(pagesMod.monthText('2027-01-25', '2027-02-07'), 'LEDEN / ÚNOR 2027');
        assert.equal(pagesMod.monthText('2026-12-28', '2027-01-03'), 'PROSINEC 2026 / LEDEN 2027');
        assert.equal(pagesMod.monthText('2027-08-30', '2027-09-05'), 'SRPEN / ZÁŘÍ 2027');
    });

    t.test('G7 public holidays 2027: exactly the thirteen days, with their names', () => {
        const expected = {
            '2027-01-01': 'Nový rok / Den obnovy samostatného českého státu',
            '2027-03-26': 'Velký pátek',
            '2027-03-29': 'Velikonoční pondělí',
            '2027-05-01': 'Svátek práce',
            '2027-05-08': 'Den vítězství',
            '2027-07-05': 'Den slovanských věrozvěstů Cyrila a Metoděje',
            '2027-07-06': 'Den upálení mistra Jana Husa',
            '2027-09-28': 'Den české státnosti',
            '2027-10-28': 'Den vzniku samostatného československého státu',
            '2027-11-17': 'Den boje za svobodu a demokracii',
            '2027-12-24': 'Štědrý den',
            '2027-12-25': '1. svátek vánoční',
            '2027-12-26': '2. svátek vánoční',
        };
        const found = {};
        for (const d of everyDay(2027)) {
            const name = czech.holidayName(d);
            if (name) found[d] = name;
        }
        assert.deepEqual(found, expected);
        const ctx = createDayContext(week);
        for (const d of everyDay(2027)) {
            const info = dayInfo(week, d, ctx);
            assert.equal(info.numberKind === 'numHol', Boolean(expected[d]), d);
            assert.equal(info.holiday, expected[d] || '', d);
        }
    });

    t.test('G8 Easter in other years: 2026 (3 and 6 April), 2028 (14 and 17 April)', () => {
        assert.equal(dates.easterSunday(2026), '2026-04-05');
        assert.equal(dates.easterSunday(2027), '2027-03-28');
        assert.equal(dates.easterSunday(2028), '2028-04-16');
        assert.equal(czech.holidayName('2026-04-03'), 'Velký pátek');
        assert.equal(czech.holidayName('2026-04-06'), 'Velikonoční pondělí');
        assert.equal(czech.holidayName('2028-04-14'), 'Velký pátek');
        assert.equal(czech.holidayName('2028-04-17'), 'Velikonoční pondělí');
        assert.equal(czech.holidayName('2028-04-16'), '');
        // long-known Easter Sundays
        const known = { 2000: '2000-04-23', 2008: '2008-03-23', 2011: '2011-04-24', 2019: '2019-04-21', 2024: '2024-03-31', 2025: '2025-04-20', 2030: '2030-04-21', 2038: '2038-04-25' };
        for (const [y, d] of Object.entries(known)) assert.equal(dates.easterSunday(Number(y)), d);
    });

    t.test('name-day table is complete: 366 days, only the eight known gaps', () => {
        assert.equal(czech.NAME_DAYS.length, 12);
        assert.deepEqual(czech.NAME_DAYS.map((m) => m.length), [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]);
        const gaps = [];
        czech.NAME_DAYS.forEach((month, m) => month.forEach((name, d) => {
            assert.equal(typeof name, 'string');
            assert.equal(name, name.trim());
            if (name === '') gaps.push(`${m + 1}-${d + 1}`);
        }));
        assert.deepEqual(gaps, ['1-1', '5-1', '5-8', '7-5', '7-6', '10-28', '11-17', '12-25']);
        const spot = { '1-2': 'Karina', '2-14': 'Valentýn', '2-29': 'Horymír', '3-19': 'Josef', '4-24': 'Jiří', '6-24': 'Jan', '6-29': 'Petr / Pavel', '7-26': 'Anna', '8-15': 'Hana', '9-28': 'Václav', '11-11': 'Martin', '12-6': 'Mikuláš', '12-24': 'Adam / Eva', '12-31': 'Silvestr' };
        for (const [k, v] of Object.entries(spot)) {
            const [m, d] = k.split('-').map(Number);
            assert.equal(czech.nameDay(m, d), v);
        }
    });

    t.test('G9 name days: 2 January prints Karina, with the extra name Karina / Vasil', async () => {
        assert.equal(dayInfo(week, '2027-01-02').names, 'Karina');
        const doc = await makeDoc(load, { content: { names: { n2: { date: '2027-01-02', name: 'Vasil' }, n1: { date: '2027-01-02', name: '  ' }, n3: { date: '2027-01-01', name: 'Mečislav' } } } });
        assert.equal(dayInfo(doc, '2027-01-02').names, 'Karina / Vasil');
        assert.equal(dayInfo(doc, '2027-01-01').names, 'Mečislav');
        assert.equal(dayInfo(week, '2027-01-01').names, '');
    });

    t.test('G10 number style priority: holiday > Sunday > Saturday > workday', () => {
        assert.equal(dayInfo(week, '2027-11-17').numberKind, 'numHol'); // Wednesday holiday
        assert.equal(dayInfo(week, '2027-05-01').numberKind, 'numHol'); // Saturday holiday
        assert.equal(dayInfo(week, '2027-12-26').numberKind, 'numHol'); // Sunday holiday
        assert.equal(dayInfo(week, '2027-01-02').numberKind, 'numSat');
        assert.equal(dayInfo(week, '2027-01-03').numberKind, 'numSun');
        assert.equal(dayInfo(week, '2027-01-04').numberKind, 'num');
        assert.equal(dayInfo(week, '2027-01-04').weekday, 'Pondělí');
        assert.equal(dayInfo(week, '2027-01-03').weekdayShort, 'Ne');
    });

    t.test('G11 time change 2027: exactly 28 March and 31 October', () => {
        const found = everyDay(2027).filter((d) => time.dstChange(d) !== null);
        assert.deepEqual(found, ['2027-03-28', '2027-10-31']);
        assert.equal(time.dstChange('2027-03-28'), 'summer');
        assert.equal(time.dstChange('2027-10-31'), 'winter');
        assert.equal(time.dstChange('2026-03-29'), 'summer');
        assert.equal(time.dstChange('2026-10-25'), 'winter');
        assert.equal(time.dstChange('2028-03-26'), 'summer');
        assert.equal(time.dstChange('2028-10-29'), 'winter');
        const f = { timeChange: true, seasons: true };
        assert.deepEqual(time.timeTexts('2027-03-28', f), ['Začátek letního času']);
        assert.deepEqual(time.timeTexts('2027-10-31', f), ['Začátek zimního času']);
        assert.deepEqual(time.timeTexts('2027-03-28', { timeChange: false, seasons: true }), []);
        assert.deepEqual(time.timeTexts('2027-03-20', f), ['Začátek jara']);
        assert.deepEqual(time.timeTexts('2027-06-21', f), ['Začátek léta']);
        assert.deepEqual(time.timeTexts('2027-09-22', f), ['Začátek podzimu']);
        assert.deepEqual(time.timeTexts('2027-12-21', f), ['Začátek zimy']);
        assert.deepEqual(time.timeTexts('2027-12-21', { timeChange: true, seasons: false }), []);
        assert.deepEqual([time.seasonStart('2027-03-20'), time.seasonStart('2027-06-21'), time.seasonStart('2027-09-22'), time.seasonStart('2027-12-21'), time.seasonStart('2027-12-22')], [0, 1, 2, 3, null]);
    });

    t.test('G12 week number forms change every week page', async () => {
        const texts = {};
        for (const form of ['a', 'b', 'c']) {
            const doc = await makeDoc(load, { styles: (l) => { l.styles.wk.form = form; } });
            const pages = weekPages(buildPages(doc));
            texts[form] = pages.map((p) => p.week.weekText);
            assert.equal(new Set(pages.map((p) => p.title)).size, 53, 'titles stay in form a');
        }
        assert.equal(texts.a[1], '1. týden');
        assert.equal(texts.b[1], 'Týden 1');
        assert.equal(texts.c[1], 'T1');
        assert.equal(texts.a[0], '53. týden');
        assert.equal(texts.b[52], 'Týden 52');
        assert.equal(pagesMod.weekText('a', [53, 1]), '53. a 1. týden');
        assert.equal(pagesMod.weekText('b', [53, 1]), 'Týdny 53–1');
        assert.equal(pagesMod.weekText('c', [2, 3]), 'T2–3');
    });

    t.test('ISO weeks agree with an independent ordinal-based formula for 1990–2060', () => {
        const ordinal = (d) => dates.diffDays(dates.iso(dates.parts(d).y, 1, 1), d) + 1;
        const weeksIn = (y) => {
            const p = (yy) => (yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400)) % 7;
            return p(y) === 4 || p(y - 1) === 3 ? 53 : 52;
        };
        for (let d = '1990-01-01'; d <= '2060-12-31'; d = dates.addDays(d, 1)) {
            const y = dates.parts(d).y;
            let w = Math.floor((ordinal(d) - (dates.weekday(d) + 1) + 10) / 7);
            let wy = y;
            if (w < 1) {
                wy = y - 1;
                w = weeksIn(wy);
            } else if (w > weeksIn(y)) {
                wy = y + 1;
                w = 1;
            }
            const got = dates.isoWeek(d);
            if (got.week !== w || got.year !== wy) assert.fail(`${d}: expected ${wy}-W${w}, got ${got.year}-W${got.week}`);
        }
        assert.deepEqual(dates.isoWeek('2026-12-28'), { year: 2026, week: 53 });
        assert.deepEqual(dates.isoWeek('2027-01-04'), { year: 2027, week: 1 });
        assert.deepEqual(dates.isoWeek('2028-01-01'), { year: 2027, week: 52 });
        assert.deepEqual(dates.isoWeek('2024-12-30'), { year: 2025, week: 1 });
        assert.equal(dates.weekday('2027-01-01'), 4);
        assert.equal(dates.mondayOf('2027-01-01'), '2026-12-28');
    });

    t.test('date helpers: validity, arithmetic, month lengths', () => {
        assert.equal(dates.isValid('2027-02-29'), false);
        assert.equal(dates.isValid('2028-02-29'), true);
        assert.equal(dates.isValid('2027-13-01'), false);
        assert.equal(dates.isValid('2027-1-1'), false);
        assert.equal(dates.addDays('2027-12-31', 1), '2028-01-01');
        assert.equal(dates.addDays('2028-03-01', -1), '2028-02-29');
        assert.equal(dates.diffDays('2027-01-01', '2028-01-01'), 365);
        assert.equal(dates.daysInMonth(2028, 2), 29);
        assert.equal(dates.compare('2027-01-01', '2027-01-02'), -1);
        assert.deepEqual(dates.parts('2027-03-09'), { y: 2027, m: 3, d: 9 });
    });

    t.test('page keys: pre / post / week numbers, independent of type', async () => {
        const y2024 = await makeDoc(load, { year: 2024, name: 'x' });
        const keys = weekPages(buildPages(y2024)).map((p) => p.key);
        assert.equal(keys[0], 'w01'); // 1 January 2024 is a Monday
        assert.equal(keys[keys.length - 1], 'post'); // 30 December 2024 is week 1 of 2025
        assert.equal(keys.length, 53);
        assert.equal(new Set(keys).size, keys.length);
        const keys2027 = weekPages(buildPages(week)).map((p) => p.key);
        assert.equal(keys2027[0], 'pre');
        assert.equal(keys2027[1], 'w01');
        assert.equal(keys2027[52], 'w52');
        assert.equal(pagesMod.pageKeyOfWeek('2026-12-28', 2027), 'pre');
        assert.equal(pagesMod.pageKeyOfWeek('2024-12-30', 2024), 'post');
        const pages = buildPages(week);
        assert.equal(pages[0].key, 'cover');
        assert.equal(pages[54].key, 'back');
    });

    t.test('special pages: order, position, back page variant', async () => {
        const content = {
            specialPages: {
                year: { kind: 'year', on: true, layout: 0, position: 'back', nextYear: true, name: '', asset: null, text: null, fileName: null, pos: 2 },
                intro: { kind: 'intro', on: true, layout: 1, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 1 },
                Own1Own1: { kind: 'image', on: true, layout: 0, position: 'front', nextYear: false, name: 'Mapa obce', asset: null, text: null, fileName: 'mapa.png', pos: 0 },
                contacts: { kind: 'contacts', on: false, layout: 0, position: 'front', nextYear: false, name: '', asset: null, text: null, fileName: null, pos: 3 },
            },
        };
        const on = await makeDoc(load, { features: { specialPages: true }, content });
        const pages = buildPages(on);
        assert.equal(pages.length, 58);
        assert.deepEqual(pages.slice(0, 4).map((p) => p.key), ['cover', 'sp:Own1Own1', 'sp:intro', 'pre']);
        assert.equal(pages[1].title, 'Mapa obce');
        assert.equal(pages[1].subtitle, 'Vlastní strana');
        assert.equal(pages[2].title, 'Úvodní slovo');
        assert.equal(pages[2].subtitle, 'Speciální strana');
        assert.equal(pages[56].key, 'sp:year');
        assert.equal(pages[56].title, 'Roční kalendárium');
        assert.equal(pages[57].backVariant, 'contacts');
        assert.equal(pages[57].subtitle, 'Kontakty klienta');
        assert.equal(pagesMod.pageIndexOfDate(pages, '2027-01-05'), 4);
        assert.equal(pagesMod.pageIndexOfDate(pages, '2029-01-05'), -1);
        // the feature switch alone adds nothing; with the feature off stored pages are not listed
        const off = await makeDoc(load, { features: { specialPages: false }, content });
        assert.equal(buildPages(off).length, 55);
        const empty = await makeDoc(load, { features: { specialPages: true } });
        assert.equal(buildPages(empty).length, 55);
    });

    t.test('sample page: first week page, or the first with a time text while Změna času is selected', async () => {
        const doc = await makeDoc(load, { features: { timeChange: true, seasons: false } });
        const pages = buildPages(doc);
        assert.equal(pagesMod.samplePage(doc, pages, null).key, 'pre');
        assert.equal(pagesMod.samplePage(doc, pages, { el: 'cal', part: 'num' }).key, 'pre');
        const sample = pagesMod.samplePage(doc, pages, { el: 'cal', part: 'time' });
        assert.ok(sample.week.days.includes('2027-03-28'));
        assert.equal(pagesMod.samplePage(week, buildPages(week), { el: 'cal', part: 'time' }).key, 'pre');
        assert.equal(pagesMod.pageRange(sample), '22. 3. – 28. 3.');
        assert.equal(pagesMod.pageRange(pages[0]), '');
        assert.equal(pagesMod.pageTitle(pages[0]), 'Titulní strana');
    });

    t.test('G14 year change 2027 → 2028 regenerates the pages and moves dated content', async () => {
        const content = {
            events: { e1: { date: '2027-06-01', name: 'Den dětí', club: null, icon: null }, e0: { date: '2026-12-31', name: 'Silvestr', club: null, icon: null } },
            names: { n1: { date: '2027-01-02', name: 'Vasil' } },
            wasteExtras: { x1: { kind: 'bio', date: '2027-04-08' } },
            wasteKinds: { bio: { builtin: 'bio', name: 'Bio odpad', on: true, place: '', mode: 'dates', weekdays: [3], interval: 'w', from: '2027-04-01', to: '2027-11-30', dates: ['2027-04-01', '2027-04-15'], pos: 0 } },
            comments: { c1: { date: '2027-02-10', text: 'ok', author: 'client', at: '2026-11-11T10:00:00+00:00' } },
            moonEdits: { m1: { op: 'add', date: '2027-01-05', phase: 2 } },
            pagePhotos: { w05: { asset: 'a1', zoom: 1, px: 0.5, py: 0.5 } },
        };
        const doc = await makeDoc(load, { content });
        const { ops, dropped } = shiftContentYear(doc.content, 2027, 2028);
        assert.equal(dropped, 0);
        const by = Object.fromEntries(ops.map((o) => [`${o.c}/${o.id}`, o.v]));
        assert.equal(by['events/e1'].date, '2028-06-01');
        assert.equal(by['events/e0'].date, '2027-12-31');
        assert.equal(by['names/n1'].date, '2028-01-02');
        assert.equal(by['wasteExtras/x1'].date, '2028-04-08');
        assert.deepEqual(by['wasteKinds/bio'].dates, ['2028-04-01', '2028-04-15']);
        assert.equal(by['wasteKinds/bio'].from, '2028-04-01');
        assert.equal(by['wasteKinds/bio'].to, '2028-11-30');
        assert.equal(by['comments/c1'].date, '2028-02-10');
        assert.equal(by['moonEdits/m1'], null);
        assert.ok(!ops.some((o) => o.c === 'pagePhotos'), 'page overrides are untouched');
        const doc2028 = await makeDoc(load, { year: 2028 });
        const pages = weekPages(buildPages(doc2028));
        assert.equal(pages[0].week.days[0], '2027-12-27');
        assert.equal(pages[pages.length - 1].week.days[6], '2028-12-31');
        assert.equal(pages.length, 53);
    });

    t.test('year change away from a leap year: 29 February items are dropped, bounds move to 28 February', async () => {
        const content = {
            events: { e1: { date: '2028-02-29', name: 'Přestupný den', club: null, icon: null }, e2: { date: '2028-03-01', name: 'x', club: null, icon: null } },
            names: { n1: { date: '2028-02-29', name: 'Horymír ml.' } },
            wasteExtras: { x1: { kind: 'bio', date: '2028-02-29' } },
            wasteKinds: { bio: { builtin: 'bio', name: 'Bio odpad', on: true, place: '', mode: 'dates', weekdays: [3], interval: 'w', from: '2028-02-29', to: '2028-02-29', dates: ['2028-02-29', '2028-03-07'], pos: 0 } },
            comments: { c1: { date: '2028-02-29', text: 'ok', author: 'client', at: 'x' } },
        };
        const doc = await makeDoc(load, { year: 2028, content });
        const { ops, dropped } = shiftContentYear(doc.content, 2028, 2027);
        assert.equal(dropped, 4);
        const by = Object.fromEntries(ops.map((o) => [`${o.c}/${o.id}`, o.v]));
        assert.equal(by['events/e1'], null);
        assert.equal(by['events/e2'].date, '2027-03-01');
        assert.equal(by['names/n1'], null);
        assert.equal(by['wasteExtras/x1'], null);
        assert.deepEqual(by['wasteKinds/bio'].dates, ['2027-03-07']);
        assert.equal(by['wasteKinds/bio'].from, '2027-02-28');
        assert.equal(by['wasteKinds/bio'].to, '2027-02-28');
        assert.equal(by['comments/c1'].date, '2027-02-28');
        // arguments are not mutated
        assert.equal(doc.content.events.e1.date, '2028-02-29');
    });

    t.test('events of a day: club code, empty names skipped, at most four', async () => {
        const events = {};
        ['a', 'b', 'c', 'd', 'e'].forEach((id, i) => { events[`ev${id}`] = { date: '2027-06-01', name: `Akce ${i + 1}`, club: i === 0 ? 'zs' : i === 1 ? 'gone' : null, icon: null }; });
        events.blank = { date: '2027-06-02', name: '   ', club: null, icon: null };
        const content = { events, clubs: { zs: { code: 'ZŠ', name: 'Základní škola', pos: 0 } } };
        const doc = await makeDoc(load, { features: { events: true }, content });
        const info = dayInfo(doc, '2027-06-01');
        assert.deepEqual(info.events.map((e) => e.text), ['ZŠ · Akce 1', 'Akce 2', 'Akce 3', 'Akce 4']);
        assert.equal(info.eventsDropped, 1);
        assert.deepEqual(dayInfo(doc, '2027-06-02').events, []);
        const off = await makeDoc(load, { features: { events: false }, content });
        assert.deepEqual(dayInfo(off, '2027-06-01').events, []);
    });

    t.test('plural helpers and vocabulary', () => {
        assert.deepEqual([1, 2, 4, 5, 55].map(czech.pluralPages), ['strana', 'strany', 'strany', 'stran', 'stran']);
        assert.deepEqual([1, 3, 5].map(czech.pluralTerms), ['termín', 'termíny', 'termínů']);
        assert.equal(czech.MONTHS.length, 12);
        assert.deepEqual(czech.WEEKDAY_INITIALS, ['P', 'Ú', 'S', 'Č', 'P', 'S', 'N']);
        assert.equal(czech.WEEK_RULES.length, 6);
        assert.equal(ENV.currentYear, 2026);
    });
}
