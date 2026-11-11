// Headless render entries, run inside Gotenberg's Chromium:
//   - `renderHeadless`: ONE page as an SVG element that fills the viewport, screenshotted for the
//     MCP tool `render_calendar_page` (Services/Calendar/CalendarPageRenderer.php,
//     templates/calendar/headless_render.html.twig);
//   - `renderHeadlessSheets`: SEVERAL pages, one `.sheet` element each, printed to a PDF for the
//     PDF export (Services/Calendar/CalendarPdfRenderer.php, templates/calendar/headless_print.html.twig).
//     The SVG is printed, not screenshotted — texts and shapes stay vectors in the PDF.
//
// The very pipeline of the workspace — `normalizeDocument` → `buildPages` → `renderPage` with the
// canvas measurer and the project fonts loaded — so the picture is what the web shows, not an
// approximation of it. The only differences: asset URLs are data URIs (Chromium has no network
// there) and the page is addressed by its page key.
//
// Like the rest of the non-`ui/` modules this file never names `window` / `document`: the caller
// passes the element to draw into.

import { normalizeDocument } from '../model/normalize.js';
import { buildPages } from '../generate/pages.js';
import { renderPage } from '../render/page.js';
import { createCanvasMeasurer, ensureFontsLoaded, usedFamilies } from '../render/measure.js';

const IMAGE_TIMEOUT_MS = 8000;

function settled(image) {
    return new Promise((resolve) => {
        const done = () => resolve();
        image.addEventListener('load', done, { once: true });
        image.addEventListener('error', done, { once: true });
        setTimeout(done, IMAGE_TIMEOUT_MS);
    });
}

/**
 * @param boot  `{ calendar, assets, env: { fonts, palette, currentYear }, pageKey, bleed?, marks?, placeholders? }`
 * @param root  the element that receives the `<svg>`
 * @returns the rendered page (`{ key, kind, title }`); throws when the page key matches no page
 */
export async function renderHeadless(boot, root) {
    const palette = Array.isArray(boot.env.palette) ? boot.env.palette : [];
    const env = {
        fonts: Array.isArray(boot.env.fonts) ? boot.env.fonts : [],
        accent: palette.find((c) => c !== '#FFFFFF' && c !== '#000000') || null,
        currentYear: boot.env.currentYear,
        assets: boot.assets && typeof boot.assets === 'object' ? boot.assets : {},
    };
    const doc = normalizeDocument(boot.calendar, env);
    await ensureFontsLoaded(env.fonts, usedFamilies(doc.design.layout));

    const page = buildPages(doc).find((p) => p.key === boot.pageKey);
    if (!page) throw new Error(`calendar-render: no page "${boot.pageKey}"`);

    root.innerHTML = renderPage(doc, page, {
        env: { assets: env.assets, fonts: env.fonts },
        measurer: createCanvasMeasurer(),
        idPrefix: 'hl',
        bleed: boot.bleed === true || boot.marks === true,
        marks: boot.marks === true,
        placeholders: boot.placeholders === 'all' ? 'all' : 'frames',
        quality: 'full',
    });
    const svg = root.firstElementChild;
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', '100%');
    svg.setAttribute('preserveAspectRatio', 'none');
    await Promise.all([...root.querySelectorAll('image')].map(settled));
    return { key: page.key, kind: page.kind, title: page.title };
}

/**
 * The pages of a PDF chunk: one `<div class="sheet">` per page key, in the given order. The
 * template sizes a sheet in mm (the view box of the chosen variant) and breaks the page after it.
 *
 * @param boot  `{ calendar, assets, env, pageKeys: string[], bleed?, marks?, placeholders?: 'frames' | 'none' }` — the server
 *              decides which file of an asset travels (the upload or its preview) and hands it as `url`
 * @param root  the element that receives the sheets
 * @returns the rendered page keys; throws when a key matches no page
 */
export async function renderHeadlessSheets(boot, root) {
    const palette = Array.isArray(boot.env.palette) ? boot.env.palette : [];
    const env = {
        fonts: Array.isArray(boot.env.fonts) ? boot.env.fonts : [],
        accent: palette.find((c) => c !== '#FFFFFF' && c !== '#000000') || null,
        currentYear: boot.env.currentYear,
        assets: boot.assets && typeof boot.assets === 'object' ? boot.assets : {},
    };
    const doc = normalizeDocument(boot.calendar, env);
    await ensureFontsLoaded(env.fonts, usedFamilies(doc.design.layout));

    const pages = buildPages(doc);
    const measurer = createCanvasMeasurer();
    let markup = '';
    (Array.isArray(boot.pageKeys) ? boot.pageKeys : []).forEach((key, index) => {
        const page = pages.find((p) => p.key === key);
        if (!page) throw new Error(`calendar-render: no page "${key}"`);
        const svg = renderPage(doc, page, {
            env: { assets: env.assets, fonts: env.fonts },
            measurer,
            // gradient / clip-path ids must be unique in the whole document, not per sheet
            idPrefix: `s${index}`,
            bleed: boot.bleed === true || boot.marks === true,
            marks: boot.marks === true,
            placeholders: boot.placeholders === 'none' ? 'none' : 'frames',
            quality: 'full',
        });
        markup += `<div class="sheet">${svg}</div>`;
    });
    root.innerHTML = markup;
    await Promise.all([...root.querySelectorAll('image')].map(settled));
    return boot.pageKeys;
}
