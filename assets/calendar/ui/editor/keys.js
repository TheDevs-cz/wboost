// Editor keyboard: arrows nudge, Esc, Ctrl/⌘+Z, Ctrl/⌘+Shift+Z, Ctrl/⌘+Y (architecture §D.6) —
// STUB of phase P3, owned by P4. Ctrl+S is NOT handled here: the workspace controller owns it.
//
// Contract: `mountKeys(ctx): { destroy() }` — listens on `window`, ignores events while focus is in
// `input, select, textarea, [contenteditable]` or a `.modal.show` is open.

export function mountKeys(ctx) {
    void ctx;
    return { destroy() {} };
}
