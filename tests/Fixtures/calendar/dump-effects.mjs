// Prints the layer-effect vocabulary of assets/calendar/model/effects.js for the PHP contract test
// (tests/Calendar/CalendarEffectContractTest.php — the mirror is Services/Calendar/CalendarEffectRules).
// stdin: `{ "root": "<repo>" }`; stdout: `{ kinds, blends, gradientTypes, targets, maxEffects, maxStops, ranges, collection }` or `{ "skip": "reason" }`.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const input = JSON.parse(readFileSync(0, 'utf8'));
const [major, minor] = process.versions.node.split('.').map(Number);

if (major < 22 || (major === 22 && minor < 7)) {
    process.stdout.write(JSON.stringify({ skip: `node ${process.versions.node} is older than 22.7 (ES module syntax detection)` }));
} else {
    const load = (path) => import(pathToFileURL(join(input.root, 'assets/calendar', path)).href);
    const FX = await load('model/effects.js');
    const { LIMITS } = await load('model/defaults.js');
    const { COLLECTIONS } = await load('model/content.js');
    const kinds = {};
    for (const { kind } of FX.EFFECT_KINDS) kinds[kind] = FX.effectKeys(kind);
    const range = (key) => [LIMITS[key].min, LIMITS[key].max];
    process.stdout.write(JSON.stringify({
        kinds,
        blends: FX.BLEND_VALUES,
        gradientTypes: FX.GRADIENT_TYPES,
        targets: FX.PAGE_EFFECT_TARGETS,
        maxEffects: FX.MAX_EFFECTS,
        maxStops: FX.MAX_STOPS,
        ranges: {
            dropShadow: { opacity: range('percent'), angle: range('fxAngle'), distance: range('fxDistance'), spread: range('percent'), size: range('fxSize') },
            innerShadow: { opacity: range('percent'), angle: range('fxAngle'), distance: range('fxDistance'), spread: range('percent'), size: range('fxSize') },
            outerGlow: { opacity: range('percent'), spread: range('percent'), size: range('fxSize') },
            innerGlow: { opacity: range('percent'), spread: range('percent'), size: range('fxSize') },
            gradient: { opacity: range('percent'), angle: [0, 359], scale: range('fxScale'), x: range('fxCentre'), y: range('fxCentre') },
        },
        collection: COLLECTIONS.pageEffects || null,
    }));
}
