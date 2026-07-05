import { Controller } from "@hotwired/stimulus";

/**
 * Container ("smart text area") authoring for the admin canvas editor.
 *
 * A container groups 2+ text placeholders into a vertical flow: at render time
 * (export / fill preview) a filled text that wraps to more lines pushes the
 * members below it down, hidden members collapse, and the flow is bounded by
 * the container's max height (exceeding it is a validation error on export).
 * The reflow algorithm is the shared classic-script module
 * assets/editor/container_layout.js (window.WBoostContainerLayout) — the same
 * code the headless Gotenberg render and the fill page run, so what the
 * designer sees here is exactly what exports.
 *
 * Editor semantics:
 *  - "Vytvořit kontejner" on the multi-select bar groups the selected
 *    textboxes (each stays an ordinary independent input).
 *  - Dragging any member moves the WHOLE container; Alt-drag repositions just
 *    that member within it (the new gap becomes part of the design).
 *  - Typing into a member reflows the members below it live.
 *  - Each container draws a dashed DOM zone (top edge = first member's top,
 *    height = maxHeight) with a bottom drag-handle to set the max height. The
 *    zone is plain DOM in the unscaled stage layer — never on the canvas
 *    bitmap, so it can't leak into the preview thumbnail or the server PNG.
 *
 * State model: container definitions live on the Fabric canvas instance as
 * `canvas.wboostContainers` ([{id, maxHeight, memberInputIds}]). The
 * orchestrator serializes them into the canvas JSON top-level `containers`
 * key on save, the history controller snapshots them with every undo state,
 * and loadCanvasWithoutHistory restores them (dispatching
 * canvas-editor:canvas:loaded, which we listen to below). Membership is by
 * the members' inputId — the same id every other surface keys on.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer", "createButton", "section", "maxHeightInput"];

    initialize() {
        // State lives in initialize(), NOT connect(): Stimulus may fire
        // canvasEditorOutletConnected() before connect() (outlet callbacks are
        // driven by element observation, not the controller's own lifecycle),
        // and the outlet hook touches all of this.
        this._zones = [];
        // containerId -> { memberInputIds, designedTops, gaps } — the designed
        // geometry reflow is anchored to. Re-derived after every design change
        // (member move, member add/remove, load); reflow between re-derivations
        // keeps gaps constant by construction.
        this._snapshots = new Map();
        this._dragLast = null;
    }

    connect() {
        this._boundReposition = () => this.repositionZones();
        window.addEventListener('resize', this._boundReposition);
    }

    disconnect() {
        window.removeEventListener('resize', this._boundReposition);
        this._clearZones();
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;

        this._onMouseDown = (e) => this._beginMemberDrag(e);
        this._onMouseUp = () => { this._dragLast = null; };
        this._onObjectMoving = (e) => this._handleMemberDrag(e);
        this._onObjectModified = () => this._afterDesignChange();
        this._onTextChanged = (e) => this._reflowFor(e.target);
        this._onObjectRemoved = (e) => {
            if (!outlet.loadingCanvas) this._pruneRemoved(e.target);
        };
        this._onAfterRender = () => { if (this._zones.length) this._positionZones(); };

        canvas.on('mouse:down', this._onMouseDown);
        canvas.on('mouse:up', this._onMouseUp);
        canvas.on('object:moving', this._onObjectMoving);
        canvas.on('object:modified', this._onObjectModified);
        canvas.on('text:changed', this._onTextChanged);
        canvas.on('object:removed', this._onObjectRemoved);
        canvas.on('after:render', this._onAfterRender);

        // The canvas may already be loaded (outlet connect order is not
        // guaranteed relative to the async JSON load) — sync from whatever
        // state exists now; onCanvasLoaded re-syncs when the load finishes.
        this._resnapshotAll();
        this.renderZones();
    }

    canvasEditorOutletDisconnected(outlet) {
        const canvas = outlet.canvas;
        if (!canvas) return;
        canvas.off('mouse:down', this._onMouseDown);
        canvas.off('mouse:up', this._onMouseUp);
        canvas.off('object:moving', this._onObjectMoving);
        canvas.off('object:modified', this._onObjectModified);
        canvas.off('text:changed', this._onTextChanged);
        canvas.off('object:removed', this._onObjectRemoved);
        canvas.off('after:render', this._onAfterRender);
    }

    // --- shared state accessors -------------------------------------------

    _canvas() {
        return this.hasCanvasEditorOutlet ? this.canvasEditorOutlet.canvas : null;
    }

    _containers() {
        const canvas = this._canvas();
        if (!canvas) return [];
        if (!Array.isArray(canvas.wboostContainers)) {
            canvas.wboostContainers = [];
        }
        return canvas.wboostContainers;
    }

    _layout() {
        return window.WBoostContainerLayout || null;
    }

    _objects() {
        const canvas = this._canvas();
        return canvas ? canvas.getObjects() : [];
    }

    _memberObjects(container) {
        const layout = this._layout();
        if (!layout) return [];
        return layout.collectMembers(this._objects(), container);
    }

    _containerOf(inputId) {
        if (!inputId) return null;
        return this._containers().find((c) => Array.isArray(c.memberInputIds) && c.memberInputIds.includes(inputId)) || null;
    }

    /**
     * Absolute top/left even while the object sits inside an ActiveSelection
     * (Fabric makes member left/top relative to the selection transform; the
     * transform matrix's translation is the object's absolute centre).
     */
    _absTop(obj) {
        if (obj.group) {
            const m = obj.calcTransformMatrix();
            return m[5] - (obj.height * (obj.scaleY || 1)) / 2;
        }
        return obj.top;
    }

    _absLeft(obj) {
        if (obj.group) {
            const m = obj.calcTransformMatrix();
            return m[4] - (obj.width * (obj.scaleX || 1)) / 2;
        }
        return obj.left;
    }

    // --- lifecycle hooks from the orchestrator / template ------------------

    /** canvas-editor:canvas:loaded — initial load AND undo/redo restores. */
    onCanvasLoaded() {
        this._dragLast = null;
        this._resnapshotAll();
        this.renderZones();
        this._syncSection();
    }

    /** canvas-editor:selection:changed */
    onSelectionChanged(event) {
        this._syncSection(event.detail ? event.detail.activeObject : null);
        this._syncCreateButton(event.detail ? event.detail.activeObject : null);
    }

    // --- creating / dissolving / membership --------------------------------

    createFromSelection() {
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;

        const selection = canvas.getActiveObject();
        if (!selection || (selection.type || '').toLowerCase() !== 'activeselection') return;

        const members = selection.getObjects().filter(
            (o) => (o.type || '').toLowerCase() === 'textbox',
        );
        if (members.length < 2 || members.length !== selection.getObjects().length) return;
        if (members.some((o) => o.inputId && this._containerOf(o.inputId))) return;

        members.forEach((o) => {
            if (!o.inputId) o.inputId = crypto.randomUUID();
        });

        // Discard the selection FIRST so member coordinates are absolute again.
        canvas.discardActiveObject();

        const sorted = [...members].sort((a, b) => a.top - b.top);
        const top = sorted[0].top;
        const bottom = Math.max(...sorted.map((o) => o.top + o.height * (o.scaleY || 1)));
        // Default bound: designed content + 25% headroom — the designer tunes
        // it via the zone handle / the popover field.
        const maxHeight = Math.ceil((bottom - top) * 1.25);

        this._containers().push({
            id: crypto.randomUUID(),
            maxHeight,
            memberInputIds: sorted.map((o) => o.inputId),
        });

        this._resnapshotAll();
        this.renderZones();
        canvas.renderAll();
        // Synthetic modified event: marks the form dirty (orchestrator) and
        // pushes an undo snapshot (history controller) — container creation
        // changes no Fabric object, so nothing would fire otherwise.
        canvas.fire('object:modified', {});
        this.canvasEditorOutlet.dispatchSelectionChanged();
    }

    removeActiveFromContainer() {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        if (!active || !active.inputId) return;
        const container = this._containerOf(active.inputId);
        if (!container) return;

        container.memberInputIds = container.memberInputIds.filter((id) => id !== active.inputId);
        this._dropDegenerate();
        this._resnapshotAll();
        this.renderZones();
        canvas.fire('object:modified', {});
        this._syncSection(active);
    }

    dissolveActiveContainer() {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        const container = active && active.inputId ? this._containerOf(active.inputId) : null;
        if (!container) return;

        const containers = this._containers();
        containers.splice(containers.indexOf(container), 1);
        this._resnapshotAll();
        this.renderZones();
        canvas.fire('object:modified', {});
        this._syncSection(active);
    }

    updateMaxHeightFromInput() {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        const container = active && active.inputId ? this._containerOf(active.inputId) : null;
        if (!container || !this.hasMaxHeightInputTarget) return;

        const value = parseFloat(this.maxHeightInputTarget.value);
        if (!(value > 0)) return;
        container.maxHeight = value;
        this.repositionZones();
        this.canvasEditorOutlet.markUnsaved();
    }

    _pruneRemoved(obj) {
        if (!obj || !obj.inputId) return;
        let changed = false;
        this._containers().forEach((c) => {
            if (Array.isArray(c.memberInputIds) && c.memberInputIds.includes(obj.inputId)) {
                c.memberInputIds = c.memberInputIds.filter((id) => id !== obj.inputId);
                changed = true;
            }
        });
        if (changed) {
            this._dropDegenerate();
            this._resnapshotAll();
            this.renderZones();
        }
    }

    /** A container with fewer than 2 resolvable members has nothing to reflow. */
    _dropDegenerate() {
        const canvas = this._canvas();
        if (!canvas) return;
        canvas.wboostContainers = this._containers().filter(
            (c) => this._memberObjects(c).length >= 2,
        );
    }

    // --- move-together dragging --------------------------------------------

    _beginMemberDrag(e) {
        const target = e.target;
        if (!target || (target.type || '').toLowerCase() !== 'textbox' || !target.inputId) {
            this._dragLast = null;
            return;
        }
        if (!this._containerOf(target.inputId)) {
            this._dragLast = null;
            return;
        }
        // Capture BEFORE the first object:moving so no delta is lost.
        this._dragLast = { target, left: target.left, top: target.top };
    }

    _handleMemberDrag(e) {
        this.repositionZones();

        const target = e.target;
        if (!target || !this._dragLast || this._dragLast.target !== target) return;
        if (e.e && e.e.altKey) return; // Alt = reposition just this member

        const container = this._containerOf(target.inputId);
        if (!container) return;

        const dx = target.left - this._dragLast.left;
        const dy = target.top - this._dragLast.top;
        this._dragLast.left = target.left;
        this._dragLast.top = target.top;
        if (dx === 0 && dy === 0) return;

        this._memberObjects(container).forEach((obj) => {
            if (obj === target) return;
            obj.set({ left: obj.left + dx, top: obj.top + dy });
            obj.setCoords();
        });
    }

    // --- reflow + design snapshots ------------------------------------------

    /**
     * Any interactive transform ended (drag drop, resize, text-edit exit,
     * group move): the CURRENT geometry is the design now — re-derive flow
     * order + gaps from it and refresh the zones.
     */
    _afterDesignChange() {
        const layout = this._layout();
        if (layout) {
            this._containers().forEach((c) => {
                const objects = this._objects();
                c.memberInputIds = layout.sortMemberIdsByTop(objects, c.memberInputIds || []);
            });
        }
        this._resnapshotAll();
        this.renderZones();
    }

    _resnapshotAll() {
        this._snapshots.clear();
        this._containers().forEach((c) => {
            const members = this._memberObjects(c);
            if (members.length < 2) return;
            const designed = members.map((o) => ({
                designedTop: this._absTop(o),
                designedHeight: o.height * (o.scaleY || 1),
            }));
            const layout = this._layout();
            this._snapshots.set(c.id, {
                memberInputIds: members.map((o) => o.inputId),
                designedTops: designed.map((d) => d.designedTop),
                gaps: layout ? layout.computeGaps(designed) : [],
            });
        });
    }

    /** Live reflow while the designer types into a member textbox. */
    _reflowFor(target) {
        if (!target || !target.inputId) return;
        const container = this._containerOf(target.inputId);
        if (!container) return;

        const layout = this._layout();
        const snapshot = this._snapshots.get(container.id);
        if (!layout || !snapshot) return;

        const objects = this._objects();
        const memberObjects = snapshot.memberInputIds
            .map((id) => objects.find((o) => o.inputId === id))
            .filter(Boolean);
        if (memberObjects.length !== snapshot.memberInputIds.length) return;

        const members = memberObjects.map((o, i) => ({
            designedTop: snapshot.designedTops[i],
            actualHeight: o.height * (o.scaleY || 1),
            hidden: false,
        }));
        const result = layout.computeLayout(members, container.maxHeight, snapshot.gaps);
        memberObjects.forEach((o, i) => {
            if (result.tops[i] !== null && o.top !== result.tops[i]) {
                o.set({ top: result.tops[i] });
                o.setCoords();
            }
        });
        this.repositionZones();
    }

    // --- zone overlay --------------------------------------------------------

    renderZones() {
        this._clearZones();
        if (!this.hasLayerTarget) return;

        this._containers().forEach((container) => {
            if (this._memberObjects(container).length < 2) return;

            const zone = document.createElement('div');
            zone.className = 'container-zone';

            const label = document.createElement('span');
            label.className = 'container-zone__label';
            zone.appendChild(label);

            const handle = document.createElement('div');
            handle.className = 'container-zone__handle';
            handle.title = 'Táhnutím nastavíte maximální výšku kontejneru';
            handle.addEventListener('mousedown', (event) => this._beginHandleDrag(event, container));
            zone.appendChild(handle);

            this.layerTarget.appendChild(zone);
            this._zones.push({ container, zone, label });
        });

        this._positionZones();
    }

    repositionZones() {
        this._positionZones();
    }

    _positionZones() {
        if (!this._zones.length) return;
        const g = this._geometry();
        if (!g) return;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const PAD = 6;

        this._zones.forEach(({ container, zone, label }) => {
            const members = this._memberObjects(container);
            if (members.length < 2) {
                zone.style.display = 'none';
                return;
            }
            zone.style.display = '';

            const tops = members.map((o) => this._absTop(o));
            const lefts = members.map((o) => this._absLeft(o));
            const rights = members.map((o) => this._absLeft(o) + o.width * (o.scaleX || 1));
            const bottoms = members.map((o, i) => tops[i] + o.height * (o.scaleY || 1));

            const containerTop = Math.min(...tops);
            const contentBottom = Math.max(...bottoms);
            const left = Math.min(...lefts) - PAD;
            const width = Math.max(...rights) - Math.min(...lefts) + 2 * PAD;

            zone.style.left = `${offX + left * g.scale}px`;
            zone.style.top = `${offY + containerTop * g.scale}px`;
            zone.style.width = `${width * g.scale}px`;
            zone.style.height = `${container.maxHeight * g.scale}px`;

            const overflowPx = contentBottom - (containerTop + container.maxHeight);
            const overflowing = overflowPx > 0.5;
            zone.classList.toggle('container-zone--overflow', overflowing);
            label.textContent = overflowing
                ? `Kontejner · obsah přesahuje o ${Math.ceil(overflowPx)} px`
                : `Kontejner · max ${Math.round(container.maxHeight)} px`;
        });
    }

    _clearZones() {
        this._zones.forEach(({ zone }) => zone.remove());
        this._zones = [];
    }

    _beginHandleDrag(event, container) {
        event.preventDefault();
        event.stopPropagation();
        const g = this._geometry();
        if (!g) return;

        const startY = event.clientY;
        const startMaxHeight = container.maxHeight;

        const onMove = (e) => {
            const dyCanvas = (e.clientY - startY) / g.scale;
            container.maxHeight = Math.max(20, Math.round(startMaxHeight + dyCanvas));
            this._positionZones();
            this._syncSection();
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            const canvas = this._canvas();
            if (canvas) {
                // Dirty + undo snapshot, same as createFromSelection.
                canvas.fire('object:modified', {});
            }
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    /** Same coordinate model as the floating toolbar: unscaled stage layer,
     *  scale derived from the live (CSS-zoomed) canvas rect vs logical width. */
    _geometry() {
        const canvas = this._canvas();
        if (!canvas || !this.hasLayerTarget) return null;
        const canvasEl = canvas.getElement ? canvas.getElement() : null;
        if (!canvasEl) return null;
        const container = canvasEl.parentElement || canvasEl;
        const contRect = container.getBoundingClientRect();
        const layerRect = this.layerTarget.getBoundingClientRect();
        const logicalWidth = (typeof canvas.getWidth === 'function' ? canvas.getWidth() : canvas.width) || canvasEl.width;
        const scale = logicalWidth ? contRect.width / logicalWidth : 1;
        return { contRect, layerRect, scale };
    }

    // --- popover section (active member) ------------------------------------

    _syncSection(activeObject) {
        if (!this.hasSectionTarget) return;
        const canvas = this._canvas();
        const active = activeObject !== undefined
            ? activeObject
            : (canvas ? canvas.getActiveObject() : null);

        const container = active && active.inputId && (active.type || '').toLowerCase() === 'textbox'
            ? this._containerOf(active.inputId)
            : null;

        this.sectionTarget.classList.toggle('d-none', !container);
        if (container && this.hasMaxHeightInputTarget) {
            this.maxHeightInputTarget.value = Math.round(container.maxHeight);
        }
    }

    _syncCreateButton(activeObject) {
        if (!this.hasCreateButtonTarget) return;
        const isSelection = activeObject && (activeObject.type || '').toLowerCase() === 'activeselection';
        let enabled = false;
        if (isSelection) {
            const objects = activeObject.getObjects();
            const textboxes = objects.filter((o) => (o.type || '').toLowerCase() === 'textbox');
            enabled = textboxes.length >= 2
                && textboxes.length === objects.length
                && !textboxes.some((o) => o.inputId && this._containerOf(o.inputId));
        }
        this.createButtonTarget.disabled = !enabled;
        this.createButtonTarget.title = enabled
            ? 'Vytvořit kontejner'
            : 'Kontejner lze vytvořit z 2+ textových prvků, které zatím v žádném kontejneru nejsou';
    }
}
