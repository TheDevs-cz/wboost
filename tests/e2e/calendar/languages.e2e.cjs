/*
 * Multilingual names (feedback K15b; docs/architecture/calendars.md § "Languages of the printed
 * names"): step 1 „Jazyky názvů“ — Czech fixed, Slovenština / Polština / Němčina / Angličtina as
 * checkboxes, stored as `design.languages` in the fixed print order; the week page prints the month
 * (two lines across months), weekday and week-number words in every language; step 3 › Kalendárium
 * „Názvy dnů“ Celé · Zkrácené · Automaticky.
 */
const assert = require('assert/strict');
const ed = require('./lib/editor.cjs');

const prop = (path) => `[data-prop="${path}"]`;
const texts = (page, selector) => page.$$eval(selector, (nodes) => nodes.map((n) => n.textContent));

module.exports = {
    name: 'languages',
    checks: ['K15b'],
    async run({ page, h, check }) {
        let id = null;

        await check('step 1: Czech fixed, the extra languages as checkboxes, saved in print order', async () => {
            ed.leaveFreely(page);
            id = await h.createCalendar(page);
            assert.deepEqual(await texts(page, '.cal-langs .cal-check-t'), ['Čeština', 'Slovenština', 'Polština', 'Němčina', 'Angličtina']);
            assert.equal(await page.$eval('[data-language="cs"]', (el) => el.checked && el.disabled), true, 'Czech is always printed');
            await page.click('label:has([data-language="en"])');
            await page.click('label:has([data-language="pl"])');
            assert.deepEqual((await h.state(page)).design.languages, ['pl', 'en'], 'the fixed order, not the click order');
            await h.save(page);
            await h.reload(page);
            assert.deepEqual((await h.state(page)).design.languages, ['pl', 'en']);
            const ticked = await page.$$eval('.cal-langs input', (nodes) => nodes.filter((n) => n.checked).map((n) => n.dataset.language));
            assert.deepEqual(ticked, ['cs', 'pl', 'en']);
        });

        await check('the week page prints every language: a two-line Měsíc across the years, weekday and week number', async () => {
            await h.gotoStep(page, 2);
            await page.click('[data-preset="photoLeft"]');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            await h.save(page);
            const year = (await h.state(page)).design.year;
            const month = await texts(page, '.cal-stage svg [data-el="month"] text');
            assert.deepEqual(month, [`PROSINEC / GRUDZIEŃ / DECEMBER ${year - 1}`, `LEDEN / STYCZEŃ / JANUARY ${year}`]);
            assert.match((await texts(page, '.cal-stage svg [data-el="wk"] text'))[0], /^\d+\. týden \/ tydzień \/ week$/);
            const days = await texts(page, '.cal-stage svg [data-el="cal"] [data-part="day"]');
            assert.equal(days.length, 7);
            assert.ok(days[0] === 'Pondělí / Poniedziałek / Monday' || days[0] === 'Po / Pn / Mo', `weekday ${days[0]}`);
        });

        await check('step 3 › Kalendárium: Názvy dnů Celé · Zkrácené · Automaticky (new layouts: Automaticky)', async () => {
            await page.click('[data-layer="cal"]');
            await page.waitForSelector(prop('cal.dayFormat'));
            assert.deepEqual(await texts(page, `${prop('cal.dayFormat')} [data-value]`), ['Celé', 'Zkrácené', 'Automaticky']);
            assert.equal((await h.state(page)).design.layout.styles.cal.dayFormat, 'auto');
            await page.click(`${prop('cal.dayFormat')} [data-value="short"]`);
            await page.waitForFunction(() => document.querySelector('.cal-stage svg [data-el="cal"] [data-part="day"]').textContent === 'Po / Pn / Mo', { timeout: 5000 });
            await page.click(`${prop('cal.dayFormat')} [data-value="full"]`);
            await page.waitForFunction(() => document.querySelector('.cal-stage svg [data-el="cal"] [data-part="day"]').textContent === 'Pondělí / Poniedziałek / Monday', { timeout: 5000 });
            await h.save(page);
        });

        await check('back to Czech only: the old texts', async () => {
            await h.gotoStep(page, 1);
            await page.click('label:has([data-language="pl"])');
            await page.click('label:has([data-language="en"])');
            assert.deepEqual((await h.state(page)).design.languages, []);
            await h.save(page);
            await h.gotoStep(page, 3);
            const year = (await h.state(page)).design.year;
            assert.deepEqual(await texts(page, '.cal-stage svg [data-el="month"] text'), [`PROSINEC ${year - 1} / LEDEN ${year}`]);
            assert.equal((await texts(page, '.cal-stage svg [data-el="cal"] [data-part="day"]'))[0], 'Pondělí');
            await h.deleteCalendar(page, id);
        });
    },
};
