/*
 * Fonts of the project in the calendar editor (phase P8a; handoff §6.1 "Fonts", E18 with REAL
 * uploaded fonts): family and cut switching, and the page really drawn in the chosen face.
 *
 * The fonts are uploaded to the e2e project through the dropzone of its Fonty page
 * (`/project/{id}/fonts`, the real file input): five Nunito WOFF files and Inter (one static
 * ExtraBold + a variable file the server expands into 18 faces). The upload is idempotent — the
 * project keeps its fonts between runs, „Tento řez už je nahraný“ is fine.
 *
 * Families come from the name table INSIDE each file, so the project ends up with three fonts:
 * „Nunito“ (Regular, Medium, SemiBold, Bold), „Inter“ (nine weights, upright + italic, two upright
 * faces of weight 800) and „Nunito Light“ — Nunito-Light.woff names its own family and is a
 * one-cut font of its own. The script therefore compares the lightest and the heaviest cut of
 * „Nunito“ (Regular / Bold).
 *
 * Then, on a new Fotka vlevo calendar in step 3:
 *   - a new layout starts in the first project font; Měsíc › Písmo offers exactly the project's
 *     fonts („Výchozí písmo“ is offered only while a style uses no project font — never here);
 *   - choosing a font keeps the wanted weight and picks the nearest cut (upright preferred, a tie
 *     goes to the heavier one); Řez lists exactly the cuts of that font;
 *   - another cut changes `styles.month.family`; another font then picks ITS nearest cut;
 *   - the month text on the stage is drawn in that face: computed `font-family`, the face is
 *     loaded, and the text width differs between Regular and Bold and from the built-in font;
 *   - the same for Kalendárium parts: day numbers (family — Nunito's digits are tabular, equally
 *     wide in every cut) and weekday names (cut);
 *   - saved and reloaded: same faces, same widths; another week page of step 5 uses them too.
 * The calendar is deleted at the end; the fonts stay.
 */
const assert = require('assert/strict');
const path = require('path');
const ed = require('./lib/editor.cjs');

const ROOT = path.resolve(__dirname, '../../..');
const FONT_FILES = [
    ...['Light', 'Regular', 'Medium', 'SemiBold', 'Bold'].map((cut) => `public/css/manual-preview/fonts/Nunito-${cut}.woff`),
    'tests/Fixtures/fonts/Inter-ExtraBold-StaticSubset.ttf',
    'tests/Fixtures/fonts/Inter-VariableSubset.ttf',
].map((file) => path.join(ROOT, file));

const STAGE3 = '.cal-cv svg[data-page]';
const STAGE5 = '.cal-5w svg[data-page]';
const MONTH = '[data-el="month"] text';
const NUM = '[data-el="cal"] text[data-part="num"]';
const FALLBACK_LABEL = 'Výchozí písmo';

