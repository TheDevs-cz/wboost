// The workspace's address (K9): where you are is in the URL, so a link can point at a step and
// a page. Pure — the workspace controller reads `location.search` and writes `history.replaceState`.
//
//   ?krok=N          the step shown (1–5) — designer only; a client is always on step 5
//   &strana=<key>    the page in steps 4 and 5 — the year/type-independent page key of
//                    `generate/pages.js` (`cover`, `pre`, `w01`…`w53`, `post`, `back`, `sp:<id>`);
//                    step 4 writes it only while a page is open (not on „Styl stránek“ /
//                    „Speciální stránky“)
//
// Other parameters (`?sken=` of ui/scan.js) are kept as they are. Unknown or invalid values are
// ignored silently.

const PAGE_KEY = /^(cover|pre|post|back|w\d{2}|sp:[A-Za-z0-9_-]{1,64})$/;

/**
 * @param search  `location.search` (with or without the leading `?`)
 * @returns `{ step: 1–5 | null, page: string | null, scan: boolean }` — `scan`: a `?sken=` link,
 *          which wins over `krok`
 */
export function readAddress(search) {
    const params = new URLSearchParams(search || '');
    const rawStep = (params.get('krok') || '').trim();
    const step = /^[1-5]$/.test(rawStep) ? Number(rawStep) : null;
    const rawPage = (params.get('strana') || '').trim();
    const page = PAGE_KEY.test(rawPage) ? rawPage : null;
    return { step, page, scan: params.has('sken') };
}

/**
 * The address of the view the workspace shows.
 *
 * @param o.mode      'designer' | 'client'
 * @param o.step      the step shown (`effectiveStep()`)
 * @param o.ui        the store's `ui` state (`contentView` of step 4, `previewPage` of step 5)
 * @param o.pages     `store.pages()`
 * @returns `{ step: number | null, page: string | null }`
 */
export function addressOf({ mode, step, ui, pages }) {
    const shown = mode === 'designer' ? step : 5;
    let index = null;
    if (shown === 4 && typeof ui.contentView === 'number') index = ui.contentView;
    else if (shown === 5) index = ui.previewPage;
    const page = index !== null && pages[index] ? pages[index].key : null;
    return { step: mode === 'designer' ? shown : null, page };
}

/**
 * `search` with `krok` / `strana` set to the address (a `null` removes the parameter); every other
 * parameter stays. Returns the new search string with its `?`, or '' when nothing is left.
 */
export function writeAddress(search, { step, page }) {
    const params = new URLSearchParams(search || '');
    params.delete('krok');
    params.delete('strana');
    if (step) params.set('krok', String(step));
    if (page) params.set('strana', page);
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** Index of the page with `key` in `pages`, or null. */
export function pageIndexOf(pages, key) {
    if (!key) return null;
    const index = pages.findIndex((page) => page.key === key);
    return index >= 0 ? index : null;
}
