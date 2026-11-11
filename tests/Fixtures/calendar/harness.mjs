// Node harness for the calendar's pure ES modules (docs/calendar/architecture.md §F).
//
// PHPUnit (tests/Calendar/CalendarJsTest.php) pipes `{ "root": "<repo>", "suite": "<name>" }` to stdin
// and reads `{ "passed": n, "failures": [{ name, message }] }` — or `{ "skip": "reason" }` — from stdout.
// By hand: `node tests/Fixtures/calendar/harness.mjs --all` or `… <suite> [<suite> …]`.

import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

function nodeIsTooOld() {
    // importing extension-less-typed `.js` ES modules needs syntax detection (node ≥ 22.7)
    const [major, minor] = process.versions.node.split('.').map(Number);
    return major < 22 || (major === 22 && minor < 7);
}

async function runSuite(root, suite) {
    const failures = [];
    let passed = 0;
    const pending = [];
    const t = {
        test(name, fn) {
            pending.push(async () => {
                try {
                    await fn();
                    passed++;
                } catch (error) {
                    failures.push({ name, message: String((error && error.message) || error) });
                }
            });
        },
        root,
    };
    const load = (path) => import(pathToFileURL(join(root, 'assets/calendar', path)).href);
    try {
        const module = await import(pathToFileURL(join(here, 'suites', `${suite}.mjs`)).href);
        await module.default(t, load);
        for (const run of pending) await run();
    } catch (error) {
        failures.push({ name: `${suite} (suite failed to load)`, message: String((error && error.stack) || error) });
    }
    return { passed, failures };
}

async function main() {
    const args = process.argv.slice(2);
    if (args.length || process.stdin.isTTY) {
        const root = resolve(here, '../../..');
        const suites = args.length && !args.includes('--all') ? args : readdirSync(join(here, 'suites')).filter((f) => f.endsWith('.mjs')).map((f) => f.slice(0, -4)).sort();
        if (nodeIsTooOld()) {
            console.log(`skip: node ${process.versions.node} is older than 22.7`);
            return;
        }
        let failed = 0;
        for (const suite of suites) {
            const result = await runSuite(root, suite);
            failed += result.failures.length;
            console.log(`${result.failures.length ? 'FAIL' : 'ok  '} ${suite}: ${result.passed} passed, ${result.failures.length} failed`);
            for (const f of result.failures) console.log(`     ✗ ${f.name}\n       ${f.message.split('\n').join('\n       ')}`);
        }
        process.exitCode = failed ? 1 : 0;
        return;
    }
    const input = JSON.parse(readFileSync(0, 'utf8'));
    if (nodeIsTooOld()) {
        process.stdout.write(JSON.stringify({ skip: `node ${process.versions.node} is older than 22.7 (ES module syntax detection)` }));
        return;
    }
    process.stdout.write(JSON.stringify(await runSuite(input.root, input.suite)));
}

main();
