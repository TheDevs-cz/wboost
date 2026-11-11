// Step 5 — Náhled, and the whole client view (handoff §4.7, architecture §D.7) — STUB of phase P3,
// owned by P7a.
//
// What the stub does: the tool row (tools, Odkaz pro klienta, warning chip, Historie), one large
// page — with bleed and crop marks for the designer, trimmed for the client — previous / next and
// the page list. No photo controls, pencil, plus buttons or comments yet.
//
// Contract (final): `mountStep5(root, ctx): { destroy() }`
//   - the shown page is `ui.previewPage` (an index into `store.pages()`)
//   - registers `ctx.setStageProbe({ scale(), toClient(xMm, yMm), page() })` for the large page
//   - the forward button of the top bar (Dokončit / Uložit vzhled) is NOT part of this module
//   - buttons: `[data-cal-action="client-link"]` (designer), `[data-cal-action="history"]`,
//     `[data-cal-action="warnings"]` (the chip of `ui/warnings.js`)

import { bleedOf } from '../model/defaults.js';
import { pageViewBox, renderPage } from '../render/page.js';
import { h, icon } from './dom.js';
import { openClientLink } from './client-link.js';
import { openHistory } from './history.js';
import { mountPageList } from './pagelist.js';
import { mountToolbar } from './tools/index.js';
import { openWarnings, warningChip } from './warnings.js';

export function mountStep5(root, ctx) {
    const { store, env } = ctx;
    const designer = env.mode === 'designer';
    const row = h('div', { class: 'cal-t4' });
    const picture = h('div', { class: 'cal-page-svg' });
    const counter = h('span', { class: 'cap cal-5n' });
    const go = (delta) => {
        const count = store.pages().length;
        store.setUi({ previewPage: Math.min(count - 1, Math.max(0, store.getState().ui.previewPage + delta)) });
    };
    const centre = h(
        'section',
        { class: 'cal-b6-main' },
        h('div', { class: 'cal-5w' }, picture),
        h(
            'div',
            { class: 'cal-5nav' },
            h('button', { type: 'button', class: 'btn icon sm btn-tertiary', 'aria-label': 'Předchozí strana', 'data-cal-action': 'page-prev', onClick: () => go(-1) }, icon('chev-left', 's16')),
            counter,
            h('button', { type: 'button', class: 'btn icon sm btn-tertiary', 'aria-label': 'Další strana', 'data-cal-action': 'page-next', onClick: () => go(1) }, icon('chev-right', 's16')),
        ),
    );
    const side = h('aside', { class: 'cal-b6-list' });
    root.replaceChildren(h('div', { class: 'cal-b6', 'data-cal-stub': 'P7a' }, row, centre, side));

    const toolbar = mountToolbar(row, ctx, { step: 5 });
    const chip = warningChip(ctx, { onClick: () => openWarnings(ctx, { onPick: (index) => store.setUi({ previewPage: index }) }) });
    // Element.append() would print a `null` child as the text "null"
    const extras = [
        h('span', { class: 'cal-top-sp' }),
        chip.el,
        designer ? h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-cal-action': 'client-link', onClick: () => openClientLink(ctx) }, icon('link', 's16'), 'Odkaz pro klienta') : null,
        h('button', { type: 'button', class: 'btn sm btn-ghost', 'data-cal-action': 'history', onClick: () => openHistory({ name: store.getState().doc.design.name, load: () => ctx.api.history() }) }, icon('history', 's16'), 'Historie'),
    ];
    row.append(...extras.filter(Boolean));

    const list = mountPageList(side, ctx, { selected: () => store.getState().ui.previewPage, onSelect: (index) => store.setUi({ previewPage: index }) });

    const current = () => store.pages()[store.getState().ui.previewPage] || null;

    function draw() {
        const state = store.getState();
        const { design } = state.doc;
        const page = current();
        const B = bleedOf(design.print);
        const options = designer ? { marks: design.print.marks, bleed: B > 0, quality: 'full' } : {};
        picture.innerHTML = page ? renderPage(state.doc, page, { ...store.layoutOpts(), idPrefix: 'p5', placeholders: 'frames', ...options }) : '';
        counter.textContent = `${state.ui.previewPage + 1} / ${store.pages().length}`;
        list.refresh();
    }

    ctx.setStageProbe({
        scale() {
            const svg = picture.querySelector('svg');
            const { design } = store.getState().doc;
            if (!svg) return 0;
            const box = pageViewBox(design, designer ? { marks: design.print.marks, bleed: bleedOf(design.print) > 0 } : {});
            return svg.getBoundingClientRect().width / box.w;
        },
        toClient(xMm, yMm) {
            const svg = picture.querySelector('svg');
            if (!svg) return { x: 0, y: 0 };
            const { design } = store.getState().doc;
            const box = pageViewBox(design, designer ? { marks: design.print.marks, bleed: bleedOf(design.print) > 0 } : {});
            const rect = svg.getBoundingClientRect();
            const scale = rect.width / box.w;
            return { x: rect.left + (xMm - box.x) * scale, y: rect.top + (yMm - box.y) * scale };
        },
        page: current,
    });

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || (event.type === 'layout' && event.live)) return;
        draw();
    });
    draw();

    return {
        destroy() {
            unsubscribe();
            ctx.setStageProbe(null);
            list.destroy();
            chip.destroy();
            toolbar.destroy();
            root.replaceChildren();
        },
    };
}
