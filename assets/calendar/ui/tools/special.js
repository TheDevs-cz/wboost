// Speciální stránky — the right-hand panel of step 4 while `ui.contentView === 'special'`
// (handoff §4.6, map §8.11). STUB of phase P3, owned by P6a.
//
// Contract (final): `mountSpecialPanel(root, ctx): { destroy() }`. Called by `ui/step4.js`; edits
// `content.specialPages` through the store and switches the feature with
// `ctx.store.setFeature('specialPages', true)`.

import { stubNote } from '../stub.js';

export function mountSpecialPanel(root, ctx) {
    void ctx;
    root.replaceChildren(stubNote('P6a', 'Speciální stránky'));
    return {
        destroy() {
            root.replaceChildren();
        },
    };
}
