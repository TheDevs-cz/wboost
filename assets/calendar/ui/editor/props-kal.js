// Kalendárium panels: the whole-block panel and every part panel — STUB of phase P3, owned by P5b
// (handoff §6.2, map §8.9).
//
// Contract (final): `mountKalProps(root, ctx): { destroy() }` — called by `props.js` (P5a) whenever
// `ui.selection.el === 'cal'`, with a fresh child element; it reads `ui.selection.part` /
// `.frame` itself. `props.js` destroys and re-mounts it on every selection change, so this module
// needs no selection listener of its own.

import { stubNote } from '../stub.js';

export function mountKalProps(root, ctx) {
    void ctx;
    root.replaceChildren(stubNote('P5b', 'Vlastnosti kalendária'));
    return {
        destroy() {
            root.replaceChildren();
        },
    };
}
