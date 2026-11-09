/*
 * Node harness for assets/controllers/fill_state_hash.js — used by
 * tests/Controller/Template/FillStateHashOffsetsTest.php to run the REAL
 * browser module over the fill form's mirrors as a browser exposes them,
 * and to compare its number format with the PHP twin.
 *
 * Input (stdin, JSON): { module: "<path to fill_state_hash.js>",
 *   mirrors: [{ attributes: {name: value, …}, value: string, checked: bool }, …],
 *   numbers: [float, …] }
 * Output (stdout, JSON): { hash: string, numbers: [string, …] }
 *
 * The module is an ES module inside a non-"type: module" tree, so it is
 * imported from a data: URL of its source (no copy on disk needed).
 */
import { readFileSync } from 'node:fs';

const input = JSON.parse(readFileSync(0, 'utf8'));
const source = readFileSync(input.module, 'utf8');
const module = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

// The module only uses attribute-presence selectors ("[data-text-mirror]").
const mirrors = (input.mirrors || []).map((mirror) => ({
    value: mirror.value,
    checked: mirror.checked === true,
    getAttribute: (name) => (Object.prototype.hasOwnProperty.call(mirror.attributes, name) ? mirror.attributes[name] : null),
}));
const root = {
    querySelectorAll(selector) {
        const match = /^\[([a-z0-9-]+)\]$/.exec(selector);
        if (!match) throw new Error(`unsupported selector ${selector}`);
        return mirrors.filter((mirror) => mirror.getAttribute(match[1]) !== null);
    },
};

process.stdout.write(JSON.stringify({
    hash: module.fillStateHash(root),
    numbers: (input.numbers || []).map((value) => module.hashNumber(value)),
}));
