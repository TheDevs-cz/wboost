/*
 * Node harness for assets/controllers/print_settings_geometry.js, used by
 * tests/Value/PrintSettingsGeometryContractTest.php.
 *
 * Input (stdin, JSON): { module: "<path>", setups: [{width, height, bleed, marks, offset, length}] }
 * Output (stdout, JSON): per setup { layout, info, summary, svg }.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

const input = JSON.parse(fs.readFileSync(0, 'utf8'));

(async () => {
    // An ES module with a .js name and no package.json "type" here.
    const copy = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'print-settings-')), 'geometry.mjs');
    fs.copyFileSync(input.module, copy);
    const geometry = await import(pathToFileURL(copy).href);

    process.stdout.write(JSON.stringify(input.setups.map((setup) => ({
        layout: geometry.computeLayout(setup),
        info: geometry.infoParts(setup),
        summary: geometry.summaryText(setup),
        svg: geometry.previewSvg(setup, 1),
    }))));
})();
