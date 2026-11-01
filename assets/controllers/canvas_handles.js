/**
 * Transform-handle chrome for the admin editors (single-variant + group).
 *
 * The editors zoom with a CSS `scale()` on Fabric's container, and Fabric draws
 * its controls ON the canvas in canvas-element pixels — so a 10 px handle was
 * 2.6 screen px at 26 % auto-fit zoom: barely visible and nearly impossible to
 * hit on print-size canvases. Everything here is therefore divided by the live
 * zoom so the handles keep ONE on-screen size at any zoom:
 *
 * - HIT area (`cornerSize`/`touchCornerSize` — Fabric's corner polygons and
 *   `shouldActivate` use it) is a generous HANDLE_HIT square,
 * - the DRAWN handle (custom `control.render`) is a smaller HANDLE_SIZE circle
 *   at the corners / rotation knob and a pill on the side handles, so the
 *   X, Y and corner handles read as different things,
 * - the selection border is a constant BORDER_WIDTH hairline in the app accent.
 *
 * Textboxes resize by WIDTH ONLY (`applyTextboxDefaults` locks scaling and
 * rotation), but Fabric still drew all eight handles + the rotation knob and
 * its side-handle cursor handler reads `lockScalingX` — so the WORKING width
 * handles showed the `not-allowed` cursor and the dead corner/top/bottom/
 * rotate handles were offered at all. Textboxes keep only `ml`/`mr`, with a
 * plain resize cursor.
 *
 * Only the ACTIVE object draws (and hit-tests) controls, so the chrome is
 * applied lazily to the active object — from `before:render` (setActiveObject
 * fires no selection event, but always renders) and on every zoom change.
 * None of these properties serialize, so saved JSON and renders are untouched.
 */

export const HANDLE_SIZE = 10;
export const HANDLE_HIT = 24;
const SIDE_LENGTH = 18;
const SIDE_THICKNESS = 6;
const HANDLE_STROKE = 1.5;
const BORDER_WIDTH = 1.5;
const ROTATE_OFFSET = 28;
const ACCENT = '#727cf5';
const TEXTBOX_HIDDEN = { tl: false, tr: false, bl: false, br: false, mt: false, mb: false, mtr: false };

/** CSS zoom of the Fabric container: rendered width ÷ layout width. */
export function measureZoom(canvas) {
    const el = canvas && canvas.wrapperEl;
    if (!el || !(el.offsetWidth > 0)) return 1;
    const rendered = el.getBoundingClientRect().width;
    return rendered > 0 ? rendered / el.offsetWidth : 1;
}

/**
 * Resize cursor for a handle pointing `degrees` away from the object's +X
 * axis (0 = east). Pure, so it is node-testable.
 */
export function resizeCursorFor(degrees) {
    const a = ((Math.round(degrees) % 180) + 180) % 180;
    if (a < 22.5 || a >= 157.5) return 'ew-resize';
    if (a < 67.5) return 'nwse-resize';
    if (a < 112.5) return 'ns-resize';
    return 'nesw-resize';
}

function isTextbox(obj) {
    return typeof obj.isType === 'function' ? obj.isType('Textbox', 'textbox') : obj.type === 'textbox';
}

function totalAngle(obj) {
    return typeof obj.getTotalAngle === 'function' ? obj.getTotalAngle() : (obj.angle || 0);
}

function renderHandle(key) {
    const side = key === 'ml' || key === 'mr' || key === 'mt' || key === 'mb';
    const vertical = key === 'ml' || key === 'mr';

    return function (ctx, left, top, styleOverride, obj) {
        const zoom = obj.wboostHandleZoom > 0 ? obj.wboostHandleZoom : 1;
        ctx.save();
        ctx.translate(left, top);
        ctx.rotate((totalAngle(obj) * Math.PI) / 180);
        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = ACCENT;
        ctx.lineWidth = HANDLE_STROKE / zoom;
        ctx.beginPath();
        if (side) {
            const w = (vertical ? SIDE_THICKNESS : SIDE_LENGTH) / zoom;
            const h = (vertical ? SIDE_LENGTH : SIDE_THICKNESS) / zoom;
            if (typeof ctx.roundRect === 'function') {
                ctx.roundRect(-w / 2, -h / 2, w, h, SIDE_THICKNESS / 2 / zoom);
            } else {
                ctx.rect(-w / 2, -h / 2, w, h);
            }
        } else {
            ctx.arc(0, 0, HANDLE_SIZE / 2 / zoom, 0, Math.PI * 2);
        }
        ctx.fill();
        ctx.stroke();
        ctx.restore();
    };
}

function textboxWidthCursor(eventData, control, obj) {
    return resizeCursorFor(totalAngle(obj));
}

/**
 * Size + style one object's controls for `zoom`. Idempotent; cheap when the
 * zoom did not change.
 */
export function applyHandleChrome(obj, zoom) {
    if (!obj || !obj.controls) return;
    const z = zoom > 0 ? zoom : 1;
    if (obj.wboostHandleZoom === z) return;

    obj.wboostHandleZoom = z;
    obj.cornerSize = HANDLE_HIT / z;
    obj.touchCornerSize = HANDLE_HIT / z;
    obj.borderScaleFactor = BORDER_WIDTH / z;
    obj.borderColor = ACCENT;
    obj.borderOpacityWhenMoving = 0.6;

    for (const [key, control] of Object.entries(obj.controls)) {
        if (!control.wboostStyled) {
            control.render = renderHandle(key);
            control.wboostStyled = true;
        }
        if (key === 'mtr') control.offsetY = -ROTATE_OFFSET / z;
    }

    if (isTextbox(obj)) {
        if (typeof obj.setControlsVisibility === 'function') obj.setControlsVisibility(TEXTBOX_HIDDEN);
        ['ml', 'mr'].forEach((key) => {
            if (obj.controls[key]) obj.controls[key].cursorStyleHandler = textboxWidthCursor;
        });
    }

    if (typeof obj.setCoords === 'function') obj.setCoords();
}

/**
 * Wire the chrome onto an editor canvas. Returns a disposer.
 */
export function installHandleChrome(canvas) {
    let zoom = measureZoom(canvas);
    // Until the zoom controller has announced a scale, measure it live — the
    // editor may connect before the auto-fit is applied.
    let announced = false;

    const applyActive = () => {
        const active = canvas.getActiveObject();
        if (!active) return;
        if (!announced) zoom = measureZoom(canvas);
        applyHandleChrome(active, zoom);
        // ActiveSelection members draw their own borders inside the group box.
        const isSelection = typeof active.isType === 'function'
            ? active.isType('ActiveSelection', 'activeselection')
            : active.type === 'activeselection';
        if (isSelection) {
            active.getObjects().forEach((member) => applyHandleChrome(member, zoom));
        }
    };

    const onZoom = (event) => {
        const scale = event && event.detail && event.detail.scale;
        zoom = scale > 0 ? scale : measureZoom(canvas);
        announced = scale > 0;
        applyActive();
        canvas.requestRenderAll();
    };

    canvas.on('before:render', applyActive);
    window.addEventListener('canvas-zoom:changed', onZoom);

    return () => {
        canvas.off('before:render', applyActive);
        window.removeEventListener('canvas-zoom:changed', onZoom);
    };
}
