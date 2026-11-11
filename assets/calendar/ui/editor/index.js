// The page editor of step 3 and of step 4 „Styl stránek“ (architecture §D.2 / §D.6) — owner P4.
//
// `mountEditor(slots: { layers: HTMLElement, stage: HTMLElement, props: HTMLElement }, ctx, opts: { embedded: boolean }): { destroy() }`
//   - step 3 (workspace controller) passes the three columns of `.cal-b3`; step 4 (P6a) passes the
//     centre column as `stage` and two regions of its right aside as `layers` / `props` with
//     `embedded: true`
//   - the editor fills ONLY the three slots: Vrstvy (`layers.js`), the stage (`stage.js`), the
//     properties (`mountProps` of P5a — the editor never builds property controls itself) and the
//     keyboard (`keys.js`); it never shows, hides or decorates a slot
//   - same store, same undo stack in both places; `destroy()` leaves the slots empty
//
// Embedded (step 4): the host owns its aside — the header „Styl stránek“, showing either the
// Vrstvy region or the properties region and the back button are step 4's business. The editor
// only leaves out what makes no sense there: the collapse handle of the properties column.
//
// Step 3: the properties column is collapsed by the handle on the stage (`ui.panelCollapsed`);
// the stage carries `.panel-off` and calendar-editor.css hides the column from that.

import { mountKeys } from './keys.js';
import { mountLayers } from './layers.js';
import { mountProps } from './props.js';
import { mountStage } from './stage.js';

export function mountEditor(slots, ctx, opts = {}) {
    const embedded = opts.embedded === true;
    const parts = [mountLayers(slots.layers, ctx, { embedded }), mountStage(slots.stage, ctx, { embedded }), mountProps(slots.props, ctx), mountKeys(ctx)];
    return {
        destroy() {
            for (const part of parts) part.destroy();
            for (const slot of [slots.layers, slots.stage, slots.props]) slot.replaceChildren();
        },
    };
}
