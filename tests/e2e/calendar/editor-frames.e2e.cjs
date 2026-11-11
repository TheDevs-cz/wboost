/*
 * 14 dní: Měsíc and Číslo týdne are printed once per week column, as on the client's printed
 * calendars — each of the two elements has two frames (`month` / `month2`, `wk` / `wk2`) that share
 * one style and one row in Vrstvy, like the two frames of the Kalendárium.
 *
 * new 14-day calendar (default geometry, one Vrstvy row each) → each frame selected on the page
 * (tag, outline of the other frame) → month2 dragged, wk2 nudged → wk2 / cal2 resized with and
 * without Propojit prvky → the „1. týden | 2. týden“ switch of Pozice a velikost → the eye and
 * „Zobrazení týdnů“ hide both frames → a font size restyles both frames and each grows on its own
 * → every step undone → save + reload → step 5 prints per-column texts → a calendar stored in the
 * shape from BEFORE the change opens migrated, and its layout loads into another calendar.
 */
const assert = require('assert/strict');
const path = require('path');
const e = require('./lib/editor.cjs');

const prop = (p) => `[data-prop="${p}"]`;
const field = (f) => `[data-field="${f}"]`;
const KEYS = ['cal', 'cal2', 'cap', 'head', 'logo', 'month', 'month2', 'photo', 'qr', 'wk', 'wk2'];

