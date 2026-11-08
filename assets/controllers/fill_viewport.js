/**
 * Pan / zoom viewport for the single fill page (`.fill-viewport` > `.fill-stage`).
 *
 * The viewport is `overflow: hidden` — it never scrolls natively, so nothing
 * inside the stage (a layer the size of the design, a frame bleeding off the
 * canvas, chrome hanging past an edge) can ever stretch a scroll range again
 * (the 2026-11-06 "scrolls into nowhere" report: an invisible full-size echo
 * canvas doubled the scroll extent). The stage is placed with ONE transform,
 * `translate(x, y) scale(z)` from its top-left corner, and the pan is clamped:
 *
 * - an axis where the canvas fits is CENTRED and does not pan at all — at the
 *   fit zoom nothing moves;
 * - an axis where it does not fit pans only until the canvas edge sits 24 px
 *   inside the viewport edge — the canvas can never be lost.
 *
 * The selected / dragged picture's bounding box (`extraBounds()`, UNSCALED
 * stage px, rotation included) WIDENS that range so its handles stay
 * reachable when it hangs off the canvas; selecting never moves the view.
 * When the range narrows again (deselect), the view eases back instead of
 * jumping (`clamp(true)`).
 *
 * Inputs: wheel / two-finger trackpad = pan (captured only when there is room
 * on that axis, otherwise the page scrolls); ctrl/⌘ + wheel, trackpad pinch
 * (Chrome / Firefox send it as ctrl+wheel) and Safari `gesture*` events = zoom
 * around the cursor; Space + drag and middle-drag pan; touch: one finger pans
 * only when zoomed past fit, two fingers pinch. Plain left-drag never pans (it
 * belongs to the pictures). Keyboard on the focused viewport: arrows pan,
 * + / - zoom, 0 fits.
 */

export const PAN_MARGIN = 24;
export const MIN_ZOOM = 0.1;
export const MAX_ZOOM = 3;

/**
 * Allowed translate range on one axis.
 *   size     — viewport size (px)
 *   lo, hi   — content extent in SCALED stage px: the canvas [0, canvas],
 *              widened by the selected picture's box when it hangs off
 *   canvas   — scaled canvas size
 * Returns { min, max, centre, pannable }.
 *
 * The canvas alone: centred and fixed when it fits, else
 * pannable until its edge is `margin` inside the viewport edge. A picture
 * hanging off the canvas only WIDENS that range (the convex hull with the
 * range that shows the picture) — selecting it never moves the view; it just
 * lets the user pan to its handles. Deselecting narrows the range again.
 */
export function axisRange(size, lo, hi, canvas, margin = PAN_MARGIN) {
    const centre = (size - canvas) / 2;
    // Fits (the fit zoom makes it exactly as wide/tall as the viewport): no pan.
    const base = canvas <= size + 0.5
        ? { min: centre, max: centre }
        : { min: size - margin - canvas, max: margin };
    let range = base;
    if (lo < -0.5 || hi > canvas + 0.5) {
        const ext = hi - lo + 2 * margin <= size
            ? { min: margin - lo, max: size - margin - hi }
            : { min: size - margin - hi, max: margin - lo };
        range = { min: Math.min(base.min, ext.min), max: Math.max(base.max, ext.max) };
    }
    return {
        min: range.min,
        max: range.max,
        centre: Math.min(range.max, Math.max(range.min, centre)),
        pannable: range.max - range.min > 0.5,
    };
}

export function clampTo(value, range) {
    return Math.min(range.max, Math.max(range.min, value));
}

function isEditable(target) {
    if (!target || !(target instanceof Element)) return false;
    return Boolean(target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]'));
}

function wheelPixels(event, axis) {
    const delta = axis === 'x' ? event.deltaX : event.deltaY;
    if (event.deltaMode === 1) return delta * 16;
    if (event.deltaMode === 2) return delta * 400;
    return delta;
}

