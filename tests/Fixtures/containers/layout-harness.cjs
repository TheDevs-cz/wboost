/*
 * Node harness for the shared container layout engine
 * (assets/editor/container_layout.js) — used by
 * tests/Golden/ContainerFixtureLayoutTest.php to pin how the REAL engine lays
 * out the prod container fixtures, without a browser and without fonts.
 *
 * It mirrors the fill overlay's POJO path (variant_fill_overlay_controller
 * `_layoutSurface`): texts + decorations as geometry POJOs at their DESIGNED
 * frames → phase A → the scenario's measured heights + fill-time hides →
 * phase B. Input (stdin, JSON):
 *   { engine: "<path to container_layout.js>",
 *     layout: <FillTextPlaceholders::layoutData()>,
 *     heights: { inputId: px },   // measured heights (missing = designed)
 *     hidden: [inputId, …] }      // fill-time hides (texts + decorations)
 * Output (stdout, JSON): applyFabricLayout's results, rounded to 1/1000 px.
 */
'use strict';

const fs = require('fs');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
global.window = global;
require(input.engine);
const engine = global.WBoostContainerLayout;

const layout = input.layout;
const heights = input.heights || {};
const hidden = new Set(input.hidden || []);

const pojos = [];
const texts = {};
Object.keys(layout.inputs).forEach((inputId) => {
    const def = layout.inputs[inputId];
    if (!def.frame) return;
    const pojo = {
        type: 'textbox', inputId,
        top: def.frame.y, left: def.frame.x, width: def.frame.width, height: def.frame.height,
        scaleX: 1, scaleY: 1, visible: true,
    };
    texts[inputId] = pojo;
    pojos.push(pojo);
});
Object.keys(layout.decorations || {}).forEach((inputId) => {
    const frame = layout.decorations[inputId].frame;
    pojos.push({
        type: 'image', inputId,
        top: frame.y, left: frame.x, width: frame.width, height: frame.height,
        scaleX: 1, scaleY: 1, visible: true,
    });
});

const prepared = engine.prepareFabricContainers(pojos, layout.containers || [], {
    canvasHeight: typeof layout.canvasHeight === 'number' ? layout.canvasHeight : null,
});
pojos.forEach((pojo) => {
    if (texts[pojo.inputId] && typeof heights[pojo.inputId] === 'number') {
        pojo.height = heights[pojo.inputId];
    }
    if (hidden.has(pojo.inputId)) pojo.visible = false;
});

const round = (value) => (typeof value === 'number' ? Math.round(value * 1000) / 1000 : value);
const results = engine.applyFabricLayout(prepared).map((result) => ({
    id: result.id,
    nested: result.nested,
    containerTop: round(result.containerTop),
    contentBottom: round(result.contentBottom),
    overflowPx: round(result.overflowPx),
    textFlow: result.textFlow
        ? result.textFlow.map((entry) => ({ inputId: entry.inputId, top: round(entry.top) }))
        : undefined,
}));

process.stdout.write(JSON.stringify(results));
