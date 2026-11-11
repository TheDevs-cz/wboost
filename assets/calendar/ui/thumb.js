// Page thumbnails: inline SVG of a page, rendered lazily (architecture §D.7 "Thumbnails").

import { renderPage } from '../render/page.js';

let counter = 0;

/**
 * SVG markup of one page for a thumbnail: trimmed (no bleed, no marks), preview-quality images,
 * `placeholders: 'frames'`.
 *
 * @param doc   the Doc to draw — normally `ctx.store.getState().doc`, but any Doc works (step 2
 *              draws documents that are not in the store)
 * @param page  a page of `buildPages(doc)`
 * @param ctx   anything with `{ env: { assets, fonts }, measurer }` (the workspace `Ctx` qualifies)
 * @param opts  `RenderOpts` overrides (`placeholders`, `bleed`, `dayContext`, `idPrefix`, …)
 * @returns `<svg …>` string; every call gets its own id prefix, so many thumbnails can share a page
 */
export function renderThumb(doc, page, ctx, opts = {}) {
    counter += 1;
    return renderPage(doc, page, {
        env: { assets: ctx.env.assets || {}, fonts: ctx.env.fonts || [] },
        measurer: ctx.measurer,
        idPrefix: `th${counter}`,
        placeholders: 'frames',
        quality: 'preview',
        bleed: false,
        marks: false,
        ...opts,
    });
}

/**
 * Fills `el` with `getMarkup()` once it scrolls into view and keeps it fresh.
 *
 * @param el         the element that receives the SVG (its children are replaced)
 * @param getMarkup  `() => string` — called only while `el` is (nearly) visible
 * @returns `{ refresh(), destroy() }` — `refresh()` marks the thumbnail stale: it re-renders at
 *          once when visible, otherwise when it next scrolls into view. Callers debounce
 *          `refresh` themselves (150 ms on `layout` non-live / `design` / `content` / `replace`).
 */
export function mountLazyThumb(el, getMarkup) {
    let visible = false;
    let stale = true;
    let destroyed = false;

    const paint = () => {
        if (destroyed || !visible || !stale) return;
        stale = false;
        try {
            el.innerHTML = getMarkup();
        } catch (error) {
            el.replaceChildren();
            // a broken page must not take the whole list down
            console.error('[calendar] thumbnail failed', error);
        }
    };

    let observer = null;
    if (typeof IntersectionObserver === 'function') {
        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) visible = entry.isIntersecting;
                paint();
            },
            { rootMargin: '200px' },
        );
        observer.observe(el);
    } else {
        visible = true;
        paint();
    }

    return {
        refresh() {
            stale = true;
            paint();
        },
        destroy() {
            destroyed = true;
            if (observer) observer.disconnect();
        },
    };
}
