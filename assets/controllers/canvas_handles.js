/**
 * Selection chrome (border + transform handles) for the admin editors
 * (single-variant + group), drawn as an SVG layer in the UNSCALED stage
 * instead of on the Fabric bitmap.
 *
 * Why not Fabric's own drawing:
 * - the editors zoom with a CSS `scale()`, so Fabric's handles shrank with the
 *   zoom (2–3 screen px at a print auto-fit);
 * - Fabric draws them ON the canvas element, so any handle past the element
 *   edge (an object hanging beyond the pasteboard) was clipped and dead.
 * Fabric's chrome is switched off (`skipControlsDrawing`, which Fabric itself
 * saves/restores around toCanvasElement) and this layer paints the same
 * geometry — `obj.oCoords` (viewport px) mapped through the live element rect —
 * at a constant on-screen size, anywhere in the stage.
 *
 * Interaction stays Fabric's: a press on a DOM handle is re-dispatched as a
 * `mousedown` on Fabric's upper canvas at the same client point. Fabric's
 * pointer math has no bounds check, so `findControl` resolves the control even
 * outside the element and the normal transform (snapping, events, undo) runs
 * on its document-level move/up listeners. Hit areas are Fabric's too
 * (`control.sizeX/sizeY`, recomputed per frame by `applyHitAreas`): corners
 * shrink on small objects and side handles cover their whole edge between the
 * corners, so the X/Y handle of a 30 px tall box is still grabbable (corners
 * are tested first by Fabric and used to swallow it).
 *
 * Cursors: Fabric 7 derives a scale cursor from the centre→handle DIRECTION,
 * so the corner of a wide box read `w-resize` like its side handle. Ours come
 * from the control's nominal axis + the object angle (`scaleCursor`).
 *
 * Textboxes keep only `ml`/`mr` (width-only resize, see applyTextboxDefaults):
 * Fabric used to offer dead corner/top/bottom/rotate handles there, and its
 * side-handle cursor handler read `lockScalingX` → `not-allowed`.
 *
 * Nothing here serializes; the saved JSON and renders are untouched.
 */

export const HANDLE_SIZE = 10;
const SIDE_LENGTH = 18;
const SIDE_THICKNESS = 6;
const HANDLE_STROKE = 1.5;
const BORDER_WIDTH = 1.5;
const ROTATE_OFFSET = 28;
// Screen-px hit areas.
const CORNER_HIT_MAX = 24;
const CORNER_HIT_MIN = 10;
const SIDE_HIT_THICKNESS = 20;
const SIDE_HIT_MIN_LENGTH = 8;
const ROTATE_HIT = 24;
const ACCENT = '#727cf5';
const SVG_NS = 'http://www.w3.org/2000/svg';
const TEXTBOX_HIDDEN = { tl: false, tr: false, bl: false, br: false, mt: false, mb: false, mtr: false };
const SIDE_KEYS = ['ml', 'mr', 'mt', 'mb'];
const CORNER_KEYS = ['tl', 'tr', 'bl', 'br'];

/** CSS zoom of the Fabric container: rendered width ÷ layout width. */
export function measureZoom(canvas) {
    const el = canvas && canvas.wrapperEl;
    if (!el || !(el.offsetWidth > 0)) return 1;
    const rendered = el.getBoundingClientRect().width;
    return rendered > 0 ? rendered / el.offsetWidth : 1;
}

/**
 * Two-way resize cursor for a handle pointing `degrees` away from the
 * object's +X axis (0 = east, clockwise — screen y points down). Pure.
 */
export function resizeCursorFor(degrees) {
    const a = ((Math.round(degrees) % 180) + 180) % 180;
    if (a < 22.5 || a >= 157.5) return 'ew-resize';
    if (a < 67.5) return 'nwse-resize';
    if (a < 112.5) return 'ns-resize';
    return 'nesw-resize';
}

/**
 * Corner hit size (screen px) for an object whose shorter on-screen side is
 * `minSide`: generous on normal objects, shrinking on small ones so the side
 * handles between the corners keep a grabbable strip. Pure.
 */
export function cornerHitFor(minSide) {
    return Math.max(CORNER_HIT_MIN, Math.min(CORNER_HIT_MAX, minSide * 0.35));
}

function isTextbox(obj) {
    return typeof obj.isType === 'function' ? obj.isType('Textbox', 'textbox') : obj.type === 'textbox';
}

