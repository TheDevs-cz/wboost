/*
 * Node harness for the editor action "Sloučit do opakovaného řádku"
 * (assets/controllers/container_row_merge.js, container-rows plan P6) — used
 * by tests/ContainerFixtures.php::merged() so the PHP suite can prove what the
 * EDITOR would save: the merged design renders like the hand-numbered one,
 * old export versions restore into the right rows, API callers' old ids still
 * land.
 *
 * It runs the very module the editor runs, on the stored canvas (objects are
 * the stored POJOs, geometry = their designed frames — what the editor sees
 * with no selection active). Input (stdin, JSON):
 *   { merge: "<container_row_merge.js>", canvas: {objects, containers},
 *     selectedIds: [inputId, …], rowId: "<id for a new row container>",
 *     max: <int|null> }
 * Output (stdout, JSON): { plan, containers?, rowId? } — `containers` /
 * `rowId` only when the plan succeeded (applyRowMerge's result).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));

(async () => {
    // An ES module with a .js name and no package.json "type" here — import
    // a .mjs copy (same trick as rows-editor-harness.cjs).
    const copy = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'merge-')), 'container_row_merge.mjs');
    fs.copyFileSync(input.merge, copy);
    const merge = await import(pathToFileURL(copy).href);

    const canvas = input.canvas || {};
    const plan = merge.planRowMerge({
        objects: canvas.objects || [],
        containers: canvas.containers || [],
        selectedIds: input.selectedIds || [],
    });

    const out = { plan };
    if (plan.ok) {
        const applied = merge.applyRowMerge(canvas.containers || [], plan, {
            rowId: input.rowId,
            max: typeof input.max === 'number' ? input.max : plan.count,
        });
        out.containers = applied.containers;
        out.rowId = applied.rowId;
    }
    process.stdout.write(JSON.stringify(out));
})().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error));
    process.exit(1);
});
