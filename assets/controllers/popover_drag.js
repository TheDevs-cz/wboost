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
            let left = baseLeft + (e.clientX - startX);
            let top = baseTop + (e.clientY - startY);
            if (fixed) {
                // Keep the grip reachable: the title row never leaves the
                // window (a popover dragged off-screen could not come back).
                top = Math.min(Math.max(top, 0), window.innerHeight - 40);
                left = Math.min(Math.max(left, 60 - rect.width), window.innerWidth - 60);
            }
            popover.style.left = `${left}px`;
            popover.style.top = `${top}px`;
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

/**
 * Keep the popover where it is until it closes, as if the user had dragged
 * it there. Used by controls that resize the very element the popover is
 * anchored to (the editor's image size slider): re-anchoring on every tick
 * would pull the slider out from under the pointer.
 */
export function pinPopover(popover) {
    if (popover) popover[DRAGGED] = true;
}

/**
 * Place an anchored, position:fixed editor popover (client px). It prefers to
 * start at `preferredTop` and to stay BELOW `safeTop` (the editor header),
 * shrinking to fit there (inline max-height, it scrolls inside); only when
 * the space under the header is too short (< MIN_FIT px) does it rise over
 * the header — fixed + z-index above the editor chrome, so it is never cut
 * off (2026-11-25, Jan: popovers hid under the top bar). Returns the top.
 */
const MIN_FIT = 320;
export function placeFixedPopover(el, left, preferredTop, safeTop) {
    const MARGIN = 8;
    // The full content height WITHOUT touching max-height: clearing it would
    // reset the popover's own scroll position on every re-place (it re-places
    // on each canvas change while the user edits inside it).
    const natural = el.scrollHeight + (el.offsetHeight - el.clientHeight);
    const below = window.innerHeight - safeTop - MARGIN;
    let top;
    let maxHeight = '';
    if (natural <= below) {
        top = Math.min(Math.max(preferredTop, safeTop), window.innerHeight - natural - MARGIN);
    } else if (below >= MIN_FIT) {
        top = safeTop;
        maxHeight = `${below}px`;
    } else {
        top = Math.min(Math.max(preferredTop, MARGIN), Math.max(MARGIN, window.innerHeight - natural - MARGIN));
    }
    if (el.style.maxHeight !== maxHeight) el.style.maxHeight = maxHeight;
    el.style.left = `${left}px`;
    el.style.top = `${top}px`;
    return top;
}

/** A scroll event that happened INSIDE a floating popover (its own content
 *  scrolling) — the scroll-follow listeners ignore it. */
export function isPopoverScroll(event) {
    const t = event && event.target;
    return Boolean(t && t.closest && t.closest('.canvas-floating-popover, .container-zone-popover'));
}
