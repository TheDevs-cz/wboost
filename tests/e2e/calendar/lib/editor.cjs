/*
 * Helpers shared by the editor e2e scripts of phase P4 (editor-*.e2e.cjs, link-*.e2e.cjs).
 * Everything goes through the real UI and the read-only hook `window.__calendar`; `h` is harness.cjs.
 */
const assert = require('assert/strict');

const TOL = 0.051; // boxes are stored on the 0.1 mm grid

const copy = (value) => JSON.parse(JSON.stringify(value));

/**
 * Unsaved changes arm `beforeunload`; these scripts reload on purpose (`restore`), so the prompt
 * is accepted. Registered once per page.
 */
function leaveFreely(page) {
    if (page.leavesFreely) return;
    page.leavesFreely = true;
    page.on('dialog', (dialog) => {
        if (dialog.type() === 'beforeunload') dialog.accept().catch(() => null);
    });
}

/**
 * Creates a calendar through the UI and opens step 3.
 * @param o.type      'week' (default) | 'fortnight'
 * @param o.preset    'photoLeft' (default) | 'photoRight' — ignored for a fortnight
 * @param o.features  step-1 switches to flip before the layout is chosen, e.g. ['qr', 'events']
 * @returns {{ id: string, design: object }} `design` = the saved design right after creation
 */
async function newCalendar(page, h, o = {}) {
    leaveFreely(page);
    const id = await h.createCalendar(page);
    for (const key of o.features || []) await page.click(`label:has([data-feature="${key}"])`);
    if (o.type === 'fortnight') {
        await page.click('[data-type="fortnight"]');
        await h.gotoStep(page, 2);
        await page.click('[data-cal-action="continue"]');
    } else {
        await h.gotoStep(page, 2);
        await page.click(`[data-preset="${o.preset || 'photoLeft'}"]`);
    }
    await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
    await h.save(page);
    return { id, design: (await h.state(page)).design };
}

/** Puts the calendar back to a saved design (layout, features, print, linking) and reloads into step 3. */
async function restore(page, h, design, patch = {}) {
    // a silent step save of the page may still be on its way; it must not land after the seed
    await page.waitForNetworkIdle({ idleTime: 200, timeout: 8000 }).catch(() => null);
    await h.seed(page, { design: { layout: design.layout, features: design.features, print: design.print, preset: design.preset, linkElements: true, step: 3, ...patch } });
    await page.waitForSelector('.cal-ws[data-cal-shown-step="3"]', { timeout: 10000 });
}

/** Every stored box (hidden ones and QR included), mm. */
const allBoxes = (page, h) => h.cal(page, (c) => c.state().design.layout.boxes);

/** Asserts that two box maps have the same keys and the same geometry (and `hidden` flags). */
function assertBoxes(actual, expected, label = 'boxes') {
    assert.deepEqual(Object.keys(actual).sort(), Object.keys(expected).sort(), `${label}: box keys`);
    for (const key of Object.keys(expected)) {
        for (const f of ['x', 'y', 'w', 'h']) {
            assert.ok(Math.abs(actual[key][f] - expected[key][f]) <= TOL, `${label}: ${key}.${f} expected ${expected[key][f]}, got ${actual[key][f]}`);
        }
        assert.equal(Boolean(actual[key].hidden), Boolean(expected[key].hidden), `${label}: ${key}.hidden`);
    }
}

/** `expected` = `before` with the listed boxes replaced: `withBoxes(before, { photo: { w: 165 } })`. */
function withBoxes(before, patch) {
    const out = copy(before);
    for (const [key, box] of Object.entries(patch)) out[key] = { ...out[key], ...box };
    return out;
}

const selection = (page, h) => h.cal(page, (c) => c.state().selection);

/** Selects an element through its Vrstvy row (no-op when it already is the selection). */
async function selectLayer(page, h, el) {
    const current = await selection(page, h);
    if (current && current.el === el && !current.part) return;
    if (current && current.el === el) await page.keyboard.press('Escape');
    else await page.click(`[data-layer="${el}"]`);
    const now = await selection(page, h);
    assert.ok(now && now.el === el && !now.part, `selecting ${el} through Vrstvy`);
}

