#!/usr/bin/env node
/*
 * Runs the calendar e2e scripts:   node tests/e2e/calendar/run.cjs [filter]
 *
 * Every `*.e2e.cjs` beside this file exports `{ name, checks: ['R4', …], run(ctx) }`.
 * `ctx` = `{ browser, page, h, check(id, fn) }`: `page` is logged in as the e2e user, `h` is
 * lib/harness.cjs and `check` records one named result (a throwing `fn` fails that check and the
 * script goes on). Setup: README.md.
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
    h.purgeCalendars();
    const browser = await h.launch();
    const rows = [];
    for (const file of files) {
        const script = require(path.join(__dirname, file));
        const page = await h.newPage(browser);
        const started = Date.now();
        const record = (id, ok, note = '') => {
            rows.push({ script: script.name || file, id, ok, note, ms: Date.now() - started });
            // E2E_LIVE=1: each result as it happens (the table is printed at the end only)
            if (process.env.E2E_LIVE === '1') console.error(`  ${ok ? 'pass' : 'FAIL'}  ${id}  ${note}`);
        };
        const check = async (id, fn) => {
            try {
                await fn();
                record(id, true);
            } catch (error) {
                record(id, false, String((error && error.message) || error).replace(/\s+/g, ' ').slice(0, 260));
                if (h.config.shots) await h.shot(page, `FAIL-${(script.name || file).replace(/\W+/g, '_')}-${id.replace(/\W+/g, '_')}`).catch(() => null);
            }
        };
        try {
            await h.login(page);
            await script.run({ browser, page, h, check });
            if (page.problems.length) record('console is free of errors', false, page.problems.slice(0, 3).join(' | '));
            else record('console is free of errors', true);
        } catch (error) {
            record('(script aborted)', false, String((error && error.stack) || error).split('\n').slice(0, 2).join(' '));
        }
        await page.close().catch(() => null);
    }
    await browser.close();
    h.purgeCalendars();

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
