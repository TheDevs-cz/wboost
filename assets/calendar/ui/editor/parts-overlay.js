// Part chrome of the Kalendárium: event box bars, icon cell bars, holiday wrap bars — STUB of phase
// P3, owned by P5b (architecture §D.2, §D.6, §E.6.8).
//
// Contract (final): `mountPartsOverlay(overlayRoot, ctx): { update(geometry, scale, selection), destroy() }`
//   overlayRoot  `div.cal-ovl` of the stage: origin at the trim corner, positions are `mm × scale` px
//   update       called by the stage after EVERY render (also during live drags) with the
//                `WeekGeometry` of the page on the stage (or null), the px-per-mm scale and
//                `ui.selection`; draws the bars only while the matching part is selected
//   drags        run here: `pointerdown` on a bar stops propagation, then
//                `ctx.store.beginChange()` / `updateLive()` / `commitChange()`
// Elements: `[data-evbox-bar="n|e|s|w"]`, `[data-iconcell-bar="start|end"]`, `[data-holwrap-bar="start|end"]`.

export function mountPartsOverlay(overlayRoot, ctx) {
    void overlayRoot;
    void ctx;
    return { update() {}, destroy() {} };
}
