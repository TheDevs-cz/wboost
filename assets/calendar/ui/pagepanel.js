// Per-page right panel of step 4 (handoff §4.6 "Right panel for a page") — STUB of phase P3, owned
// by P6a.
//
// Contract (final): `mountPagePanel(root, ctx, opts: { pageIndex: number }): { destroy() }` — one
// mount per shown page; `ui/step4.js` destroys and re-mounts it when the page changes.

import { h } from './dom.js';
import { stubNote } from './stub.js';

export function mountPagePanel(root, ctx, opts = {}) {
    const page = ctx.store.pages()[opts.pageIndex];
    root.replaceChildren(h('div', { class: 'cal-panel-h' }, h('h3', null, page ? page.title : 'Strana')), stubNote('P6a', 'Úpravy strany'));
    return {
        destroy() {
            root.replaceChildren();
        },
    };
}
