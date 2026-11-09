/*
 * Node harness for the fill pages' repeatable-row actions
 * (assets/controllers/fill_rows.js) — used by
 * tests/Services/SocialNetwork/FillRowActionsTest.php to pin what add /
 * remove / ↑ / ↓ do to the fill state the undo history snapshots.
 *
 * Input (stdin, JSON):
 *   { module: "<fill_rows.js>", rows: <FillRepeatableRows payload>,
 *     neutral: {…NEUTRAL_PLACEMENT}, state: {fields, images},
 *     steps: [{row, count, action, slot}] }
 * Output (stdout, JSON): per step { plan: null | {count, sources: [[t, s]]},
 *   state: null | the state after the action, inactive: [ids] (after) }.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));

(async () => {
    // An ES module with a .js name and no package.json "type" here.
    const copy = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'fill-rows-')), 'fill_rows.mjs');
    fs.copyFileSync(input.module, copy);
    const rows = await import(pathToFileURL(copy).href);

    const out = input.steps.map((step) => {
        const row = input.rows[step.row];
        const plan = rows.plan(row, step.count, step.action, step.slot === undefined ? null : step.slot);
        if (!plan) return { plan: null, state: null, inactive: [] };
        const state = rows.applyPlan(input.state, row, plan, {
            pristineImage: (keys, role, value) => rows.pristineImageState(keys, value, input.neutral),
        });
        return {
            plan: { count: plan.count, sources: Array.from(plan.sources.entries()) },
            state,
            inactive: Array.from(rows.inactiveIds(row, plan.count)),
        };
    });

    process.stdout.write(JSON.stringify(out));
})().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error));
    process.exit(1);
});
