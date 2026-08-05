/**
 * Tames Fabric's hidden textarea for a CSS-zoomed canvas inside a pinned shell.
 *
 * Entering text editing (double-click a textbox — or a `.editable-outline__name`
 * badge, which is pointer-events:none and falls through to the canvas) makes
 * Fabric create a 1×1 invisible `<textarea>`, append it to `document.body` and
 * focus it. Two facts about that textarea are hostile to this editor:
 *
 * 1. `_calcTextareaPosition()` works in UNSCALED canvas logical pixels — it
 *    knows nothing about the `transform: scale()` the zoom controller puts on
 *    `.canvas-wrapper`. On an A4 @300dpi canvas (2480 × 3508) a caret that sits
 *    ~480 px down on screen is reported at `top: 1860px`.
 * 2. It is `position: absolute` on the (unpositioned) body, so those numbers are
 *    DOCUMENT coordinates: the textarea both extends the page's scrollable
 *    overflow and — because `.focus()` scrolls a freshly focused element into
 *    view — yanks the whole document down by ~1200 px on the first double-click.
 *    Under the app shell (`body.editor-shell-page { overflow: hidden }`) that
 *    scroll cannot be undone by the user: the editor was stranded on a blank
 *    band below the canvas until a reload.
 *
 * The fix keeps Fabric's caret tracking (IME candidate windows and mobile
 * keyboards need the textarea near the caret) but expresses it in VIEWPORT
 * coordinates with `position: fixed`, so the element is outside layout and can
 * never extend a scroll area — plus `focus({ preventScroll: true })` so no
 * scroll container is nudged even before the first reposition.
 */

/** Marker so repeated Stimulus connects don't stack patches on the prototype. */
const PATCHED = '_wboostHiddenTextareaPatched';

export function patchHiddenTextarea(ITextClass) {
    const proto = ITextClass && ITextClass.prototype;

    if (!proto || Object.prototype.hasOwnProperty.call(proto, PATCHED)) {
        return;
    }

    proto[PATCHED] = true;

    const originalCalc = proto._calcTextareaPosition;
    const originalInit = proto.initHiddenTextarea;

    /**
     * Re-express Fabric's document-space, unscaled result as viewport coords of
     * the caret as it actually appears on screen.
     */
    proto._calcTextareaPosition = function () {
        // Fabric only refreshes `_offset` on resize/setDimensions, and we
        // subtract it back out below — recompute so the two agree exactly even
        // after the viewport was panned or the left panel resized.
        if (this.canvas && typeof this.canvas.calcOffset === 'function') {
            this.canvas.calcOffset();
        }

        const result = originalCalc.call(this);
        const el = this.canvas && this.canvas.upperCanvasEl;

        if (!el) {
            return result;
        }

        const rect = el.getBoundingClientRect();
        const layoutWidth = el.clientWidth || rect.width;
        const layoutHeight = el.clientHeight || rect.height;
        const offset = this.canvas._offset || { left: 0, top: 0 };
        const left = parseFloat(result.left);
        const top = parseFloat(result.top);

        if (!layoutWidth || !layoutHeight || !Number.isFinite(left) || !Number.isFinite(top)) {
            return result;
        }

        // (document coord − canvas origin) = offset inside the unscaled canvas;
        // the bounding rect carries the CSS zoom, so this lands on screen.
        const x = rect.left + (left - offset.left) * (rect.width / layoutWidth);
        const y = rect.top + (top - offset.top) * (rect.height / layoutHeight);

        return {
            ...result,
            left: `${clamp(x, 0, window.innerWidth - 2)}px`,
            top: `${clamp(y, 0, window.innerHeight - 2)}px`,
        };
    };

    proto.initHiddenTextarea = function () {
        originalInit.call(this);

        const textarea = this.hiddenTextarea;

        if (!textarea) {
            return;
        }

        // Fabric hardcodes `position: absolute` in the cssText it builds; the
        // coordinates above are viewport-relative, and fixed keeps the element
        // out of every scrollable overflow area.
        textarea.style.position = 'fixed';

        // enterEditing() focuses right after this returns. Even clamped into the
        // viewport a focus can nudge an ancestor scroller by a pixel or two —
        // there is nothing to reveal here, so opt out entirely.
        const nativeFocus = textarea.focus.bind(textarea);
        textarea.focus = (options) => nativeFocus({ ...(options || {}), preventScroll: true });
    };
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}
