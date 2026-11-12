/*
 * Shared infrastructure of the AI scan end-to-end scripts (docs/architecture/ai-scan.md § "UI",
 * tests/e2e/ai-scan/README.md) — the conventions of tests/e2e/calendar: puppeteer-core drives a
 * real Chrome against the running dev stack, scripts click and type like a user and assert on
 * what the page shows, plus the read-only hook `window.__aiScan` (the page's ScanApp).
 *
 * No model is called: lib/fake-scanner.cjs plays the scanner over the real scanner API.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert/strict');
const { execFileSync } = require('child_process');
const { createRequire } = require('module');
const fake = require('./fake-scanner.cjs');
const { posterPng } = require('./poster.cjs');

const ROOT = path.resolve(__dirname, '../../../..');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function findChrome() {
    if (process.env.CHROME) return process.env.CHROME;
    const cache = path.join(os.homedir(), '.cache/puppeteer/chrome');
    const builds = fs.existsSync(cache) ? fs.readdirSync(cache).sort().reverse() : [];
    return [
        ...builds.map((build) => path.join(cache, build, 'chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing')),
        ...builds.map((build) => path.join(cache, build, 'chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing')),
        ...builds.map((build) => path.join(cache, build, 'chrome-linux64/chrome')),
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
    ].find((candidate) => fs.existsSync(candidate)) || null;
}

const config = {
    base: (process.env.BASE || 'http://localhost:8099').replace(/\/$/, ''),
    puppeteerDir: process.env.PUPPETEER_DIR || path.join(os.homedir(), '.cache/wboost-e2e'),
    chrome: findChrome(),
    // The editor of the entitled project — a plain user, it sees no other project.
    email: process.env.USER_EMAIL || 'e2e-aiscan@test.cz',
    password: process.env.USER_PASS || 'E2eAiScan123',
    projectId: process.env.PROJECT_ID || '0e2ea15c-0000-7000-8000-000000000001',
    projectName: 'E2E AI sken',
    // A second plain user with a project WITHOUT AI scan: the locked card and the access request.
    lockedEmail: 'e2e-aiscan-locked@test.cz',
    lockedProjectId: '0e2ea15c-0000-7000-8000-000000000002',
    lockedProjectName: 'E2E AI sken — zamčeno',
    headless: process.env.HEADFUL === '1' ? false : 'new',
    shots: process.env.SHOTS || null,
    mailer: (process.env.MAILER || 'http://localhost:8027').replace(/\/$/, ''),
    tmp: fs.mkdtempSync(path.join(os.tmpdir(), 'wboost-aiscan-e2e-')),
};

function compose(args, input) {
    return execFileSync('docker', ['compose', 'exec', '-T', ...args], { cwd: ROOT, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
}
function psql(sql) {
    return compose(['postgres', 'psql', '-U', 'postgres', '-d', 'wboost', '-v', 'ON_ERROR_STOP=1', '-At'], sql).trim();
}
const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;
const consoleCommand = (...args) => compose(['web', 'bin/console', ...args]);

/**
 * The two users and two projects, AI scan switched on for the first project only, the mode
 * `on` for everybody (not only admins) and generous daily limits. Fonts are uploaded by the
 * first script through the UI (a scan cannot start in a project without a font).
 * Skipped with E2E_NO_FIXTURE=1.
 */
function ensureFixture() {
    if (process.env.E2E_NO_FIXTURE === '1') return;
    for (const email of [config.email, config.lockedEmail]) {
        if (psql(`SELECT count(*) FROM "user" WHERE email = ${quote(email)}`) === '0') consoleCommand('app:user:register', email, config.password);
    }
    const project = (id, name, email, enabled) => psql(`
        INSERT INTO project (id, created_at, name, owner_id, sharing, slug, modules, ai_scan_enabled, ai_scan_monthly_limit)
        SELECT ${quote(id)}, now(), ${quote(name)}, u.id, '[]', ${quote('e2e-ai-sken-' + id.slice(-4))}, '["manuals", "templates"]', ${enabled}, NULL
        FROM "user" u WHERE u.email = ${quote(email)}
        ON CONFLICT (id) DO UPDATE SET ai_scan_enabled = ${enabled}, ai_scan_monthly_limit = NULL, modules = '["manuals", "templates"]';
    `);
    project(config.projectId, config.projectName, config.email, 'true');
    project(config.lockedProjectId, config.lockedProjectName, config.lockedEmail, 'false');
    // A JS / CSS edit leaves stale asset digests in the dev cache (a 404 on one module stops them all).
    if (process.env.E2E_KEEP_CACHE !== '1') {
        compose(['web', 'sh', '-c', 'rm -rf public/assets']);
        consoleCommand('cache:clear');
        execFileSync('curl', ['-s', '-o', '/dev/null', '--max-time', '120', `${config.base}/login`]); // warm it up
    }
    consoleCommand('app:ai-scan:status', '--mode=on', '--admin-only=0', '--max-running=1', '--daily-starts-user=500', '--daily-starts-project=500');
}