async function clickMm(page, h, pt) {
    const p = await h.cal(page, (c, q) => c.toClient(q.x, q.y), pt);
    await page.mouse.click(p.x, p.y);
}

const centre = (box) => ({ x: box.x + box.w / 2, y: box.y + box.h / 2 });

/** Selects `el` in Vrstvy and drags one of its handles by mm. */
async function resize(page, h, el, handle, dx, dy, opts = {}) {
    await selectLayer(page, h, el);
    await h.dragHandle(page, handle, dx, dy, opts);
}

/** Moves the pointer back to where a `hold: true` drag of a handle / point began + an offset in mm. */
async function release(page, alt = false) {
    await page.mouse.up();
    if (alt) await page.keyboard.up('Alt');
    await new Promise((resolve) => setTimeout(resolve, 50));
}

async function pressMod(page, key, { shift = false } = {}) {
    await page.keyboard.down('Control');
    if (shift) await page.keyboard.down('Shift');
    await page.keyboard.press(key);
    if (shift) await page.keyboard.up('Shift');
    await page.keyboard.up('Control');
}

/** Centre of a handle of the selection frame, viewport px. */
async function handleCentre(page, handle) {
    return page.$eval(`[data-handle="${handle}"]`, (el) => {
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    });
}

/** Viewport rect of the selection frame, or null when no frame is drawn. */
const frameRect = (page) =>
    page.evaluate(() => {
        const el = document.querySelector('.cal-ovl > .cal-sel:not(.other)');
        if (!el || el.hidden) return null;
        const r = el.getBoundingClientRect();
        return { x: r.left, y: r.top, w: r.width, h: r.height };
    });

const visibleHandles = (page) => page.$$eval('[data-handle]', (nodes) => nodes.filter((n) => n.offsetParent !== null && getComputedStyle(n).display !== 'none').length);

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Viewport rect a trim-relative mm box must have, computed from the page SVG alone (not from the overlay). */
const svgRectOf = (page, box) =>
    page.evaluate((b) => {
        const svg = document.querySelector('.cal-cv .cal-page-svg svg');
        const r = svg.getBoundingClientRect();
        const vb = svg.viewBox.baseVal;
        const k = r.width / vb.width;
        return { x: r.left + (b.x - vb.x) * k, y: r.top + (b.y - vb.y) * k, w: b.w * k, h: b.h * k };
    }, box);

function assertRect(actual, expected, label, tol = 1) {
    assert.ok(actual, `${label}: no frame`);
    for (const f of ['x', 'y', 'w', 'h']) assert.ok(Math.abs(actual[f] - expected[f]) <= tol, `${label}.${f}: expected ${expected[f].toFixed(2)}, got ${actual[f].toFixed(2)}`);
}

/** Drags a Vrstvy row onto another row (upper or lower half) with the real mouse. */
async function dragRow(page, from, to, below) {
    const a = await page.$eval(`[data-layer-item="${from}"] .cal-eln`, (el) => {
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    });
    const b = await page.$eval(`[data-layer-item="${to}"] .cal-elr`, (el) => {
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, top: r.top, bottom: r.bottom };
    });
    await page.mouse.move(a.x, a.y);
    await page.mouse.down();
    await page.mouse.move(a.x, a.y + (below ? 8 : -8), { steps: 4 });
    await page.mouse.move(b.x, below ? b.bottom - 4 : b.top + 4, { steps: 14 });
    await sleep(200);
    await page.mouse.up();
    await sleep(250);
}

module.exports = {
    leaveFreely,
    svgRectOf,
    assertRect,
    dragRow,
    sleep, TOL, copy, newCalendar, restore, allBoxes, assertBoxes, withBoxes, selection, selectLayer, clickMm, centre, resize, release, pressMod, handleCentre, frameRect, visibleHandles };
