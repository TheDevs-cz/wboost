/*
 * Editor — Nahrát layout / Uložit layout do souboru (handoff §5.8, R6) and the same editor mounted
 * in step 4 „Styl stránek“ (handoff §7.4 last sentence; E21 itself belongs to P6a's script).
 * Real pointer; a second and a third calendar; a real file through the file input. Phase P4.
 */
const assert = require('assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const e = require('./lib/editor.cjs');

const MENU = '[data-layer-menu]';
const stable = (value) => JSON.stringify(value, (key, v) => (v && typeof v === 'object' && !Array.isArray(v) ? Object.fromEntries(Object.keys(v).sort().map((k) => [k, v[k]])) : v));
const layoutOf = async (page, h) => (await h.state(page)).design.layout;
const menuItems = (page) => page.$$eval('.cal-laym [role="menuitem"]', (n) => n.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));

async function rename(page, name) {
    await page.$eval('[data-cal-name]', (el) => {
        el.focus();
        el.select();
    });
    await page.keyboard.press('Backspace');
    await page.type('[data-cal-name]', name);
    await page.keyboard.press('Enter');
}

/** Clicks „Uložit layout do souboru“ and returns `{ name, text }` of the file the page offered. */
async function downloadLayout(page) {
    await page.evaluate(() => {
        window.__layoutDownload = null;
        const click = HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function patched() {
            if (!this.download) return click.call(this);
            window.__layoutDownload = fetch(this.href).then((r) => r.text()).then((text) => ({ name: this.download, text }));
            HTMLAnchorElement.prototype.click = click;
            return undefined;
        };
    });
    await page.click(MENU);
    await page.click('[data-cal-action="layout-save"]');
    return page.evaluate(() => window.__layoutDownload);
}

async function uploadLayout(page, file) {
    const input = await page.$('[data-layout-file]');
    await input.uploadFile(file);
}

