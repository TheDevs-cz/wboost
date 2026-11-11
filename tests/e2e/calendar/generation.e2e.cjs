/*
 * Generation in the running application (handoff §9.1 G1–G8, G10, G11; phase P8a).
 *
 * The pure rules are pinned by the node suite `generation`; this script proves the same checks in
 * the browser: a calendar is created through the UI, the page list is read from the app
 * (`__calendar.pages()`), the texts from what the app lays out (`__calendar.geometry(i)` — the very
 * items the renderer serialises), and for the named pages from the SVG that is on the screen in
 * step 5 (month text, week text, fills of the day numbers, the printed holiday name).
 *
 * G9 lives in content-tools, G12 in panels, G13 in content-pages, G14 in shell.
 */
const assert = require('assert/strict');
const ed = require('./lib/editor.cjs');

const HOLIDAYS_2027 = {
    '2027-01-01': /Nový rok/,
    '2027-03-26': /Velký pátek/,
    '2027-03-29': /Velikonoční pondělí/,
    '2027-05-01': /Svátek práce/,
    '2027-05-08': /Den vítězství/,
    '2027-07-05': /Cyrila a Metoděje/,
    '2027-07-06': /Jana Husa/,
    '2027-09-28': /Den české státnosti/,
    '2027-10-28': /československého státu/,
    '2027-11-17': /Den boje za svobodu a demokracii/,
    '2027-12-24': /Štědrý den/,
    '2027-12-25': /1\. svátek vánoční/,
    '2027-12-26': /2\. svátek vánoční/,
};

const weekPages = (page, h) => h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => ({ index: p.index, key: p.key, title: p.title, first: p.week.frames[0][0], last: p.week.frames[p.week.frames.length - 1].slice(-1)[0], monthText: p.week.monthTexts[0], weekText: p.week.weekTexts[0], monthTexts: p.week.monthTexts, weekTexts: p.week.weekTexts, weeks: p.week.weeks, frames: p.week.frames })));

/** Every day row the app lays out: `{ date, kind (number style), holiday: joined 'hol' lines, numFill, pageIndex }`. */
const allRows = (page, h) =>
    h.cal(page, (c) => {
        const out = [];
        for (const p of c.pages()) {
            if (p.kind !== 'week') continue;
            for (const frame of c.geometry(p.index).frames) {
                for (const row of frame.rows) {
                    const num = row.items.find((i) => ['num', 'numSat', 'numSun', 'numHol'].includes(i.part));
                    out.push({ date: row.date, pageIndex: p.index, kind: num.part, numFill: num.fill, numText: num.text, holiday: row.items.filter((i) => i.part === 'hol').map((i) => i.text).join(' '), time: row.info.timeTexts, icons: row.info.icons.map((i) => i.category) });
                }
            }
        }
        return out;
    });

