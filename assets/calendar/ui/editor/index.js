// The page editor of step 3 and of step 4 „Styl stránek“ — STUB of phase P3, owned by P4.
//
// Contract (final, architecture §D.2 / §D.6):
//   `mountEditor(slots: { layers: HTMLElement, stage: HTMLElement, props: HTMLElement }, ctx, opts: { embedded: boolean }): { destroy() }`
//   - step 3 (workspace controller) passes the three columns of `.cal-b3`; step 4 (P6a) passes the
//     centre column as `stage` and two regions of its right aside as `layers` / `props` with
//     `embedded: true`
//   - the editor fills ONLY the three slots; it mounts `mountProps(slots.props, ctx)` (P5a) and
//     never builds property controls itself
//   - same store, same undo stack in both places; `destroy()` leaves the slots empty

import { mountKeys } from './keys.js';
import { mountLayers } from './layers.js';
import { mountProps } from './props.js';
import { mountStage } from './stage.js';

export function mountEditor(slots, ctx, opts = {}) {
    const parts = [mountLayers(slots.layers, ctx, opts), mountStage(slots.stage, ctx, opts), mountProps(slots.props, ctx), mountKeys(ctx)];
    return {
        destroy() {
            for (const part of parts) part.destroy();
        },
    };
}
