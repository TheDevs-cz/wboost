// Buttons over the large step-5 page: photo controls, caption pencil, a plus per day row, comment
// bubbles, warning marks (architecture §D.7 "Step 5") — STUB of phase P3, owned by P7a and used
// only by P7a's `step5.js`.
//
// Working shape: `mountStep5Overlay(overlayRoot, ctx): { update(geometry, scale, page), destroy() }`
// — positioned from `WeekGeometry`, never from SVG nodes. Elements: `[data-day-plus="<date>"]`,
// `[data-day-comments="<date>"]`, `[data-photo-zoom]`, `[data-caption-edit]`.

export function mountStep5Overlay(overlayRoot, ctx) {
    void overlayRoot;
    void ctx;
    return { update() {}, destroy() {} };
}