function isActiveSelection(obj) {
    return typeof obj.isType === 'function'
        ? obj.isType('ActiveSelection', 'activeselection')
        : obj.type === 'activeselection';
}

function totalAngle(obj) {
    return typeof obj.getTotalAngle === 'function' ? obj.getTotalAngle() : (obj.angle || 0);
}

/** Cursor for a scale handle from its nominal axis, not Fabric's direction. */
function scaleCursor(eventData, control, obj) {
    // A textbox's width handles change `width`, not scale — its scale locks
    // are deliberate and must not read as "forbidden".
    if (!isTextbox(obj)) {
        const lockedX = obj.lockScalingX === true;
        const lockedY = obj.lockScalingY === true;
        const corner = control.x !== 0 && control.y !== 0;
        if (corner ? (lockedX && lockedY) : (control.x !== 0 ? lockedX : lockedY)) return 'not-allowed';
    }
    const sx = obj.flipX ? -1 : 1;
    const sy = obj.flipY ? -1 : 1;
    const nominal = (Math.atan2(control.y * sy, control.x * sx) * 180) / Math.PI;
    return resizeCursorFor(nominal + totalAngle(obj));
}

/**
 * One-time per-object setup: cursors, textbox handle set, rotation offset.
 * Hit areas depend on the live size and are refreshed by applyHitAreas.
 */
function prepareObject(obj) {
    if (!obj.controls || obj.wboostHandlesPrepared) return;
    obj.wboostHandlesPrepared = true;
    obj.borderColor = ACCENT;
    obj.borderOpacityWhenMoving = 0.6;
    [...SIDE_KEYS, ...CORNER_KEYS].forEach((key) => {
        if (obj.controls[key]) obj.controls[key].cursorStyleHandler = scaleCursor;
    });
    if (isTextbox(obj) && typeof obj.setControlsVisibility === 'function') {
        obj.setControlsVisibility(TEXTBOX_HIDDEN);
    }
}

/**
 * Size Fabric's control hit polygons for the current zoom and on-screen object
 * size (all in viewport px = screen px ÷ zoom). Returns true when anything
 * changed (the caller then refreshes oCoords).
 */
export function applyHitAreas(obj, zoom) {
    if (!obj || !obj.controls) return false;
    prepareObject(obj);
    const z = zoom > 0 ? zoom : 1;
    const w = Math.abs(typeof obj.getScaledWidth === 'function' ? obj.getScaledWidth() : obj.width || 0) * z;
    const h = Math.abs(typeof obj.getScaledHeight === 'function' ? obj.getScaledHeight() : obj.height || 0) * z;
    const corner = cornerHitFor(Math.min(w, h));
    const sizes = {
        corner,
        sideAlongX: Math.max(SIDE_HIT_MIN_LENGTH, w - corner),
        sideAlongY: Math.max(SIDE_HIT_MIN_LENGTH, h - corner),
        z,
    };
    const prev = obj.wboostHitSizes;
    if (prev && prev.corner === sizes.corner && prev.sideAlongX === sizes.sideAlongX
        && prev.sideAlongY === sizes.sideAlongY && prev.z === sizes.z) {
        return false;
    }
    obj.wboostHitSizes = sizes;

    const set = (key, sx, sy) => {
        const c = obj.controls[key];
        if (!c) return;
        c.sizeX = sx / z;
        c.sizeY = sy / z;
        c.touchSizeX = sx / z;
        c.touchSizeY = sy / z;
    };
    CORNER_KEYS.forEach((key) => set(key, corner, corner));
    set('ml', SIDE_HIT_THICKNESS, sizes.sideAlongY);
    set('mr', SIDE_HIT_THICKNESS, sizes.sideAlongY);
    set('mt', sizes.sideAlongX, SIDE_HIT_THICKNESS);
    set('mb', sizes.sideAlongX, SIDE_HIT_THICKNESS);
    set('mtr', ROTATE_HIT, ROTATE_HIT);
    if (obj.controls.mtr) obj.controls.mtr.offsetY = -ROTATE_OFFSET / z;
    obj.cornerSize = corner / z;
    obj.touchCornerSize = corner / z;
    return true;
}

/**
 * Wire the chrome onto an editor canvas. `layer` is the unscaled stage the
 * SVG lives in (`.canvas-stage`). Returns a disposer.
 */