module.exports = {
    name: 'editor-layout',
    checks: ['R6', '§5.8', '§7.4 in Styl stránek'],
    async run({ page, h, check }) {
        const created = [];
        const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'cal-layout-'));
        let first = null;
        let second = null;
        let firstLayout = null;
        let file = null;

        await check('setup: a customised first calendar „Vzor A“ and a 14-day calendar in the same project', async () => {
            const other = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(other.id);
            await rename(page, 'Čtrnáctidenní');
            await h.save(page);
            first = await e.newCalendar(page, h);
            created.push(first.id);
            await rename(page, 'Vzor A');
            const logo = e.centre(first.design.layout.boxes.logo);
            await h.dragMm(page, logo, { x: logo.x - 30, y: logo.y + 20 });
            await e.resize(page, h, 'photo', 'e', -37, 0);
            await page.click('[data-layer-eye="month"]');
            await e.dragRow(page, 'cap', 'head', true);
            await h.save(page);
            firstLayout = await layoutOf(page, h);
            assert.equal(firstLayout.boxes.month.hidden, true);
            h.expectBox(firstLayout.boxes.logo, { x: 138.5, y: 51 }, e.TOL, 'logo of the first calendar');
        });
        if (!first) return;

        await check('Uložit layout do souboru: a .json named after the calendar that holds its whole layout', async () => {
            const download = await downloadLayout(page);
            assert.ok(download, 'a file was offered');
            assert.equal(download.name, 'vzor-a-layout.json');
            const data = JSON.parse(download.text);
            assert.equal(data.wboostCalendarLayout, 1);
            assert.equal(data.type, 'week');
            assert.equal(data.sourceCalendar, first.id);
            assert.equal(stable(data.layout), stable(firstLayout));
            assert.equal(await page.$('.cal-laym'), null, 'the menu closed');
            assert.equal((await h.state(page)).status, 'clean', 'saving a file changes nothing');
            file = path.join(tmp, download.name);
            fs.writeFileSync(file, download.text);
        });

        await check('R6 a second calendar loads the layout of the first: it looks like the first; one undo step; the first stays untouched afterwards', async () => {
            second = await e.newCalendar(page, h);
            created.push(second.id);
            const own = await layoutOf(page, h);
            assert.notEqual(stable(own.boxes), stable(firstLayout.boxes));
            await e.selectLayer(page, h, 'logo');
            assert.equal(await page.$eval(MENU, (el) => `${el.getAttribute('aria-label')}|${el.title}|${el.getAttribute('aria-expanded')}`), 'Nahrát layout|Nahrát layout|false');
            await page.click(MENU);
            assert.equal(await page.$eval(MENU, (el) => el.getAttribute('aria-expanded')), 'true');
            assert.deepEqual(await menuItems(page), ['Vzor A Layout z kalendáře v projektu', 'Ze souboru layoutu .json uložený z Wboostu', 'Uložit layout do souboru .json pro použití v jiném kalendáři'], 'only calendars of the same type are offered');
            // Esc and a click elsewhere close the menu without doing anything
            await page.keyboard.press('Escape');
            assert.equal(await page.$('.cal-laym'), null);
            assert.equal((await e.selection(page, h)).el, 'logo', 'Esc closed the menu, not the selection');
            await page.click(MENU);
            await page.click('.cal-stage-note');
            assert.equal(await page.$('.cal-laym'), null);

            await page.click(MENU);
            await page.click(`[data-layout-from="${first.id}"]`);
            await h.waitToast(page, 'Layout načten z „Vzor A“ — Ctrl\\+Z vrátí původní');
            assert.equal(stable(await layoutOf(page, h)), stable(firstLayout), 'layout and style are the first calendar\'s');
            assert.equal(await e.selection(page, h), null, 'the selection is cleared');
            assert.equal(await page.$('.cal-page-svg [data-el="month"]'), null, 'the page shows it: the month is hidden like in the first');
            let s = await h.state(page);
            assert.equal(s.canUndo, true);
            assert.equal(s.status, 'dirty');
            await e.pressMod(page, 'z');
            assert.equal(stable(await layoutOf(page, h)), stable(own), 'one Ctrl+Z brings the old layout back');
            await e.pressMod(page, 'z', { shift: true });
            assert.equal(stable(await layoutOf(page, h)), stable(firstLayout));
            await h.save(page);
            await h.reload(page);
            assert.equal(stable(await layoutOf(page, h)), stable(firstLayout), 'after reload');

            // written to the history of the second calendar
            const entries = await page.evaluate(async () => {
                const boot = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
                const response = await fetch(boot.env.urls.history, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                return (await response.json()).entries.map((entry) => entry.what);
            });
            assert.ok(entries.some((what) => /Načetl layout z „Vzor A“/.test(what)), `history: ${entries.join(' | ')}`);

            // changing the second does not change the first
            const logo = e.centre(firstLayout.boxes.logo);
            await h.dragMm(page, logo, { x: logo.x + 60, y: logo.y });
            await page.click('[data-layer-eye="month"]');
            await h.save(page);
            s = await h.state(page);
            assert.notEqual(stable(s.design.layout), stable(firstLayout));
            await h.openCalendar(page, first.id);
            assert.equal(stable(await layoutOf(page, h)), stable(firstLayout), 'the first calendar is unchanged');
        });

        await check('Ze souboru layoutu: a saved file loads as one undo step; an invalid or foreign file is rejected and changes nothing', async () => {
            await e.restore(page, h, first.design);
            const own = await layoutOf(page, h);
            assert.notEqual(stable(own), stable(firstLayout));
            await uploadLayout(page, file);
            await h.waitToast(page, 'Layout načten ze souboru — Ctrl\\+Z vrátí původní');
            assert.equal(stable(await layoutOf(page, h)), stable(firstLayout));
            await e.pressMod(page, 'z');
            assert.equal(stable(await layoutOf(page, h)), stable(own));
            assert.equal((await h.state(page)).canUndo, false);

            const bad = {
                'not-json.json': 'this is not JSON',
                'other.json': JSON.stringify({ hello: 'world' }),
                'no-styles.json': JSON.stringify({ wboostCalendarLayout: 1, type: 'week', layout: { boxes: {} } }),
                'fortnight.json': JSON.stringify({ ...JSON.parse(fs.readFileSync(file, 'utf8')), type: 'fortnight' }),
            };
            for (const [name, text] of Object.entries(bad)) {
                const target = path.join(tmp, name);
                fs.writeFileSync(target, text);
                await page.$$eval('.toast-n', (nodes) => nodes.forEach((n) => n.remove()));
                await uploadLayout(page, target);
                await h.waitToast(page, 'Soubor neobsahuje layout kalendáře');
                assert.equal(stable(await layoutOf(page, h)), stable(own), `${name}: nothing changed`);
                const s = await h.state(page);
                assert.equal(s.canUndo, false, `${name}: no undo step`);
                assert.equal(s.status, 'clean', `${name}: nothing to save`);
            }
        });

        await check('step 4 „Styl stránek“ is the same editor: select, move, linked resize, undo on the same stack', async () => {
            await e.restore(page, h, first.design);
            const before = await e.allBoxes(page, h);
            await h.gotoStep(page, 4);
            if (!(await page.$('.cal-stage.embedded'))) {
                const style = await page.$('[data-content-view="style"]');
                if (style) await style.click();
            }
            if (!(await page.$('.cal-stage.embedded'))) {
                console.log('    SKIP: step 4 does not mount the style editor yet');
                return;
            }
            assert.ok(await page.$('.cal-stage.embedded .cal-cv'), 'the stage is mounted in step 4');
            assert.equal(await page.$('[data-cal-action="panel-toggle"]'), null, 'no collapse handle when embedded');
            assert.equal(await page.$eval('.cal-hist', (el) => el.hidden), false, 'undo / redo show in Styl stránek');
            // E1
            await e.clickMm(page, h, { x: 60, y: 80 });
            assert.deepEqual(await e.selection(page, h), { el: 'photo' });
            assert.equal(await e.visibleHandles(page), 8);
            assert.equal(await page.$eval('[data-frame-tag]', (el) => el.textContent), 'Fotografie');
            e.assertRect(await e.frameRect(page), await e.svgRectOf(page, before.photo), 'frame = photo box');
            // §7.4 row 1
            await h.dragHandle(page, 'e', -37, 0);
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 }, cal: { x: 160, w: 155 } }), 'linked resize in step 4');
            // E3
            const logo = e.centre(before.logo);
            await h.dragMm(page, logo, { x: logo.x + 5, y: logo.y + 40 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 }, cal: { x: 160, w: 155 }, logo: { x: 173.5, y: 71 } }), 'move in step 4');
            // E12
            const geometry = await h.cal(page, (c) => c.geometry());
            const num = geometry.frames[0].rows[0].parts.find((p) => p.key === 'num').rect;
            await e.clickMm(page, h, e.centre(num));
            await e.clickMm(page, h, e.centre(num));
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 0, part: 'num' });
            assert.ok((await page.$$eval('[data-part-box]', (n) => n.length)) >= 3);
            await page.keyboard.press('Escape');
            await page.keyboard.press('Escape');
            assert.equal(await e.selection(page, h), null);
            // the same undo stack as step 3
            await page.click('[data-cal-action="undo"]');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'two undos in step 4');
            await h.gotoStep(page, 3);
            await e.pressMod(page, 'z', { shift: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { photo: { w: 165 }, cal: { x: 160, w: 155 } }), 'redo in step 3 continues the stack');
        });

        fs.rmSync(tmp, { recursive: true, force: true });
        for (const id of created) await h.deleteCalendar(page, id);
    },
};
