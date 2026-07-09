import { Controller } from "@hotwired/stimulus";

/**
 * Figma / Canva style smart guides + snapping for the admin canvas editor —
 * the "cheap Photoshop" precision layer. As the user drags an object (or a
 * multi-selection), its edges and centers snap to the edges/centers of the
 * OTHER objects and to the canvas edges/center, magenta guide lines appear to
 * explain the snap, a live px badge shows the gap to the neighbour it aligned
 * with, and `=` marks appear when the object lands evenly between two others.
 *
 * SMOOTHNESS is a first-class concern here (a naive re-snap-every-frame reads
 * as jittery on a dense grid). Three things keep it deliberate:
 *   - Sticky HYSTERESIS: a line is CAPTUREd within a small radius but only
 *     RELEASEd once the free (pointer-driven) position pulls a larger distance
 *     away. Once snapped it holds, instead of chattering on/off at the boundary
 *     or hopping between neighbouring grid lines every few pixels.
 *   - CENTER BIAS: center-to-center wins over an edge match at (nearly) the
 *     same distance, so it stops oscillating between "align left" and "align
 *     center".
 *   - The guide/badge DOM is REUSED (repositioned + toggled), never recreated
 *     per frame — so the lines don't flicker.
 *
 * Guides are plain DOM in the UNSCALED `.canvas-stage` layer (the same place
 * canvas_floating_toolbar draws its `.editable-outline`s), NEVER on the Fabric
 * bitmap — so they can't leak into the saved preview thumbnail or the
 * server-side PNG export, and we sidestep Fabric's contextTop retina/viewport
 * handling. The stage→screen geometry is identical to the floating toolbar's
 * `_geometry()`, cached once per drag gesture (nothing scrolls/zooms mid-drag).
 *
 * All radii are SCREEN px, divided by the live geometry scale each frame so the
 * magnetism feels the same at every zoom (zoom is a CSS transform folded into
 * `scale`). Holding ⌘ / Ctrl bypasses; a left-panel switch turns it off.
 *
 * Fabric v7 note: the editor's canvas dispatches MOUSE events (not pointer
 * events) — object:moving is what drives this.
 *
 * State is initialised in `initialize()` (not `connect()`) because Stimulus
 * outlet callbacks can fire before `connect()`.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer"];

    static CAPTURE_PX = 6;   // engage a snap within this screen radius
    static RELEASE_PX = 10;  // …but only let go once this far away (hysteresis)
    static CENTER_BIAS_PX = 3; // prefer center↔center over a nearby edge match

    // Which of the moving box's lines snap to which of a target's lines. Only
    // MATCHED roles (left↔left, center↔center, right↔right) plus opposite-edge
    // ADJACENCY (left↔right / right↔left) — never left↔center etc. Snapping every
    // 3×3 combination floods a grid with competing lines and is what makes the
    // box "jump a lot"; this keeps only the alignments a user actually intends.
    static PAIRS = {
        x: [['left', 'left'], ['cx', 'cx'], ['right', 'right'], ['left', 'right'], ['right', 'left']],
        y: [['top', 'top'], ['cy', 'cy'], ['bottom', 'bottom'], ['top', 'bottom'], ['bottom', 'top']],
    };

    initialize() {
        this._enabled = true;
        this._targets = null;             // target rects, cached per gesture
        this._geo = null;                 // stage geometry, cached per gesture
        this._snap = { x: null, y: null };// active snapped line per axis (hysteresis)
        this._vLine = null;
        this._hLine = null;
        this._badges = [];                // reusable badge element pool
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
        this._snap = { x: null, y: null };
    }

    // --- core -------------------------------------------------------------

    _handleMoving(e) {
        if (!this._enabled || !this._canvas) { this._hideAll(); return; }
        const obj = e.target;
        if (!obj) { this._hideAll(); return; }

        // ⌘ / Ctrl held → free placement, no snapping this drag.
        const domEvt = e.e || {};
        if (domEvt.metaKey || domEvt.ctrlKey) { this._hideAll(); this._snap = { x: null, y: null }; return; }

        if (!this._geo) this._geo = this._geometry();
        const geo = this._geo;
        if (!geo) return;

        // Exclude the moving object and (for a multi-selection) its members —
        // the whole selection snaps by its shared bounding box (center↔center
        // included), never to its own members.
        if (!this._targets) {
            const members = typeof obj.getObjects === 'function' ? obj.getObjects() : [];
            this._targets = this._collectTargets(new Set([obj, ...members]));
        }

        // Fresh coords before reading the free (pointer-driven) position, so a
        // stale bounding box can never make the snap delta overcorrect.
        obj.setCoords();
        let mr = this._rect(obj);

        // 1) Edge/center alignment with sticky hysteresis, per axis.
        const xSnap = this._axisSnap('x', mr, geo);
        const ySnap = this._axisSnap('y', mr, geo);
        if (xSnap) obj.left += xSnap.delta;
        if (ySnap) obj.top += ySnap.delta;

        // 2) Equal spacing — only on an axis that did NOT hard-align.
        obj.setCoords();
        mr = this._rect(obj);
        const cap = this.constructor.CAPTURE_PX / geo.scale;
        const xEqual = xSnap ? null : this._equalSpaceAxis('x', mr, cap);
        const yEqual = ySnap ? null : this._equalSpaceAxis('y', mr, cap);
        if (xEqual) obj.left += xEqual.delta;
        if (yEqual) obj.top += yEqual.delta;

        if (xSnap || ySnap || xEqual || yEqual) {
            obj.setCoords();
            this._canvas.requestRenderAll();
        }

        // 3) Draw the chrome from the final position (reused DOM nodes).
        const fr = this._rect(obj);
        this._draw(xSnap, ySnap, xEqual, yEqual, fr, geo);
    }

    /**
     * One axis, with hysteresis. Keeps the currently-snapped line as long as the
     * free position stays within RELEASE of it; otherwise looks for a fresh
     * CAPTURE (center-biased, closest wins). Returns {key, coord, delta, target}
     * or null. `mr` is the object's CURRENT (pointer-driven, pre-snap) bbox.
     */
    _axisSnap(axis, mr, geo) {
        const cap = this.constructor.CAPTURE_PX / geo.scale;
        const rel = this.constructor.RELEASE_PX / geo.scale;
        const bias = this.constructor.CENTER_BIAS_PX / geo.scale;
        const lines = axis === 'x'
            ? { left: mr.left, cx: mr.cx, right: mr.right }
            : { top: mr.top, cy: mr.cy, bottom: mr.bottom };
        const centerKey = axis === 'x' ? 'cx' : 'cy';

        // Hold the active snap until the pointer pulls RELEASE px away.
        const active = this._snap[axis];
        if (active && lines[active.key] !== undefined) {
            if (Math.abs(active.coord - lines[active.key]) <= rel) {
                return { key: active.key, coord: active.coord, delta: active.coord - lines[active.key], target: active.target };
            }
            this._snap[axis] = null; // broke free
        }

        // Look for a new capture — matched-role / adjacency pairs only.
        const pairs = this.constructor.PAIRS[axis];
        let best = null;
        for (const t of this._targets) {
            const tLines = axis === 'x'
                ? { left: t.left, cx: t.cx, right: t.right }
                : { top: t.top, cy: t.cy, bottom: t.bottom };
            for (const [mk, tk] of pairs) {
                const raw = Math.abs(tLines[tk] - lines[mk]);
                if (raw > cap) continue;
                // Bias center↔center (tie-break only — does NOT widen capture).
                const score = raw - (mk === centerKey && tk === centerKey ? bias : 0);
                if (!best || score < best.score) {
                    best = { score, key: mk, coord: tLines[tk], delta: tLines[tk] - lines[mk], target: t };
                }
            }
        }
        if (best) this._snap[axis] = { key: best.key, coord: best.coord, target: best.target };
        return best;
    }

    /**
     * "Centered between two neighbours" equal spacing. Only real objects that
     * overlap the moving box on the perpendicular axis (same row/column) count;
     * when the two edge gaps are already roughly equal it nudges them to exactly
     * equal. Returns {delta, gap, loRect, hiRect} or null.
     */
    _equalSpaceAxis(axis, mr, tol) {
        const isX = axis === 'x';
        const near = mr[isX ? 'left' : 'top'];
        const far = mr[isX ? 'right' : 'bottom'];

        let lo = null, hi = null;
        for (const t of this._targets) {
            if (t.isCanvas) continue;
            const overlaps = isX
                ? this._overlap1D(mr.top, mr.bottom, t.top, t.bottom)
                : this._overlap1D(mr.left, mr.right, t.left, t.right);
            if (!overlaps) continue;
            const tHi = t[isX ? 'right' : 'bottom'];
            const tLo = t[isX ? 'left' : 'top'];
            if (tHi <= near + tol) { if (!lo || tHi > lo.hi) lo = { rect: t, hi: tHi }; }
            if (tLo >= far - tol) { if (!hi || tLo < hi.lo) hi = { rect: t, lo: tLo }; }
        }
        if (!lo || !hi) return null;

        const loGap = near - lo.hi;
        const hiGap = hi.lo - far;
        if (loGap < 0 || hiGap < 0) return null;
        if (Math.abs(loGap - hiGap) > tol * 2) return null;

        return { delta: (hiGap - loGap) / 2, gap: (loGap + hiGap) / 2, loRect: lo.rect, hiRect: hi.rect };
    }

    // --- drawing (reused DOM) --------------------------------------------

    _draw(xSnap, ySnap, xEqual, yEqual, fr, geo) {
        let bi = 0;

        // Vertical alignment line + gap badge.
        if (xSnap) {
            const t = xSnap.target;
            const y1 = t.isCanvas ? 0 : Math.min(fr.top, t.top);
            const y2 = t.isCanvas ? this._canvas.getHeight() : Math.max(fr.bottom, t.bottom);
            this._line('v', xSnap.coord, y1, y2, geo);
            if (!t.isCanvas) {
                const gap = this._gap1D(fr.top, fr.bottom, t.top, t.bottom);
                if (gap) this._badge(bi++, xSnap.coord, gap.mid, `${Math.round(gap.size)}`, geo);
            }
        } else { this._hideLine('v'); }

        // Horizontal alignment line + gap badge.
        if (ySnap) {
            const t = ySnap.target;
            const x1 = t.isCanvas ? 0 : Math.min(fr.left, t.left);
            const x2 = t.isCanvas ? this._canvas.getWidth() : Math.max(fr.right, t.right);
            this._line('h', ySnap.coord, x1, x2, geo);
            if (!t.isCanvas) {
                const gap = this._gap1D(fr.left, fr.right, t.left, t.right);
                if (gap) this._badge(bi++, gap.mid, ySnap.coord, `${Math.round(gap.size)}`, geo);
            }
        } else { this._hideLine('h'); }

        // Equal-spacing `=` marks.
        if (xEqual) {
            this._badge(bi++, (xEqual.loRect.right + fr.left) / 2, fr.cy, '=', geo, 'eq');
            this._badge(bi++, (fr.right + xEqual.hiRect.left) / 2, fr.cy, '=', geo, 'eq');
        }
        if (yEqual) {
            this._badge(bi++, fr.cx, (yEqual.loRect.bottom + fr.top) / 2, '=', geo, 'eq');
            this._badge(bi++, fr.cx, (fr.bottom + yEqual.hiRect.top) / 2, '=', geo, 'eq');
        }

        for (let i = bi; i < this._badges.length; i++) this._badges[i].style.display = 'none';
    }

    _line(dir, coord, from, to, geo) {
        const el = this._ensureLine(dir);
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

    _overlap1D(a1, a2, b1, b2) {
        return Math.min(a2, b2) > Math.max(a1, b1);
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
