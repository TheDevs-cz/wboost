/*
 * Node harness for the admin editor's repeatable-row geometry
 * (assets/controllers/container_rows.js) — used by
 * tests/Golden/ContainerRowsEditorGeometryTest.php to pin that the editor's
 * "fits N rows" (the ⚙ prefill, the ghost outlines, the overflow warning)
 * agrees with the server's RepeatableRows::designedCapacity().
 *
 * It feeds the AUTHORED (unexpanded) design the way the editor sees it: the
 * geometry POJOs of FillTextPlaceholders::layoutData() (texts + decorations
 * at their designed frames, like layout-harness.cjs), runs the shared engine
 * once (the editor's normalize pass — identity on a designed canvas) and asks
 * container_rows.js for the row's capacity. Input (stdin, JSON):
 *   { engine: "<container_layout.js>", rows: "<container_rows.js>",
 *     layout: <layoutData()>, rowIds: [id, …] }
 * Output (stdout, JSON): rowId → { fits, pitch, gap } (null = not a row).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
global.window = global;
require(input.engine);
const engine = global.WBoostContainerLayout;

(async () => {
    // container_rows.js is an ES module with a .js name and no package.json
    // "type" here — import a .mjs copy.
    const copy = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'rows-')), 'container_rows.mjs');
    fs.copyFileSync(input.rows, copy);
    const rows = await import(pathToFileURL(copy).href);

    const layout = input.layout;
    const pojos = [];
    Object.keys(layout.inputs).forEach((inputId) => {
        const def = layout.inputs[inputId];
        if (!def.frame) return;
        pojos.push({
            type: 'textbox', inputId,
            top: def.frame.y, left: def.frame.x, width: def.frame.width, height: def.frame.height,
            scaleX: 1, scaleY: 1, visible: true,
        });
    });
    Object.keys(layout.decorations || {}).forEach((inputId) => {
        const frame = layout.decorations[inputId].frame;
        pojos.push({
            type: 'image', inputId,
            imagePlaceholder: layout.decorations[inputId].slot === true,
            top: frame.y, left: frame.x, width: frame.width, height: frame.height,
            scaleX: 1, scaleY: 1, visible: true,
        });
    });

    const containers = layout.containers || [];
    const canvasHeight = typeof layout.canvasHeight === 'number' ? layout.canvasHeight : null;
    const prepared = engine.prepareFabricContainers(pojos, containers, { canvasHeight });
    const results = engine.applyFabricLayout(prepared);

    const out = {};
    (input.rowIds || []).forEach((rowId) => {
        const capacity = rows.rowCapacity(pojos, containers, rowId, { layout: engine, results, canvasHeight });
        out[rowId] = capacity
            ? {
                fits: capacity.fits,
                pitch: Math.round(capacity.geometry.pitch * 1000) / 1000,
                gap: Math.round(capacity.geometry.gap * 1000) / 1000,
            }
            : null;
    });
    process.stdout.write(JSON.stringify(out));
})().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error));
    process.exit(1);
});
