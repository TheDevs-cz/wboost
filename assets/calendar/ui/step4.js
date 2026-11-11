// Step 4 — Obsah (handoff §4.6, architecture §D.7, map §8.10) — phase P6a.
//
//   toolbar            the content tools (`ui/tools/index.js`) + the chip „N upozornění“, which
//                      jumps to the next page that has a warning (wrapping round)
//   left list          Styl stránek · Speciální stránky · plus menu · thumbnails of every page
//   middle             `ui.contentView`:
//                        'style'    the page editor of step 3 (`mountEditor`, embedded)
//                        'special'  the special page chosen in the panel on the right
//                        a number   that page, with previous / next
//                      pages are drawn trimmed with `placeholders: 'frames'`; zoom is `ui.zoom`,
//                      shared with the editor
//   right panel        the editor's layers / properties · Speciální stránky · the page panel
//
// Contract: `mountStep4(root, ctx): { destroy() }`. A page shown in the middle registers
// `ctx.setStageProbe(…)` the way the editor stage does (the editor registers its own).
//
// DOM hooks: `.cal-b5[data-view="style|special|page"]`, `[data-cal-action="page-prev|page-next|
// zoom-in|zoom-out|zoom-fit|style-back"]`, `[data-page-title]`, `.cal-m4c` (the page, sized in px),
// `[data-special-empty]`; the right aside is `aside.cal-b5-side[aria-label="Úpravy"]`.

import { K } from '../model/defaults.js';
import { zoomIn, zoomOut } from '../editor/zoom.js';
import { renderPage } from '../render/page.js';
import { h, icon } from './dom.js';
import { mountEditor } from './editor/index.js';
import { mountPageList } from './pagelist.js';
import { mountPagePanel } from './pagepanel.js';
import { mountSpecialPanel, previewedSpecial } from './tools/special.js';
import { mountToolbar } from './tools/index.js';
import { warningChip } from './warnings.js';

/** Below this width the three columns stack (calendar-content.css) and the page fits by width only. */
const STACKED = '(max-width: 960px)';
const PAGE_PAD = 24;