export class FillViewport {
    /**
     * options: {
     *   viewport, stage            — elements
     *   onChange()                 — after every applied view (re-anchor popovers)
     *   onZoom(z)                  — user zoom through a gesture (wheel / pinch / keys)
     *   extraBounds()              — {left, top, right, bottom} unscaled stage px, or null
     *   isInteractive(event)       — true when a touch press must stay with the page
     *                                 content (a picture, a tool) instead of panning
     * }
     */
    constructor(options) {
        this.viewport = options.viewport;
        this.stage = options.stage;
        this.options = options;
        this.zoom = 1;
        this.x = 0;
        this.y = 0;
        this._anim = null;
        this._pointers = new Map();
        this._space = false;
        this._barsTimer = null;

        this.viewport.classList.add('fill-viewport--pan');
        if (!this.viewport.hasAttribute('tabindex')) this.viewport.setAttribute('tabindex', '0');

        this._bars = { x: this._makeBar('x'), y: this._makeBar('y') };

        this._onWheel = (event) => this._wheel(event);
        this._onPointerDown = (event) => this._pointerDown(event);
        this._onPointerMove = (event) => this._pointerMove(event);
        this._onPointerUp = (event) => this._pointerUp(event);
        this._onKeyDown = (event) => this._keyDown(event);
        this._onKeyUp = (event) => this._keyUp(event);
        this._onBlur = () => this._setSpace(false);
        this._onGestureStart = (event) => this._gestureStart(event);
        this._onGestureChange = (event) => this._gestureChange(event);
        this._onGestureEnd = (event) => event.preventDefault();

        // overflow: hidden still lets focus() / scrollIntoView() scroll the box
        // (overflow: clip in the CSS stops that where supported) — undo it.
        this._onScroll = () => {
            if (this.viewport.scrollLeft || this.viewport.scrollTop) {
                this.viewport.scrollLeft = 0;
                this.viewport.scrollTop = 0;
            }
        };
        this.viewport.addEventListener('scroll', this._onScroll);
        this.viewport.addEventListener('wheel', this._onWheel, { passive: false });
        // Capture: a pan press must never reach Fabric's canvas underneath.
        this.viewport.addEventListener('pointerdown', this._onPointerDown, true);
        window.addEventListener('pointermove', this._onPointerMove);
        window.addEventListener('pointerup', this._onPointerUp);
        window.addEventListener('pointercancel', this._onPointerUp);
        document.addEventListener('keydown', this._onKeyDown);
        document.addEventListener('keyup', this._onKeyUp);
        window.addEventListener('blur', this._onBlur);
        this.viewport.addEventListener('gesturestart', this._onGestureStart);
        this.viewport.addEventListener('gesturechange', this._onGestureChange);
        this.viewport.addEventListener('gestureend', this._onGestureEnd);
    }

    destroy() {
        this.viewport.removeEventListener('scroll', this._onScroll);
        this.viewport.removeEventListener('wheel', this._onWheel);
        this.viewport.removeEventListener('pointerdown', this._onPointerDown, true);
        window.removeEventListener('pointermove', this._onPointerMove);
        window.removeEventListener('pointerup', this._onPointerUp);
        window.removeEventListener('pointercancel', this._onPointerUp);
        document.removeEventListener('keydown', this._onKeyDown);
        document.removeEventListener('keyup', this._onKeyUp);
        window.removeEventListener('blur', this._onBlur);
        this.viewport.removeEventListener('gesturestart', this._onGestureStart);
        this.viewport.removeEventListener('gesturechange', this._onGestureChange);
        this.viewport.removeEventListener('gestureend', this._onGestureEnd);
        if (this._anim) cancelAnimationFrame(this._anim);
        Object.values(this._bars).forEach((bar) => bar.remove());
    }

    // --- Geometry --------------------------------------------------------------

    /** Unscaled stage size (transforms do not affect offset*). */
    _stageSize() {
        return { w: this.stage.offsetWidth, h: this.stage.offsetHeight };
    }

    _ranges(z = this.zoom) {
        const { w, h } = this._stageSize();
        const vw = this.viewport.clientWidth;
        const vh = this.viewport.clientHeight;
        let lx = 0, hx = w * z, ly = 0, hy = h * z;
        const extra = this.options.extraBounds ? this.options.extraBounds() : null;
        if (extra) {
            lx = Math.min(lx, extra.left * z);
            hx = Math.max(hx, extra.right * z);
            ly = Math.min(ly, extra.top * z);
            hy = Math.max(hy, extra.bottom * z);
        }
        return { x: axisRange(vw, lx, hx, w * z), y: axisRange(vh, ly, hy, h * z) };
    }

    isPannable() {
        const r = this._ranges();
        return r.x.pannable || r.y.pannable;
    }

    // --- View ------------------------------------------------------------------

