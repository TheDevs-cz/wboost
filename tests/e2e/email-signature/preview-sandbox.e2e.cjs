#!/usr/bin/env node
/*
 * E-mail signature preview — real-browser check that authored HTML stays inert inside the app:
 *
 *   node tests/e2e/email-signature/preview-sandbox.e2e.cjs
 *
 * A signature template is HTML written by a project's owner (or an admin); a variant's texts are
 * written by anyone the project is shared with. Both end up in the preview of „Varianta podpisu“,
 * which every member and every admin opens. The script plants a template and a variant that carry
 * script (an `onerror` handler, a `<script>`, a `javascript:` link, a `<style>` that restyles the
 * page) and opens the preview as the OTHER person: nothing may run, the app page keeps its look,
 * the preview shows the signature, and the HTML to copy out / download is byte-for-byte the
 * authored one.
 *
 * Setup (puppeteer-core, Chrome, BASE): tests/e2e/calendar/README.md — the harness is shared.
 * Works in its own project with its own two users (never real data) and removes them at the end.
 * SHOTS=<dir> also writes the preview at 390 / 820 / 1440 in both themes.
 */
const path = require('path');
const h = require('../calendar/lib/harness.cjs');

const quote = (text) => `'${String(text).replace(/'/g, "''")}'`;

const OWNER = { email: 'e2e-signature-owner@test.cz', password: 'E2eSignature123' };
const MEMBER = { email: 'e2e-signature-member@test.cz', password: 'E2eSignature123' };
const PROJECT_ID = '0e2ecafe-0000-7000-8000-0000000000e5';
const TEMPLATE_ID = '0e2ecafe-0000-7000-8000-0000000000e6';
const VARIANT_ID = '0e2ecafe-0000-7000-8000-0000000000e7';

// What an owner could store as the template (the editor posts `code` as a plain form field).
const TEMPLATE_CODE = [
    '<html><head><style>',
    'body { background: rgb(255, 0, 0) !important; } .head, .side, nav { display: none !important; }',
    '.sig-name { color: rgb(0, 128, 0); font-weight: bold; }',
    '</style></head><body style="margin: 0;">',
    '<table width="420" cellpadding="0" cellspacing="0" border="0" id="sig"><tbody><tr>',
    '<td style="padding: 8px; font-family: Arial, sans-serif; font-size: 14px;">',
    '<span class="sig-name" id="jmeno" data-text-placeholder>Jméno Příjmení</span><br>',
    'tel. ___telefon___<br>',
    '<a id="sig-link" href="javascript:window.top.__sigLink = 1">web</a>',
    '<img src="x-missing.png" onerror="window.top.__sigTemplateHandler = 1" width="1" height="1">',
    '<script>window.top.__sigScript = 1;</' + 'script>',
    '</td></tr></tbody></table></body></html>',
].join('\n');

// What a member could type into a variant's text.
const VARIANT_VALUE = 'Žaneta Šťastná<img src="x-missing.png" onerror="window.top.__sigValueHandler = 1" width="1" height="1">';

function fixture() {
    for (const user of [OWNER, MEMBER]) {
        if (h.psql(`SELECT count(*) FROM "user" WHERE email = ${quote(user.email)}`) === '0') {
            h.compose(['web', 'bin/console', 'app:user:register', user.email, user.password]);
        }
    }
    h.psql(`
        INSERT INTO project (id, created_at, name, owner_id, sharing, slug, modules)
        SELECT ${quote(PROJECT_ID)}, now(), 'E2E Podpisy', u.id, '[]', 'e2e-podpisy-00e5', '["email_signatures"]'
        FROM "user" u WHERE u.email = ${quote(OWNER.email)}
        ON CONFLICT (id) DO NOTHING;
        INSERT INTO project_share (id, level, shared_at, project_id, user_id)
        SELECT '0e2ecafe-0000-7000-8000-0000000000e8', 'read', now(), ${quote(PROJECT_ID)}, u.id
        FROM "user" u WHERE u.email = ${quote(MEMBER.email)}
        ON CONFLICT DO NOTHING;
        DELETE FROM email_signature_template WHERE project_id = ${quote(PROJECT_ID)};
        INSERT INTO email_signature_template (id, created_at, name, code, project_id, text_inputs, vcard_info)
        VALUES (${quote(TEMPLATE_ID)}, now(), 'E2E podpis', ${quote(TEMPLATE_CODE)}, ${quote(PROJECT_ID)},
                '[{"id": "jmeno", "content": "Jméno Příjmení"}, {"id": "telefon", "content": "+420 000 000 000"}]', '{}');
        INSERT INTO email_signature_variant (id, created_at, name, code, template_id, text_inputs)
        VALUES (${quote(VARIANT_ID)}, now(), 'E2E varianta', '', ${quote(TEMPLATE_ID)},
                ${quote(JSON.stringify({ jmeno: VARIANT_VALUE, telefon: '+420 111 222 333' }))});
    `);
}

