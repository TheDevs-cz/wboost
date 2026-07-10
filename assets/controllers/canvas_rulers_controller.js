import { Controller } from "@hotwired/stimulus";

/**
 * Photoshop-style rulers + draggable guides for the admin canvas editor
 * (Photopea was the UX reference).
 *
 * - Two 22px ruler BARS (top = X, left = Y) drawn on plain 2D <canvas>
 *   elements: px scale with zoom-adaptive tick steps, DPR-crisp, labels on
 *   majors (the vertical ruler stacks digits, Photopea-style).
 * - Drag OUT of a ruler to create a GUIDE (top ruler → horizontal guide,
 *   left ruler → vertical guide). Drag a placed guide to move it; release it
 *   outside the canvas (e.g. back onto the ruler) to delete it. While
 *   dragging, a dark badge shows the live position ("X: 204 px") and the
 *   guide magnetically snaps to object edges/centers and the canvas
 *   center/edges, so placing it exactly on an element is effortless.
 * - The left-panel switch (#ruler-enabled-control) toggles the whole
 *   feature; hidden guides also stop being snap targets
 *   (canvas.wboostGuidesHidden, read by canvas_snapping_controller).
 *
 * Guides live on the Fabric instance as `canvas.wboostGuides`
 * ([{axis:'x'|'y', pos}] in canvas px; axis 'x' = vertical line at x=pos)
 * and ride the canvas document as a top-level `guides` key — the exact
 * wboostContainers pattern: buildVariantPayload serializes them, the editor
 * loader restores them (only when the key is present, so undo/redo — whose
 * snapshots don't carry guides — never wipes them). Guide edits mark the
 * form dirty via the orchestrator's markUnsaved().
 *
 * Everything is plain DOM / offscreen-of-canvas chrome in the UNSCALED
 * .canvas-stage layer (the floating-toolbar geometry model): nothing is ever
 * drawn on the Fabric bitmap, so rulers/guides can never leak into the saved
 * preview thumbnail or the server-side PNG export. The stage gets a
 * `has-rulers` padding gutter for the bars while enabled.
 *
 * State is initialised in `initialize()` — outlet callbacks can fire before
 * connect().
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer", "hBar", "vBar", "corner"];

    static SIZE = 22;          // ruler bar thickness (screen px)
    static SNAP_SCREEN_PX = 5; // guide-drag magnetism radius (screen px)

    initialize() {
        this._enabled = true;
        this._canvas = null;
        this._guideEls = [];   // [{guide, el}] currently rendered guide divs
        this._drag = null;     // active guide drag state
        this._badge = null;
        this._boundRefresh = () => this.refresh();
        this._boundDragMove = (e) => this._onDragMove(e);
        this._boundDragUp = (e) => this._onDragUp(e);
        this._boundDragKey = (e) => { if (e.key === 'Escape') this._cancelDrag(); };
    }

    connect() {
        const toggle = document.getElementById('ruler-enabled-control');
        if (toggle) this._enabled = toggle.checked;
        window.addEventListener('resize', this._boundRefresh);
    }

    disconnect() {
        window.removeEventListener('resize', this._boundRefresh);
        this._teardownDragListeners();
        this._clearGuideEls();
        if (this._badge) { this._badge.remove(); this._badge = null; }
    }

    canvasEditorOutletConnected(outlet) {
        this._canvas = outlet.canvas;
        this._applyEnabled();
    }

    canvasEditorOutletDisconnected() {
        this._canvas = null;
    }

    /** canvas-editor:canvas:loaded — guides may have been (re)hydrated. */
    onCanvasLoaded() {
        this.refresh();
    }

    /** Left-panel switch. */
    toggle(event) {
        this._enabled = event.target.checked;
        this._applyEnabled();
    }

    _applyEnabled() {
        if (!this.hasLayerTarget) return;
        this.layerTarget.classList.toggle('has-rulers', this._enabled);
        if (this._canvas) this._canvas.wboostGuidesHidden = !this._enabled;
        [this.hasHBarTarget && this.hBarTarget, this.hasVBarTarget && this.vBarTarget, this.hasCornerTarget && this.cornerTarget]
            .filter(Boolean)
            .forEach((el) => el.classList.toggle('d-none', !this._enabled));
        if (this._enabled) {
            this.refresh();
        } else {
            this._clearGuideEls();
        }
        // The gutter shifts the canvas 23px, but sibling chrome (editable
        // outlines, container zones) repositions on after:render — request a
        // repaint so nothing is left anchored to the pre-toggle position.
        if (this._canvas) this._canvas.requestRenderAll();
    }

    /** Reposition + redraw everything (zoom change, resize, load, edits). */
    refresh() {
        if (!this._enabled || !this._canvas) return;
        const g = this._geometry();
        if (!g) return;
        this._placeBars(g);
        this._drawBar('h', g);
        this._drawBar('v', g);
        this._renderGuides(g);
    }

    // --- ruler bars ---------------------------------------------------------

    _placeBars(g) {
        const S = this.constructor.SIZE;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const dispW = Math.round(g.contRect.width);
        const dispH = Math.round(g.contRect.height);

        if (this.hasHBarTarget) {
            const el = this.hBarTarget;
            el.style.left = `${offX}px`;
            el.style.top = `${offY - S - 1}px`;
            el.style.width = `${dispW}px`;
            el.style.height = `${S}px`;
        }
        if (this.hasVBarTarget) {
            const el = this.vBarTarget;
            el.style.left = `${offX - S - 1}px`;
            el.style.top = `${offY}px`;
            el.style.width = `${S}px`;
            el.style.height = `${dispH}px`;
        }
        if (this.hasCornerTarget) {
            const el = this.cornerTarget;
            el.style.left = `${offX - S - 1}px`;
            el.style.top = `${offY - S - 1}px`;
            el.style.width = `${S}px`;
            el.style.height = `${S}px`;
        }
    }

    /**
     * Tick model (Photopea-style): labelled major every `step` canvas px
     * (adaptive so majors are ≥ ~46 screen px apart), minor ticks at step/10,
     * a taller mid tick at step/2. DPR-scaled for crisp 1px lines.
     */
    _drawBar(dir, g) {
        const el = dir === 'h' ? (this.hasHBarTarget && this.hBarTarget) : (this.hasVBarTarget && this.vBarTarget);
        if (!el) return;
        const S = this.constructor.SIZE;
        const lengthScreen = dir === 'h' ? g.contRect.width : g.contRect.height;
        const lengthCanvas = dir === 'h' ? this._canvas.getWidth() : this._canvas.getHeight();
        const dpr = window.devicePixelRatio || 1;

        if (dir === 'h') {
            el.width = Math.max(1, Math.round(lengthScreen * dpr));
            el.height = Math.round(S * dpr);
        } else {
            el.width = Math.round(S * dpr);
            el.height = Math.max(1, Math.round(lengthScreen * dpr));
        }

        const ctx = el.getContext('2d');
        ctx.save();
        ctx.scale(dpr, dpr);
        const W = dir === 'h' ? lengthScreen : S;
        const H = dir === 'h' ? S : lengthScreen;
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = '#fafbfe';
        ctx.fillRect(0, 0, W, H);

        const steps = [5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000];
        const step = steps.find((s) => s * g.scale >= 46) || steps[steps.length - 1];
        const minor = step / 10;
        const showMinor = minor * g.scale >= 3.5;

        ctx.strokeStyle = '#b6bfc9';
        ctx.fillStyle = '#8a96a3';
        ctx.font = '9px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        ctx.lineWidth = 1;
        ctx.beginPath();

        // Index-based (v = i·minor) so fractional steps never break the
        // major/mid classification with float modulo.
        for (let i = 0; i * minor <= lengthCanvas; i++) {
            const isMajor = i % 10 === 0;
            const isMid = !isMajor && i % 5 === 0;
            if (!isMajor && !isMid && !showMinor) continue;
            const p = Math.round(i * minor * g.scale) + 0.5;
            const len = isMajor ? 14 : (isMid ? 8 : 4.5);
            if (dir === 'h') {
                ctx.moveTo(p, S);
                ctx.lineTo(p, S - len);
            } else {
                ctx.moveTo(S, p);
                ctx.lineTo(S - len, p);
            }
        }
        ctx.stroke();

        // Labels on majors. Horizontal: beside the tick. Vertical: stacked
        // digits (the Photopea/classic ruler look — no rotated text).
        for (let v = 0; v <= lengthCanvas; v += step) {
            const p = Math.round(v * g.scale);
            const label = String(v);
            if (dir === 'h') {
                ctx.fillText(label, p + 3, 9);
            } else {
                for (let i = 0; i < label.length; i++) {
                    ctx.fillText(label[i], 3, p + 10 + i * 8);
                }
            }
        }
        ctx.restore();
    }

    // --- guides -------------------------------------------------------------

    _guides() {
        if (!this._canvas) return [];
        if (!Array.isArray(this._canvas.wboostGuides)) this._canvas.wboostGuides = [];
        return this._canvas.wboostGuides;
    }

    _renderGuides(g) {
        this._clearGuideEls();
        if (!this.hasLayerTarget) return;
        this._guideEls = this._guides().map((guide) => {
            const el = document.createElement('div');
            el.className = `canvas-guide canvas-guide--${guide.axis === 'x' ? 'v' : 'h'}`;
            el.appendChild(document.createElement('span')); // the visible 1px line
            el.addEventListener('mousedown', (e) => this._startMove(e, guide));
            this.layerTarget.appendChild(el);
            this._positionGuideEl(el, guide, g);
            return { guide, el };
        });
    }

    _positionGuideEl(el, guide, g) {
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        if (guide.axis === 'x') {
            el.style.left = `${offX + guide.pos * g.scale}px`;
            el.style.top = `${offY}px`;
            el.style.height = `${g.contRect.height}px`;
        } else {
            el.style.top = `${offY + guide.pos * g.scale}px`;
            el.style.left = `${offX}px`;
            el.style.width = `${g.contRect.width}px`;
        }
    }

    _clearGuideEls() {
        this._guideEls.forEach(({ el }) => el.remove());
        this._guideEls = [];
    }

    // --- drag lifecycle (create from ruler / move existing) -----------------

    /** mousedown on the TOP ruler → drag out a HORIZONTAL guide. */
    startCreateH(event) {
        this._startDrag(event, { axis: 'y', pos: null }, true);
    }

    /** mousedown on the LEFT ruler → drag out a VERTICAL guide. */
    startCreateV(event) {
        this._startDrag(event, { axis: 'x', pos: null }, true);
    }

    _startMove(event, guide) {
        this._startDrag(event, guide, false);
    }

    _startDrag(event, guide, isNew) {
        if (!this._enabled || !this._canvas || event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();

        const g = this._geometry();
        if (!g) return;

        this._drag = { guide, isNew, g, entry: null };
        if (!isNew) {
            this._drag.entry = this._guideEls.find((e) => e.guide === guide) || null;
            if (this._drag.entry) this._drag.entry.el.classList.add('is-dragging');
        }
        document.body.classList.add(guide.axis === 'x' ? 'is-dragging-guide-v' : 'is-dragging-guide-h');
        document.addEventListener('mousemove', this._boundDragMove);
        document.addEventListener('mouseup', this._boundDragUp);
        document.addEventListener('keydown', this._boundDragKey);
        this._onDragMove(event);
    }

    _onDragMove(event) {
        const d = this._drag;
        if (!d) return;
        const g = d.g;
        const raw = d.guide.axis === 'x'
            ? (event.clientX - g.contRect.left) / g.scale
            : (event.clientY - g.contRect.top) / g.scale;

        // Magnetise to object edges/centers + canvas center/edges, then fall
        // back to whole px (designers think in integers; a snapped value may
        // legitimately be fractional, e.g. an odd-width object's center).
        const snapped = this._snapGuidePos(d.guide.axis, raw, g);
        const pos = snapped !== null ? snapped : Math.round(raw);
        d.pos = pos;

        const limit = d.guide.axis === 'x' ? this._canvas.getWidth() : this._canvas.getHeight();
        d.inside = pos >= 0 && pos <= limit;

        // Phantom guide element for creates; live-move for existing ones.
        if (!d.entry && this.hasLayerTarget) {
            const el = document.createElement('div');
            el.className = `canvas-guide canvas-guide--${d.guide.axis === 'x' ? 'v' : 'h'} is-dragging`;
            el.appendChild(document.createElement('span'));
            this.layerTarget.appendChild(el);
            d.entry = { guide: d.guide, el };
        }
        if (d.entry) {
            d.entry.el.classList.toggle('is-deleting', !d.inside);
            this._positionGuideEl(d.entry.el, { axis: d.guide.axis, pos }, g);
        }
        this._showBadge(d, event, g);
    }

    _onDragUp() {
        const d = this._drag;
        if (!d) return;
        const guides = this._guides();

        if (d.inside) {
            if (d.isNew) {
                guides.push({ axis: d.guide.axis, pos: d.pos });
            } else {
                d.guide.pos = d.pos;
            }
            this._markDirty();
        } else if (!d.isNew) {
            const idx = guides.indexOf(d.guide);
            if (idx !== -1) guides.splice(idx, 1);
            this._markDirty();
        }

        this._endDrag();
        this.refresh();
    }

    _cancelDrag() {
        if (!this._drag) return;
        this._endDrag();
        this.refresh();
    }

    _endDrag() {
        if (this._drag && this._drag.entry && this._drag.isNew) this._drag.entry.el.remove();
        this._drag = null;
        this._hideBadge();
        document.body.classList.remove('is-dragging-guide-v', 'is-dragging-guide-h');
        this._teardownDragListeners();
    }

    _teardownDragListeners() {
        document.removeEventListener('mousemove', this._boundDragMove);
        document.removeEventListener('mouseup', this._boundDragUp);
        document.removeEventListener('keydown', this._boundDragKey);
    }

    /** Nearest object/canvas line within the magnetism radius, or null. */
    _snapGuidePos(axis, raw, g) {
        const threshold = this.constructor.SNAP_SCREEN_PX / g.scale;
        const lines = [];
        const W = this._canvas.getWidth();
        const H = this._canvas.getHeight();
        if (axis === 'x') lines.push(0, W / 2, W); else lines.push(0, H / 2, H);
        this._canvas.getObjects().forEach((o) => {
            if (o.visible === false) return;
            const r = o.getBoundingRect();
            if (axis === 'x') lines.push(r.left, r.left + r.width / 2, r.left + r.width);
            else lines.push(r.top, r.top + r.height / 2, r.top + r.height);
        });
        let best = null;
        lines.forEach((v) => {
            const dist = Math.abs(v - raw);
            if (dist <= threshold && (!best || dist < best.dist)) best = { v, dist };
        });
        return best ? Math.round(best.v * 10) / 10 : null;
    }

    // --- badge ---------------------------------------------------------------

    _showBadge(d, event, g) {
        if (!this.hasLayerTarget) return;
        if (!this._badge) {
            this._badge = document.createElement('div');
            this._badge.className = 'canvas-guide-badge';
            this.layerTarget.appendChild(this._badge);
        }
        const label = d.guide.axis === 'x' ? 'X' : 'Y';
        this._badge.textContent = d.inside ? `${label}: ${d.pos} px` : 'Odstranit';
        this._badge.style.left = `${event.clientX - g.layerRect.left + 14}px`;
        this._badge.style.top = `${event.clientY - g.layerRect.top + 14}px`;
        this._badge.style.display = '';
    }

    _hideBadge() {
        if (this._badge) this._badge.style.display = 'none';
    }

    // --- shared helpers -------------------------------------------------------

    _markDirty() {
        if (this.hasCanvasEditorOutlet) this.canvasEditorOutlet.markUnsaved();
    }

    /** Same live stage→screen model as the floating toolbar / snapping. */
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