const prop = (p) => `[data-prop="${p}"]`;
const boot = (page) => page.evaluate(() => JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent));
const unquote = (family) => family.replace(/^["']|["']$/g, '');

// `style` of a bootstrap face is the subfamily name of the file („Regular“, „Bold“, „Italic“, „Bold Italic“)
const isUpright = (face) => !/italic|oblique/i.test(`${face.style || ''} ${face.faceName || ''}`);

// the documented rule of `nearestFace` (assets/calendar/model/fonts.js), written again on purpose:
// upright preferred, nearest weight, a tie goes to the heavier cut
function nearestFace(fonts, fontName, weight) {
    const faces = fonts.filter((f) => f.fontName === fontName);
    const upright = faces.filter(isUpright);
    const pool = upright.length ? upright : faces;
    return pool.reduce((best, face) => {
        if (!best) return face;
        const d = Math.abs(face.weight - weight);
        const bd = Math.abs(best.weight - weight);
        return d < bd || (d === bd && face.weight > best.weight) ? face : best;
    }, null);
}

/** Uploads the font files through the dropzone's file input; returns one `{ file, status, message }` per file. */
async function uploadFonts(page, h) {
    await page.goto(`${h.config.base}/project/${h.config.projectId}/fonts`, { waitUntil: 'networkidle2' });
    const input = await page.waitForSelector('[data-controller="font-uploader"] input[type="file"][data-font-uploader-target="input"]', { timeout: 10000 });
    // a batch that added a face reloads the page 1.2 s after it has drained: keep the result rows across it
    await page.evaluate(() => {
        window.__fontRows = null;
        new MutationObserver(() => {
            const rows = [...document.querySelectorAll('.fn-ups .gallery-upload-item')].map((row) => {
                const [name, message] = row.querySelectorAll('.small');
                const status = ['text-success', 'text-danger', 'text-warning'].find((c) => message.classList.contains(c)) || 'pending';
                return { file: name.textContent.trim(), status: { 'text-success': 'added', 'text-warning': 'exists', 'text-danger': 'error', pending: 'pending' }[status], message: message.textContent.trim() };
            });
            if (rows.length) window.__fontRows = rows;
        }).observe(document.querySelector('.fn-ups'), { childList: true, subtree: true });
    });
    await input.uploadFile(...FONT_FILES);
    const settled = await page.waitForFunction((count) => (window.__fontRows && window.__fontRows.length === count && window.__fontRows.every((r) => r.status !== 'pending') ? window.__fontRows : false), { timeout: 60000 }, FONT_FILES.length);
    const rows = await settled.jsonValue();
    if (rows.some((r) => r.status === 'added')) await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }).catch(() => null);
    return rows;
}

/** A drawn text: what it is set in and how wide it is (user units = mm, and px). */
const textInfo = (page, selector) =>
    page.evaluate((s) => {
        const node = document.querySelector(s);
        if (!node) return null;
        const style = getComputedStyle(node);
        const row = node.closest('[data-date]');
        return { text: node.textContent, family: style.fontFamily, weight: style.fontWeight, mm: node.getComputedTextLength(), px: node.getBoundingClientRect().width, date: row ? row.dataset.date : null };
    }, selector);

