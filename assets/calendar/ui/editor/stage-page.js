// Moving the step-3 stage to a page (feedback K20 P2) — shared by Vrstvy, the layer panel, the
// step-4 page panel and the Speciální stránky panel.
//
//   editLayersOnPage(ctx, pageKey, opts?: { select?, openAdd? })  step 3 with `ui.stagePage` = that
//        page (the cover, a special page, a week… — null = the sample page); `select` = an own
//        layer key to select there; `openAdd` opens the „Přidat“ menu of Vrstvy (`ui.layerAddOpen`,
//        consumed by ui/editor/layers.js)
//   showLayerOnStage(ctx, key)   the stage follows own layer `key` (`u:<id>`): it stays when the
//        layer is on the page shown, else goes to the first page the layer is on
//   layerPagesForNew(ctx)        the page set of a layer added now: the page on the stage when one
//        was chosen (`[key]` — „jen tato strana“), else every week page (D2, `'weeks'`)

import { stageKeyForLayer } from '../../editor/stage-page.js';
import { layerIdOf } from '../../model/layers.js';

export function editLayersOnPage(ctx, pageKey, opts = {}) {
    const { store } = ctx;
    store.setUi({ stagePage: pageKey || null, selection: opts.select ? { el: opts.select } : null, layerAddOpen: opts.openAdd === true });
    if (store.getState().doc.design.step !== 3) store.setStep(3);
}

export function showLayerOnStage(ctx, key) {
    const { store } = ctx;
    const state = store.getState();
    const next = stageKeyForLayer(state.doc, store.pages(), state.ui, layerIdOf(key));
    if (next !== undefined && next !== state.ui.stagePage) store.setUi({ stagePage: next });
}

export function layerPagesForNew(ctx) {
    const key = ctx.store.getState().ui.stagePage;
    return key ? [key] : 'weeks';
}