module.exports = {
    name: 'editor-frames',
    checks: ['14-day month / week number per column'],
    async run({ page, h, check }) {
        const created = [];
        let cal = null;
        let before = null;

        const tag = () => page.$eval('[data-frame-tag]', (el) => el.textContent);
        const otherOutline = () => page.$eval('.cal-ovl > .cal-sel.other', (el) => (el.hidden ? null : { x: el.getBoundingClientRect().left, y: el.getBoundingClientRect().top, w: el.getBoundingClientRect().width, h: el.getBoundingClientRect().height }));
        const value = (selector) => page.$eval(selector, (el) => el.value);
        const styles = async () => (await h.state(page)).design.layout.styles;

        /** What the sample page (step 3) or the large page (step 5) prints for an element, per frame. */
        const drawn = (root, el) =>
            page.evaluate(
                (rootSel, key) => {
                    const svg = document.querySelector(`${rootSel} svg[data-page]`);
                    const k = svg.viewBox.baseVal.width / svg.getBoundingClientRect().width;
                    const mm = (px) => svg.viewBox.baseVal.x + (px - svg.getBoundingClientRect().left) * k;
                    return [...svg.querySelectorAll(`[data-el="${key}"]`)].map((g) => {
                        const text = g.querySelector('text');
                        const r = text.getBoundingClientRect();
                        return { frame: Number(g.dataset.frame), text: text.textContent, anchor: text.getAttribute('text-anchor') || 'start', size: Number(text.getAttribute('font-size')), fill: text.getAttribute('fill'), left: mm(r.left), right: mm(r.right) };
                    });
                },
                root,
                el,
            );
        const sample = (el) => drawn('.cal-cv', el);

        async function type(selector, text) {
            await page.$eval(selector, (el) => {
                el.scrollIntoView({ block: 'center' });
                el.focus();
                el.select();
            });
            await page.keyboard.press('Backspace');
            await page.keyboard.type(text);
            await page.keyboard.press('Enter');
            await h.sleep(60);
        }

        /** Presses an option of a segmented control (atomic: the panel may be re-rendering). */
        async function seg(path_, v) {
            await page.waitForSelector(`${prop(path_)} [data-value="${v}"]`, { timeout: 5000 });
            await page.$eval(`${prop(path_)} [data-value="${v}"]`, (el) => el.click());
            await h.sleep(60);
        }

        /** Clicks a Vrstvy row / its eye (atomic: the list re-renders after every store event). */
        async function layer(el) {
            await page.waitForSelector(`[data-layer="${el}"]`, { timeout: 5000 });
            await page.$eval(`[data-layer="${el}"]`, (node) => node.click());
            await h.sleep(60);
        }
        async function eye(el) {
            await page.$eval(`[data-layer-eye="${el}"]`, (node) => node.click());
            await h.sleep(60);
        }

        /** A toast lies over the lower right corner of the stage — where the second week's frames are. */
        const toastsGone = () => page.waitForFunction(() => !document.querySelector('.toast-n'), { timeout: 15000 });

        /** Leaves the field so the next Ctrl+Z belongs to the editor, not to the input. */
        const blur = () => page.evaluate(() => document.activeElement && document.activeElement.blur());

        async function selectFrame(el, frame) {
            const boxes = await e.allBoxes(page, h);
            // the handles of a selected neighbour win over what lies under them (the bottom handle of
            // the Kalendárium frame sits on the week-number frame): start from no selection
            const current = await e.selection(page, h);
            if (current && current.el !== el) await page.keyboard.press('Escape');
            await e.clickMm(page, h, e.centre(boxes[frame ? `${el}2` : el]));
            assert.deepEqual(await e.selection(page, h), { el, frame }, `clicking frame ${frame} of ${el}`);
        }

        await check('a new 14-day calendar: Měsíc and Číslo týdne have a frame over / under each week column; Vrstvy keeps one row each', async () => {
            cal = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(cal.id);
            if ((await h.state(page)).design.year !== 2027) {
                await h.gotoStep(page, 1);
                await page.click('[data-year="2027"]');
                await h.gotoStep(page, 3);
                await h.save(page);
                cal.design = (await h.state(page)).design;
            }
            before = await e.allBoxes(page, h);
            assert.deepEqual(Object.keys(before).sort(), KEYS);
            // default geometry (mm, 5 mm bleed): symmetric about the page centre (x = 155)
            h.expectBox(before.month, { x: 6, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month');
            h.expectBox(before.month2, { x: 184, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month2');
            h.expectBox(before.wk, { x: 6, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk');
            h.expectBox(before.wk2, { x: 232, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk2');
            h.expectBox(before.logo, { x: 143, y: 15, w: 24, h: 24 }, e.TOL, 'logo');
            // coinciding lines: month bottoms on the header line with both Kalendárium tops, wk tops on their bottoms
            for (const k of ['month', 'month2']) assert.ok(Math.abs(before[k].y + before[k].h - before.cal.y) < 0.01 && Math.abs(before.cal.y - before.cal2.y) < 0.01, `${k} bottom = kalendárium top`);
            for (const k of ['wk', 'wk2']) assert.ok(Math.abs(before[k].y - (before.cal2.y + before.cal2.h)) < 0.01, `${k} top = kalendárium bottom`);
            assert.ok(before.month.x + before.month.w < before.logo.x && before.logo.x + before.logo.w < before.month2.x, 'the logo sits between the two month frames');
            assert.deepEqual(Object.keys(await h.boxes(page)).sort(), KEYS.filter((k) => k !== 'qr'), 'both frames are visible boxes');

            assert.deepEqual(await page.$$eval('[data-layer]', (n) => n.map((x) => x.dataset.layer)), ['logo', 'cap', 'wk', 'month', 'head', 'cal', 'photo', 'bg'], 'one row per element');
            assert.deepEqual((await page.$$eval('[data-layer-eye]', (n) => n.map((x) => x.dataset.layerEye))).sort(), ['cap', 'logo', 'month', 'wk']);

            // the sample page (28 Dec 2026 – 10 Jan 2027): each column says its own month(s) and week
            const months = await sample('month');
            const wks = await sample('wk');
            assert.deepEqual(months.map((m) => [m.frame, m.text, m.anchor]), [[0, 'PROSINEC 2026 / LEDEN 2027', 'start'], [1, 'LEDEN 2027', 'end']]);
            assert.deepEqual(wks.map((m) => [m.frame, m.text, m.anchor]), [[0, '53. týden', 'start'], [1, '1. týden', 'end']]);
            // texts towards the outer page edges, inside their frames
            assert.ok(Math.abs(months[0].left - 6) < 0.8 && Math.abs(months[1].right - 304) < 0.8, `month texts at the outer edges: ${months[0].left} / ${months[1].right}`);
            assert.ok(Math.abs(wks[0].left - 6) < 0.8 && Math.abs(wks[1].right - 304) < 0.8, `week texts at the outer edges: ${wks[0].left} / ${wks[1].right}`);
            assert.ok(months[0].right <= before.month.x + before.month.w + 0.2 && months[1].left >= before.month2.x - 0.2, 'nothing overflows its frame');
            await h.shot(page, 'frames-01-default');
        });
        if (!cal) return;

        await check('each frame is selected on its own on the page: tag „· 1. týden“ / „· 2. týden“, eight handles, a thin outline around the other frame', async () => {
            for (const [el, label] of [['month', 'Měsíc'], ['wk', 'Číslo týdne']]) {
                await selectFrame(el, 1);
                assert.equal(await tag(), `${label} · 2. týden`);
                e.assertRect(await e.frameRect(page), await e.svgRectOf(page, before[`${el}2`]), `${el}: frame = second frame`);
                e.assertRect(await otherOutline(), await e.svgRectOf(page, before[el]), `${el}: outline = first frame`);
                assert.equal(await e.visibleHandles(page), 8);
                assert.equal(await page.$eval(`[data-layer="${el}"]`, (n) => n.getAttribute('aria-pressed')), 'true', 'the one Vrstvy row is the selected one');
                await selectFrame(el, 0);
                assert.equal(await tag(), `${label} · 1. týden`);
                e.assertRect(await e.frameRect(page), await e.svgRectOf(page, before[el]), `${el}: frame = first frame`);
                e.assertRect(await otherOutline(), await e.svgRectOf(page, before[`${el}2`]), `${el}: outline = second frame`);
            }
            // through Vrstvy: the first frame
            await page.keyboard.press('Escape');
            await layer('month');
            assert.equal(await tag(), 'Měsíc · 1. týden');
            await h.shot(page, 'frames-02-selected');
            await page.keyboard.press('Escape');
        });

        await check('month2 is dragged on its own; arrows nudge only the selected frame; one Ctrl+Z each', async () => {
            const from = e.centre(before.month2);
            await h.dragMm(page, from, { x: from.x - 12, y: from.y + 3 }, { alt: true });
            assert.deepEqual(await e.selection(page, h), { el: 'month', frame: 1 });
            let now = await e.allBoxes(page, h);
            e.assertBoxes(now, e.withBoxes(before, { month2: { x: before.month2.x - 12, y: before.month2.y + 3 } }), 'only month2 moved');
            // the text went with its frame
            const months = await sample('month');
            assert.ok(Math.abs(months[1].right - (now.month2.x + now.month2.w)) < 0.8 && Math.abs(months[0].left - 6) < 0.8);
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'undo');

            // dragging the FIRST frame while the second is selected moves the first
            await selectFrame('month', 1);
            const a = e.centre(before.month);
            await h.dragMm(page, a, { x: a.x + 8, y: a.y }, { alt: true });
            assert.deepEqual(await e.selection(page, h), { el: 'month', frame: 0 });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { month: { x: before.month.x + 8 } }), 'only month moved');
            await e.pressMod(page, 'z');

            await selectFrame('wk', 1);
            await page.keyboard.down('Shift');
            await page.keyboard.press('ArrowUp');
            await page.keyboard.up('Shift');
            await page.keyboard.press('ArrowLeft');
            now = await e.allBoxes(page, h);
            e.assertBoxes(now, e.withBoxes(before, { wk2: { x: before.wk2.x - 0.3, y: before.wk2.y - 3 } }), 'only wk2 nudged');
            await e.pressMod(page, 'z');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'two undos');
            assert.equal((await h.state(page)).canUndo, false);
        });

        await check('Propojit prvky: a second frame links like any box — wk2 top takes the bottom line along, cal2 takes month2 / wk2 along; off = alone', async () => {
            // wk2's top edge lies on the line y = 139 (both Kalendárium frames, the photo, wk)
            await selectFrame('wk', 1);
            await h.dragHandle(page, 'n', 0, -8, { alt: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { wk2: { y: 131, h: 12.5 }, wk: { y: 131, h: 12.5 }, cal: { h: 104 }, cal2: { h: 104 }, photo: { h: 104 } }), 'wk2 top, linked');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'one undo restores wk2 and every follower');

            // its left edge coincides with nothing
            await selectFrame('wk', 1);
            await h.dragHandle(page, 'w', 10, 0, { alt: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { wk2: { x: 242, w: 62 } }), 'wk2 left edge moves alone');
            await e.pressMod(page, 'z');

            // the second week's Kalendárium frame: bottom → wk2 (and the rest of the line), top → month2
            await e.clickMm(page, h, e.centre(before.cal2));
            assert.deepEqual(await e.selection(page, h), { el: 'cal', frame: 1 });
            await h.dragHandle(page, 's', 0, -10, { alt: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cal2: { h: 102 }, cal: { h: 102 }, photo: { h: 102 }, wk: { y: 129, h: 14.5 }, wk2: { y: 129, h: 14.5 } }), 'cal2 bottom, linked');
            await e.pressMod(page, 'z');
            await e.clickMm(page, h, e.centre(before.cal2));
            await h.dragHandle(page, 'n', 0, 6, { alt: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { cal2: { y: 33, h: 106 }, cal: { y: 33, h: 106 }, photo: { y: 33, h: 106 }, head: { h: 38 }, month: { h: 20.5 }, month2: { h: 20.5 } }), 'cal2 top, linked');
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'undone');

            // linking off: each alone
            await page.click('[data-cal-action="link"]');
            await toastsGone();
            await selectFrame('wk', 1);
            await h.dragHandle(page, 'n', 0, -8, { alt: true });
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { wk2: { y: 131, h: 12.5 } }), 'wk2 top, unlinked');
            await e.pressMod(page, 'z');
            await page.click('[data-cal-action="link"]');
            await toastsGone();
            assert.equal((await h.state(page)).design.linkElements, true);
            e.assertBoxes(await e.allBoxes(page, h), before, 'back');
        });

        await check('Pozice a velikost: the „1. týden | 2. týden“ switch picks the frame; X / Y / Š / V show and edit that frame only', async () => {
            for (const el of ['month', 'wk']) {
                await page.keyboard.press('Escape');
                await layer(`${el}`);
                await page.waitForSelector(`[data-props="${el}"]`);
                assert.deepEqual(await page.$$eval(`${prop(`${el}.frame`)} [data-value]`, (n) => n.map((x) => x.textContent.trim())), ['1. týden', '2. týden']);
                assert.equal(await value(field('x')), String(before[el].x).replace('.', ','));
                await seg(`${el}.frame`, '1');
                await h.sleep(60);
                assert.deepEqual(await e.selection(page, h), { el, frame: 1 });
                assert.equal(await tag(), `${el === 'month' ? 'Měsíc' : 'Číslo týdne'} · 2. týden`);
                assert.deepEqual([await value(field('x')), await value(field('y')), await value(field('w')), await value(field('h'))], ['x', 'y', 'w', 'h'].map((f) => String(before[`${el}2`][f]).replace('.', ',')));
                e.assertRect(await e.frameRect(page), await e.svgRectOf(page, before[`${el}2`]), `${el}: the frame on the page follows the switch`);
                await type(field('x'), '190');
                e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { [`${el}2`]: { x: 190 } }), `${el}: typed X moves the second frame only`);
                await blur();
                await e.pressMod(page, 'z');
                e.assertBoxes(await e.allBoxes(page, h), before, 'undo');
                // clicking a frame on the page moves the switch
                await selectFrame(el, 0);
                assert.equal(await page.$eval(`${prop(`${el}.frame`)} [aria-pressed="true"], ${prop(`${el}.frame`)} .on, ${prop(`${el}.frame`)} [aria-checked="true"]`, (n) => n.dataset.value), '0');
                assert.equal(await value(field('x')), String(before[el].x).replace('.', ','));
            }
            // typed V moves the bottom edge: the other week-number frame ends on the same line and follows
            // (collinear is enough, handoff §7); typed Š moves the inner edge, which coincides with nothing
            await selectFrame('wk', 1);
            await type(field('h'), '6');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { wk2: { h: 6 }, wk: { h: 6 } }), 'typed V, linked');
            await blur();
            await e.pressMod(page, 'z');
            await selectFrame('wk', 0);
            await type(field('w'), '60');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(before, { wk: { w: 60 } }), 'typed Š');
            await blur();
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), before, 'undone');
            await h.shot(page, 'frames-03-position');
        });

        await check('the eye hides both frames; „Zobrazení týdnů“ hides both week numbers; a hidden frame is neither selectable nor a follower', async () => {
            for (const el of ['month', 'wk']) {
                await eye(`${el}`);
                assert.equal(await page.$$eval(`.cal-cv svg[data-page] [data-el="${el}"]`, (n) => n.length), 0, `${el}: both frames gone from the page`);
                const stored = await e.allBoxes(page, h);
                assert.deepEqual([stored[el].hidden, stored[`${el}2`].hidden], [true, undefined], 'the eye is the flag of the first frame');
                const visible = Object.keys(await h.boxes(page));
                assert.ok(!visible.includes(el) && !visible.includes(`${el}2`), 'neither frame is a visible box');
                await e.clickMm(page, h, e.centre(before[`${el}2`]));
                assert.notEqual(((await e.selection(page, h)) || {}).el, el, 'a click where the second frame was selects what lies under it');
                await page.keyboard.press('Escape');
            }
            // with both hidden, the bottom line of cal2 takes no week number along
            await e.clickMm(page, h, e.centre(before.cal2));
            await h.dragHandle(page, 's', 0, -10, { alt: true });
            const moved = await e.allBoxes(page, h);
            h.expectBox(moved.wk, before.wk, e.TOL, 'hidden wk');
            h.expectBox(moved.wk2, before.wk2, e.TOL, 'hidden wk2');
            await e.pressMod(page, 'z');
            await page.keyboard.press('Escape');
            for (const el of ['wk', 'month']) {
                await e.pressMod(page, 'z');
                assert.equal(await page.$$eval(`.cal-cv svg[data-page] [data-el="${el}"]`, (n) => n.length), 2, `${el}: undo of the eye brings both frames back`);
            }
            e.assertBoxes(await e.allBoxes(page, h), before, 'as before');

            // the step-1 switch
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="weekNumbers"])');
            await h.gotoStep(page, 3);
            assert.equal((await h.state(page)).design.features.weekNumbers, false);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="wk"]', (n) => n.length), 0);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="month"]', (n) => n.length), 2);
            assert.ok(!Object.keys(await h.boxes(page)).some((k) => k === 'wk' || k === 'wk2'));
            await h.gotoStep(page, 1);
            await page.click('label:has([data-feature="weekNumbers"])');
            await h.gotoStep(page, 3);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="wk"]', (n) => n.length), 2);
        });

        await check('E18 on 14 days: a font size restyles both frames (one shared style) and each frame grows on its own, away from its outer edge; one Ctrl+Z', async () => {
            const start = await e.allBoxes(page, h);
            await page.keyboard.press('Escape');
            await layer('month');
            await page.waitForSelector('[data-props="month"]');
            await type(prop('month.size'), '36');
            assert.equal((await styles()).month.size, 36);
            let months = await sample('month');
            assert.equal(months.length, 2);
            assert.ok(Math.abs(months[0].size - (36 * 25.4) / 72) < 0.01 && months[0].size === months[1].size, 'both frames in the new size');
            let now = await e.allBoxes(page, h);
            assert.ok(now.month.w > start.month.w + 10, `month grew: ${start.month.w} → ${now.month.w}`);
            assert.ok(now.month2.w > start.month2.w + 1, `month2 grew: ${start.month2.w} → ${now.month2.w}`);
            // first frame is start-aligned → the left edge stays; the second is its mirror → the right edge stays
            assert.ok(Math.abs(now.month.x - start.month.x) < 0.06, 'month: left edge stayed');
            assert.ok(Math.abs(now.month2.x + now.month2.w - (start.month2.x + start.month2.w)) < 0.06, 'month2: right edge stayed');
            // each frame fits the widest text IT shows on any page — they are different texts
            const widths = await h.cal(page, (c) => {
                const out = { month: 0, month2: 0, wk: 0, wk2: 0 };
                for (const p of c.pages().filter((x) => x.kind === 'week')) for (const k of Object.keys(out)) out[k] = Math.max(out[k], c.geometry(p.index).textWidths[k]);
                return out;
            });
            for (const k of ['month', 'month2']) assert.ok(widths[k] <= now[k].w + 0.11 && now[k].w < widths[k] + 2, `${k}: frame ${now[k].w} mm for its widest text ${widths[k].toFixed(1)} mm`);
            assert.ok(Math.abs(now.month.w - now.month2.w) > 1, 'the two columns need different widths');
            assert.ok(months[0].right <= now.month.x + now.month.w + 0.3 && months[1].left >= now.month2.x - 0.3, 'the texts are inside their frames');
            e.assertBoxes(now, e.withBoxes(start, { month: { w: now.month.w }, month2: { x: now.month2.x, w: now.month2.w } }), 'nothing else moved');
            await h.shot(page, 'frames-04-grown');
            await blur();
            await e.pressMod(page, 'z');
            assert.equal((await styles()).month.size, 15);
            e.assertBoxes(await e.allBoxes(page, h), start, 'one undo: size and both frames back');

            // Číslo týdne: size + colour + Tvar are shared too
            await page.keyboard.press('Escape');
            await layer('wk');
            await page.waitForSelector('[data-props="wk"]');
            await type(prop('wk.size'), '60');
            now = await e.allBoxes(page, h);
            assert.ok(now.wk.w > start.wk.w && now.wk2.w > start.wk2.w, 'both week-number frames grew');
            assert.ok(Math.abs(now.wk.x - start.wk.x) < 0.06 && Math.abs(now.wk2.x + now.wk2.w - (start.wk2.x + start.wk2.w)) < 0.06, 'each away from its outer edge');
            await blur();
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), start, 'undo');
            for (const [form, texts] of [['b', ['Týden 53', 'Týden 1']], ['c', ['T53', 'T1']], ['a', ['53. týden', '1. týden']]]) {
                await seg('wk.form', `${form}`);
                await h.sleep(60);
                assert.deepEqual((await sample('wk')).map((w) => w.text), texts, `Tvar ${form}`);
            }
            await e.pressMod(page, 'z');
            await e.pressMod(page, 'z');
            await e.pressMod(page, 'z');
            assert.equal((await styles()).wk.form, 'a');
        });

        await check('Zarovnání is the first frame\'s; the second frame mirrors it', async () => {
            await page.keyboard.press('Escape');
            await layer('wk');
            await page.waitForSelector('[data-props="wk"]');
            const anchors = async () => (await sample('wk')).map((w) => w.anchor);
            assert.deepEqual(await anchors(), ['start', 'end']);
            await seg('wk.align', 'end');
            await h.sleep(60);
            assert.deepEqual(await anchors(), ['end', 'start']);
            await seg('wk.align', 'middle');
            await h.sleep(60);
            assert.deepEqual(await anchors(), ['middle', 'middle']);
            await e.pressMod(page, 'z');
            await e.pressMod(page, 'z');
            assert.deepEqual(await anchors(), ['start', 'end']);
        });

        let saved = null;
        await check('save + reload keeps every frame and the shared style', async () => {
            const from = e.centre(before.month2);
            await page.keyboard.press('Escape');
            await h.dragMm(page, from, { x: from.x - 6, y: from.y + 2 }, { alt: true });
            await selectFrame('wk', 1);
            await h.dragHandle(page, 'w', 14, 0, { alt: true });
            await seg('wk.form', 'b');
            await h.save(page);
            saved = await e.allBoxes(page, h);
            e.assertBoxes(saved, e.withBoxes(before, { month2: { x: before.month2.x - 6, y: before.month2.y + 2 }, wk2: { x: 246, w: 58 } }), 'before the reload');
            await h.reload(page);
            e.assertBoxes(await e.allBoxes(page, h), saved, 'after the reload');
            assert.equal((await styles()).wk.form, 'b');
            assert.equal((await h.state(page)).status, 'clean');
            assert.deepEqual((await sample('wk')).map((w) => w.text), ['Týden 53', 'Týden 1']);
            const boot = await page.$eval('[data-calendar-workspace-target="bootstrap"]', (el) => JSON.parse(el.textContent).calendar.layout.boxes);
            assert.deepEqual(Object.keys(boot).sort(), KEYS, 'the server stores both second frames');
            await h.shot(page, 'frames-05-saved');
        });

        await check('step 5 prints the per-column texts on every page: both weeks in one month, only the second week crossing a month, the turn of the year', async () => {
            await h.gotoStep(page, 5);
            const weeks = await h.cal(page, (c) => c.pages().filter((p) => p.kind === 'week').map((p) => ({ index: p.index, key: p.key, title: p.title, frames: p.week.frames, monthTexts: p.week.monthTexts, weekTexts: p.week.weekTexts })));
            assert.equal(weeks.length, 27);
            const show = async (index) => {
                await page.$eval(`[data-page-thumb="${index}"]`, (el) => el.click());
                await page.waitForFunction((i) => document.querySelector(`[data-page-thumb="${i}"]`).getAttribute('aria-current') === 'true', {}, index);
                await h.sleep(200);
            };
            const expectPage = async (p, months, wks, shot) => {
                assert.deepEqual([p.monthTexts, p.weekTexts], [months, wks], `page ${p.key}`);
                await show(p.index);
                const m = await drawn('.cal-5w', 'month');
                const w = await drawn('.cal-5w', 'wk');
                assert.deepEqual([m.map((x) => x.text), w.map((x) => x.text)], [months, wks], `drawn page ${p.key}`);
                assert.deepEqual([m.map((x) => x.anchor), w.map((x) => x.anchor)], [['start', 'end'], ['start', 'end']]);
                // the first week's texts are left of the second week's
                assert.ok(m[0].right < m[1].left && w[0].right < w[1].left, `columns do not collide on ${p.key}`);
                if (shot) {
                    await h.shot(page, shot);
                    // with SHOTS set: the printed page alone, for the comparison with the reference calendars
                    if (process.env.SHOTS) await (await page.$('.cal-5w .cal-page-svg svg')).screenshot({ path: path.join(process.env.SHOTS, `${shot}-page.png`) });
                }
            };
            await expectPage(weeks[0], ['PROSINEC 2026 / LEDEN 2027', 'LEDEN 2027'], ['Týden 53', 'Týden 1'], 'frames-06-step5-first');
            await expectPage(weeks[1], ['LEDEN 2027', 'LEDEN 2027'], ['Týden 2', 'Týden 3']);
            await expectPage(weeks.find((p) => p.frames[1][0] === '2027-03-29'), ['BŘEZEN 2027', 'BŘEZEN / DUBEN 2027'], ['Týden 12', 'Týden 13'], 'frames-07-step5-second-week-crosses');
            // only the FIRST week crosses; then a mid-year page inside one month
            await expectPage(weeks.find((p) => p.frames[0][0] === '2027-05-31'), ['KVĚTEN / ČERVEN 2027', 'ČERVEN 2027'], ['Týden 22', 'Týden 23']);
            await expectPage(weeks.find((p) => p.frames[0][0] === '2027-06-14'), ['ČERVEN 2027', 'ČERVEN 2027'], ['Týden 24', 'Týden 25'], 'frames-08-step5-mid-year');
            await expectPage(weeks[26], ['PROSINEC 2027 / LEDEN 2028', 'LEDEN 2028'], ['Týden 52', 'Týden 1'], 'frames-09-step5-last');
            // the page names in the list still cover both weeks
            assert.deepEqual([weeks[0].title, weeks[1].title], ['53. a 1. týden', '2. a 3. týden']);
            // every thumbnail of a week page carries two month texts
            const thumbs = await page.$$eval('[data-page-thumb] svg[data-page]', (nodes) => nodes.filter((n) => n.querySelector('[data-el="cal"]')).map((n) => n.querySelectorAll('[data-el="month"]').length));
            assert.ok(thumbs.length > 0 && thumbs.every((n) => n === 2), `thumbnails: ${JSON.stringify(thumbs)}`);
            await h.gotoStep(page, 3);
        });

        // ---- calendars stored before the change ---------------------------------------------------

        /** The 14-day layout as it was created until 2026-11-11: one Měsíc, one Číslo týdne (right, end-aligned). */
        const oldShape = (design, change = {}) => {
            const layout = e.copy(design.layout);
            delete layout.boxes.month2;
            delete layout.boxes.wk2;
            layout.boxes.month = { x: 6, y: 12.5, w: 120, h: 14.5 };
            layout.boxes.wk = { x: 232, y: 139, w: 72, h: 4.5, ...(change.wk || {}) };
            layout.styles.month = { ...layout.styles.month, align: 'start' };
            layout.styles.wk = { ...layout.styles.wk, align: 'end', form: 'a' };
            return layout;
        };

        let old = null;
        await check('a calendar stored in the OLD shape (no month2 / wk2) opens migrated: second frames mirrored about the page centre, the stored week number stays where it was', async () => {
            old = await e.newCalendar(page, h, { type: 'fortnight' });
            created.push(old.id);
            const layout = oldShape(old.design);
            const answer = await h.seed(page, { design: { layout, step: 3 } });
            assert.deepEqual(Object.keys(answer.calendar.layout.boxes).sort(), KEYS.filter((k) => k !== 'month2' && k !== 'wk2'), 'the server stored the old shape as sent');
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            const boot = await page.$eval('[data-calendar-workspace-target="bootstrap"]', (el) => JSON.parse(el.textContent).calendar.layout);
            assert.equal(boot.boxes.month2, undefined, 'the page was served the old shape');
            assert.equal(boot.styles.wk.align, 'end');

            const now = await e.allBoxes(page, h);
            assert.deepEqual(Object.keys(now).sort(), KEYS);
            h.expectBox(now.month, { x: 6, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month kept');
            h.expectBox(now.month2, { x: 184, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month2 = mirror of month');
            h.expectBox(now.wk2, { x: 232, y: 139, w: 72, h: 4.5 }, e.TOL, 'the stored week-number box is the second week\'s frame');
            h.expectBox(now.wk, { x: 6, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk = its mirror');
            const s = await styles();
            assert.deepEqual([s.month.align, s.wk.align], ['start', 'start'], 'wk.align flipped with the swap');
            // on the page: per-column texts, the old week number still end-aligned at the right
            assert.deepEqual((await sample('month')).map((m) => [m.text, m.anchor]), [['PROSINEC 2026 / LEDEN 2027', 'start'], ['LEDEN 2027', 'end']]);
            const wks = await sample('wk');
            assert.deepEqual(wks.map((m) => [m.text, m.anchor]), [['53. týden', 'start'], ['1. týden', 'end']]);
            assert.ok(Math.abs(wks[1].right - 304) < 0.8 && Math.abs(wks[0].left - 6) < 0.8);
            const state = await h.state(page);
            assert.equal(state.status, 'clean', 'opening an old calendar does not make it unsaved');
            assert.equal(state.canUndo, false);
            await h.shot(page, 'frames-10-migrated');

            // each migrated frame is an ordinary frame; the first change stores the new shape
            await selectFrame('wk', 0);
            assert.equal(await tag(), 'Číslo týdne · 1. týden');
            await page.keyboard.press('ArrowRight');
            await h.save(page);
            await h.reload(page);
            const stored = await page.$eval('[data-calendar-workspace-target="bootstrap"]', (el) => JSON.parse(el.textContent).calendar.layout);
            assert.deepEqual(Object.keys(stored.boxes).sort(), KEYS, 'saved in the new shape');
            assert.equal(stored.styles.wk.align, 'start');
            h.expectBox(stored.boxes.wk, { x: 6.3, y: 139, w: 72, h: 4.5 }, e.TOL, 'stored wk');
            h.expectBox(stored.boxes.wk2, { x: 232, y: 139, w: 72, h: 4.5 }, e.TOL, 'stored wk2');
            e.assertBoxes(await e.allBoxes(page, h), e.withBoxes(now, { wk: { x: 6.3 } }), 'idempotent: opening again changes nothing');
        });

        await check('an old month frame across most of the page width is cut to its own column before it is mirrored; a hidden one stays hidden', async () => {
            if (!old) throw new Error('no old calendar');
            const layout = oldShape(old.design);
            layout.boxes.month = { x: 6, y: 12.5, w: 298, h: 14.5 };
            layout.boxes.wk = { x: 232, y: 139, w: 72, h: 4.5, hidden: true };
            await h.seed(page, { design: { layout, step: 3 } });
            await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
            const now = await e.allBoxes(page, h);
            // cut 2 mm short of the centre line (x = 155): 6 … 153, mirrored 157 … 304
            h.expectBox(now.month, { x: 6, y: 12.5, w: 147, h: 14.5 }, e.TOL, 'month cut to the left half');
            h.expectBox(now.month2, { x: 157, y: 12.5, w: 147, h: 14.5 }, e.TOL, 'month2');
            assert.deepEqual([now.wk.hidden, now.wk2.hidden], [true, undefined], 'the eye stays on the first key');
            h.expectBox(now.wk2, { x: 232, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk2');
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="wk"]', (n) => n.length), 0, 'still hidden');
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="month"]', (n) => n.length), 2);
            await eye('wk');
            assert.deepEqual((await sample('wk')).map((m) => [m.text, m.anchor]), [['53. týden', 'start'], ['1. týden', 'end']]);
        });

        await check('Nahrát layout from a calendar stored in the old shape: the loaded layout has both frames; one Ctrl+Z', async () => {
            if (!old) throw new Error('no old calendar');
            // the old calendar keeps the old shape in the database, with a mark that is easy to recognise
            const layout = oldShape(old.design);
            layout.boxes.logo = { x: 20, y: 60, w: 30, h: 30 };
            await h.seed(page, { design: { layout, step: 3, name: 'Starý tvar' } });
            await h.openCalendar(page, cal.id);
            await h.gotoStep(page, 3);
            const own = await e.allBoxes(page, h);
            await page.click('[data-layer-menu]');
            await page.click(`[data-layout-from="${old.id}"]`);
            await h.waitToast(page, 'Layout načten z „Starý tvar“');
            const loaded = await e.allBoxes(page, h);
            assert.deepEqual(Object.keys(loaded).sort(), KEYS);
            h.expectBox(loaded.logo, { x: 20, y: 60, w: 30, h: 30 }, e.TOL, 'it is the other calendar\'s layout');
            h.expectBox(loaded.month2, { x: 184, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month2');
            h.expectBox(loaded.wk, { x: 6, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk');
            h.expectBox(loaded.wk2, { x: 232, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk2');
            assert.equal((await styles()).wk.align, 'start');
            assert.deepEqual((await sample('wk')).map((m) => [m.frame, m.anchor]), [[0, 'start'], [1, 'end']]);
            await e.pressMod(page, 'z');
            e.assertBoxes(await e.allBoxes(page, h), own, 'one undo brings the own layout back');
        });

        await check('type change 14 → 7 → 14: the second frames go and come back with the default geometry, the shared style is kept', async () => {
            await h.openCalendar(page, cal.id);
            await page.waitForSelector('.cal-ws[data-cal-ready="1"]');
            await h.gotoStep(page, 3);
            await page.keyboard.press('Escape');
            await layer('month');
            await page.waitForSelector('[data-props="month"]');
            await type(prop('month.size'), '13');
            await blur();
            await h.gotoStep(page, 1);
            await page.click('[data-type="week"]');
            await h.answerDialog(page, 'confirm').catch(() => null);
            await page.waitForFunction(() => window.__calendar.state().design.type === 'week', { timeout: 8000 });
            let boxes = await h.cal(page, (c) => c.state().design.layout.boxes);
            assert.deepEqual([boxes.month2, boxes.wk2, boxes.cal2], [undefined, undefined, undefined]);
            await h.gotoStep(page, 3);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="month"]', (n) => n.length), 1);
            await layer('month');
            await page.waitForSelector('[data-props="month"]');
            assert.equal(await page.$(prop('month.frame')), null, 'a 7-day calendar has no frame switch');
            await h.gotoStep(page, 1);
            await page.click('[data-type="fortnight"]');
            await h.answerDialog(page, 'confirm').catch(() => null);
            await page.waitForFunction(() => window.__calendar.state().design.type === 'fortnight', { timeout: 8000 });
            boxes = await h.cal(page, (c) => c.state().design.layout.boxes);
            assert.deepEqual(Object.keys(boxes).sort(), KEYS);
            h.expectBox(boxes.month2, { x: 184, y: 12.5, w: 120, h: 14.5 }, e.TOL, 'month2 default');
            h.expectBox(boxes.wk, { x: 6, y: 139, w: 72, h: 4.5 }, e.TOL, 'wk default');
            const s = await styles();
            assert.deepEqual([s.month.size, s.month.align, s.wk.align, s.wk.form], [13, 'start', 'start', 'b'], 'style kept, preset alignments reset');
            await h.gotoStep(page, 3);
            assert.equal(await page.$$eval('.cal-cv svg[data-page] [data-el="month"]', (n) => n.length), 2);
            await h.save(page);
        });

        for (const id of created) await h.deleteCalendar(page, id);
    },
};
