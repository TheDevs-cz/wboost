// Editor stage — STUB of phase P3, owned by P4 (architecture §D.6).
//
// What the stub does: draws the sample page (bleed, `placeholders: 'all'`) at the fit scale,
// selects the element under a click through `hitTest`, outlines the selected box, feeds
// `mountPartsOverlay` and registers the stage probe. No dragging, resizing, snapping or zoom.
//
// Contract P4 keeps:
//   `mountStage(root, ctx, opts: { embedded: boolean }): { destroy() }`
//   - the canvas is `.cal-cv` inside `.cal-stage` (§D.10); the overlay root is `div.cal-ovl`, its
//     origin is the TRIM corner and its coordinates are `mm × scale` px
//   - after every render it calls `partsOverlay.update(geometry, scale, selection)` (§D.2)
//   - while mounted it registers `ctx.setStageProbe({ scale(), toClient(xMm, yMm), page() })` and
//     clears it with `ctx.setStageProbe(null)` on destroy — `window.__calendar.scale()`,
//     `.toClient()` and `.geometry()` read the probe

import { bleedOf } from '../../model/defaults.js';
import { boxKeysOf, elementLabel } from '../../model/elements.js';
import { samplePage } from '../../generate/pages.js';
import { renderPage } from '../../render/page.js';
import { hitTest } from '../../editor/geometry.js';
import { fitScale } from '../../editor/zoom.js';
import { h } from '../dom.js';
import { mountPartsOverlay } from './parts-overlay.js';

export function mountStage(root, ctx, opts = {}) {
    const { store } = ctx;
    const page = h('div', { class: 'cal-page-svg' });
    const overlay = h('div', { class: 'cal-ovl' });
    const canvas = h('div', { class: 'cal-cv' }, page, overlay);
    const stage = h(
        'div',
        { class: `cal-stage${opts.embedded ? ' embedded' : ''}`, 'data-cal-stub': 'P4' },
        h('div', { class: 'cal-stage-bar' }, h('span', { class: 'cap' }, 'Vzorová strana · styl platí pro všechny strany')),
        h('div', { class: 'cal-cvw' }, canvas),
    );
    root.replaceChildren(stage);
    const partsOverlay = mountPartsOverlay(overlay, ctx);

    let scale = 0;
    let sample = null;
    let frame = 0;

    function render() {
        frame = 0;
        const state = store.getState();
        const { design } = state.doc;
        if (!design.layout) return;
        const B = bleedOf(design.print);
        sample = samplePage(state.doc, store.pages(), state.ui.selection);
        scale = fitScale(stage.clientWidth, stage.clientHeight, design.page, B) * state.ui.zoom;
        canvas.style.width = `${(design.page.width + 2 * B) * scale}px`;
        canvas.style.height = `${(design.page.height + 2 * B) * scale}px`;
        overlay.style.left = `${B * scale}px`;
        overlay.style.top = `${B * scale}px`;
        page.innerHTML = sample ? renderPage(state.doc, sample, { ...store.layoutOpts(), idPrefix: 'stage', bleed: true, placeholders: 'all' }) : '';

        for (const old of overlay.querySelectorAll('.cal-sel')) old.remove();
        const selection = state.ui.selection;
        if (selection && selection.el !== 'bg') {
            const keys = boxKeysOf(selection.el, design);
            const box = design.layout.boxes[keys[selection.frame || 0] || keys[0]];
            if (box) {
                overlay.append(
                    h('div', { class: 'cal-sel', style: { left: `${box.x * scale}px`, top: `${box.y * scale}px`, width: `${box.w * scale}px`, height: `${box.h * scale}px` } }, h('span', { class: 'cal-tag', 'data-frame-tag': '' }, elementLabel(selection.el))),
                );
            }
        }
        partsOverlay.update(sample ? store.geometry(sample) : null, scale, selection);
    }

    const schedule = () => {
        if (!frame) frame = requestAnimationFrame(render);
    };

    function toClient(xMm, yMm) {
        const rect = overlay.getBoundingClientRect();
        return { x: rect.left + xMm * scale, y: rect.top + yMm * scale };
    }

    canvas.addEventListener('click', (event) => {
        if (!scale) return;
        const rect = overlay.getBoundingClientRect();
        const pt = { x: (event.clientX - rect.left) / scale, y: (event.clientY - rect.top) / scale };
        const state = store.getState();
        store.setUi({ selection: hitTest(pt, state.doc.design, state.ui.selection) });
    });

    const unsubscribe = store.subscribe((event) => {
        if (event.type !== 'status') schedule();
    });
    const resize = new ResizeObserver(schedule);
    resize.observe(stage);
    ctx.setStageProbe({ scale: () => scale, toClient, page: () => sample });
    render();

    return {
        destroy() {
            unsubscribe();
            resize.disconnect();
            if (frame) cancelAnimationFrame(frame);
            partsOverlay.destroy();
            ctx.setStageProbe(null);
            root.replaceChildren();
        },
    };
}