function cleanup() {
    h.psql(`
        DELETE FROM project WHERE id = ${quote(PROJECT_ID)};
        DELETE FROM "user" WHERE email IN (${quote(OWNER.email)}, ${quote(MEMBER.email)});
    `);
}

async function loginAs(browser, user) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    page.problems = [];
    page.on('pageerror', (error) => page.problems.push(String(error.message || error)));
    await page.goto(`${h.config.base}/login`, { waitUntil: 'networkidle2' });
    await page.type('input[name="_username"]', user.email);
    await page.type('input[name="_password"]', user.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type="submit"]')]);
    if (/\/login/.test(page.url())) throw new Error(`Login failed for ${user.email}`);
    return page;
}

/** What the preview page looks like from the outside, and what ran. */
async function inspect(page) {
    await page.goto(`${h.config.base}/edit-email-signature-variant/${VARIANT_ID}`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('[data-email-signature-export-target="preview"]');
    await new Promise((resolve) => setTimeout(resolve, 600));
    // a click on the javascript: link, wherever the signature lives
    const frame = page.frames().find((candidate) => candidate !== page.mainFrame() && candidate.parentFrame() === page.mainFrame() && /srcdoc|about:/.test(candidate.url()));
    const scope = frame || page;
    await scope.evaluate(() => { const link = document.querySelector('#sig-link'); if (link) link.click(); }).catch(() => null);
    await new Promise((resolve) => setTimeout(resolve, 300));

    return page.evaluate(() => {
        const preview = document.querySelector('[data-email-signature-export-target="preview"]');
        const iframe = preview.matches('iframe') ? preview : preview.querySelector('iframe');
        const doc = iframe ? iframe.contentDocument : null;
        const root = doc || preview;
        const name = root.querySelector('#jmeno');
        const head = document.querySelector('.head');
        return {
            ran: {
                templateHandler: window.__sigTemplateHandler === 1,
                valueHandler: window.__sigValueHandler === 1,
                script: window.__sigScript === 1,
                javascriptLink: window.__sigLink === 1,
            },
            pageBackground: getComputedStyle(document.body).backgroundColor,
            headVisible: !!head && getComputedStyle(head).display !== 'none',
            sandbox: iframe ? iframe.getAttribute('sandbox') : null,
            csp: doc ? ((doc.querySelector('meta[http-equiv="Content-Security-Policy"]') || {}).content || null) : null,
            nameText: name ? name.textContent : null,
            nameColor: name ? (doc ? doc.defaultView : window).getComputedStyle(name).color : null,
            tableWidth: root.querySelector('#sig') ? Math.round(root.querySelector('#sig').getBoundingClientRect().width) : null,
            frameHeight: iframe ? Math.round(iframe.getBoundingClientRect().height) : null,
            contentHeight: doc ? doc.documentElement.scrollHeight : null,
            code: document.querySelector('[data-email-signature-export-target="codeInput"]').value,
        };
    });
}

(async () => {
    const results = [];
    const check = (name, ok, note = '') => { results.push({ name, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${note ? '  — ' + note : ''}`); };

    cleanup();
    fixture();
    const browser = await h.launch();

    try {
        for (const [who, user, what] of [['member', MEMBER, 'the owner\'s template'], ['owner', OWNER, 'the member\'s variant text']]) {
            const page = await loginAs(browser, user);
            const seen = await inspect(page);

            check(`${who}: no authored script runs (${what})`, !Object.values(seen.ran).some(Boolean), JSON.stringify(seen.ran));
            check(`${who}: the app page keeps its look`, seen.headVisible && seen.pageBackground !== 'rgb(255, 0, 0)', `head visible ${seen.headVisible}, body ${seen.pageBackground}`);
            check(`${who}: the preview is a sandboxed frame without scripts`, seen.sandbox !== null && !/allow-scripts/.test(seen.sandbox), `sandbox="${seen.sandbox}"`);
            check(`${who}: the frame's content has a policy that allows no script`, /script-src 'none'|default-src 'none'/.test(seen.csp || ''), String(seen.csp));
            check(`${who}: the signature is drawn with its own styles`, seen.nameText === 'Žaneta Šťastná' && seen.nameColor === 'rgb(0, 128, 0)' && seen.tableWidth === 420, `${seen.nameText} / ${seen.nameColor} / ${seen.tableWidth}px`);
            check(`${who}: the frame is as tall as the signature`, seen.frameHeight !== null && Math.abs(seen.frameHeight - seen.contentHeight) <= 2 && seen.frameHeight > 20, `${seen.frameHeight} vs ${seen.contentHeight}`);
            check(`${who}: the HTML to copy out is the authored one`, seen.code.includes('onerror="window.top.__sigTemplateHandler = 1"') && seen.code.includes('<script>window.top.__sigScript = 1;</' + 'script>') && seen.code.includes('tel. +420 111 222 333') && !seen.code.includes('Content-Security-Policy') && !seen.code.includes('<base'), `${seen.code.length} characters`);

            // typing still updates the preview and the code — and the body swapped in place stays inert
            await page.type('[data-text-input-id="telefon"]', '9');
            await new Promise((resolve) => setTimeout(resolve, 500));
            const typed = await page.evaluate(() => {
                const preview = document.querySelector('[data-email-signature-export-target="preview"]');
                const iframe = preview.matches('iframe') ? preview : preview.querySelector('iframe');
                const value = document.querySelector('[data-text-input-id="telefon"]').value;
                return {
                    value,
                    preview: ((iframe ? iframe.contentDocument.body : preview).textContent || '').includes('tel. ' + value),
                    code: document.querySelector('[data-email-signature-export-target="codeInput"]').value.includes('tel. ' + value),
                    ran: [window.__sigTemplateHandler, window.__sigValueHandler, window.__sigScript, window.__sigLink].some((marker) => marker === 1),
                    frameHeight: iframe ? Math.round(iframe.getBoundingClientRect().height) : null,
                };
            });
            check(`${who}: typing updates the preview and the code`, typed.value.includes('9') && typed.preview && typed.code, JSON.stringify(typed));
            check(`${who}: still nothing ran after the update`, !typed.ran);
            check(`${who}: no page error`, page.problems.length === 0, page.problems.slice(0, 2).join(' | '));

            if (h.config.shots && who === 'owner') {
                for (const theme of ['dark', 'light']) {
                    for (const width of [390, 820, 1440]) {
                        await page.setViewport({ width, height: 900 });
                        await page.evaluate((value) => { document.documentElement.setAttribute('data-theme', value); }, theme);
                        await new Promise((resolve) => setTimeout(resolve, 400));
                        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
                        check(`preview page at ${width}px (${theme}): no horizontal overflow`, !overflow);
                        await page.screenshot({ path: path.join(h.config.shots, `signature-preview--${width}--${theme}.png`), fullPage: true });
                    }
                }
            }
            await page.browserContext().close();
        }
    } finally {
        await browser.close();
        cleanup();
    }

    const failed = results.filter((row) => !row.ok).length;
    console.log(`\n${results.length - failed} passed, ${failed} failed`);
    process.exit(failed ? 1 : 0);
})().catch((error) => { console.error(error); try { cleanup(); } catch (ignored) { /* nothing to clean */ } process.exit(1); });
