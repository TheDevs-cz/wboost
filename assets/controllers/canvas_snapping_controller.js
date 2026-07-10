import { Controller } from "@hotwired/stimulus";

/**
 * Figma / Canva style smart guides + snapping for the admin canvas editor —
 * the "cheap Photoshop" precision layer. As the user drags an object (or a
 * multi-selection — it snaps by its shared bounding box, center included),
 * its edges/centers snap to the other objects' edges/centers and to the
 * canvas edges/center; magenta guide lines explain the snap, a live px badge
 * shows the gap to the snapped neighbour, and `=` marks appear when the
 * object sits evenly between two others.
 *
 * SMOOTHNESS is the key requirement, and it rests on ONE state machine per
 * axis instead of independent competing snap rules:
 *
 *   - Every possible snap — alignment lines AND equal-spacing midpoints — is
 *     a CANDIDATE. Per axis at most ONE candidate is ACTIVE at a time.
 *   - Capture/release HYSTERESIS: a candidate captures within CAPTURE_PX and
 *     the active one holds until the free (pointer-driven) position pulls
 *     RELEASE_PX away. While held, no rescanning — nothing can steal the
 *     snap mid-hold, and hand jitter at the boundary can't flip it on/off
 *     (the old equal-spacing rule re-derived per frame with no memory: ±1px
 *     of cursor noise oscillated the box several px every frame).
 *   - A release NEVER captures again in the same frame: the object visibly
 *     rejoins the cursor for at least one frame between locks. Without this,
 *     dense canvases ratchet the box line→line→line (glue, jump, re-glue)
 *     and it never appears to follow the cursor.
 *   - The release jump IS the release radius, so RELEASE_PX stays small.
 *   - Matched-role pairs only (left↔left, center↔center, right↔right, plus
 *     opposite-edge adjacency) — never left↔center — so a grid of objects
 *     produces few, intentional lines instead of a flood.
 *
 * All radii are SCREEN px, divided by the live stage scale each gesture, so
 * the magnetism feels identical at every zoom (zoom is a CSS transform on
 * .canvas-wrapper, folded into `scale`). Holding ⌘/Ctrl bypasses snapping;
 * the left-panel switch (#snap-enabled-control) turns it off entirely.
 *
 * Guides are plain DOM in the UNSCALED `.canvas-stage` layer (the same place
 * canvas_floating_toolbar draws its `.editable-outline`s), POOLED and
 * repositioned — never recreated per frame (no flicker), and NEVER on the
 * Fabric bitmap, so they cannot leak into the saved preview thumbnail or the
 * server-side PNG export. Stage→screen math mirrors the floating toolbar's
 * `_geometry()`, cached per drag gesture.
 *
 * Fabric v7 notes: the canvas dispatches MOUSE events (not pointer events);
 * the drag handler sets left/top ABSOLUTELY from the pointer each move, so
 * on every `object:moving` the object's own position IS the free position —
 * our correction from the previous frame never feeds back. Programmatic
 * moves (arrow nudge, container reflow) fire no object:moving, but guard on
 * e.e anyway. State lives in `initialize()` (outlets can connect before
 * connect()).
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer"];

    static CAPTURE_PX = 5;      // engage a snap within this screen radius
    static RELEASE_PX = 7;      // hold until this far away = max release jump
    static CENTER_BIAS_PX = 2;  // prefer center↔center over a nearby edge match

    // Moving-box line ↔ target line pairs allowed to snap, per axis.
    static PAIRS = {
        x: [['left', 'left'], ['cx', 'cx'], ['right', 'right'], ['left', 'right'], ['right', 'left']],
        y: [['top', 'top'], ['cy', 'cy'], ['bottom', 'bottom'], ['top', 'bottom'], ['bottom', 'top']],
    };

    initialize() {
        this._enabled = true;
        this._targets = null;              // target rects, cached per gesture
        this._geo = null;                  // stage geometry, cached per gesture
        this._active = { x: null, y: null }; // the held snap per axis
        this._vLine = null;
        this._hLine = null;
        this._badges = [];                 // reusable badge element pool
    }

    connect() {
        const toggle = document.getElementById('snap-enabled-control');
        if (toggle) this._enabled = toggle.checked;
    }

    disconnect() {
        [this._vLine, this._hLine, ...this._badges].forEach((el) => el && el.remove());
        this._vLine = this._hLine = null;
        this._badges = [];
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;
        this._canvas = canvas;

        this._onMoving = (e) => this._handleMoving(e);
        this._onDown = () => this._resetGesture();
        this._onUp = () => { this._resetGesture(); this._hideAll(); };
        this._onCleared = () => this._hideAll();

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

    toggleSnapping(event) {
        this._enabled = event.target.checked;
        if (!this._enabled) this._hideAll();
    }

    _resetGesture() {
        this._targets = null;
        this._geo = null;
        this._active = { x: null, y: null };
    }

    // --- core -------------------------------------------------------------

    _handleMoving(e) {
        if (!this._enabled || !this._canvas) { this._hideAll(); return; }
        const obj = e.target;
        const src = e.e;
        // Programmatic move, or ⌘/Ctrl held → free placement, drop any hold.
        if (!obj || !src || src.metaKey || src.ctrlKey) {
            this._active = { x: null, y: null };
            this._hideAll();
            return;
        }

        if (!this._geo) this._geo = this._geometry();
        const geo = this._geo;
        if (!geo) return;

        // Exclude the moving object and (for a multi-selection) its members —
        // the selection snaps as one box, never against its own members.
        if (!this._targets) {
            const members = typeof obj.getObjects === 'function' ? obj.getObjects() : [];
            this._targets = this._collectTargets(new Set([obj, ...members]));
        }

        // Fabric has just set the free (pointer-driven) position — read it.
        obj.setCoords();
        const free = this._rect(obj);

        const rx = this._resolveAxis('x', free, geo);
        const ry = this._resolveAxis('y', free, geo);
        if (rx) obj.left += rx.delta;
        if (ry) obj.top += ry.delta;
        if (rx || ry) {
            obj.setCoords();
            this._canvas.requestRenderAll();
        }

        this._draw(rx, ry, this._rect(obj), geo);
    }

    /**
     * The per-axis snap state machine: hold the active snap (hysteresis),
     * else release (and skip capture this frame), else look for the nearest
     * new candidate. Returns {kind, key, coord, delta, ...} or null.
     */
    _resolveAxis(axis, free, geo) {
        const cap = this.constructor.CAPTURE_PX / geo.scale;
        const rel = this.constructor.RELEASE_PX / geo.scale;
        const bias = this.constructor.CENTER_BIAS_PX / geo.scale;
        const lines = axis === 'x'
            ? { left: free.left, cx: free.cx, right: free.right }
            : { top: free.top, cy: free.cy, bottom: free.bottom };
        const centerKey = axis === 'x' ? 'cx' : 'cy';

        // HOLD: while the free position stays within RELEASE, keep the active
        // snap — no rescanning, nothing can steal it, no boundary flutter.
        const act = this._active[axis];
        if (act) {
            const stillPaired = act.kind !== 'equal'
                || (this._overlapsPerp(axis, free, act.lo) && this._overlapsPerp(axis, free, act.hi));
            if (stillPaired && Math.abs(act.coord - lines[act.key]) <= rel) {
                return { ...act, delta: act.coord - lines[act.key] };
            }
            // RELEASE — and deliberately NO capture on this same frame, so the
            // object rejoins the cursor instead of ratcheting onto the next line.
            this._active[axis] = null;
            return null;
        }

        // CAPTURE: nearest candidate within CAPTURE wins; center↔center gets a
        // small score bias (tie-break only — never widens the radius).
        let best = null;
        for (const t of this._targets) {
            for (const [mk, tk] of this.constructor.PAIRS[axis]) {
                const coord = t[tk];
                if (!Number.isFinite(coord)) continue; // guide rects only carry their own axis
                const raw = Math.abs(coord - lines[mk]);
                if (raw > cap) continue;
                const score = raw - (mk === centerKey && tk === centerKey ? bias : 0);
                if (!best || score < best.score) {
                    best = { score, kind: 'align', key: mk, coord, delta: coord - lines[mk], target: t };
                }
            }
        }
        const eq = this._equalCandidate(axis, free, cap, rel);
        if (eq && (!best || eq.score < best.score)) best = eq;

        if (best) {
            this._active[axis] = {
                kind: best.kind, key: best.key, coord: best.coord,
                target: best.target || null, lo: best.lo || null, hi: best.hi || null,
            };
        }
        return best;
    }

    /**
     * Equal-spacing candidate: the position where the moving box sits exactly
     * midway between its nearest neighbour on each side (same row/column —
     * perpendicular overlap required). Expressed as a candidate line for the
     * near edge so it competes in the SAME capture/hold machine as alignment.
     */
    _equalCandidate(axis, free, cap, slack) {
        const isX = axis === 'x';
        const nearKey = isX ? 'left' : 'top';
        const farKey = isX ? 'right' : 'bottom';
        const near = free[nearKey];
        const far = free[farKey];
        const size = far - near;

        let lo = null, hi = null;
        for (const t of this._targets) {
            if (t.isCanvas || t.isGuide || !this._overlapsPerp(axis, free, t)) continue;
            if (t[farKey] <= near + slack && (!lo || t[farKey] > lo[farKey])) lo = t;
            if (t[nearKey] >= far - slack && (!hi || t[nearKey] < hi[nearKey])) hi = t;
        }
        if (!lo || !hi) return null;

        const span = hi[nearKey] - lo[farKey];
        if (span < size) return null; // can't fit between them with equal gaps

        const coord = lo[farKey] + (span - size) / 2; // near-edge pos for equal gaps
        const delta = coord - near;
        if (Math.abs(delta) > cap) return null;

        return { score: Math.abs(delta), kind: 'equal', key: nearKey, coord, delta, lo, hi };
    }

    /** Do two rects overlap on the axis PERPENDICULAR to the snap axis? */
    _overlapsPerp(axis, a, b) {
        return axis === 'x'
            ? Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top)
            : Math.min(a.right, b.right) > Math.max(a.left, b.left);
    }

    // --- drawing (pooled DOM) ----------------------------------------------

    _draw(rx, ry, fr, geo) {
        let bi = 0;

        if (rx && rx.kind === 'align') {
            const t = rx.target;
            const fullSpan = t.isCanvas || t.isGuide; // guides span the whole canvas
            const y1 = fullSpan ? 0 : Math.min(fr.top, t.top);
            const y2 = fullSpan ? this._canvas.getHeight() : Math.max(fr.bottom, t.bottom);
            this._line('v', rx.coord, y1, y2, geo);
            if (!fullSpan) {
                const gap = this._gap1D(fr.top, fr.bottom, t.top, t.bottom);
                if (gap) this._badge(bi++, rx.coord, gap.mid, `${Math.round(gap.size)}`, geo);
            }
        } else { this._hideLine('v'); }

        if (ry && ry.kind === 'align') {
            const t = ry.target;
            const fullSpan = t.isCanvas || t.isGuide;
            const x1 = fullSpan ? 0 : Math.min(fr.left, t.left);
            const x2 = fullSpan ? this._canvas.getWidth() : Math.max(fr.right, t.right);
            this._line('h', ry.coord, x1, x2, geo);
            if (!fullSpan) {
                const gap = this._gap1D(fr.left, fr.right, t.left, t.right);
                if (gap) this._badge(bi++, gap.mid, ry.coord, `${Math.round(gap.size)}`, geo);
            }
        } else { this._hideLine('h'); }

        if (rx && rx.kind === 'equal') {
            this._badge(bi++, (rx.lo.right + fr.left) / 2, fr.cy, '=', geo, 'eq');
            this._badge(bi++, (fr.right + rx.hi.left) / 2, fr.cy, '=', geo, 'eq');
        }
        if (ry && ry.kind === 'equal') {
            this._badge(bi++, fr.cx, (ry.lo.bottom + fr.top) / 2, '=', geo, 'eq');
            this._badge(bi++, fr.cx, (fr.bottom + ry.hi.top) / 2, '=', geo, 'eq');
        }

        for (let i = bi; i < this._badges.length; i++) this._badges[i].style.display = 'none';
    }

    _line(dir, coord, from, to, geo) {
        const el = this._ensureLine(dir);
        if (!el) return;
        const offX = geo.contRect.left - geo.layerRect.left;
        const offY = geo.contRect.top - geo.layerRect.top;
        if (dir === 'v') {
            el.style.left = `${offX + coord * geo.scale}px`;
            el.style.top = `${offY + from * geo.scale}px`;
            el.style.height = `${(to - from) * geo.scale}px`;
        } else {
            el.style.top = `${offY + coord * geo.scale}px`;
            el.style.left = `${offX + from * geo.scale}px`;
            el.style.width = `${(to - from) * geo.scale}px`;
        }
        el.style.display = '';
    }

    _ensureLine(dir) {
        const prop = dir === 'v' ? '_vLine' : '_hLine';
        if (!this[prop] && this.hasLayerTarget) {
            const el = document.createElement('div');
            el.className = `snap-guide snap-guide--${dir}`;
            el.style.display = 'none';
            this.layerTarget.appendChild(el);
            this[prop] = el;
        }
        return this[prop];
    }

    _hideLine(dir) {
        const el = dir === 'v' ? this._vLine : this._hLine;
        if (el) el.style.display = 'none';
    }

    _badge(i, cx, cy, text, geo, variant) {
        if (!this.hasLayerTarget) return;
        let el = this._badges[i];
        if (!el) {
            el = document.createElement('div');
            this.layerTarget.appendChild(el);
            this._badges[i] = el;
        }
        el.className = 'snap-badge' + (variant === 'eq' ? ' snap-badge--eq' : '');
        el.textContent = text;
        el.style.left = `${(geo.contRect.left - geo.layerRect.left) + cx * geo.scale}px`;
        el.style.top = `${(geo.contRect.top - geo.layerRect.top) + cy * geo.scale}px`;
        el.style.display = '';
    }

    _hideAll() {
        this._hideLine('v');
        this._hideLine('h');
        this._badges.forEach((el) => { el.style.display = 'none'; });
    }

    // --- helpers ----------------------------------------------------------

    _collectTargets(exclude) {
        const rects = this._canvas.getObjects()
            .filter((o) => !exclude.has(o) && o.visible !== false)
            .map((o) => this._rect(o));
        const W = this._canvas.getWidth();
        const H = this._canvas.getHeight();
        rects.push({ left: 0, top: 0, right: W, bottom: H, cx: W / 2, cy: H / 2, isCanvas: true });

        // Ruler guides (canvas_rulers_controller) are snap lines too — a
        // vertical guide is a zero-width "rect" spanning the canvas, so the
        // matched-role pairs all resolve to the same coordinate. Skipped when
        // the rulers toggle is off (hidden guides must not snap); isGuide
        // keeps them out of equal-spacing (they're lines, not neighbours).
        if (!this._canvas.wboostGuidesHidden && Array.isArray(this._canvas.wboostGuides)) {
            this._canvas.wboostGuides.forEach((guide) => {
                if (!Number.isFinite(guide.pos)) return;
                // Only the guide's OWN axis carries lines — the perpendicular
                // fields stay undefined and the capture loop's finite guard
                // skips them (a horizontal guide must never offer X lines).
                if (guide.axis === 'x') {
                    rects.push({ left: guide.pos, right: guide.pos, cx: guide.pos, isCanvas: false, isGuide: true });
                } else if (guide.axis === 'y') {
                    rects.push({ top: guide.pos, bottom: guide.pos, cy: guide.pos, isCanvas: false, isGuide: true });
                }
            });
        }
        return rects;
    }

    _rect(o) {
        const r = o.getBoundingRect();
        return {
            left: r.left, top: r.top,
            right: r.left + r.width, bottom: r.top + r.height,
            cx: r.left + r.width / 2, cy: r.top + r.height / 2,
            isCanvas: false,
        };
    }

    _gap1D(a1, a2, b1, b2) {
        if (a2 < b1) return { size: b1 - a2, mid: (a2 + b1) / 2 };
        if (b2 < a1) return { size: a1 - b2, mid: (b2 + a1) / 2 };
        return null;
    }

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
