// Step 5 — Náhled, and the whole client view (handoff §4.7, architecture §D.7). Owner: phase P7a.
// CSS: calendar-preview.css.
//
// Contract: `mountStep5(root, ctx): { destroy() }`
//   - the shown page is `ui.previewPage` (an index into `store.pages()`)
//   - the designer sees the page as it goes to print — bleed (when on) and crop marks (when on);
//     with both switched off in step 1, and always for the client, the page is trimmed.
//     `.cal-b6[data-view="print | trim"]` says which.
//   - registers `ctx.setStageProbe({ scale(), toClient(xMm, yMm), page() })` for the large page
//   - the forward button of the top bar (Dokončit / Uložit vzhled) is NOT part of this module
//   - buttons: `[data-tool]` (`ui/tools/index.js` decides which the role gets),
//     `[data-cal-action="export-pdf"]` (every role — „Stáhnout PDF“, `ui/export.js`),
//     `[data-cal-action="client-link"]` (designer), `[data-cal-action="history"]`,
//     `[data-cal-action="warnings"]` (the chip of `ui/warnings.js`), `page-prev`, `page-next`
//   - everything on the page itself (photo, caption, day buttons, comments) is
//     `ui/step5-overlay.js`; this module gives it the page geometry and the room it needs
//
// DOM:
//   .cal-b6
//     .cal-t4                      tools · Stáhnout PDF · Odkaz pro klienta · N upozornění · Historie
//     section.cal-b6-main
//       .cal-5h                    previous / title · range · i / n / next, photo controls
//       .cal-5stage                the page is fitted into it (width, and height on wide screens)
//         .cal-5w > .cal-page-svg + .cal-5o (overlay; origin = top-left of the SVG)
//     aside.cal-b6-list            thumbnails (`mountPageList`)

import { K, bleedOf } from '../model/defaults.js';
import { pageViewBox, renderPage } from '../render/page.js';
import { h, icon } from './dom.js';
import { openClientLink } from './client-link.js';
import { openExport } from './export.js';
import { openHistory } from './history.js';
import { mountPageList } from './pagelist.js';
import { closePopover } from './popover.js';
import { mountStep5Overlay } from './step5-overlay.js';
import { mountToolbar } from './tools/index.js';
import { openWarnings, warningChip } from './warnings.js';

// room beside the drawn page for the day buttons (plus, warning mark, comment bubble), px
const GUTTER = 88;
const GUTTER_NARROW = 68;
const GUTTER_MIN = 24;
const GUTTER_NONE = 12;
// compact day rows (overlay.isCompact): no plus buttons, only two small marks beside a row
const GUTTER_COMPACT = 40;
const NARROW = 700;
const PAD_Y = 16;

