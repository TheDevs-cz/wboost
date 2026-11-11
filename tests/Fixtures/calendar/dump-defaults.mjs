// Prints the calendar's JS defaults that PHP contract tests pin (tests/Calendar/CalendarSeedContractTest.php).
// stdin: `{ "root": "<repo>", "seed": <CalendarContentSeed::initial()> }`
// stdout: `{ builtinWaste, clientCollections, collections, normalizedSeed }` or `{ "skip": "reason" }`.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const input = JSON.parse(readFileSync(0, 'utf8'));
const [major, minor] = process.versions.node.split('.').map(Number);

if (major < 22 || (major === 22 && minor < 7)) {
    process.stdout.write(JSON.stringify({ skip: `node ${process.versions.node} is older than 22.7 (ES module syntax detection)` }));
} else {
    const load = (path) => import(pathToFileURL(join(input.root, 'assets/calendar', path)).href);
    const { BUILTIN_WASTE } = await load('model/defaults.js');
    const { COLLECTIONS } = await load('model/content.js');
    const { normalizeContent } = await load('model/normalize.js');
    process.stdout.write(JSON.stringify({
        builtinWaste: BUILTIN_WASTE,
        collections: Object.keys(COLLECTIONS),
        clientCollections: Object.keys(COLLECTIONS).filter((name) => COLLECTIONS[name].clientWritable),
        normalizedSeed: normalizeContent(input.seed || {}),
    }));
}