/** Removes what earlier runs left: the scans of both projects, their templates and access requests. */
function purge() {
    if (process.env.E2E_NO_FIXTURE === '1') return;
    const ids = `(${quote(config.projectId)}, ${quote(config.lockedProjectId)})`;
    psql(`
        DELETE FROM ai_access_request WHERE project_id IN ${ids};
        UPDATE ai_scan SET result_template_id = NULL, result_variant_id = NULL WHERE project_id IN ${ids};
        DELETE FROM ai_scan WHERE project_id IN ${ids};
        DELETE FROM template WHERE project_id IN ${ids};
        UPDATE "user" SET ai_scan_consent_version = NULL, ai_scan_consent_at = NULL WHERE email IN (${quote(config.email)}, ${quote(config.lockedEmail)});
    `);
}

function loadPuppeteer() {
    try {
        return createRequire(path.join(config.puppeteerDir, 'package.json'))('puppeteer-core');
    } catch (error) {
        throw new Error(`puppeteer-core not found in ${config.puppeteerDir} — see tests/e2e/calendar/README.md "One-time setup" (or set PUPPETEER_DIR)`);
    }
}

async function launch() {
    if (!config.chrome) throw new Error('No Chrome binary found — set CHROME');
    return loadPuppeteer().launch({ executablePath: config.chrome, headless: config.headless, args: ['--no-sandbox', '--hide-scrollbars', '--window-size=1440,900'] });
}

/** A page with console / page errors in `page.problems` and every request URL in `page.requests`. */
async function newPage(browser, viewport = { width: 1440, height: 900 }) {
    const page = await browser.newPage();
    await page.setViewport(viewport);
    page.setDefaultNavigationTimeout(120000); // the first pages after a cache clear are slow
    page.setDefaultTimeout(60000);
    page.problems = [];
    page.requests = [];
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
        if (/_wdt|favicon/.test(text) || /_wdt|favicon/.test((message.location() || {}).url || '')) return;
        page.problems.push(`console: ${text} ${(message.location() || {}).url || ''}`);
    });
    page.on('request', (request) => page.requests.push(`${request.method()} ${request.url().replace(config.base, '')}`));
    return page;
}