    /** Set the zoom, keeping the viewport point (px, py) fixed on the artwork
     *  (default: the viewport centre). Re-clamps. */
    setZoom(z, px, py) {
        z = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, z));
        const vw = this.viewport.clientWidth;
        const vh = this.viewport.clientHeight;
        const ax = px === undefined ? vw / 2 : px;
        const ay = py === undefined ? vh / 2 : py;
        const old = this.zoom || 1;
        // Stage point under the anchor stays under it.
        this.x = ax - ((ax - this.x) / old) * z;
        this.y = ay - ((ay - this.y) / old) * z;
        this.zoom = z;
        this._stopAnim();
        this.clamp(false);
    }

    /** Centre the canvas (fit / reset). */
    centre() {
        const r = this._ranges();
        this.x = clampTo(r.x.centre, r.x);
        this.y = clampTo(r.y.centre, r.y);
        this._stopAnim();
        this._apply();
    }

    panBy(dx, dy) {
        this._stopAnim();
        const r = this._ranges();
        this.x = clampTo(this.x - dx, r.x);
        this.y = clampTo(this.y - dy, r.y);
        this._apply();
        this._flashBars();
    }

    /** Pull the view back inside the current bounds — eased when `animate`. */
    clamp(animate) {
        const r = this._ranges();
        const tx = clampTo(this.x, r.x);
        const ty = clampTo(this.y, r.y);
        if (!animate || (Math.abs(tx - this.x) < 0.5 && Math.abs(ty - this.y) < 0.5)) {
            this._stopAnim();
            this.x = tx;
            this.y = ty;
            this._apply();
            return;
        }
        const fromX = this.x, fromY = this.y;
        const start = performance.now();
        const duration = 220;
        this._stopAnim();
        const step = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const e = 1 - Math.pow(1 - t, 3);
            this.x = fromX + (tx - fromX) * e;
            this.y = fromY + (ty - fromY) * e;
            this._apply();
            this._anim = t < 1 ? requestAnimationFrame(step) : null;
        };
        this._anim = requestAnimationFrame(step);
    }

    _stopAnim() {
        if (this._anim) cancelAnimationFrame(this._anim);
        this._anim = null;
    }

    _apply() {
        const z = this.zoom;
        this.stage.style.transformOrigin = '0 0';
        this.stage.style.transform = `translate(${this.x}px, ${this.y}px) scale(${z})`;
        this.stage.style.setProperty('--fill-zoom', z);
        this.viewport.classList.toggle('is-pannable', this.isPannable());
        this._updateBars();
        if (this.options.onChange) this.options.onChange();
    }

    // --- Position bars -----------------------------------------------------------

    _makeBar(axis) {
        const bar = document.createElement('div');
        bar.className = `fill-viewport__bar fill-viewport__bar--${axis}`;
        bar.setAttribute('aria-hidden', 'true');
        const thumb = document.createElement('div');
        thumb.className = 'fill-viewport__thumb';
        bar.appendChild(thumb);
        this.viewport.appendChild(bar);
        return bar;
    }

    _updateBars() {
        const r = this._ranges();
        const vw = this.viewport.clientWidth;
        const vh = this.viewport.clientHeight;
        [['x', vw, r.x, this.x], ['y', vh, r.y, this.y]].forEach(([axis, size, range, pos]) => {
            const bar = this._bars[axis];
            if (!range.pannable) {
                bar.hidden = true;
                return;
            }
            bar.hidden = false;
            const content = size + (range.max - range.min);
            const track = axis === 'x' ? bar.clientWidth || size : bar.clientHeight || size;
            const thumbSize = Math.max(24, track * (size / content));
            const progress = range.max === range.min ? 0 : (range.max - pos) / (range.max - range.min);
            const offset = (track - thumbSize) * progress;
            const thumb = bar.firstElementChild;
            if (axis === 'x') {
                thumb.style.width = `${thumbSize}px`;
                thumb.style.transform = `translateX(${offset}px)`;
            } else {
                thumb.style.height = `${thumbSize}px`;
                thumb.style.transform = `translateY(${offset}px)`;
            }
        });
    }

    _flashBars() {
        this.viewport.classList.add('is-panning');
        clearTimeout(this._barsTimer);
        this._barsTimer = setTimeout(() => this.viewport.classList.remove('is-panning'), 800);
    }

    // --- Wheel / trackpad ----------------------------------------------------------

    _wheel(event) {
        if (event.ctrlKey || event.metaKey) {
            // Pinch (Chrome/Firefox report it as ctrl+wheel) or ctrl/⌘ + wheel.
            event.preventDefault();
            const rect = this.viewport.getBoundingClientRect();
            // A trackpad pinch sends many small deltas, a mouse notch one big
            // one (~100): clamp per event so a notch is a ~25 % step.
            const delta = Math.max(-60, Math.min(60, wheelPixels(event, 'y')));
            const factor = Math.exp(-delta * 0.005);
            this.setZoom(this.zoom * factor, event.clientX - rect.left, event.clientY - rect.top);
            if (this.options.onZoom) this.options.onZoom(this.zoom);
            return;
        }
        let dx = wheelPixels(event, 'x');
        let dy = wheelPixels(event, 'y');
        if (event.shiftKey && dx === 0) {
            dx = dy;
            dy = 0;
        }
        const r = this._ranges();
        // Capture an axis only when it can still move in that direction —
        // otherwise let the page scroll (no scroll trap at the fit zoom).
        const canX = r.x.pannable && ((dx > 0 && this.x > r.x.min + 0.5) || (dx < 0 && this.x < r.x.max - 0.5));
        const canY = r.y.pannable && ((dy > 0 && this.y > r.y.min + 0.5) || (dy < 0 && this.y < r.y.max - 0.5));
        if (!canX && !canY) return;
        event.preventDefault();
        this.panBy(canX ? dx : 0, canY ? dy : 0);
    }

    // --- Safari gestures ---------------------------------------------------------

    _gestureStart(event) {
        event.preventDefault();
        this._gestureZoom = this.zoom;
    }

    _gestureChange(event) {
        event.preventDefault();
        const rect = this.viewport.getBoundingClientRect();
        this.setZoom((this._gestureZoom || this.zoom) * event.scale, event.clientX - rect.left, event.clientY - rect.top);
        if (this.options.onZoom) this.options.onZoom(this.zoom);
    }

    // --- Pointer: Space / middle drag, touch pan + pinch -------------------------

    _pointerDown(event) {
        if (event.pointerType === 'touch') {
            this._touchDown(event);
            return;
        }
        const pan = event.button === 1 || (event.button === 0 && this._space);
        if (!pan) return;
        event.preventDefault();
        event.stopPropagation();
        this._drag = { id: event.pointerId, x: event.clientX, y: event.clientY };
        this.viewport.classList.add('is-grabbing');
    }

    _touchDown(event) {
        this._pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (this._pointers.size === 2) {
            // Two fingers always pinch — even when the first one landed on a picture.
            event.preventDefault();
            event.stopPropagation();
            this._drag = null;
            this._pinch = this._pinchState();
            return;
        }
        if (this._pointers.size === 1 && this.isPannable() && !(this.options.isInteractive && this.options.isInteractive(event))) {
            event.preventDefault();
            event.stopPropagation();
            this._drag = { id: event.pointerId, x: event.clientX, y: event.clientY };
        }
    }

    _pinchState() {
        const [a, b] = [...this._pointers.values()];
        const rect = this.viewport.getBoundingClientRect();
        return {
            dist: Math.hypot(a.x - b.x, a.y - b.y) || 1,
            zoom: this.zoom,
            cx: (a.x + b.x) / 2 - rect.left,
            cy: (a.y + b.y) / 2 - rect.top,
        };
    }

    _pointerMove(event) {
        if (this._pointers.has(event.pointerId)) {
            this._pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        }
        if (this._pinch && this._pointers.size >= 2) {
            const now = this._pinchState();
            this.setZoom(this._pinch.zoom * (now.dist / this._pinch.dist), now.cx, now.cy);
            // Two-finger drag pans too.
            this.panBy(this._pinch.cx - now.cx, this._pinch.cy - now.cy);
            this._pinch.cx = now.cx;
            this._pinch.cy = now.cy;
            if (this.options.onZoom) this.options.onZoom(this.zoom);
            return;
        }
        if (!this._drag || this._drag.id !== event.pointerId) return;
        const dx = event.clientX - this._drag.x;
        const dy = event.clientY - this._drag.y;
        this._drag.x = event.clientX;
        this._drag.y = event.clientY;
        this.panBy(-dx, -dy);
    }

    _pointerUp(event) {
        this._pointers.delete(event.pointerId);
        if (this._pointers.size < 2) this._pinch = null;
        if (this._drag && this._drag.id === event.pointerId) {
            this._drag = null;
            this.viewport.classList.remove('is-grabbing');
        }
    }

    // --- Keyboard ------------------------------------------------------------------

    _setSpace(on) {
        this._space = on;
        this.viewport.classList.toggle('is-grab', on);
    }

    _keyDown(event) {
        if (event.key === ' ' || event.code === 'Space') {
            if (isEditable(event.target) || event.target instanceof HTMLButtonElement) return;
            // Only while the pointer is over the preview or it has focus —
            // Space elsewhere keeps scrolling the page.
            if (!this.viewport.matches(':hover') && document.activeElement !== this.viewport) return;
            event.preventDefault();
            if (!event.repeat) this._setSpace(true);
            return;
        }
        if (document.activeElement !== this.viewport || event.ctrlKey || event.metaKey || event.altKey) return;
        if (this.options.ownsArrows && this.options.ownsArrows()) return;
        const step = event.shiftKey ? 200 : 40;
        const keys = {
            ArrowLeft: () => this.panBy(-step, 0),
            ArrowRight: () => this.panBy(step, 0),
            ArrowUp: () => this.panBy(0, -step),
            ArrowDown: () => this.panBy(0, step),
        };
        if (keys[event.key]) {
            event.preventDefault();
            keys[event.key]();
            return;
        }
        if (this.options.onKey && this.options.onKey(event.key)) event.preventDefault();
    }

    _keyUp(event) {
        if (event.key === ' ' || event.code === 'Space') this._setSpace(false);
    }
}
