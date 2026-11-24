// The page on the editor stage (feedback K20 P2, docs/calendar/custom-layers-plan.md §4.2). Pure.
//
// `ui.stagePage` = a page key (`cover`, `w05`, `sp:<id>`, `back`…) or null = the sample page
// (`samplePage()` — the first week page, or one with a time change while that part is selected).
// The built-in elements are ONE style for every week page, so they are edited on any week page;
// the cover, back and special pages are drawn by render/special.js and only their own layers (and
// Pozadí) are editable there.
//
//   stagePageOf(doc, pages, ui)           the page shown (a stored key without a page → the sample)
//   stagePageChoices(doc, pages, ui)      the entries of the page switcher: the sample, every page
//                                         that is not a week page, and the week pages a layer names
//                                         in its page list (+ the page shown), in page order
//   stageKeyForLayer(doc, pages, ui, id)  where the stage should go to show layer `id`: the page
//                                         shown when the layer is on it, else the first page it is
//                                         on (null = the sample page when that is the one), or
//                                         `undefined` when the layer is on no page at all
//   isBuiltInEditable(page)               the built-ins are editable on week pages only

import { samplePage } from '../generate/pages.js';
import { layerOnPage } from '../model/layers.js';

export function stagePageOf(doc, pages, ui) {
    const key = ui && ui.stagePage;
    if (key) {
        const found = pages.find((page) => page.key === key);
        if (found) return found;
    }
    return samplePage(doc, pages, ui ? ui.selection : null);
}

export const isBuiltInEditable = (page) => Boolean(page && page.kind === 'week');

export function stagePageChoices(doc, pages, ui) {
    const sample = samplePage(doc, pages, ui ? ui.selection : null);
    const listed = new Set();
    const layers = (doc.design.layout && doc.design.layout.layers) || {};
    for (const layer of Object.values(layers)) {
        if (Array.isArray(layer.pages)) for (const key of layer.pages) listed.add(key);
    }
    if (ui && ui.stagePage) listed.add(ui.stagePage);
    const out = [{ key: null, page: sample, label: sample ? `Vzorová · ${sample.title}` : 'Vzorová strana' }];
    for (const page of pages) {
        if (page.kind === 'week' && !listed.has(page.key)) continue;
        out.push({ key: page.key, page, label: page.kind === 'week' ? `${page.title} (${page.subtitle})` : page.title });
    }
    return out;
}

export function stageKeyForLayer(doc, pages, ui, id) {
    const layer = doc.design.layout && doc.design.layout.layers ? doc.design.layout.layers[id] : null;
    if (!layer) return undefined;
    const shown = stagePageOf(doc, pages, ui);
    if (shown && layerOnPage(layer, shown)) return ui.stagePage || null;
    const sample = samplePage(doc, pages, ui ? ui.selection : null);
    if (sample && layerOnPage(layer, sample)) return null;
    const first = pages.find((page) => layerOnPage(layer, page));
    return first ? first.key : undefined;
}