export function installHandleChrome(canvas, layer) {
    if (!canvas || !layer) return () => {};
    canvas.skipControlsDrawing = true;

    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'canvas-handles');
    svg.setAttribute('aria-hidden', 'true');
    layer.appendChild(svg);

    const borders = document.createElementNS(SVG_NS, 'g');
    const connector = document.createElementNS(SVG_NS, 'line');
    connector.setAttribute('class', 'canvas-handles__connector');
    svg.appendChild(borders);
    svg.appendChild(connector);
    const handleEls = new Map();

    let zoom = measureZoom(canvas);
    let announced = false;

    const handleEl = (key) => {
        let el = handleEls.get(key);
        if (el) return el;
        const side = SIDE_KEYS.includes(key);
        el = document.createElementNS(SVG_NS, side ? 'rect' : 'circle');
        el.setAttribute('class', 'canvas-handles__handle');
        el.dataset.control = key;
        el.addEventListener('mousedown', forwardPress);
        svg.appendChild(el);
        handleEls.set(key, el);
        return el;
    };

    /** Re-dispatch the press to Fabric at the same client point. */
    function forwardPress(event) {
        if (event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();
        const upper = canvas.upperCanvasEl;
        if (!upper) return;
        upper.dispatchEvent(new MouseEvent('mousedown', {
            bubbles: true,
            cancelable: true,
            view: window,
            clientX: event.clientX,
            clientY: event.clientY,
            screenX: event.screenX,
            screenY: event.screenY,
            button: 0,
            buttons: 1,
            shiftKey: event.shiftKey,
            altKey: event.altKey,
            ctrlKey: event.ctrlKey,
            metaKey: event.metaKey,
        }));
    }

    const borderPolys = [];
    const borderPoly = (i) => {
        if (!borderPolys[i]) {
            const p = document.createElementNS(SVG_NS, 'polygon');
            p.setAttribute('class', 'canvas-handles__border');
            borders.appendChild(p);
            borderPolys[i] = p;
        }
        return borderPolys[i];
    };

    const hideAll = () => {
        svg.style.display = 'none';
    };

    const toStage = (geo, x, y) => [geo.offX + x * geo.scale, geo.offY + y * geo.scale];

    const scenePolygon = (geo, obj) => {
        const vpt = canvas.viewportTransform;
        const pts = typeof obj.getCoords === 'function' ? obj.getCoords() : [];
        return pts.map((p) => {
            const vx = vpt[0] * p.x + vpt[2] * p.y + vpt[4];
            const vy = vpt[1] * p.x + vpt[3] * p.y + vpt[5];
            return toStage(geo, vx, vy).join(',');
        }).join(' ');
    };

    const paint = () => {
        const active = canvas.getActiveObject();
        if (!active || active.visible === false || !active.oCoords) {
            hideAll();
            return;
        }
        const el = canvas.upperCanvasEl || canvas.getElement();
        const contRect = (el.parentElement || el).getBoundingClientRect();
        const layerRect = layer.getBoundingClientRect();
        if (!contRect.width) {
            hideAll();
            return;
        }
        const geo = {
            offX: contRect.left - layerRect.left,
            offY: contRect.top - layerRect.top,
            scale: contRect.width / canvas.getWidth(),
        };
        svg.style.display = '';
        svg.style.opacity = active.isMoving ? String(active.borderOpacityWhenMoving ?? 0.6) : '1';

        // Borders: the active object, plus each member of a multi-selection.
        const borderObjects = [];
        if (active.hasBorders !== false) borderObjects.push(active);
        if (isActiveSelection(active) && typeof active.getObjects === 'function') {
            active.getObjects().forEach((m) => { if (m.visible !== false) borderObjects.push(m); });
        }
        borderObjects.forEach((obj, i) => {
            const poly = borderPoly(i);
            poly.style.display = '';
            if (obj === active) {
                const c = active.oCoords;
                poly.setAttribute('points', ['tl', 'tr', 'br', 'bl']
                    .map((k) => toStage(geo, c[k].x, c[k].y).join(',')).join(' '));
            } else {
                poly.setAttribute('points', scenePolygon(geo, obj));
            }
            poly.style.stroke = (obj === active && active.borderColor) || ACCENT;
            poly.style.strokeDasharray = obj === active ? '' : '4 3';
        });
        for (let i = borderObjects.length; i < borderPolys.length; i++) borderPolys[i].style.display = 'none';

        // Handles.
        const angle = totalAngle(active);
        const showControls = active.hasControls !== false;
        const seen = new Set();
        if (showControls) {
            Object.keys(active.controls || {}).forEach((key) => {
                const coord = active.oCoords[key];
                if (!coord || !active.isControlVisible(key)) return;
                seen.add(key);
                const control = active.controls[key];
                const node = handleEl(key);
                const [x, y] = toStage(geo, coord.x, coord.y);
                if (SIDE_KEYS.includes(key)) {
                    const vertical = key === 'ml' || key === 'mr';
                    const w = vertical ? SIDE_THICKNESS : SIDE_LENGTH;
                    const h = vertical ? SIDE_LENGTH : SIDE_THICKNESS;
                    node.setAttribute('x', String(x - w / 2));
                    node.setAttribute('y', String(y - h / 2));
                    node.setAttribute('width', String(w));
                    node.setAttribute('height', String(h));
                    node.setAttribute('rx', String(SIDE_THICKNESS / 2));
                    node.setAttribute('transform', `rotate(${angle} ${x} ${y})`);
                } else {
                    node.setAttribute('cx', String(x));
                    node.setAttribute('cy', String(y));
                    node.setAttribute('r', String(HANDLE_SIZE / 2));
                }
                let cursor = 'default';
                try {
                    cursor = control.cursorStyleHandler(null, control, active, coord) || 'default';
                } catch (e) { /* a handler that needs a real event — keep default */ }
                node.style.cursor = cursor;
                node.style.display = '';
            });
        }
        handleEls.forEach((node, key) => { if (!seen.has(key)) node.style.display = 'none'; });

        // Rotation knob connector (mt edge midpoint → knob).
        if (seen.has('mtr') && active.oCoords.mt) {
            const [x1, y1] = toStage(geo, active.oCoords.mt.x, active.oCoords.mt.y);
            const [x2, y2] = toStage(geo, active.oCoords.mtr.x, active.oCoords.mtr.y);
            connector.setAttribute('x1', String(x1));
            connector.setAttribute('y1', String(y1));
            connector.setAttribute('x2', String(x2));
            connector.setAttribute('y2', String(y2));
            connector.style.display = '';
        } else {
            connector.style.display = 'none';
        }
    };

    const applyActive = () => {
        const active = canvas.getActiveObject();
        if (!active) return;
        if (!announced) zoom = measureZoom(canvas);
        if (applyHitAreas(active, zoom) && typeof active.setCoords === 'function') active.setCoords();
        if (isActiveSelection(active) && typeof active.getObjects === 'function') {
            active.getObjects().forEach((member) => prepareObject(member));
        }
    };

    // While Fabric owns a transform the pointer may be over the stage, a DOM
    // handle or the canvas — pin the transform's cursor everywhere.
    const root = document.documentElement;
    const onDown = (opt) => {
        if (!((opt && opt.transform) || canvas._currentTransform)) return;
        const cursor = canvas.upperCanvasEl ? canvas.upperCanvasEl.style.cursor : '';
        if (cursor) {
            root.style.setProperty('--canvas-transform-cursor', cursor);
            root.classList.add('canvas-transforming');
        }
    };
    const onUp = () => root.classList.remove('canvas-transforming');

    const onZoom = (event) => {
        const scale = event && event.detail && event.detail.scale;
        zoom = scale > 0 ? scale : measureZoom(canvas);
        announced = scale > 0;
        applyActive();
        canvas.requestRenderAll();
    };
    const onResize = () => canvas.requestRenderAll();

    canvas.on('before:render', applyActive);
    canvas.on('after:render', paint);
    canvas.on('mouse:down', onDown);
    canvas.on('mouse:up', onUp);
    window.addEventListener('canvas-zoom:changed', onZoom);
    window.addEventListener('resize', onResize);

    return () => {
        canvas.off('before:render', applyActive);
        canvas.off('after:render', paint);
        canvas.off('mouse:down', onDown);
        canvas.off('mouse:up', onUp);
        window.removeEventListener('canvas-zoom:changed', onZoom);
        window.removeEventListener('resize', onResize);
        root.classList.remove('canvas-transforming');
        svg.remove();
    };
}
