/**
 * Shared drag helper for the floating editing popovers — the admin canvas
 * editor (canvas_floating_toolbar_controller) and the user-fill overlay
 * (variant_fill_overlay_controller).
 *
 * A popover is normally auto-anchored next to its element/box, but sometimes it
 * covers the very thing being edited, so the user may want to nudge it aside.
 * `makeDraggable` turns a grip element into a move handle: it mutates the
 * popover's inline `left`/`top` by raw pointer deltas and flags the popover as
 * manually moved. The owning controller reads that flag (`isDragged`) and stops
 * re-anchoring the popover; it clears it (`resetDrag`) when the popover closes,
 * so the popover snaps back to its default position next time it opens.
 *
 * Pointer deltas map 1:1 to `left`/`top` px because neither popover (nor its
 * offset parent) is CSS-scaled: the admin popover is `position:absolute` inside
 * the UNSCALED `.canvas-stage` layer, and the fill popover is `position:fixed`
 * in viewport coordinates. So the same delta math works for both.
 */

const DRAGGED = "__wboostPopoverDragged";

/**
 * Wire `handle` to drag `popover`. Idempotent per handle (guards against a
 * double bind if a controller re-runs its setup).
 */
export function makeDraggable(handle, popover) {
    if (!handle || !popover || handle.dataset.popoverDragBound === "1") return;
    handle.dataset.popoverDragBound = "1";

    handle.addEventListener("pointerdown", (event) => {
        // Primary button / touch / pen only; ignore secondary + middle clicks.
        if (event.button !== 0) return;
        event.preventDefault();

        const rect = popover.getBoundingClientRect();
        const fixed = getComputedStyle(popover).position === "fixed";
        // Base position in the popover's own coordinate space: viewport px for
        // fixed, offset-from-offset-parent px for absolute.
        let baseLeft = rect.left;
        let baseTop = rect.top;
        if (!fixed) {
            const parent = popover.offsetParent;
            const p = parent ? parent.getBoundingClientRect() : { left: 0, top: 0 };
            baseLeft = rect.left - p.left;
            baseTop = rect.top - p.top;
        }
        const startX = event.clientX;
        const startY = event.clientY;

        const move = (e) => {
            popover.style.left = `${baseLeft + (e.clientX - startX)}px`;
            popover.style.top = `${baseTop + (e.clientY - startY)}px`;
            popover[DRAGGED] = true;
            handle.classList.add("is-dragging");
        };
        const up = () => {
            document.removeEventListener("pointermove", move);
            document.removeEventListener("pointerup", up);
            document.removeEventListener("pointercancel", up);
            handle.classList.remove("is-dragging");
        };
        document.addEventListener("pointermove", move);
        document.addEventListener("pointerup", up);
        document.addEventListener("pointercancel", up);
    });
}

/** Has the user dragged this popover away from its default anchor? */
export function isDragged(popover) {
    return Boolean(popover && popover[DRAGGED]);
}

/** Forget the manual position (call on close) so it re-anchors next open. */
export function resetDrag(popover) {
    if (popover) popover[DRAGGED] = false;
}