async function login(page, email = config.email) {
    // A clean session (switching users): drop the cookies rather than walk through the logout.
    const session = await page.createCDPSession();
    await session.send('Network.clearBrowserCookies');
    await session.detach();
    await page.goto(`${config.base}/login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="_username"]', email);
    await page.type('input[name="_password"]', config.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')]);
    if (/\/login/.test(page.url())) throw new Error(`Login failed for ${email}`);
}

const urls = {
    templates: (projectId = config.projectId) => `${config.base}/project/${projectId}/templates`,
    scanNew: (projectId = config.projectId) => `${config.base}/project/${projectId}/sablony/z-obrazku`,
    scan: (id, projectId = config.projectId) => `${config.base}/project/${projectId}/sablony/z-obrazku/${id}`,
    history: (projectId = config.projectId) => `${config.base}/project/${projectId}/sablony/historie-skenu`,
    fonts: (projectId = config.projectId) => `${config.base}/project/${projectId}/fonts`,
};

// ---- the page under test ---------------------------------------------------------------------

/** The page's ScanApp state: `scan(page, (app) => app.scan.status)`. */
const scan = (page, fn, ...args) => page.evaluate(`(${fn.toString()})(window.__aiScan, ...${JSON.stringify(args)})`);
const view = (page) => page.$eval('.as-page', (el) => el.dataset.view);
async function waitView(page, name, timeout = 30000) {
    await page.waitForFunction((wanted) => { const el = document.querySelector('.as-page'); return el && el.dataset.view === wanted; }, { timeout }, name);
}
/** Waits until no fix is being saved (the review's "Ukládám…" is gone). */
async function settled(page, timeout = 30000) {
    await page.waitForFunction(() => window.__aiScan && window.__aiScan.saving === 0 && !window.__aiScan.busy, { timeout });
}
const text = (page, selector) => page.$eval(selector, (el) => el.innerText.replace(/\s+/g, ' ').trim());
const exists = async (page, selector) => (await page.$(selector)) !== null;
const toasts = (page) => page.$$eval('.toast-n', (nodes) => nodes.map((n) => n.textContent.trim()));
async function waitToast(page, pattern, timeout = 10000) {
    await page.waitForFunction((source) => [...document.querySelectorAll('.toast-n')].some((n) => new RegExp(source).test(n.textContent)), { timeout }, pattern instanceof RegExp ? pattern.source : String(pattern));
}
/** Clicks a control by its `data-k`. */
const press = (page, k) => page.click(`[data-k="${k}"]`);

/** Writes a fresh poster (new bytes every time) and returns its path. */
async function poster(browser, name = 'plakat.png', options = {}) {
    const file = path.join(config.tmp, name);
    const tint = `#${Math.floor(Math.random() * 0xffffff).toString(16).padStart(6, '0')}`;
    fs.writeFileSync(file, await posterPng(browser, { tint, ...options }));
    return file;
}
/** A small picture file for the pictures step. */
async function picture(browser, name, width, height, colour = '#12355b') {
    const page = await browser.newPage();
    await page.setViewport({ width, height });
    await page.setContent(`<body style="margin:0;height:${height}px;background:${colour}"></body>`);
    const file = path.join(config.tmp, name);
    fs.writeFileSync(file, Buffer.from(await page.screenshot({ type: 'png' })));
    await page.close();
    return file;
}

/** Chooses a file on the upload page (S1 → S2). */
async function choose(page, file) {
    const input = await page.waitForSelector('#asFile');
    await input.uploadFile(file);
}

/** Upload → consent (when asked) → start; resolves on the waiting screen with the scan id. */
async function start(page, file) {
    await page.goto(urls.scanNew(), { waitUntil: 'networkidle2' });
    await choose(page, file);
    await waitView(page, 'chosen');
    for (const k of ['consent0', 'consent1']) if (await exists(page, `[data-k="${k}"]`)) await press(page, k);
    await page.waitForFunction(() => !document.querySelector('[data-k="start"]').disabled, { timeout: 8000 });
    await press(page, 'start');
    await page.waitForFunction(() => window.__aiScan && window.__aiScan.scan, { timeout: 20000 });
    return scan(page, (app) => app.scan.id);
}

// ---- the fake scanner ------------------------------------------------------------------------

/** Starts lib/fake-scanner.cjs inside this process; `scanner.options` may be changed at any time. */
function startScanner(overrides = {}) {
    // Only the e2e projects' scans: a real scanner (or somebody's own scan) beside us keeps its work.
    const accepts = fake.projectFilter([config.projectId, config.lockedProjectId]);
    const options = { scenario: 'happy', beatMs: 500, beats: 6, once: false, intakeOnly: false, paused: false, stopped: false, accepts, ...overrides };
    const lines = [];
    const done = fake.serve(options, (line) => lines.push(line)).catch((error) => { lines.push(`ERROR ${error.message}`); options.failed = error; });
    return { options, lines, stop: async () => { options.stopped = true; await done; } };
}

async function shot(page, name) {
    if (!config.shots) return;
    fs.mkdirSync(config.shots, { recursive: true });
    await page.screenshot({ path: path.join(config.shots, `${name}.png`), fullPage: true });
}

/** Messages in the local mail catcher (newest first): `[{subject, to}]`. */
async function mails() {
    const response = await fetch(`${config.mailer}/api/v1/messages`).catch(() => null);
    if (!response || !response.ok) return null;
    const body = await response.json();
    const list = Array.isArray(body) ? body : body.messages || body.items || [];
    return list.map((m) => ({ subject: m.Subject || m.subject || (m.Content && m.Content.Headers && m.Content.Headers.Subject && m.Content.Headers.Subject[0]) || '', raw: JSON.stringify(m) }));
}

module.exports = {
    assert, config, sleep, psql, quote, consoleCommand, ensureFixture, purge, launch, newPage, login, urls,
    scan, view, waitView, settled, text, exists, toasts, waitToast, press, poster, picture, choose, start, startScanner, shot, mails,
};
