/*
 * Node harness for the admin editor's one-click container plans
 * (assets/controllers/container_follow.js — „Dynamické řádky" and „Navázat
 * pod sebe") — used by tests/Golden/ContainerFollowActionsTest.php to run
 * the REAL module on plain definitions.
 *
 * Input (stdin, JSON):
 *   { module: "<container_follow.js>", op: "rows"|"link"|"targets",
 *     containers: [...], selection: [{inputId, top, bottom, candidate}],
 *     canvasHeight: px, containerId?: id (targets) }
 * Output (stdout, JSON): { ok, reason?, mode?, result?, containers } —
 * `containers` after apply() (ids minted as "new-1", "new-2", …), or for
 * "targets" { ids: [...] }.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));

(async () => {
    const copy = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'follow-')), 'container_follow.mjs');
    fs.copyFileSync(input.module, copy);
    const mod = await import(pathToFileURL(copy).href);

    let counter = 0;
    const newId = () => `new-${++counter}`;
    const containers = input.containers || [];

    if (input.op === 'targets') {
        const container = containers.find((c) => c.id === input.containerId);
        process.stdout.write(JSON.stringify({ ids: mod.followTargets(containers, container).map((c) => c.id) }));
        return;
    }

    const plan = input.op === 'rows'
        ? mod.planDynamicRows({ containers, selection: input.selection, canvasHeight: input.canvasHeight, newId })
        : mod.planLink({ containers, selection: input.selection, newId });
    if (!plan.ok) {
        process.stdout.write(JSON.stringify({ ok: false, reason: plan.reason }));
        return;
    }
    const result = plan.apply(containers);
    process.stdout.write(JSON.stringify({ ok: true, mode: plan.mode || null, result, containers }));
})().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error));
    process.exit(1);
});
