import { Controller } from "@hotwired/stimulus";

/**
 * Figma / Canva style smart guides + snapping for the admin canvas editor —
 * the "cheap Photoshop" precision layer. As the user drags an object (or a
 * multi-selection), its edges and centers snap to the edges/centers of the
 * OTHER objects and to the canvas edges/center, magenta guide lines appear to
 * explain the snap, a live px badge shows the gap to the neighbour it aligned
 * with, and `=` marks appear when the object lands evenly between two others.
 *
 * Design decisions that matter here:
 *   - Guides are plain DOM in the UNSCALED `.canvas-stage` layer (the same
 *     place canvas_floating_toolbar draws its `.editable-outline`s), NEVER on
 *     the Fabric bitmap — so they can never leak into the saved preview
 *     thumbnail or the server-side PNG export, and we sidestep Fabric's
 *     contextTop retina/viewport-transform handling entirely. The stage→screen
 *     geometry math is identical to the floating toolbar's `_geometry()`.
 *   - The snap threshold is kept in SCREEN pixels and divided by the live
 *     geometry scale each frame, so the magnetism feels the same at every zoom
 *     level (zoom is a CSS transform on `.canvas-wrapper`, already folded into
 *     `scale`).
 *   - Target rects are snapshotted once per drag gesture (mouse:down resets the
 *     cache) — the other objects don't move while one is being dragged.
 *   - Holding ⌘ / Ctrl bypasses snapping for free placement (Figma muscle
 *     memory); a left-panel switch turns the whole feature off.
 *
 * State is initialised in `initialize()` (not `connect()`) because Stimulus
 * outlet callbacks can fire before `connect()`.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer"];

    /** Snap radius in SCREEN px (converted to canvas px per-frame). */
    static SNAP_SCREEN_PX = 6;

    initialize() {
        this._enabled = true;
        this._guides = [];    // DOM guide/badge elements shown for the current frame
        this._targets = null; // cached target rects for the active drag gesture
    }

    connect() {
        // Sync the enabled flag with the left-panel switch's initial state.
        const toggle = document.getElementById('snap-enabled-control');
        if (toggle) this._enabled = toggle.checked;
    }

    disconnect() {
        this._clearGuides();
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;
        this._canvas = canvas;

        this._onMoving = (e) => this._handleMoving(e);
        this._onDown = () => { this._targets = null; };
        this._onUp = () => { this._targets = null; this._clearGuides(); };
        this._onCleared = () => this._clearGuides();

        canvas.on('object:moving', this._onMoving);
        canvas.on('mouse:down', this._onDown);
        canvas.on('mouse:up', this._onUp);
        canvas.on('selection:cleared', this._onCleared);
    }

    canvasEditorOutletDisconnected(outlet) {
        const canvas = outlet.canvas;
        if (!canvas) return;
        canvas.off('object:moving', this._onMoving);
        canvas.off('mouse:down', this._onDown);
        canvas.off('mouse:up', this._onUp);
        canvas.off('selection:cleared', this._onCleared);
    }

    /** Left-panel switch. */
    toggleSnapping(event) {
        this._enabled = event.target.checked;
        if (!this._enabled) this._clearGuides();
    }

    // --- core -------------------------------------------------------------

    _handleMoving(e) {
        this._clearGuides();

        if (!this._enabled || !this._canvas) return;
        const obj = e.target;
        if (!obj) return;

        // ⌘ / Ctrl held → free placement, no snapping this drag.
        const domEvt = e.e || {};
        if (domEvt.metaKey || domEvt.ctrlKey) return;

        const g = this._geometry();
        if (!g) return;

        // Exclude the moving object and (for a multi-selection) its members
        // from the target set — they travel with the drag.
        const members = typeof obj.getObjects === 'function' ? obj.getObjects() : [];
        const exclude = new Set([obj, ...members]);
        if (!this._targets) this._targets = this._collectTargets(exclude);

        // Screen radius → canvas radius (constant on-screen feel at any zoom).
        const threshold = this.constructor.SNAP_SCREEN_PX / g.scale;

        let mr = this._rect(obj);

        // 1) Edge/center alignment — nearest match per axis, closest wins.
        const xSnap = this._snapAxis('x', mr, this._targets, threshold);
        const ySnap = this._snapAxis('y', mr, this._targets, threshold);
        if (xSnap) obj.left += xSnap.delta;
        if (ySnap) obj.top += ySnap.delta;

        // 2) Equal spacing — only on an axis that did NOT hard-align (an
        //    alignment shift on the same axis would fight the equalisation).
        obj.setCoords();
        mr = this._rect(obj);
        const xEqual = xSnap ? null : this._equalSpaceAxis('x', mr, this._targets, threshold);
        const yEqual = ySnap ? null : this._equalSpaceAxis('y', mr, this._targets, threshold);
        if (xEqual) obj.left += xEqual.delta;
        if (yEqual) obj.top += yEqual.delta;

        if (xSnap || ySnap || xEqual || yEqual) {
            obj.setCoords();
            this._canvas.requestRenderAll();
        }

        // 3) Draw the explanation chrome from the final position.
        const fr = this._rect(obj);
        if (xSnap) this._drawAlignment('x', xSnap, fr, g);
        if (ySnap) this._drawAlignment('y', ySnap, fr, g);
        if (xEqual) this._drawEqual('x', xEqual, fr, g);
        if (yEqual) this._drawEqual('y', yEqual, fr, g);
    }

    /** Nearest edge/center match for one axis. Returns {delta, coord, target} or null. */
    _snapAxis(axis, mr, targets, threshold) {
        const movingLines = axis === 'x'
            ? [mr.left, mr.cx, mr.right]
            : [mr.top, mr.cy, mr.bottom];

        let best = null;
        for (const t of targets) {
            const targetLines = axis === 'x' ? [t.left, t.cx, t.right] : [t.top, t.cy, t.bottom];
            for (const mv of movingLines) {
                for (const tv of targetLines) {
                    const d = Math.abs(tv - mv);
                    if (d <= threshold && (!best || d < best.dist)) {
                        best = { dist: d, coord: tv, delta: tv - mv, target: t };
                    }
                }
            }
        }
        return best;
    }

    /**
     * "Centered between two neighbours" equal spacing. Considers only real
     * objects that overlap the moving box on the perpendicular axis (i.e. are
     * in the same row/column), finds the nearest neighbour on each side, and —
     * when the two edge gaps are already roughly equal — nudges the object so
     * they become exactly equal. Returns {delta, gap, left, right} or null.
     */
    _equalSpaceAxis(axis, mr, targets, threshold) {
        const isX = axis === 'x';
        const near = mr[isX ? 'left' : 'top'];
        const far = mr[isX ? 'right' : 'bottom'];

        let lo = null, hi = null; // neighbour on the low side / high side
        for (const t of targets) {
            if (t.isCanvas) continue;
            const overlaps = isX
                ? this._overlap1D(mr.top, mr.bottom, t.top, t.bottom)
                : this._overlap1D(mr.left, mr.right, t.left, t.right);
            if (!overlaps) continue;

            const tHi = t[isX ? 'right' : 'bottom'];
            const tLo = t[isX ? 'left' : 'top'];
            if (tHi <= near + threshold) { if (!lo || tHi > lo.hi) lo = { rect: t, hi: tHi }; }
            if (tLo >= far - threshold) { if (!hi || tLo < hi.lo) hi = { rect: t, lo: tLo }; }
        }
        if (!lo || !hi) return null;

        const loGap = near - lo.hi;
        const hiGap = hi.lo - far;
        if (loGap < 0 || hiGap < 0) return null;
        // Only engage when the gaps are already close to equal (magnetic, not a
        // long-range yank).
        if (Math.abs(loGap - hiGap) > threshold * 2) return null;

        const delta = (hiGap - loGap) / 2; // shift toward the larger gap
        return { delta, gap: (loGap + hiGap) / 2, loRect: lo.rect, hiRect: hi.rect };
    }

    // --- drawing ----------------------------------------------------------

    /** Alignment guide line + (for object targets) a live gap badge. */
    _drawAlignment(axis, snap, fr, g) {
        const t = snap.target;
        if (axis === 'x') {
            // Vertical line at the shared X; span both boxes (or full canvas).
            const y1 = t.isCanvas ? 0 : Math.min(fr.top, t.top);
            const y2 = t.isCanvas ? this._canvas.getHeight() : Math.max(fr.bottom, t.bottom);
            this._line('v', snap.coord, y1, y2, g);
            if (!t.isCanvas) {
                const gap = this._gap1D(fr.top, fr.bottom, t.top, t.bottom);
                if (gap) this._badge(snap.coord, gap.mid, `${Math.round(gap.size)}`, g);
            }
        } else {
            const x1 = t.isCanvas ? 0 : Math.min(fr.left, t.left);
            const x2 = t.isCanvas ? this._canvas.getWidth() : Math.max(fr.right, t.right);
            this._line('h', snap.coord, x1, x2, g);
            if (!t.isCanvas) {
                const gap = this._gap1D(fr.left, fr.right, t.left, t.right);
                if (gap) this._badge(gap.mid, snap.coord, `${Math.round(gap.size)}`, g);
            }
        }
    }

    /** Two `=` marks in the equalised gaps. */
    _drawEqual(axis, eq, fr, g) {
        const label = `${Math.round(eq.gap)}`;
        if (axis === 'x') {
            const y = fr.cy;
            this._badge((eq.loRect.right + fr.left) / 2, y, label, g, 'eq');
            this._badge((fr.right + eq.hiRect.left) / 2, y, label, g, 'eq');
        } else {
            const x = fr.cx;
            this._badge(x, (eq.loRect.bottom + fr.top) / 2, label, g, 'eq');
            this._badge(x, (fr.bottom + eq.hiRect.top) / 2, label, g, 'eq');
        }
    }

    _line(dir, coord, from, to, g) {
        if (!this.hasLayerTarget) return;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const el = document.createElement('div');
        el.className = `snap-guide snap-guide--${dir}`;
        if (dir === 'v') {
            el.style.left = `${offX + coord * g.scale}px`;
            el.style.top = `${offY + from * g.scale}px`;
            el.style.height = `${(to - from) * g.scale}px`;
        } else {
            el.style.top = `${offY + coord * g.scale}px`;
            el.style.left = `${offX + from * g.scale}px`;
            el.style.width = `${(to - from) * g.scale}px`;
        }
        this.layerTarget.appendChild(el);
        this._guides.push(el);
    }

    _badge(cx, cy, text, g, variant) {
        if (!this.hasLayerTarget) return;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const el = document.createElement('div');
        el.className = 'snap-badge' + (variant === 'eq' ? ' snap-badge--eq' : '');
        el.textContent = variant === 'eq' ? '=' : text;
        el.style.left = `${offX + cx * g.scale}px`;
        el.style.top = `${offY + cy * g.scale}px`;
        this.layerTarget.appendChild(el);
        this._guides.push(el);
    }

    _clearGuides() {
        (this._guides || []).forEach((el) => el.remove());
        this._guides = [];
    }

    // --- helpers ----------------------------------------------------------

    _collectTargets(exclude) {
        const rects = this._canvas.getObjects()
            .filter((o) => !exclude.has(o) && o.visible !== false)
            .map((o) => this._rect(o));
        const W = this._canvas.getWidth();
        const H = this._canvas.getHeight();
        // Canvas edges + center as a synthetic target (no gap badge for it).
        rects.push({ left: 0, top: 0, right: W, bottom: H, cx: W / 2, cy: H / 2, isCanvas: true });
        return rects;
    }

    /** Axis-aligned canvas-space bbox of a Fabric object, with derived lines. */
    _rect(o) {
        const r = o.getBoundingRect();
        return {
            left: r.left, top: r.top,
            right: r.left + r.width, bottom: r.top + r.height,
            cx: r.left + r.width / 2, cy: r.top + r.height / 2,
            isCanvas: false,
        };
    }

    _overlap1D(a1, a2, b1, b2) {
        return Math.min(a2, b2) > Math.max(a1, b1);
    }

    /** Empty gap between two 1D ranges, with its midpoint. null when they overlap. */
    _gap1D(a1, a2, b1, b2) {
        if (a2 < b1) return { size: b1 - a2, mid: (a2 + b1) / 2 };
        if (b2 < a1) return { size: a1 - b2, mid: (b2 + a1) / 2 };
        return null;
    }

    /**
     * Live stage→screen geometry — identical to canvas_floating_toolbar's
     * `_geometry()`. `scale` folds in both retina DPR and the CSS zoom
     * transform (contRect is the post-transform visual rect).
     */
    _geometry() {
        if (!this.hasCanvasEditorOutlet || !this.hasLayerTarget) return null;
        const canvas = this.canvasEditorOutlet.canvas;
        const canvasEl = canvas.getElement();
        if (!canvasEl) return null;
        const container = canvasEl.parentElement || canvasEl;
        const contRect = container.getBoundingClientRect();
        const layerRect = this.layerTarget.getBoundingClientRect();
        const logicalWidth = (typeof canvas.getWidth === 'function' ? canvas.getWidth() : canvas.width) || canvasEl.width;
        const scale = logicalWidth ? contRect.width / logicalWidth : 1;
        return { contRect, layerRect, scale };
    }
}