export function mountStep5(root, ctx) {
    const { store, env } = ctx;
    const designer = env.mode === 'designer';

    const row = h('div', { class: 'cal-t4' });
    const picture = h('div', { class: 'cal-page-svg' });
    const overlayRoot = h('div', { class: 'cal-5o' });
    const wrapper = h('div', { class: 'cal-5w' }, picture, overlayRoot);
    const stage = h('div', { class: 'cal-5stage' }, wrapper);
    const title = h('b');
    const range = h('span', { class: 'cap' });
    const counter = h('span', { class: 'cap cal-5n' });
    const go = (delta) => {
        const count = store.pages().length;
        store.setUi({ previewPage: Math.min(count - 1, Math.max(0, store.getState().ui.previewPage + delta)) });
    };
    const prev = h('button', { type: 'button', class: 'btn icon sm btn-ghost', 'aria-label': 'Předchozí strana', 'data-cal-action': 'page-prev', onClick: () => go(-1) }, icon('chev-left', 's16'));
    const next = h('button', { type: 'button', class: 'btn icon sm btn-ghost', 'aria-label': 'Další strana', 'data-cal-action': 'page-next', onClick: () => go(1) }, icon('chev-right', 's16'));
    const controlsSlot = h('div', { class: 'cal-5ctl' });
    // shown while the day rows themselves are the buttons (phones, see step5-overlay.js "compact")
    const tapHint = h('p', { class: 'cap cal-5tap', hidden: true }, icon('plus', 's14'), 'Klepněte na den a přidejte odpad, akci nebo komentář.');
    const head = h('div', { class: 'cal-5h' }, h('div', { class: 'cal-5nav' }, prev, h('span', { class: 'cal-5t', 'aria-live': 'polite' }, title, range, counter), next), controlsSlot, tapHint);
    const centre = h('section', { class: 'cal-b6-main' }, head, stage);
    const side = h('aside', { class: 'cal-b6-list' });
    const frame = h('div', { class: 'cal-b6' }, row, centre, side);
    root.replaceChildren(frame);

    const toolbar = mountToolbar(row, ctx, { step: 5 });
    const chip = warningChip(ctx, { onClick: () => openWarnings(ctx, { onPick: (index) => store.setUi({ previewPage: index }) }) });
    // h() skips the `null` of the client (Element.append would print it)
    row.append(
        h(
            'div',
            { class: 'cal-5acts' },
            // whoever sees the calendar may take it away as a PDF — the client and the read-only member too
            h(
                'button',
                {
                    type: 'button',
                    class: 'btn sm btn-tertiary',
                    'data-cal-action': 'export-pdf',
                    onClick: () => openExport({ name: store.getState().doc.design.name, url: env.urls.export, csrf: env.csrf, toast: ctx.toast, unsaved: { dirty: () => store.isDirty(), save: () => ctx.actions.save() } }),
                },
                icon('download', 's16'),
                'Stáhnout PDF',
            ),
            designer ? h('button', { type: 'button', class: 'btn sm btn-tertiary', 'data-cal-action': 'client-link', onClick: () => openClientLink(ctx) }, icon('link', 's16'), 'Odkaz pro klienta') : null,
            chip.el,
            h('button', { type: 'button', class: 'btn sm btn-ghost', 'data-cal-action': 'history', onClick: () => openHistory({ name: store.getState().doc.design.name, load: () => ctx.api.history() }) }, icon('history', 's16'), 'Historie'),
        ),
    );

    const list = mountPageList(side, ctx, { selected: () => store.getState().ui.previewPage, onSelect: (index) => store.setUi({ previewPage: index }) });
    const overlay = mountStep5Overlay(overlayRoot, ctx, { controls: controlsSlot, picture: () => picture.querySelector('svg') });

    const current = () => store.pages()[store.getState().ui.previewPage] || null;

    /** How the large page is drawn for this role and these print settings. */
    function view() {
        const { design } = store.getState().doc;
        const B = bleedOf(design.print);
        const options = designer ? { marks: design.print.marks === true, bleed: B > 0 } : { marks: false, bleed: false };
        const shown = options.marks || options.bleed ? B : 0;
        return {
            options,
            print: options.marks || options.bleed,
            box: pageViewBox(design, options),
            // the part of the page that is drawn: the bleed box in the print view, else the trim
            area: { x0: -shown, y0: -shown, x1: design.page.width + shown, y1: design.page.height + shown },
        };
    }

    let scale = 0;
    let lastMarkup = null;

    /**
     * Fits the page into the stage: by width, and by height too where the workspace is one screen
     * high. Returns whether the day rows are compact at the fitted size.
     */
    function fit(v, geometry) {
        const width = stage.clientWidth;
        if (!width) return false;
        const narrow = width < NARROW;
        const sides = overlay.outerSides(geometry);
        const stacked = window.matchMedia('(max-width: 960px)').matches;
        const maxByHeight = !stacked && stage.clientHeight > 2 * PAD_Y ? (stage.clientHeight - 2 * PAD_Y) / v.box.h : Infinity;
        // the white margin of the print view (crop marks) already is room for the buttons
        const margin = v.area.x0 - v.box.x;
        let left = 0;
        let right = 0;
        let s = 0;
        for (let pass = 0, inside = 0; pass < 2; pass++) {
            const wide = Math.max(GUTTER_MIN, GUTTER - inside);
            const tight = Math.max(GUTTER_NONE, GUTTER_NARROW - inside);
            left = narrow ? (sides.left ? tight : GUTTER_NONE) : wide;
            right = narrow ? (sides.right ? tight : GUTTER_NONE) : wide;
            s = Math.max(0.2, Math.min(K.FIT_MAX, maxByHeight, (width - left - right) / v.box.w));
            inside = margin * s;
        }
        // rows too low for a button each: the rows become the buttons, the freed gutter goes to
        // the page. Decided at the size the page would have WITH buttons, so it cannot flip back.
        const compact = overlay.isCompact(geometry, s);
        if (compact) {
            const room = Math.max(GUTTER_NONE, GUTTER_COMPACT - margin * s);
            left = sides.left ? room : GUTTER_NONE;
            right = sides.right ? room : GUTTER_NONE;
            s = Math.max(0.2, Math.min(K.FIT_MAX, maxByHeight, (width - left - right) / v.box.w));
        }
        scale = s;
        stage.style.paddingLeft = `${left}px`;
        stage.style.paddingRight = `${right}px`;
        wrapper.style.width = `${Math.round(v.box.w * s * 100) / 100}px`;
        wrapper.style.height = `${Math.round(v.box.h * s * 100) / 100}px`;
        return compact;
    }

    function layout() {
        const page = current();
        const v = view();
        const geometry = page ? store.geometry(page) : null;
        v.compact = fit(v, geometry);
        tapHint.hidden = !v.compact;
        overlay.update(geometry, scale, page, v);
    }

    function draw() {
        const state = store.getState();
        const page = current();
        const v = view();
        const markup = page ? renderPage(state.doc, page, { ...store.layoutOpts(), idPrefix: 'p5', placeholders: 'frames', quality: designer ? 'full' : 'preview', ...v.options }) : '';
        // an unchanged page keeps its DOM (no re-decoding of a print-size photo on unrelated events)
        if (markup !== lastMarkup) {
            picture.innerHTML = markup;
            lastMarkup = markup;
        }
        frame.dataset.view = v.print ? 'print' : 'trim';
        const index = state.ui.previewPage;
        const count = store.pages().length;
        title.textContent = page ? page.title : '';
        range.textContent = page && page.subtitle ? `· ${page.subtitle}` : '';
        counter.textContent = `· ${index + 1} / ${count}`;
        prev.disabled = index <= 0;
        next.disabled = index >= count - 1;
        layout();
    }

    ctx.setStageProbe({
        scale: () => scale,
        toClient(xMm, yMm) {
            const rect = wrapper.getBoundingClientRect();
            const { box } = view();
            return { x: rect.left + (xMm - box.x) * scale, y: rect.top + (yMm - box.y) * scale };
        },
        page: current,
    });

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status' || (event.type === 'layout' && event.live)) return;
        if (event.type === 'ui') {
            if (!event.keys.includes('previewPage')) return;
            draw();
            list.refresh();
            return;
        }
        draw();
    });
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(() => layout()) : null;
    if (observer) observer.observe(stage);
    draw();
    list.refresh();

    return {
        destroy() {
            unsubscribe();
            if (observer) observer.disconnect();
            closePopover();
            ctx.setStageProbe(null);
            overlay.destroy();
            list.destroy();
            chip.destroy();
            toolbar.destroy();
            root.replaceChildren();
        },
    };
}