async function showPage(page, h, index) {
    await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.click());
    await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`).getAttribute('aria-current') === 'true', {}, index);
    await h.sleep(200);
}

/** What the SVG on the step-5 stage prints. */
const drawn = (page) =>
    page.evaluate(() => {
        const svg = document.querySelector('.cal-5w .cal-page-svg svg');
        const texts = (sel) => [...svg.querySelectorAll(sel)].map((n) => n.textContent);
        return {
            key: svg.dataset.page,
            month: texts('[data-el="month"] text').join(' '),
            wk: texts('[data-el="wk"] text').join(' '),
            // per week column (frame 0 = first week), with the centre of the text on the screen
            months: [...svg.querySelectorAll('[data-el="month"]')].map((g) => ({ frame: Number(g.dataset.frame), text: g.querySelector('text').textContent, cx: g.querySelector('text').getBoundingClientRect().left + g.querySelector('text').getBoundingClientRect().width / 2 })),
            wks: [...svg.querySelectorAll('[data-el="wk"]')].map((g) => ({ frame: Number(g.dataset.frame), text: g.querySelector('text').textContent, cx: g.querySelector('text').getBoundingClientRect().left + g.querySelector('text').getBoundingClientRect().width / 2 })),
            frames: [...svg.querySelectorAll('[data-el="cal"]')].map((g) => ({
                frame: Number(g.dataset.frame),
                x: g.getBoundingClientRect().left,
                days: [...g.querySelectorAll('[data-date]')].map((row) => {
                    const num = row.querySelector('[data-part="num"], [data-part="numSat"], [data-part="numSun"], [data-part="numHol"]');
                    return { date: row.dataset.date, num: num.textContent, kind: num.dataset.part, fill: num.getAttribute('fill'), hol: [...row.querySelectorAll('[data-part="hol"]')].map((n) => n.textContent).join(' ') };
                }),
            })),
        };
    });

const weekdayOf = (date) => (new Date(`${date}T00:00:00Z`).getUTCDay() + 6) % 7; // 0 = Monday

module.exports = {
    name: 'generation',
    checks: ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G10', 'G11'],
    async run({ page, h, check }) {
        let week = null;
        let fort = null;

        await check('setup: a 7-day calendar for 2027 with a layout (time changes on)', async () => {
            ed.leaveFreely(page);
            const id = await h.createCalendar(page);
            if ((await h.state(page)).design.year !== 2027) await page.click('[data-year="2027"]');
            assert.equal((await h.state(page)).design.year, 2027, 'the year buttons offer 2027');
            await page.click('label:has([data-feature="timeChange"])');
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]');
            await h.gotoStep(page, 5);
            await h.save(page);
            week = id;
        });
        if (!week) return;

        await check('G1 7 dní without special pages: 55 pages — cover, 53 week pages, back; the type card and the thumbnails say so', async () => {
            const pages = await h.cal(page, (c) => c.pages().map((p) => p.kind));
            assert.equal(pages.length, 55);
            assert.deepEqual([pages[0], pages[54], pages.filter((k) => k === 'week').length], ['cover', 'back', 53]);
            assert.equal(await page.$$eval('[data-page-thumb]', (n) => n.length), 55, 'one thumbnail per page');
            await h.gotoStep(page, 1);
            assert.match(await page.$eval('[data-type="week"]', (el) => el.textContent), /55 stran v roce 2027/);
            assert.match(await page.$eval('[data-type="fortnight"]', (el) => el.textContent), /29 stran v roce 2027/);
            await h.gotoStep(page, 5);
        });

        await check('G3 first week page: Mon 28 Dec 2026 – Sun 3 Jan 2027, PROSINEC 2026 / LEDEN 2027, week 53 — on the drawn page', async () => {
            const [first] = await weekPages(page, h);
            assert.deepEqual([first.first, first.last, first.monthText, first.weeks], ['2026-12-28', '2027-01-03', 'PROSINEC 2026 / LEDEN 2027', [53]]);
            await showPage(page, h, first.index);
            const d = await drawn(page);
            assert.equal(d.month, 'PROSINEC 2026 / LEDEN 2027');
            assert.equal(d.wk, '53. týden');
            assert.deepEqual(d.frames[0].days.map((x) => x.date), ['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03']);
            assert.deepEqual(d.frames[0].days.map((x) => x.num), ['28', '29', '30', '31', '1', '2', '3']);
            assert.equal(weekdayOf('2026-12-28'), 0, 'a Monday');
        });

        await check('G4 second week page: 4–10 January 2027, LEDEN 2027, week 1', async () => {
            const second = (await weekPages(page, h))[1];
            assert.deepEqual([second.first, second.last, second.monthText, second.weeks], ['2027-01-04', '2027-01-10', 'LEDEN 2027', [1]]);
            await showPage(page, h, second.index);
            const d = await drawn(page);
            assert.deepEqual([d.month, d.wk], ['LEDEN 2027', '1. týden']);
            assert.deepEqual(d.frames[0].days.map((x) => x.num), ['4', '5', '6', '7', '8', '9', '10']);
        });

        await check('G5 last week page: 27 Dec 2027 – 2 Jan 2028, PROSINEC 2027 / LEDEN 2028, week 52', async () => {
            const list = await weekPages(page, h);
            const last = list[list.length - 1];
            assert.deepEqual([last.first, last.last, last.monthText, last.weeks], ['2027-12-27', '2028-01-02', 'PROSINEC 2027 / LEDEN 2028', [52]]);
            await showPage(page, h, last.index);
            const d = await drawn(page);
            assert.deepEqual([d.month, d.wk], ['PROSINEC 2027 / LEDEN 2028', '52. týden']);
            assert.deepEqual(d.frames[0].days.map((x) => x.num), ['27', '28', '29', '30', '31', '1', '2']);
        });

        let rows = [];
        await check('G7 public holidays 2027: exactly the thirteen days are styled as holidays and print their name; no other day does', async () => {
            rows = await allRows(page, h);
            assert.equal(rows.length, 53 * 7);
            const styled = rows.filter((r) => r.kind === 'numHol').map((r) => r.date).sort();
            // days of the neighbouring years on the first / last page are outside the check's year
            assert.deepEqual(styled.filter((d) => d.startsWith('2027-')), Object.keys(HOLIDAYS_2027).sort());
            assert.deepEqual(styled.filter((d) => !d.startsWith('2027-')), ['2028-01-01'], 'the last page reaches 1 January 2028');
            for (const r of rows) {
                if (HOLIDAYS_2027[r.date]) assert.match(r.holiday, HOLIDAYS_2027[r.date], r.date);
                else if (r.date !== '2028-01-01') assert.equal(r.holiday, '', `${r.date} prints no holiday name`);
            }
            // … and on the drawn page of 17 November
            const row = rows.find((r) => r.date === '2027-11-17');
            await showPage(page, h, row.pageIndex);
            const day = (await drawn(page)).frames[0].days.find((x) => x.date === '2027-11-17');
            assert.equal(day.kind, 'numHol');
            assert.match(day.hol, /Den boje za svobodu a demokracii/);
            const styles = (await h.state(page)).design.layout.styles;
            assert.equal(day.fill, styles.numHol.color);
        });

        await check('G10 number style priority: a holiday on a weekday uses the holiday style; Saturday and Sunday their own', async () => {
            const styles = (await h.state(page)).design.layout.styles;
            for (const r of rows) {
                const wd = weekdayOf(r.date);
                const holiday = Boolean(HOLIDAYS_2027[r.date]) || r.date === '2028-01-01';
                const want = holiday ? 'numHol' : wd === 5 ? 'numSat' : wd === 6 ? 'numSun' : 'num';
                assert.equal(r.kind, want, r.date);
                assert.equal(r.numFill, styles[want].color, `${r.date} is drawn in the colour of ${want}`);
            }
            // 17 November 2027 is a Wednesday, 1 May a Saturday and 26 December a Sunday: the holiday wins on all three
            assert.deepEqual([weekdayOf('2027-11-17'), weekdayOf('2027-05-01'), weekdayOf('2027-12-26')], [2, 5, 6]);
            // the four styles are told apart on the drawn page: Mon 15 – Sun 21 November
            const d = (await drawn(page)).frames[0].days;
            assert.deepEqual(d.map((x) => x.kind), ['num', 'num', 'numHol', 'num', 'num', 'numSat', 'numSun']);
            assert.equal(new Set([d[0].fill, d[2].fill, d[5].fill]).size, 3, 'workday, holiday and Saturday numbers differ in colour by default');
        });

        await check('G11 time change (on): marked on 28 March 2027 and 31 October 2027 only', async () => {
            const marked = rows.filter((r) => r.time.length > 0 || r.icons.includes('time')).map((r) => r.date);
            assert.deepEqual(marked, ['2027-03-28', '2027-10-31']);
            const row = rows.find((r) => r.date === '2027-03-28');
            await showPage(page, h, row.pageIndex);
            const svg = await page.$eval('.cal-5w .cal-page-svg svg [data-date="2027-03-28"]', (el) => el.outerHTML);
            assert.match(svg, /data-part="(time|icon)"/, 'the day row of 28 March carries the time-change mark');
            const plain = await page.$eval('.cal-5w .cal-page-svg svg [data-date="2027-03-27"]', (el) => el.outerHTML);
            assert.doesNotMatch(plain, /data-part="time"/);
        });

        await check('G8 Easter in other years: Good Friday / Easter Monday 3 and 6 April 2026, 14 and 17 April 2028', async () => {
            await h.gotoStep(page, 1);
            for (const [year, friday, monday] of [[2026, '2026-04-03', '2026-04-06'], [2028, '2028-04-14', '2028-04-17']]) {
                assert.ok(await page.$(`[data-year="${year}"]`), `step 1 offers ${year}`);
                await page.click(`[data-year="${year}"]`);
                assert.equal((await h.state(page)).design.year, year);
                const list = await allRows(page, h);
                const easter = list.filter((r) => /Velký pátek|Velikonoční pondělí/.test(r.holiday)).map((r) => `${r.date} ${r.holiday} ${r.kind}`);
                assert.deepEqual(easter, [`${friday} Velký pátek numHol`, `${monday} Velikonoční pondělí numHol`]);
            }
            await page.click('[data-year="2027"]');
            assert.equal((await h.state(page)).design.year, 2027);
            await h.save(page);
        });

        await check('G2 + G6 14 dní: 29 pages (cover, 27 week pages, back); the first page is 28 Dec 2026 – 10 Jan 2027, first week left, second right; month and week number once per column, as on the printed samples', async () => {
            const made = await ed.newCalendar(page, h, { type: 'fortnight' });
            fort = made.id;
            if ((await h.state(page)).design.year !== 2027) {
                await h.gotoStep(page, 1);
                await page.click('[data-year="2027"]');
            }
            await h.gotoStep(page, 5);
            await h.save(page);
            const kinds = await h.cal(page, (c) => c.pages().map((p) => p.kind));
            assert.equal(kinds.length, 29);
            assert.deepEqual([kinds[0], kinds[28], kinds.filter((k) => k === 'week').length], ['cover', 'back', 27]);
            assert.equal(await page.$$eval('[data-page-thumb]', (n) => n.length), 29);
            const [first] = await weekPages(page, h);
            assert.deepEqual([first.first, first.last, first.weeks], ['2026-12-28', '2027-01-10', [53, 1]]);
            await showPage(page, h, first.index);
            const d = await drawn(page);
            assert.equal(d.frames.length, 2);
            const [a, b] = d.frames.sort((x, y) => x.frame - y.frame);
            assert.deepEqual(a.days.map((x) => x.date), ['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03'], 'first week');
            assert.deepEqual(b.days.map((x) => x.date), ['2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08', '2027-01-09', '2027-01-10'], 'second week');
            assert.ok(a.x < b.x, 'the first week is drawn on the left, the second on the right');
            const photo = await page.$eval('.cal-5w .cal-page-svg svg [data-el="photo"]', (el) => el.getBoundingClientRect().left);
            assert.ok(a.x < photo && photo < b.x, 'the photo sits between the two weeks');
            // each column prints ITS OWN month(s) and week number: the first week (28 Dec – 3 Jan) spans
            // two months and years, the second (4 – 10 Jan) does not
            assert.deepEqual([first.monthTexts, first.weekTexts], [['PROSINEC 2026 / LEDEN 2027', 'LEDEN 2027'], ['53. týden', '1. týden']]);
            assert.deepEqual(d.months.map((m) => [m.frame, m.text]), [[0, 'PROSINEC 2026 / LEDEN 2027'], [1, 'LEDEN 2027']]);
            assert.deepEqual(d.wks.map((m) => [m.frame, m.text]), [[0, '53. týden'], [1, '1. týden']]);
            assert.ok(!/53\. a 1\. týden/.test(d.wk), 'no text for the whole fortnight is printed');
            // the first week's texts stand over / under the left column, the second week's over / under the right one
            const bx = b.x;
            for (const list of [d.months, d.wks]) assert.ok(list[0].cx < photo && list[1].cx > bx, `texts sit with their column: ${JSON.stringify(list)}`);
            // the logo sits between the two month texts
            const logo = await page.$eval('.cal-5w .cal-page-svg svg [data-el="logo"]', (el) => { const r = el.getBoundingClientRect(); return r.left + r.width / 2; }).catch(() => null);
            if (logo !== null) assert.ok(d.months[0].cx < logo && logo < d.months[1].cx, 'logo between the month texts');

            // more pages: both weeks in one month; only the SECOND week crosses a month boundary; the year's end
            const all = await weekPages(page, h);
            const expectPage = async (p, months, wks) => {
                assert.deepEqual([p.monthTexts, p.weekTexts], [months, wks], `page ${p.key}`);
                await showPage(page, h, p.index);
                const on = await drawn(page);
                assert.deepEqual([on.months.map((m) => m.text), on.wks.map((m) => m.text)], [months, wks], `drawn page ${p.key}`);
            };
            await expectPage(all[1], ['LEDEN 2027', 'LEDEN 2027'], ['2. týden', '3. týden']);
            await expectPage(all.find((p) => p.frames[1][0] === '2027-03-29'), ['BŘEZEN 2027', 'BŘEZEN / DUBEN 2027'], ['12. týden', '13. týden']);
            await expectPage(all[all.length - 1], ['PROSINEC 2027 / LEDEN 2028', 'LEDEN 2028'], ['52. týden', '1. týden']);
            // the page NAME in the lists still covers both weeks
            assert.equal(first.title, '53. a 1. týden');
        });

        await check('cleanup', async () => {
            for (const id of [week, fort]) if (id) await h.deleteCalendar(page, id);
        });
    },
};
