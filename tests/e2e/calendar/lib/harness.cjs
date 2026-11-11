/*
 * End-to-end harness of the Kalendáře module (docs/calendar/architecture.md §F
 * "Shared test infrastructure"). puppeteer-core drives a real Chrome against
 * the running dev stack; assertions read the page through the read-only hook
 * `window.__calendar`. Setup and environment: tests/e2e/calendar/README.md.
 *
 * Every script works in the dedicated e2e project of the dedicated e2e user —
 * never in real data — and deletes the calendars it creates.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert/strict');
const { execFileSync } = require('child_process');
const { createRequire } = require('module');

const ROOT = path.resolve(__dirname, '../../../..');

function firstExisting(candidates) {
    return candidates.find((candidate) => candidate && fs.existsSync(candidate)) || null;
}

function findChrome() {
    if (process.env.CHROME) return process.env.CHROME;
    const cache = path.join(os.homedir(), '.cache/puppeteer/chrome');
    const builds = fs.existsSync(cache) ? fs.readdirSync(cache).sort().reverse() : [];
    return firstExisting([
        ...builds.map((build) => path.join(cache, build, 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing')),
        ...builds.map((build) => path.join(cache, build, 'chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing')),
        ...builds.map((build) => path.join(cache, build, 'chrome-linux64/chrome')),
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
    ]);
}

const config = {
    base: (process.env.BASE || 'http://localhost:8099').replace(/\/$/, ''),
    puppeteerDir: process.env.PUPPETEER_DIR || path.join(os.homedir(), '.cache/wboost-e2e'),
    chrome: findChrome(),
    email: process.env.USER_EMAIL || 'e2e-calendar@test.cz',
    password: process.env.USER_PASS || 'E2eCalendar123',
    projectId: process.env.PROJECT_ID || '0e2ecafe-0000-7000-8000-000000000001',
    projectName: 'E2E Kalendáře',
    headless: process.env.HEADFUL === '1' ? false : 'new',
    shots: process.env.SHOTS || null,
};

function loadPuppeteer() {
    try {
        return createRequire(path.join(config.puppeteerDir, 'package.json'))('puppeteer-core');
    } catch (error) {
        throw new Error(`puppeteer-core not found in ${config.puppeteerDir} — run: mkdir -p ${config.puppeteerDir} && cd ${config.puppeteerDir} && npm init -y && npm i puppeteer-core (or set PUPPETEER_DIR)`);
    }
}

function compose(args, input) {
    return execFileSync('docker', ['compose', 'exec', '-T', ...args], { cwd: ROOT, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
}

function psql(sql) {
    return compose(['postgres', 'psql', '-U', 'postgres', '-d', 'wboost', '-v', 'ON_ERROR_STOP=1', '-At'], sql).trim();
}

const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;

/**
 * Creates the e2e user and its project (module Kalendáře switched on) when they are missing.
 * The module is an admin-only preview, so it is written straight into `project.modules` instead
 * of going through the UI. Skipped with E2E_NO_FIXTURE=1 (then USER_EMAIL / USER_PASS / PROJECT_ID
 * must point at an existing account and a project of it that has the module).
 */
function ensureFixture() {
    if (process.env.E2E_NO_FIXTURE === '1') return;
    if (psql(`SELECT count(*) FROM "user" WHERE email = ${quote(config.email)}`) === '0') {
        compose(['web', 'bin/console', 'app:user:register', config.email, config.password]);
    }
    psql(`
        INSERT INTO project (id, created_at, name, owner_id, sharing, slug, modules)
        SELECT ${quote(config.projectId)}, now(), ${quote(config.projectName)}, u.id, '[]', ${quote('e2e-kalendare-' + config.projectId.slice(-4))}, '["manuals", "templates", "calendars"]'
        FROM "user" u WHERE u.email = ${quote(config.email)}
        ON CONFLICT (id) DO UPDATE SET modules = '["manuals", "templates", "calendars"]';
    `);
}

/** Removes every calendar of the e2e project (a crashed run may have left some behind). */
function purgeCalendars() {
    if (process.env.E2E_NO_FIXTURE === '1') return;
    psql(`DELETE FROM calendar WHERE project_id = ${quote(config.projectId)}`);
}

