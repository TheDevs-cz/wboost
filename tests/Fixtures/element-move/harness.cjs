/*
 * Node harness for the shared "Uživatel může posunout" core
 * (assets/editor/element_move.js) — used by tests/Value/ElementMoveParityTest.php
 * to prove the classic script computes exactly what the PHP twins
 * (ElementMoveAllowance + ElementOffsets) compute.
 *
 * Input (stdin, JSON): { module: "<path to element_move.js>", cases: {
 *   normalize: [raw, …],
 *   scale:     [{allowance, rx, ry}, …],
 *   bounds:    [{allowance, frame, width, height}, …],
 *   clamp:     [{allowance, x, y, frame, width, height}, …],
 *   bake:      [{canvas, offsets}, …] } }
 * Output (stdout, JSON): the same keys, one result per case.
 */
'use strict';

const fs = require('fs');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
global.window = global;
require(input.module);
const core = global.WBoostElementMove;
const cases = input.cases || {};

process.stdout.write(JSON.stringify({
    normalize: (cases.normalize || []).map((raw) => core.normalize(raw)),
    scale: (cases.scale || []).map((c) => core.scale(c.allowance, c.rx, c.ry)),
    bounds: (cases.bounds || []).map((c) => core.bounds(c.allowance, c.frame, c.width, c.height)),
    clamp: (cases.clamp || []).map((c) => core.clamp(c.allowance, c.x, c.y, c.frame, c.width, c.height)),
    bake: (cases.bake || []).map((c) => core.bake(c.canvas, c.offsets)),
}));
