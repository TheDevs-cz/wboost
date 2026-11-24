// Prints the own-layer vocabulary of assets/calendar/model/layers.js for the PHP contract test
// (tests/Calendar/CalendarLayerContractTest.php — the mirror is Services/Calendar/CalendarLayerRules),
// plus one normalised layer per kind that the PHP rules must accept.
// stdin: `{ "root": "<repo>" }`; stdout: `{ kinds, keys, fillKeys, strokeKeys, lineStrokeKeys, pageSets,
// strokeStyles, lineCaps, fits, maxLayers, maxName, maxPages, ranges, samples }` or `{ "skip": "reason" }`.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const input = JSON.parse(readFileSync(0, 'utf8'));
const [major, minor] = process.versions.node.split('.').map(Number);

if (major < 22 || (major === 22 && minor < 7)) {
    process.stdout.write(JSON.stringify({ skip: `node ${process.versions.node} is older than 22.7 (ES module syntax detection)` }));
} else {
    const load = (path) => import(pathToFileURL(join(input.root, 'assets/calendar', path)).href);
    const L = await load('model/layers.js');
    const { LIMITS } = await load('model/defaults.js');
    const range = (key) => [LIMITS[key].min, LIMITS[key].max];
    const samples = {};
    for (const kind of L.LAYER_KINDS) samples[kind] = L.newLayer(kind, '#C84B55');
    process.stdout.write(JSON.stringify({
        kinds: L.LAYER_KINDS,
        keys: L.LAYER_KEYS,
        fillKeys: L.FILL_KEYS,
        strokeKeys: L.STROKE_KEYS,
        lineStrokeKeys: L.LINE_STROKE_KEYS,
        pageSets: L.PAGE_SETS,
        strokeStyles: L.STROKE_STYLES,
        lineCaps: L.LINE_CAPS,
        fits: L.IMAGE_FITS,
        maxLayers: L.MAX_LAYERS,
        maxName: L.LAYER_NAME_MAX,
        maxPages: L.MAX_LAYER_PAGES,
        ranges: { opacity: range('percent'), radius: range('layerRadius'), width: range('layerStroke') },
        samples,
    }));
}