async function launch() {
    if (!config.chrome) throw new Error('No Chrome binary found — set CHROME');
    const puppeteer = loadPuppeteer();
    const browser = await puppeteer.launch({ executablePath: config.chrome, headless: config.headless, args: ['--no-sandbox', '--hide-scrollbars', '--window-size=1440,900'] });
    return browser;
}

/** A page with console / page errors collected into `page.problems`. */
async function newPage(browser, viewport = { width: 1440, height: 900 }) {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    page.problems = [];
    // the Symfony web debug toolbar sits over the bottom of the page and swallows real clicks
    await page.evaluateOnNewDocument(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent = '.sf-toolbar, .sf-minitoolbar { display: none !important; }';
            document.head.append(style);
        });
    });
    page.on('pageerror', (error) => page.problems.push(`pageerror: ${error.message || error}`));
    page.on('console', (message) => {
        if (message.type() !== 'error') return;
        const text = message.text();
        // the Symfony web profiler toolbar polls /_wdt; favicon noise is not ours either
        if (/_wdt|favicon/.test(text) || /_wdt|favicon/.test((message.location() || {}).url || '')) return;
        page.problems.push(`console: ${text}`);
    });
    return page;
}

async function login(page) {
    await page.goto(`${config.base}/login`, { waitUntil: 'networkidle2' });
    if (await page.$('input[name="_username"]')) {
        await page.type('input[name="_username"]', config.email);
        await page.type('input[name="_password"]', config.password);
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')]);
    }
    if (/\/login/.test(page.url())) throw new Error(`Login failed for ${config.email}`);
}

const listUrl = () => `${config.base}/project/${config.projectId}/calendars`;

async function gotoList(page) {
    await page.goto(listUrl(), { waitUntil: 'networkidle2' });
    await page.waitForSelector('[data-controller="calendar-list"][data-cal-ready="1"]', { timeout: 20000 });
}

/** Waits for the workspace of the current page: fonts loaded, first step mounted. */
async function waitReady(page) {
    await page.waitForFunction(() => window.__calendar && document.querySelector('.cal-ws[data-cal-ready="1"]'), { timeout: 30000 });
    await page.evaluate(() => window.__calendar.ready);
}

/** Creates a calendar through the UI (Vytvořit kalendář) and returns its id; the page ends in step 1. */
async function createCalendar(page) {
    await gotoList(page);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('[data-cal-action="create"]')]);
    await waitReady(page);
    const id = await page.evaluate(() => window.location.pathname.split('/').pop());
    assert.match(id, /^[0-9a-f-]{36}$/);
    return id;
}

async function openCalendar(page, id) {
    await page.goto(`${config.base}/calendar/${id}`, { waitUntil: 'networkidle2' });
    await waitReady(page);
}

async function reload(page) {
    await page.reload({ waitUntil: 'networkidle2' });
    await waitReady(page);
}

/** Evaluates `fn(window.__calendar, ...args)` in the page. */
function cal(page, fn, ...args) {
    return page.evaluate(`(${fn.toString()})(window.__calendar, ...${JSON.stringify(args)})`);
}

const state = (page) => cal(page, (c) => c.state());
const boxes = (page) => cal(page, (c) => c.boxes());

/**
 * POSTs a payload (§C.5) to the save URL with the page's CSRF token, then reloads — for content
 * the UI under test cannot create yet. Returns the server's answer.
 */
async function seed(page, payload) {
    const answer = await page.evaluate(async (body) => {
        const boot = JSON.parse(document.querySelector('[data-calendar-workspace-target="bootstrap"]').textContent);
        const form = new FormData();
        form.append('_token', boot.env.csrf);
        form.append('payload', JSON.stringify(body));
        const response = await fetch(boot.env.urls.save, { method: 'POST', body: form, credentials: 'same-origin' });
        return { status: response.status, json: await response.json().catch(() => null) };
    }, payload);
    assert.equal(answer.status, 200, `seed failed: ${JSON.stringify(answer.json)}`);
    await reload(page);
    return answer.json;
}

async function waitStatus(page, status, timeout = 10000) {
    await page.waitForSelector(`[data-cal-status="${status}"]`, { timeout });
}

/** Explicit save through the Uložit button; resolves once the status reads "Vše uloženo". */
async function save(page) {
    await page.click('[data-cal-action="save"]');
    await waitStatus(page, 'clean');
    await page.waitForNetworkIdle({ idleTime: 150, timeout: 10000 });
}

