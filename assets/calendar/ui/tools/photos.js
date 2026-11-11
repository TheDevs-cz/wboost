// Content tool „Fotky a popisky“ — STUB of phase P3, owned by P6a.
//
// Contract (final): `ui/tools/index.js` opens the large modal (title, Hotovo, Esc), shows the
// "feature is off" notice with Zapnout when the tool's step-1 feature is off, and otherwise calls
// `mountPhotosTool(root, ctx)` with the modal body as `root`. The tool builds its DOM inside `root`,
// edits content only through `ctx.store.putItem` / `removeItem` / `applyOps` (with history labels)
// and returns `{ destroy() }`, which is called when the modal closes or the feature is switched.
// View state that should survive a re-open (tab, month, filter) lives in `ui.toolState` via
// `ctx.store.setUi({ toolState })`.

import { stubNote } from '../stub.js';

export function mountPhotosTool(root, ctx) {
    void ctx;
    root.replaceChildren(stubNote('P6a', 'Fotky a popisky'));
    return {
        destroy() {
            root.replaceChildren();
        },
    };
}