/** Waits until the browser has loaded the face (because the page uses it — the test never loads a font itself). */
async function faceLoaded(page, family) {
    await page.waitForFunction((f) => document.fonts.check(`20px "${f}"`) && [...document.fonts].some((face) => face.family.replace(/^["']|["']$/g, '') === f && face.status === 'loaded'), { timeout: 15000 }, family);
    await page.evaluate(() => document.fonts.ready);
}

function assertDrawnIn(info, face, label) {
    assert.ok(info, `${label}: the text is on the page`);
    assert.equal(unquote(info.family.split(',')[0].trim()), face.family, `${label}: computed font-family`);
}

module.exports = {
    name: 'fonts',
    checks: ['E18 (real fonts)', '§6.1 Fonts'],
    async run({ page, h, check }) {
        let id = null;
        let ready = false;
        if (process.env.E2E_DEBUG) page.on('response', (r) => r.status() >= 500 && console.log('    5xx', r.status(), r.request().method(), r.url()));
        let fonts = [];
        let names = [];
        let A = ''; // Nunito
        let B = ''; // Inter
        const faces = (name) => fonts.filter((f) => f.fontName === name);
        const upright = (name) => faces(name).filter(isUpright).sort((a, b) => a.weight - b.weight);
        const lightest = (name) => upright(name)[0];
        const heaviest = (name) => upright(name)[upright(name).length - 1];
        const styles = async () => (await h.state(page)).design.layout.styles;
        const options = (p) => page.$$eval(`${prop(p)} option`, (nodes) => nodes.map((n) => ({ value: n.value, label: n.textContent })));
        const selected = (p) => page.$eval(prop(p), (el) => el.value);
        const width = {}; // month text, mm
        const numWidth = {}; // one day number, mm
        let numSelector = NUM;

        async function select(el) {
            await ed.selectLayer(page, h, el);
            if (el !== 'cal') await page.waitForSelector(`[data-props="${el}"]`, { timeout: 5000 });
        }

        async function selectPart(part) {
            const now = await ed.selection(page, h);
            if (!now || now.el !== 'cal') await ed.selectLayer(page, h, 'cal');
            if (!now || now.el !== 'cal' || now.part !== part) await page.click(`[data-part-row="${part}"]`);
            await page.waitForSelector(`[data-kal-panel="${part}"]`, { timeout: 5000 });
        }

        /** Chooses an option of a real `<select>` of the panel. */
        async function choose(p, value) {
            await page.$eval(prop(p), (el) => el.scrollIntoView({ block: 'center' }));
            await page.select(prop(p), value);
            await h.sleep(40);
        }

        /**
         * Width (mm) the same text would have in the built-in font: a new calendar of a project
         * with fonts never uses it and the select does not offer it, so a twin of the node is set
         * in it for a moment.
         */
        const fallbackWidth = (selector) =>
            page.evaluate((s) => {
                const node = document.querySelector(s);
                const twin = node.cloneNode(true);
                twin.style.fontFamily = '"Outfit", Arial, sans-serif';
                twin.style.visibility = 'hidden';
                node.parentNode.append(twin);
                const mm = twin.getComputedTextLength();
                twin.remove();
                return mm;
            }, selector);

        /** The month / number text once the stage draws it in `face`. */
        async function drawn(selector, face) {
            if (face) await faceLoaded(page, face.family);
            await h.sleep(80);
            return textInfo(page, selector);
        }

        await check('the project fonts are uploaded through the Fonty page (idempotent): two families with at least two cuts each', async () => {
            const rows = await uploadFonts(page, h);
            const refused = rows.filter((r) => r.status === 'error');
            if (refused.length) console.log(`    refused font files: ${refused.map((r) => `${r.file} — ${r.message}`).join(' | ')}`);
            assert.equal(rows.length, FONT_FILES.length);
            assert.deepEqual(refused, [], 'no font file is refused');
            // the page lists both families after the batch
            await page.goto(`${h.config.base}/project/${h.config.projectId}/fonts`, { waitUntil: 'networkidle2' });
            const text = await page.$eval('.fn-page', (el) => el.innerText);
            assert.match(text, /Nunito/);
            assert.match(text, /Inter/);
        });

        await check('setup: a Fotka vlevo calendar in step 3; the bootstrap lists the uploaded faces', async () => {
            ({ id } = await ed.newCalendar(page, h, {}));
            fonts = (await boot(page)).env.fonts;
            names = [...new Set(fonts.map((f) => f.fontName))];
            A = names.find((n) => /^nunito$/i.test(n));
            B = names.find((n) => /^inter$/i.test(n));
            assert.ok(A && B, `Nunito and Inter among ${JSON.stringify(names)}`);
            assert.deepEqual(faces(A).map((f) => f.weight).sort(), [400, 500, 600, 700], 'Nunito Regular … Bold');
            assert.ok(names.some((n) => /^nunito light$/i.test(n)), 'Nunito-Light.woff is a family of its own (its name table says so)');
            assert.ok(faces(B).length >= 2, `Inter has ${faces(B).length} cuts`);
            assert.ok(new Set(faces(B).map((f) => f.weight)).size >= 2, 'Inter cuts differ in weight');
            for (const f of fonts) assert.ok(f.family && f.url, `face ${f.faceName} has a family and a file`);
            assert.equal(new Set(fonts.map((f) => f.family)).size, fonts.length, 'every face is its own family');
            // a new layout starts in the FIRST font of the project, each style in the cut nearest to its weight
            const s = await styles();
            for (const key of ['month', 'num', 'day', 'name', 'cap']) {
                assert.equal(s[key].family, nearestFace(fonts, names[0], s[key].weight).family, `default face of ${key} (weight ${s[key].weight})`);
            }
            ready = true;
        });
        if (!ready) {
            if (id) await h.deleteCalendar(page, id);
            return;
        }

        await check('Měsíc › Písmo offers exactly the project fonts („Výchozí písmo“ only while a style uses no project font)', async () => {
            await select('month');
            const month = (await styles()).month;
            const face = fonts.find((f) => f.family === month.family);
            assert.deepEqual(await options('month.family'), names.map((n) => ({ value: n, label: n })), 'exactly the project fonts, in their order');
            assert.ok(!(await options('month.family')).some((o) => o.label === FALLBACK_LABEL), 'the built-in font is not offered while a project font is in use');
            assert.equal(await selected('month.family'), face.fontName);
            assert.deepEqual((await options('month.weight')).map((o) => o.value), faces(face.fontName).map((f) => f.family), 'Řez lists the cuts of the font in use');
            assert.equal(await selected('month.weight'), face.family);
            // two upright cuts of the same weight are told apart by their face names, not both called „Extra Bold“
            const labels = (await options('month.weight')).map((o) => o.label);
            assert.equal(new Set(labels).size, labels.length, `cut labels are unique: ${JSON.stringify(labels)}`);
            const start = await drawn(`${STAGE3} ${MONTH}`, face);
            assertDrawnIn(start, face, 'month of a new calendar');
            width.fallback = await fallbackWidth(`${STAGE3} ${MONTH}`);
            assert.ok(width.fallback > 0 && start.mm > 0, 'the month is drawn');
        });

        await check('choosing a font keeps the weight and picks its nearest cut; Řez lists exactly the cuts of that font', async () => {
            const wanted = (await styles()).month.weight;
            const expected = nearestFace(fonts, A, wanted);
            await choose('month.family', A);
            const month = (await styles()).month;
            assert.equal(month.family, expected.family, `nearest cut of ${A} to ${wanted}`);
            assert.equal(month.weight, wanted, 'the wanted weight is kept');
            assert.equal(await selected('month.family'), A);
            assert.deepEqual((await options('month.family')).map((o) => o.label), names);
            const cuts = await options('month.weight');
            assert.deepEqual(cuts.map((o) => o.value).sort(), faces(A).map((f) => f.family).sort(), 'exactly the cuts of the font');
            assert.deepEqual(cuts.map((o) => o.label), faces(A).map((f) => ({ 400: 'Regular', 500: 'Medium', 600: 'Semi Bold', 700: 'Bold' })[f.weight]));
            assert.equal(await selected('month.weight'), expected.family);
            // the same rule the other way round, with B
            await choose('month.family', B);
            const viaB = (await styles()).month;
            assert.equal(viaB.family, nearestFace(fonts, B, wanted).family, `nearest cut of ${B} to ${wanted}`);
            assert.equal(viaB.weight, wanted);
            assert.deepEqual((await options('month.weight')).map((o) => o.value).sort(), faces(B).map((f) => f.family).sort());
            await choose('month.family', A);
            assert.equal((await styles()).month.family, expected.family);
        });

        await check('another cut changes the face and the wanted weight; another font then picks ITS nearest cut', async () => {
            const before = (await styles()).month;
            // the cut of A farthest from the current one
            const current = fonts.find((f) => f.family === before.family);
            const other = [lightest(A), heaviest(A)].sort((a, b) => Math.abs(b.weight - current.weight) - Math.abs(a.weight - current.weight))[0];
            assert.notEqual(other.family, current.family);
            await choose('month.weight', other.family);
            let month = (await styles()).month;
            assert.deepEqual([month.family, month.weight], [other.family, other.weight]);
            const nearB = nearestFace(fonts, B, other.weight);
            assert.equal(nearB.weight, other.weight, `${B} has that weight too`);
            assert.ok(isUpright(nearB) && faces(B).some((f) => f.weight === nearB.weight && !isUpright(f)), 'the upright cut, not the italic of the same weight');
            await choose('month.family', B);
            month = (await styles()).month;
            assert.deepEqual([month.family, month.weight], [nearB.family, other.weight], `${B}: nearest cut to ${other.weight}`);
            assert.equal(await selected('month.weight'), nearB.family);
            // the heaviest cut of B, then back to A: the cut nearest to that weight
            const heavy = heaviest(B);
            assert.ok(heavy.weight > heaviest(A).weight, `${B} has a heavier cut than ${A}`);
            await choose('month.weight', heavy.family);
            month = (await styles()).month;
            assert.deepEqual([month.family, month.weight], [heavy.family, heavy.weight]);
            await choose('month.family', A);
            month = (await styles()).month;
            assert.deepEqual([month.family, month.weight], [nearestFace(fonts, A, heavy.weight).family, heavy.weight], `${A}: nearest cut to ${heavy.weight}`);
            assert.equal(month.family, heaviest(A).family);
            // the one-cut family: whatever the wanted weight, its only cut
            const single = names.find((n) => faces(n).length === 1);
            if (single) {
                await choose('month.family', single);
                month = (await styles()).month;
                assert.deepEqual([month.family, month.weight], [faces(single)[0].family, heavy.weight]);
                assert.deepEqual((await options('month.weight')).map((o) => o.value), [faces(single)[0].family]);
                await choose('month.family', A);
            }
        });

        await check('the month text is really drawn in the chosen face: computed family, loaded face, width changes with the cut', async () => {
            const light = lightest(A);
            const bold = heaviest(A);
            await choose('month.weight', light.family);
            width.light = await drawn(`${STAGE3} ${MONTH}`, light);
            assertDrawnIn(width.light, light, 'lightest cut');
            await choose('month.weight', bold.family);
            width.bold = await drawn(`${STAGE3} ${MONTH}`, bold);
            assertDrawnIn(width.bold, bold, 'Bold');
            assert.equal(width.light.text, width.bold.text, 'the same word is compared');
            assert.ok(width.bold.mm > width.light.mm + 0.05, `Bold (${width.bold.mm.toFixed(2)} mm) is wider than the lightest cut (${width.light.mm.toFixed(2)} mm)`);
            assert.ok(Math.abs(width.fallback - width.light.mm) > 0.05, `the built-in font (${width.fallback.toFixed(2)} mm) differs from the lightest cut`);
            assert.ok(Math.abs(width.fallback - width.bold.mm) > 0.05, `the built-in font (${width.fallback.toFixed(2)} mm) differs from Bold`);
            // the other family draws differently again
            const interFace = nearestFace(fonts, B, 700);
            await choose('month.family', B);
            width.inter = await drawn(`${STAGE3} ${MONTH}`, interFace);
            assertDrawnIn(width.inter, interFace, B);
            assert.ok(Math.abs(width.inter.mm - width.bold.mm) > 0.05, `${B} (${width.inter.mm.toFixed(2)} mm) differs from ${A} Bold`);
            // back to Nunito Bold: the width is reproducible
            await choose('month.family', A);
            const again = await drawn(`${STAGE3} ${MONTH}`, bold);
            assert.equal((await styles()).month.family, bold.family);
            assert.ok(Math.abs(again.mm - width.bold.mm) < 0.02, 'Bold measures the same as before');
            // the text stays inside its box (Měsíc grows to its text; a width measured before the face was loaded would not fit)
            const box = (await h.boxes(page)).month;
            assert.ok(again.mm <= box.w + 0.1, `the month text (${again.mm.toFixed(2)} mm) fits its box (${box.w} mm)`);
            await h.shot(page, 'fonts-month-bold');
        });

        await check('Kalendárium › day numbers and weekday names: font and cut change the drawn text', async () => {
            await selectPart('num');
            const first = await textInfo(page, `${STAGE3} ${NUM}`);
            assert.ok(first && first.date, 'a day number is drawn');
            numSelector = `[data-el="cal"] [data-date="${first.date}"] text[data-part="num"]`;
            numWidth.fallback = await fallbackWidth(`${STAGE3} ${numSelector}`);
            assert.deepEqual((await options('num.family')).map((o) => o.label), names);
            const wanted = (await styles()).num.weight;
            const viaA = nearestFace(fonts, A, wanted);
            await choose('num.family', A);
            let num = (await styles()).num;
            assert.deepEqual([num.family, num.weight], [viaA.family, wanted]);
            assert.deepEqual((await options('num.weight')).map((o) => o.value).sort(), faces(A).map((f) => f.family).sort());
            const light = lightest(A);
            const bold = heaviest(A);
            await choose('num.weight', light.family);
            numWidth.light = await drawn(`${STAGE3} ${numSelector}`, light);
            assertDrawnIn(numWidth.light, light, 'number, lightest cut');
            await choose('num.weight', bold.family);
            numWidth.bold = await drawn(`${STAGE3} ${numSelector}`, bold);
            assertDrawnIn(numWidth.bold, bold, 'number, Bold');
            num = (await styles()).num;
            assert.deepEqual([num.family, num.weight], [bold.family, bold.weight]);
            assert.equal(numWidth.bold.text, numWidth.light.text);
            // Nunito's digits are tabular — the same advance in every cut — so the digits prove the FAMILY
            // change; the cut change is measured on the weekday names below
            assert.ok(Math.abs(numWidth.light.mm - first.mm) > 0.02, `${A} digits (${numWidth.light.mm.toFixed(2)} mm) differ from the ${names[0]} digits the calendar started with (${first.mm.toFixed(2)} mm)`);
            assert.ok(Math.abs(numWidth.bold.mm - first.mm) > 0.02);
            assert.ok(Math.abs(numWidth.fallback - numWidth.bold.mm) > 0.02, `the built-in digits (${numWidth.fallback.toFixed(2)} mm) differ from Bold`);
            // every workday number of the page changed with it, the month kept its own face
            const families = await page.$$eval(`${STAGE3} ${NUM}`, (nodes) => [...new Set(nodes.map((n) => getComputedStyle(n).fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')))]);
            assert.deepEqual(families, [bold.family]);
            assert.equal((await styles()).month.family, bold.family);
            // the digits stay inside the part rectangle the layout computed for them
            const rect = await h.cal(page, (c, date) => c.geometry().frames[0].rows.find((r) => r.date === date).parts.find((p) => p.key === 'num').rect, first.date);
            assert.ok(numWidth.bold.mm <= rect.w + 0.1, `the number (${numWidth.bold.mm.toFixed(2)} mm) fits the rectangle the layout gives it (${rect.w} mm)`);

            // weekday names: the cut changes the width of the word
            await selectPart('day');
            const dayText = `[data-el="cal"] [data-date="${first.date}"] text[data-part="day"]`;
            const dayStart = await textInfo(page, `${STAGE3} ${dayText}`);
            assert.ok(dayStart && dayStart.mm > 0, 'a weekday name is drawn');
            await choose('day.family', A);
            await choose('day.weight', light.family);
            const dayLight = await drawn(`${STAGE3} ${dayText}`, light);
            assertDrawnIn(dayLight, light, 'weekday, lightest cut');
            await choose('day.weight', bold.family);
            const dayBold = await drawn(`${STAGE3} ${dayText}`, bold);
            assertDrawnIn(dayBold, bold, 'weekday, Bold');
            assert.deepEqual([(await styles()).day.family, (await styles()).day.weight], [bold.family, bold.weight]);
            assert.equal(dayBold.text, dayLight.text);
            assert.ok(dayBold.mm > dayLight.mm + 0.02, `„${dayBold.text}“ in Bold (${dayBold.mm.toFixed(2)} mm) is wider than in the lightest cut (${dayLight.mm.toFixed(2)} mm)`);
            assert.ok(Math.abs(dayLight.mm - dayStart.mm) > 0.02, `and differs from the ${names[0]} cut it started with (${dayStart.mm.toFixed(2)} mm)`);
            await choose('day.weight', light.family);
            assert.equal((await styles()).num.family, bold.family, 'each part keeps its own face');
            await h.shot(page, 'fonts-num-bold');
        });

        await check('saved and reloaded: the faces are kept, the page draws them at the same widths; another week of step 5 uses them', async () => {
            const before = await styles();
            const monthFace = fonts.find((f) => f.family === before.month.family);
            const numFace = fonts.find((f) => f.family === before.num.family);
            assert.ok(monthFace && numFace);
            const monthBefore = await textInfo(page, `${STAGE3} ${MONTH}`);
            const numBefore = await textInfo(page, `${STAGE3} ${numSelector}`);
            await h.save(page);
            await h.reload(page);
            await page.waitForSelector(STAGE3, { timeout: 10000 });
            const after = await styles();
            assert.deepEqual([after.month.family, after.month.weight, after.num.family, after.num.weight], [before.month.family, before.month.weight, before.num.family, before.num.weight]);
            assert.deepEqual(after.day, before.day);
            assert.notEqual(after.day.family, after.num.family, 'weekday names and numbers keep different cuts');
            // right after `ready`: the faces a saved layout uses are loaded before the first render
            assert.equal(await page.evaluate((list) => list.every((f) => document.fonts.check(`20px "${f}"`)), [monthFace.family, numFace.family]), true, 'the used faces are loaded when the workspace is ready');
            const monthAfter = await drawn(`${STAGE3} ${MONTH}`, monthFace);
            const numAfter = await drawn(`${STAGE3} ${numSelector}`, numFace);
            assertDrawnIn(monthAfter, monthFace, 'month after reload');
            assertDrawnIn(numAfter, numFace, 'number after reload');
            assert.ok(Math.abs(monthAfter.px - monthBefore.px) <= 0.5, `month: ${monthBefore.px.toFixed(2)} px before, ${monthAfter.px.toFixed(2)} px after`);
            assert.ok(Math.abs(numAfter.px - numBefore.px) <= 0.5, `number: ${numBefore.px.toFixed(2)} px before, ${numAfter.px.toFixed(2)} px after`);
            // the panel shows the stored choice
            await select('month');
            assert.equal(await selected('month.family'), monthFace.fontName);
            assert.equal(await selected('month.weight'), monthFace.family);

            // step 5: another week page, large picture and its thumbnail
            await h.gotoStep(page, 5);
            const sample = await h.cal(page, (c) => c.page().index);
            const other = await h.cal(page, (c, s) => c.pages().map((p, index) => ({ index, key: p.key, kind: p.kind })).filter((p) => p.kind === 'week' && p.index !== s)[20], sample);
            await page.click(`[data-page-thumb="${other.index}"]`);
            await page.waitForSelector(`.cal-5w svg[data-page="${other.key}"]`, { timeout: 5000 });
            await h.sleep(150);
            const month5 = await textInfo(page, `${STAGE5} ${MONTH}`);
            const num5 = await textInfo(page, `${STAGE5} ${NUM}`);
            assertDrawnIn(month5, monthFace, `month of ${other.key}`);
            assertDrawnIn(num5, numFace, `number of ${other.key}`);
            assert.ok(month5.mm > 0 && num5.mm > 0);
            const thumbFamilies = await page.$eval(`[data-page-thumb="${other.index}"]`, (el) => [...el.querySelectorAll('[data-el="month"] text, [data-el="cal"] text[data-part="num"]')].map((n) => getComputedStyle(n).fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')));
            if (thumbFamilies.length) assert.deepEqual([...new Set(thumbFamilies)].sort(), [...new Set([monthFace.family, numFace.family])].sort(), 'the thumbnail uses the same faces');
            await h.shot(page, 'fonts-step5');
        });

        await h.deleteCalendar(page, id);
    },
};