/** Clicks a step of the top bar and waits until its module is mounted. */
async function gotoStep(page, n) {
    await page.click(`[data-cal-step="${n}"]`);
    await page.waitForSelector(`.cal-ws[data-cal-shown-step="${n}"]`, { timeout: 10000 });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Drags with the real mouse between two trim-relative points in mm (through `toClient`).
 * `hold: true` leaves the button down (sample `boxes()` mid-drag, then `page.mouse.up()`).
 */
async function dragMm(page, fromMm, toMm, { steps = 12, alt = false, hold = false } = {}) {
    const from = await cal(page, (c, p) => c.toClient(p.x, p.y), fromMm);
    const to = await cal(page, (c, p) => c.toClient(p.x, p.y), toMm);
    if (alt) await page.keyboard.down('Alt');
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(to.x, to.y, { steps });
    if (!hold) {
        await page.mouse.up();
        if (alt) await page.keyboard.up('Alt');
    }
    await sleep(50);
}

/** Drags a handle (`n` … `nw`) of the selected element by a distance in mm. */
async function dragHandle(page, handle, dxMm, dyMm, opts = {}) {
    const el = await page.$(`[data-handle="${handle}"]`);
    assert.ok(el, `handle ${handle} is not on the page`);
    const box = await el.boundingBox();
    const scale = await cal(page, (c) => c.scale());
    const from = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    if (opts.alt) await page.keyboard.down('Alt');
    await page.mouse.move(from.x, from.y);
    await page.mouse.down();
    await page.mouse.move(from.x + dxMm * scale, from.y + dyMm * scale, { steps: opts.steps || 12 });
    if (!opts.hold) {
        await page.mouse.up();
        if (opts.alt) await page.keyboard.up('Alt');
    }
    await sleep(50);
}

function expectBox(actual, expected, tolMm = 0.11, label = 'box') {
    assert.ok(actual, `${label}: box is missing`);
    for (const key of ['x', 'y', 'w', 'h']) {
        if (expected[key] === undefined) continue;
        assert.ok(Math.abs(actual[key] - expected[key]) <= tolMm, `${label}.${key}: expected ${expected[key]} ± ${tolMm}, got ${actual[key]}`);
    }
}

/** Deletes a calendar through the list (trash → confirmation → Smazat). */
async function deleteCalendar(page, id) {
    await gotoList(page);
    if (!(await page.$(`.cal-card[data-calendar-id="${id}"]`))) return false;
    await page.click(`.cal-card[data-calendar-id="${id}"] .cal-del`);
    await page.waitForSelector(`#cal-delete-${id}.show`, { timeout: 5000 });
    await sleep(350);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click(`#cal-delete-${id} button[type="submit"]`)]);
    return true;
}

/** Text of the toasts currently on the page. */
const toasts = (page) => page.$$eval('.toast-n', (nodes) => nodes.map((n) => n.textContent.trim()));

async function waitToast(page, pattern, timeout = 8000) {
    await page.waitForFunction((source) => [...document.querySelectorAll('.toast-n')].some((n) => new RegExp(source).test(n.textContent)), { timeout }, pattern instanceof RegExp ? pattern.source : String(pattern));
}

/** Waits for a `.cal-dialog` (choice / confirm) and clicks one of its `data-cal-choice` buttons. */
async function answerDialog(page, choice) {
    await page.waitForSelector(`.cal-dialog.show [data-cal-choice="${choice}"]`, { timeout: 5000 });
    await sleep(350);
    await page.click(`.cal-dialog.show [data-cal-choice="${choice}"]`);
    await page.waitForFunction(() => !document.querySelector('.cal-dialog'), { timeout: 5000 });
}

async function shot(page, name) {
    if (!config.shots) return;
    fs.mkdirSync(config.shots, { recursive: true });
    await page.screenshot({ path: path.join(config.shots, `${name}.png`) });
}

module.exports = {
    assert,
    config,
    ensureFixture,
    purgeCalendars,
    launch,
    newPage,
    login,
    listUrl,
    gotoList,
    waitReady,
    createCalendar,
    openCalendar,
    reload,
    cal,
    state,
    boxes,
    seed,
    save,
    waitStatus,
    gotoStep,
    dragMm,
    dragHandle,
    expectBox,
    deleteCalendar,
    toasts,
    waitToast,
    answerDialog,
    sleep,
    shot,
};
