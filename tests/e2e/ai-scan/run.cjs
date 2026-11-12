#!/usr/bin/env node
/*
 * Runs the AI scan e2e scripts:   node tests/e2e/ai-scan/run.cjs [filter]
 *
 * Every `*.e2e.cjs` beside this file exports `{ name, run(ctx) }`.
 * `ctx` = `{ browser, page, h, scanner, check(id, fn) }`: `page` is logged in as the e2e editor,
 * `h` is lib/harness.cjs, `scanner` the fake scanner (`scanner.options.scenario = 'stop'` …) and
 * `check` records one named result (a throwing `fn` fails that check and the script goes on).
 * Setup and environment: README.md.
 */
const fs = require('fs');
const path = require('path');
const h = require('./lib/harness.cjs');

(async () => {
    const filter = process.argv[2] || '';
    const files = fs.readdirSync(__dirname).filter((file) => file.endsWith('.e2e.cjs') && file.includes(filter)).sort();
    if (!files.length) {
        console.error(`no e2e script matches "${filter}"`);
        process.exit(1);
    }
    h.ensureFixture();
    h.purge();
    const browser = await h.launch();
    const scanner = h.startScanner();
    const rows = [];
    for (const file of files) {
        const script = require(path.join(__dirname, file));
        const page = await h.newPage(browser);
        const record = (id, ok, note = '') => rows.push({ script: script.name || file, id, ok, note });
        const check = async (id, fn) => {
            try {
                await fn();
                record(id, true);
            } catch (error) {
                record(id, false, String((error && error.message) || error).replace(/\s+/g, ' ').slice(0, 300));
                if (h.config.shots) await h.shot(page, `FAIL-${(script.name || file).replace(/\W+/g, '_')}-${id.replace(/\W+/g, '_')}`).catch(() => null);
            }
        };
        Object.assign(scanner.options, { scenario: 'happy', beatMs: 500, beats: 6, paused: false, intakeOnly: false });
        try {
            await h.login(page);
            await script.run({ browser, page, h, scanner, check });
            const problems = page.problems.filter((p) => !(script.allowed || []).some((pattern) => pattern.test(p)));
            if (problems.length) record('console is free of errors', false, problems.slice(0, 3).join(' | '));
            else record('console is free of errors', true);
        } catch (error) {
            record('(script aborted)', false, String((error && error.stack) || error).split('\n').slice(0, 2).join(' '));
        }
        if (scanner.options.failed) record('fake scanner', false, String(scanner.options.failed.message));
        await page.close().catch(() => null);
    }
    await scanner.stop();
    await browser.close();
    if (!process.env.E2E_KEEP) h.purge();

    const width = Math.max(...rows.map((r) => r.id.length), 10);
    let last = '';
    for (const row of rows) {
        if (row.script !== last) console.log(`\n${row.script}`);
        last = row.script;
        console.log(`  ${row.ok ? 'PASS' : 'FAIL'}  ${row.id.padEnd(width)}  ${row.note}`);
    }
    const failed = rows.filter((r) => !r.ok).length;
    console.log(`\n${rows.length - failed} passed, ${failed} failed`);
    process.exit(failed ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
