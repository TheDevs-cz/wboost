// Step 4 — Obsah (handoff §4.6, architecture §D.7) — STUB of phase P3, owned by P6a.
//
// What the stub does: the tool row, the page list, the selected page (or the style editor) in the
// middle and a placeholder panel on the right — enough to move around and to open the tools.
//
// Contract (final): `mountStep4(root, ctx): { destroy() }`
//   - `ui.contentView` decides the view: `'style'` → `mountEditor(slots, ctx, { embedded: true })`
//     (centre = stage, right aside = layers + props); `'special'` → `mountSpecialPanel`;
//     a number → that page with `mountPagePanel(root, ctx, { pageIndex })`
//   - the tool row is `mountToolbar(row, ctx, { step: 4 })` + `warningChip` (jumps to the next
//     page with a warning)
//   - a page shown here registers `ctx.setStageProbe(…)` like the editor stage does

import { renderPage } from '../render/page.js';
import { h } from './dom.js';
import { mountEditor } from './editor/index.js';
import { mountPageList } from './pagelist.js';
import { mountPagePanel } from './pagepanel.js';
import { mountSpecialPanel } from './tools/special.js';
import { mountToolbar } from './tools/index.js';
import { warningChip } from './warnings.js';

export function mountStep4(root, ctx) {
    const { store } = ctx;
    const row = h('div', { class: 'cal-t4' });
    const side = h('aside', { class: 'cal-b5-list' });
    const centre = h('section', { class: 'cal-b5-main' });
    const right = h('aside', { class: 'cal-b5-side', 'aria-label': 'Úpravy' });
    root.replaceChildren(h('div', { class: 'cal-b5', 'data-cal-stub': 'P6a' }, row, side, centre, right));

    const toolbar = mountToolbar(row, ctx, { step: 4 });
    const chip = warningChip(ctx, {
        onClick: () => {
            const indexes = [...store.warnings().keys()].sort((a, b) => a - b);
            if (!indexes.length) return;
            const current = store.getState().ui.contentView;
            const next = indexes.find((i) => typeof current === 'number' && i > current);
            store.setUi({ contentView: next === undefined ? indexes[0] : next });
        },
    });
    row.append(h('span', { class: 'cal-top-sp' }), chip.el);

    const view = () => store.getState().ui.contentView;
    const styleButton = h('button', { type: 'button', class: 'cal-th-style', 'data-content-view': 'style', onClick: () => store.setUi({ contentView: 'style' }) }, 'Styl stránek');
    const specialButton = h('button', { type: 'button', class: 'cal-th-style', 'data-content-view': 'special', onClick: () => store.setUi({ contentView: 'special' }) }, 'Speciální stránky');
    side.append(styleButton, specialButton);
    const list = mountPageList(side, ctx, { selected: () => (typeof view() === 'number' ? view() : null), onSelect: (index) => store.setUi({ contentView: index }) });

    let mounted = [];
    let shown = null;

    function drawPage() {
        const state = store.getState();
        const page = store.pages()[view()];
        centre.querySelector('.cal-page-svg').innerHTML = page ? renderPage(state.doc, page, { ...store.layoutOpts(), idPrefix: 'p4', placeholders: 'frames' }) : '';
    }

    function show() {
        for (const part of mounted) part.destroy();
        mounted = [];
        shown = view();
        styleButton.classList.toggle('on', shown === 'style');
        specialButton.classList.toggle('on', shown === 'special');
        list.refresh();
        if (shown === 'style') {
            const layers = h('div', { class: 'cal-b5-layers' });
            const props = h('div', { class: 'cal-b5-props' });
            right.replaceChildren(layers, props);
            centre.replaceChildren();
            mounted.push(mountEditor({ layers, stage: centre, props }, ctx, { embedded: true }));
            return;
        }
        centre.replaceChildren(h('div', { class: 'cal-m4p' }, h('div', { class: 'cal-page-svg' })));
        right.replaceChildren();
        if (shown === 'special') {
            mounted.push(mountSpecialPanel(right, ctx));
            return;
        }
        mounted.push(mountPagePanel(right, ctx, { pageIndex: shown }));
        drawPage();
    }

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status') return;
        if (view() !== shown) show();
        else if (typeof shown === 'number' && !(event.type === 'layout' && event.live)) drawPage();
    });
    show();

    return {
        destroy() {
            unsubscribe();
            for (const part of mounted) part.destroy();
            list.destroy();
            chip.destroy();
            toolbar.destroy();
            root.replaceChildren();
        },
    };
}