export function mountStep4(root, ctx) {
    const { store } = ctx;
    const row = h('div', { class: 'cal-t4' });
    const side = h('aside', { class: 'cal-b5-list' });
    const centre = h('section', { class: 'cal-b5-main' });
    const right = h('aside', { class: 'cal-b5-side', 'aria-label': 'Úpravy' });
    const frame = h('div', { class: 'cal-b5 cal-s4' }, row, side, centre, right);
    root.replaceChildren(frame);

    const view = () => store.getState().ui.contentView;
    const pageIndex = () => (typeof view() === 'number' ? view() : null);

    // ---- toolbar -----------------------------------------------------------------------------
    const toolbar = mountToolbar(row, ctx, { step: 4 });
    const chip = warningChip(ctx, {
        onClick: () => {
            const indexes = [...store.warnings().keys()].sort((a, b) => a - b);
            if (!indexes.length) return;
            const current = pageIndex();
            const next = indexes.find((i) => current !== null && i > current);
            store.setUi({ contentView: next === undefined ? indexes[0] : next });
        },
    });
    row.append(h('span', { class: 'cal-top-sp' }), chip.el);

    // ---- left list ---------------------------------------------------------------------------
    const list = mountPageList(side, ctx, {
        selected: pageIndex,
        onSelect: (index) => store.setUi({ contentView: index }),
        views: { current: view, onPick: (next) => store.setUi({ contentView: next }) },
    });

    // ---- the page in the middle (page and special views) -------------------------------------
    let scale = 0;
    let shownPage = null;
    let pageView = null;
    let frameRequest = 0;

    function buildPageView(withNav) {
        const title = h('b', { 'data-page-title': '' });
        const range = h('span', { class: 'cap' });
        const go = (delta) => {
            const index = pageIndex();
            if (index === null) return;
            store.setUi({ contentView: Math.min(store.pages().length - 1, Math.max(0, index + delta)) });
        };
        const prev = withNav ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Předchozí strana', title: 'Předchozí strana', 'data-cal-action': 'page-prev', onClick: () => go(-1) }, icon('chev-left', 's16')) : null;
        const next = withNav ? h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Další strana', title: 'Další strana', 'data-cal-action': 'page-next', onClick: () => go(1) }, icon('chev-right', 's16')) : null;
        const zoomValue = h('button', { type: 'button', class: 'cal-zmv', title: 'Přizpůsobit oknu', 'data-cal-action': 'zoom-fit', onClick: () => store.setUi({ zoom: 1 }) });
        const out = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Oddálit', 'data-cal-action': 'zoom-out', onClick: () => store.setUi({ zoom: zoomOut(store.getState().ui.zoom) }) }, icon('minus', 's16'));
        const into = h('button', { type: 'button', class: 'btn icon xs btn-ghost', 'aria-label': 'Přiblížit', 'data-cal-action': 'zoom-in', onClick: () => store.setUi({ zoom: zoomIn(store.getState().ui.zoom) }) }, icon('plus', 's16'));
        const picture = h('div', { class: 'cal-page-svg' });
        const canvas = h('div', { class: 'cal-m4c' }, picture);
        const empty = h('p', { class: 'cap cal-m4e', 'data-special-empty': '', hidden: true }, 'Zapněte některou speciální stránku vpravo.');
        const scroller = h('div', { class: 'cal-m4p' }, canvas, empty);
        const el = h(
            'div',
            { class: 'cal-m4' },
            h('div', { class: 'cal-m4h' }, h('span', { class: 'cal-m4n' }, prev, h('span', { class: 'cal-m4t' }, title, range), next), h('span', { class: 'cal-zm', role: 'group', 'aria-label': 'Zoom' }, out, zoomValue, into)),
            scroller,
        );
        const resize = new ResizeObserver(() => schedule());
        resize.observe(scroller);
        return { el, title, range, prev, next, zoomValue, out, into, picture, canvas, empty, scroller, resize, rendered: null };
    }

    /** px per mm of the page at zoom 1: as large as the middle column allows. */
    function fitScale(page, scroller) {
        const stacked = window.matchMedia(STACKED).matches;
        const byWidth = (scroller.clientWidth - 2 * PAGE_PAD) / page.width;
        const byHeight = stacked ? Infinity : (scroller.clientHeight - 2 * PAGE_PAD) / page.height;
        const fit = Math.min(byWidth, byHeight);
        return Math.min(K.FIT_MAX, Math.max(stacked ? 0.5 : K.FIT_MIN, fit));
    }

    function currentPage() {
        const current = view();
        if (typeof current === 'number') return store.pages()[current] || null;
        if (current === 'special') {
            const chosen = previewedSpecial(ctx);
            return chosen ? store.pages().find((page) => page.key === `sp:${chosen.id}`) || null : null;
        }
        return null;
    }

    function draw() {
        frameRequest = 0;
        if (!pageView) return;
        const state = store.getState();
        const { design } = state.doc;
        const page = currentPage();
        const pages = store.pages();
        shownPage = page;
        const special = view() === 'special';

        pageView.title.textContent = page ? page.title : special ? 'Speciální stránky' : '';
        pageView.range.textContent = page && page.kind === 'week' ? ` · ${page.subtitle}` : '';
        if (pageView.prev) pageView.prev.disabled = !page || page.index <= 0;
        if (pageView.next) pageView.next.disabled = !page || page.index >= pages.length - 1;
        pageView.empty.hidden = Boolean(page);
        pageView.canvas.hidden = !page;

        const zoom = state.ui.zoom;
        pageView.zoomValue.textContent = `${Math.round(zoom * 100)} %`;
        pageView.out.disabled = zoom <= K.ZOOM_STEPS[0] + 1e-9;
        pageView.into.disabled = zoom >= K.ZOOM_STEPS[K.ZOOM_STEPS.length - 1] - 1e-9;

        if (!page) {
            scale = 0;
            pageView.picture.replaceChildren();
            pageView.rendered = null;
            return;
        }
        scale = fitScale(design.page, pageView.scroller) * zoom;
        pageView.canvas.style.width = `${design.page.width * scale}px`;
        pageView.canvas.style.height = `${design.page.height * scale}px`;
        // the markup depends on the document only — a zoom or a resize just rescales it
        const stamp = [state.doc, page.key, page.index, ctx.env.assets, Object.keys(ctx.env.assets).length];
        if (!pageView.rendered || stamp.some((value, i) => value !== pageView.rendered[i])) {
            pageView.rendered = stamp;
            try {
                pageView.picture.innerHTML = renderPage(state.doc, page, { ...store.layoutOpts(), idPrefix: 'p4', placeholders: 'frames' });
            } catch (error) {
                pageView.picture.replaceChildren();
                console.error('[calendar] page failed to render', error);
            }
        }
    }

    function schedule() {
        if (!frameRequest) frameRequest = requestAnimationFrame(draw);
    }

    const probe = {
        scale: () => scale,
        toClient(xMm, yMm) {
            const rect = pageView ? pageView.canvas.getBoundingClientRect() : { left: 0, top: 0 };
            return { x: rect.left + xMm * scale, y: rect.top + yMm * scale };
        },
        page: () => shownPage,
    };

    // ---- right aside in style mode -----------------------------------------------------------
    function buildStyleAside() {
        const head = h('div', { class: 'cal-p4h cal-4styh' }, h('b', null, 'Styl stránek'), h('span', { class: 'cap' }, 'Změny se projeví na všech stranách kalendáře.'));
        const back = h('button', { type: 'button', class: 'btn xs btn-ghost cal-4back', 'data-cal-action': 'style-back', onClick: () => store.setUi({ selection: null }) }, icon('chev-left', 's14'), 'Vrstvy');
        const layers = h('div', { class: 'cal-b5-layers' });
        const props = h('div', { class: 'cal-b5-props' });
        const sync = () => {
            const selected = store.getState().ui.selection !== null;
            head.hidden = selected;
            layers.hidden = selected;
            back.hidden = !selected;
            props.hidden = !selected;
        };
        right.replaceChildren(head, layers, back, props);
        sync();
        return { layers, props, sync };
    }

    // ---- switching views ---------------------------------------------------------------------
    let mounted = [];
    let shown = null;
    let styleAside = null;

    const kindOf = (current) => (typeof current === 'number' ? 'page' : current);

    function show() {
        const next = view();
        const kind = kindOf(next);
        const sameFrame = kind === kindOf(shown) && kind !== 'style' && shown !== null;
        shown = next;
        frame.dataset.view = kind;
        list.refresh();

        if (kind === 'page' && sameFrame) {
            // another page: only the panel on the right is per page
            mounted.pop().destroy();
            mounted.push(mountPagePanel(right, ctx, { pageIndex: next }));
            draw();
            return;
        }

        for (const part of mounted.reverse()) part.destroy();
        mounted = [];
        styleAside = null;
        if (pageView) pageView.resize.disconnect();
        pageView = null;
        shownPage = null;
        scale = 0;

        if (kind === 'style') {
            centre.replaceChildren();
            styleAside = buildStyleAside();
            mounted.push(mountEditor({ layers: styleAside.layers, stage: centre, props: styleAside.props }, ctx, { embedded: true }));
            return;
        }
        pageView = buildPageView(kind === 'page');
        centre.replaceChildren(pageView.el);
        right.replaceChildren();
        ctx.setStageProbe(probe);
        mounted.push({ destroy: () => ctx.setStageProbe(null) });
        mounted.push(kind === 'special' ? mountSpecialPanel(right, ctx) : mountPagePanel(right, ctx, { pageIndex: next }));
        draw();
    }

    const unsubscribe = store.subscribe((event) => {
        if (event.type === 'status') return;
        if (view() !== shown) {
            show();
            return;
        }
        if (shown === 'style') {
            if (event.type === 'ui' && event.keys.includes('selection') && styleAside) styleAside.sync();
            return;
        }
        if (event.type === 'layout' && event.live) return;
        if (event.type === 'ui' && !event.keys.some((key) => key === 'zoom' || key === 'toolState')) return;
        schedule();
    });
    show();

    return {
        destroy() {
            unsubscribe();
            if (frameRequest) cancelAnimationFrame(frameRequest);
            for (const part of mounted.reverse()) part.destroy();
            if (pageView) pageView.resize.disconnect();
            list.destroy();
            chip.destroy();
            toolbar.destroy();
            root.replaceChildren();
        },
    };
}
