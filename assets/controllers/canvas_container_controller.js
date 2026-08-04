import { Controller } from "@hotwired/stimulus";

/**
 * Container ("smart text area") authoring for the admin canvas editor.
 *
 * A container groups members into a document-like vertical flow: at render
 * time (export / fill preview) a filled text that wraps to more lines pushes
 * the flow items below it down, hidden items collapse, and the flow of a
 * TOP-LEVEL container is bounded by its max height (exceeding it is a
 * validation error on export). The reflow algorithm is the shared
 * classic-script module assets/editor/container_layout.js
 * (window.WBoostContainerLayout) — the same code the headless Gotenberg render
 * and the fill page run, so what the designer sees here is exactly what
 * exports.
 *
 * Member model (v2): texts flow as before; DECORATIVE images join too (an
 * image overlapping a text rides along with it — the checklist-icon case — an
 * image between items flows standalone); CONTAINERS can nest — a child
 * container is one flow item of its parent, its own maxHeight stops being a
 * bound (only the outermost container's is). A container's `gap` (set in the
 * per-zone ⚙ popover) replaces the designed inter-item gaps with a uniform
 * spacing; with a gap set, vertical member positions only determine ORDER.
 *
 * Editor semantics:
 *  - "Vytvořit kontejner" on the multi-select bar groups the selected
 *    texts/images; selected objects that already belong to a container bring
 *    their WHOLE (root) container in as a nested child.
 *  - Dragging a member moves just that member; the whole container moves by
 *    dragging its zone label. Typing into a member reflows everything live —
 *    including ancestor containers pushing sibling sections.
 *  - Each container draws a dashed DOM zone; top-level zones have a bottom
 *    handle for maxHeight, nested zones hug their content. The ⚙ button opens
 *    the zone's settings popover (gap, maxHeight). The zone is plain DOM in
 *    the unscaled stage layer — never on the canvas bitmap.
 *  - Deleting a NESTED container's definition promotes its members to the
 *    parent (the flow survives); deleting a top-level one frees the members.
 *
 * State model: container definitions live on the Fabric canvas instance as
 * `canvas.wboostContainers` ([{id, maxHeight, memberInputIds,
 * memberContainerIds, gap?}]). The orchestrator serializes them into the
 * canvas JSON top-level `containers` key on save, the history controller
 * snapshots them with every undo state, and loadCanvasWithoutHistory restores
 * them (dispatching canvas-editor:canvas:loaded, which we listen to below).
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
        // Prepared forest from the shared layout module — the designed
        // geometry reflow is anchored to. Re-derived after every design change
        // (member move, member add/remove, gap edit, load); reflow between
        // re-derivations keeps the design stable by construction.
        this._prepared = [];
        // A design change landed while a multi-selection was active (its
        // members carry relative coords) — normalize once it clears.
        this._normalizePending = false;
        this._settingsEl = null;
        this._settingsContainerId = null;
    }

    connect() {
        this._boundReposition = () => this.repositionZones();
        window.addEventListener('resize', this._boundReposition);
    }

    disconnect() {
        window.removeEventListener('resize', this._boundReposition);
        this._closeSettings();
        this._clearZones();
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;

        // Dragging a member moves ONLY that member (standard Fabric drag) —
        // the whole container is moved by dragging the zone's label instead
        // (see _beginLabelDrag). During a drag the zone just follows.
        this._onObjectMoving = () => this.repositionZones();
        this._onObjectModified = () => this._afterDesignChange();
        this._onTextChanged = (e) => this._reflowFor(e.target);
        this._onObjectRemoved = (e) => {
            if (!outlet.loadingCanvas) this._pruneRemoved(e.target);
        };
        this._onAfterRender = () => { if (this._zones.length) this._positionZones(); };

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

    /** Direct + descendant member objects — the unit zones/moves operate on. */
    _deepMemberObjects(container) {
        const layout = this._layout();
        if (!layout) return [];
        return layout.collectDeepMemberObjects(this._objects(), this._containers(), container);
    }

    _containerOf(inputId) {
        if (!inputId) return null;
        return this._containers().find((c) => Array.isArray(c.memberInputIds) && c.memberInputIds.includes(inputId)) || null;
    }

    _parentOf(container) {
        const layout = this._layout();
        return layout ? layout.parentOf(this._containers(), container.id) : null;
    }

    _rootOf(container) {
        const layout = this._layout();
        const root = layout ? layout.rootOf(this._containers(), container.id) : null;
        return root || container;
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
        this._closeSettings();
        this._resnapshotAll();
        this.renderZones();
        this._syncSection();
    }

    /** canvas-editor:selection:changed */
    onSelectionChanged(event) {
        const active = event.detail ? event.detail.activeObject : null;
        if (this._normalizePending && !(active && (active.type || '').toLowerCase() === 'activeselection')) {
            this._normalizePending = false;
            this._normalizeDesign();
            this.renderZones();
        }
        this._syncSection(active);
        this._syncCreateButton(active);
    }

    // --- creating / dissolving / membership --------------------------------

    /**
     * Classify a multi-selection for container creation: free texts/images
     * become direct members, objects already inside a container bring their
     * whole ROOT container in as a nested child. Returns null when the
     * selection contains unsupported objects (fillable image placeholders,
     * the background layer, non-text/image types) or nothing groupable.
     */
    _classifySelection(selection) {
        const layout = this._layout();
        if (!layout) return null;
        const containers = this._containers();

        const childRootIds = [];
        const direct = [];
        for (const obj of selection.getObjects()) {
            const memberOf = obj.inputId ? this._containerOf(obj.inputId) : null;
            if (memberOf) {
                const root = layout.rootOf(containers, memberOf.id);
                if (root && !childRootIds.includes(root.id)) {
                    childRootIds.push(root.id);
                }
                continue;
            }
            if (layout.isMemberCandidate(obj)) {
                direct.push(obj);
                continue;
            }
            return null;
        }

        const itemCount = childRootIds.length + direct.length;
        const hasText = childRootIds.length > 0 || direct.some((o) => layout.isTextboxObject(o));
        if (itemCount < 2 || !hasText) {
            return null;
        }

        return { childRootIds, direct };
    }

    createFromSelection() {
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;

        const selection = canvas.getActiveObject();
        if (!selection || (selection.type || '').toLowerCase() !== 'activeselection') return;

        const plan = this._classifySelection(selection);
        if (!plan) return;

        plan.direct.forEach((o) => {
            if (!o.inputId) o.inputId = crypto.randomUUID();
        });

        // Discard the selection FIRST so member coordinates are absolute again.
        canvas.discardActiveObject();

        const containers = this._containers();
        const extentObjects = [...plan.direct];
        const childExtents = new Map();
        plan.childRootIds.forEach((id) => {
            const def = containers.find((c) => c.id === id);
            const members = def ? this._deepMemberObjects(def) : [];
            members.forEach((o) => extentObjects.push(o));
            childExtents.set(id, members.length ? Math.min(...members.map((o) => o.top)) : 0);
        });
        if (extentObjects.length === 0) return;

        const top = Math.min(...extentObjects.map((o) => o.top));
        const bottom = Math.max(...extentObjects.map((o) => o.top + o.height * (o.scaleY || 1)));
        // Default bound: designed content + 25% headroom — the designer tunes
        // it via the zone handle / the ⚙ popover.
        const maxHeight = Math.ceil((bottom - top) * 1.25);

        containers.push({
            id: crypto.randomUUID(),
            maxHeight,
            memberInputIds: [...plan.direct].sort((a, b) => a.top - b.top).map((o) => o.inputId),
            memberContainerIds: [...plan.childRootIds].sort((a, b) => childExtents.get(a) - childExtents.get(b)),
        });

        this._normalizeDesign();
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
        this._normalizeDesign();
        this.renderZones();
        canvas.fire('object:modified', {});
        this._syncSection(active);
    }

    dissolveActiveContainer() {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        const container = active && active.inputId ? this._containerOf(active.inputId) : null;
        if (!container) return;
        this._dissolve(container);
        this._syncSection(active);
    }

    updateMaxHeightFromInput(event) {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        const container = active && active.inputId ? this._containerOf(active.inputId) : null;
        if (!container) return;

        const value = parseFloat(event && event.target ? event.target.value : NaN);
        if (!(value > 0)) return;
        // The bound lives on the ROOT of the tree — a nested container grows
        // freely, so the field always edits the outermost limit.
        this._rootOf(container).maxHeight = value;
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

    /**
     * A container with fewer than 2 members (inputs + nested children) has
     * nothing to reflow. Iterated to a fixpoint: dropping a degenerate child
     * can strip its parent below the minimum.
     */
    _dropDegenerate() {
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;

        let defs = this._containers();
        for (;;) {
            const valid = new Set(
                defs
                    .filter((c) => this._memberObjects(c).length + layout.childContainersOf(defs, c).length >= 2)
                    .map((c) => c.id),
            );
            if (valid.size === defs.length) break;
            defs = defs
                .filter((c) => valid.has(c.id))
                .map((c) => ({
                    ...c,
                    memberContainerIds: (c.memberContainerIds || []).filter((id) => valid.has(id)),
                }));
        }
        canvas.wboostContainers = defs;
    }

    // --- reflow + design snapshots ------------------------------------------

    /**
     * Any interactive transform ended (drag drop, resize, text-edit exit,
     * group move): the CURRENT geometry is the design now — re-derive flow
     * order + snapshots from it, normalize (uniform gaps / nested flow are
     * enforced positions, not suggestions) and refresh the zones.
     */
    _afterDesignChange() {
        const layout = this._layout();
        if (layout) {
            this._containers().forEach((c) => {
                const objects = this._objects();
                c.memberInputIds = layout.sortMemberIdsByTop(objects, c.memberInputIds || []);
            });
        }
        this._normalizeDesign();
        this.renderZones();
        this._syncSettings();
    }

    /**
     * Snapshot the current design, apply the layout once (a no-op when every
     * gap is designed and nothing is nested-shifted — the flow reproduces the
     * current positions by construction; a real move when a uniform gap is
     * set), then re-snapshot so the committed positions ARE the design.
     *
     * Skipped while an ActiveSelection is live: its members carry
     * selection-relative coordinates that must not be written to.
     */
    _normalizeDesign() {
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;

        const active = canvas.getActiveObject ? canvas.getActiveObject() : null;
        if (active && (active.type || '').toLowerCase() === 'activeselection') {
            this._resnapshotAll();
            this._normalizePending = true;
            return;
        }

        this._resnapshotAll();
        layout.applyFabricLayout(this._prepared);
        this._resnapshotAll();
        canvas.requestRenderAll();
    }

    _resnapshotAll() {
        const layout = this._layout();
        if (!layout) return;
        this._prepared = layout.prepareFabricContainers(
            this._objects(),
            this._containers(),
            { getTop: (o) => this._absTop(o) },
        );
    }

    /** Live reflow while the designer types into a member textbox — reflows
     *  the whole forest, so growth inside a nested section pushes the sibling
     *  sections below it exactly like the render will. */
    _reflowFor(target) {
        if (!target || !target.inputId) return;
        if (!this._containerOf(target.inputId)) return;

        const layout = this._layout();
        if (!layout || !this._prepared.length) return;
        layout.applyFabricLayout(this._prepared);
        this.repositionZones();
    }

    // --- zone overlay --------------------------------------------------------

    _depthOf(container) {
        let depth = 0;
        let current = container;
        const seen = new Set();
        while (current && !seen.has(current.id)) {
            seen.add(current.id);
            const parent = this._parentOf(current);
            if (!parent) break;
            depth += 1;
            current = parent;
        }
        return depth;
    }

    renderZones() {
        this._clearZones();
        if (!this.hasLayerTarget) return;

        // Parents first so nested zones stack above them in the DOM.
        const ordered = [...this._containers()].sort((a, b) => this._depthOf(a) - this._depthOf(b));

        ordered.forEach((container) => {
            if (this._deepMemberObjects(container).length < 2) return;
            const nested = this._parentOf(container) !== null;

            const zone = document.createElement('div');
            zone.className = nested ? 'container-zone container-zone--nested' : 'container-zone';

            // The label doubles as the MOVE handle for the whole container
            // (members are dragged individually with a plain Fabric drag).
            const label = document.createElement('span');
            label.className = 'container-zone__label';
            label.title = 'Tažením přesunete celý kontejner';
            label.addEventListener('mousedown', (event) => this._beginLabelDrag(event, container));
            zone.appendChild(label);

            // Per-container settings (gap, max height).
            const settings = document.createElement('button');
            settings.type = 'button';
            settings.className = 'container-zone__settings';
            settings.title = 'Nastavení kontejneru';
            settings.setAttribute('aria-label', 'Nastavení kontejneru');
            settings.innerHTML = '<i class="mdi mdi-cog-outline"></i>';
            settings.addEventListener('mousedown', (event) => event.stopPropagation());
            settings.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this._toggleSettings(container);
            });
            zone.appendChild(settings);

            // Removes the container DEFINITION only — the members stay. For a
            // nested container they are promoted into the parent, so the flow
            // survives. Undoable (containers ride history).
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'container-zone__delete';
            remove.title = nested
                ? 'Zrušit vnořený kontejner (prvky zůstanou v nadřazeném)'
                : 'Zrušit kontejner (prvky zůstanou)';
            remove.setAttribute('aria-label', 'Zrušit kontejner');
            remove.textContent = '×';
            remove.addEventListener('mousedown', (event) => event.stopPropagation());
            remove.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this._dissolve(container);
            });
            zone.appendChild(remove);

            if (!nested) {
                const handle = document.createElement('div');
                handle.className = 'container-zone__handle';
                handle.title = 'Táhnutím nastavíte maximální výšku kontejneru';
                handle.addEventListener('mousedown', (event) => this._beginHandleDrag(event, container));
                zone.appendChild(handle);
            }

            ['left', 'right'].forEach((side) => {
                const sideHandle = document.createElement('div');
                sideHandle.className = `container-zone__side container-zone__side--${side}`;
                sideHandle.title = 'Táhnutím změníte šířku kontejneru (texty se přizpůsobí)';
                sideHandle.addEventListener('mousedown', (event) => this._beginSideDrag(event, container, side));
                zone.appendChild(sideHandle);
            });

            this.layerTarget.appendChild(zone);
            this._zones.push({ container, zone, label, nested });
        });

        this._positionZones();
        this._syncSettings();
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

        this._zones.forEach(({ container, zone, label, nested }) => {
            const members = this._deepMemberObjects(container);
            if (members.length < 2) {
                zone.style.display = 'none';
                return;
            }
            zone.style.display = '';

            const tops = members.map((o) => this._absTop(o));
            const lefts = members.map((o) => this._absLeft(o));
            const rights = members.map((o, i) => lefts[i] + o.width * (o.scaleX || 1));
            const bottoms = members.map((o, i) => tops[i] + o.height * (o.scaleY || 1));

            const containerTop = Math.min(...tops);
            const contentBottom = Math.max(...bottoms);
            const left = Math.min(...lefts) - PAD;
            const width = Math.max(...rights) - Math.min(...lefts) + 2 * PAD;
            const height = nested ? (contentBottom - containerTop) : container.maxHeight;

            zone.style.left = `${offX + left * g.scale}px`;
            zone.style.top = `${offY + containerTop * g.scale}px`;
            zone.style.width = `${width * g.scale}px`;
            zone.style.height = `${height * g.scale}px`;

            const gapBadge = (typeof container.gap === 'number' && isFinite(container.gap))
                ? ` · mezery ${Math.round(container.gap)} px`
                : '';

            if (nested) {
                zone.classList.remove('container-zone--overflow');
                label.textContent = `Vnořený kontejner${gapBadge}`;
                return;
            }

            const overflowPx = contentBottom - (containerTop + container.maxHeight);
            const overflowing = overflowPx > 0.5;
            zone.classList.toggle('container-zone--overflow', overflowing);
            label.textContent = overflowing
                ? `Kontejner · obsah přesahuje o ${Math.ceil(overflowPx)} px`
                : `Kontejner · max ${Math.round(container.maxHeight)} px${gapBadge}`;
        });

        this._positionSettings();
    }

    _clearZones() {
        this._zones.forEach(({ zone }) => zone.remove());
        this._zones = [];
    }

    /** Zone label drag = move the WHOLE container (all members together,
     *  descendants included). */
    _beginLabelDrag(event, container) {
        event.preventDefault();
        event.stopPropagation();
        const g = this._geometry();
        const canvas = this._canvas();
        if (!g || !canvas) return;

        let lastX = event.clientX;
        let lastY = event.clientY;

        const onMove = (e) => {
            const dx = (e.clientX - lastX) / g.scale;
            const dy = (e.clientY - lastY) / g.scale;
            lastX = e.clientX;
            lastY = e.clientY;
            this._deepMemberObjects(container).forEach((obj) => {
                obj.set({ left: obj.left + dx, top: obj.top + dy });
                obj.setCoords();
            });
            canvas.requestRenderAll();
            this._positionZones();
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            // Dirty + undo snapshot + design re-derivation (gaps unchanged —
            // everything moved by the same delta).
            canvas.fire('object:modified', {});
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    /**
     * Side handle drag = resize the container width. Member textboxes scale
     * horizontally with it (left + wrap width, proportionally, anchored at the
     * opposite content edge), so the text re-wraps and the flow re-runs live —
     * width IS functional for text, it is the wrap width. Non-text members
     * (icons) only follow with their LEFT edge; their size is intentionally
     * kept (scaling raster icons with the wrap width would blur them).
     */
    _beginSideDrag(event, container, side) {
        event.preventDefault();
        event.stopPropagation();
        const g = this._geometry();
        const canvas = this._canvas();
        const layout = this._layout();
        if (!g || !canvas || !layout) return;

        const members = this._deepMemberObjects(container);
        if (members.length < 2) return;

        const start = members.map((obj) => ({
            obj,
            isText: layout.isTextboxObject(obj),
            left: obj.left,
            width: obj.width * (obj.scaleX || 1),
        }));
        const minLeft = Math.min(...start.map((s) => s.left));
        const maxRight = Math.max(...start.map((s) => s.left + s.width));
        const contentWidth = maxRight - minLeft;
        if (!(contentWidth > 0)) return;
        const startX = event.clientX;
        const MIN_WIDTH = 30;

        const onMove = (e) => {
            const dxCanvas = (e.clientX - startX) / g.scale;
            let ratio = side === 'right'
                ? (contentWidth + dxCanvas) / contentWidth
                : (contentWidth - dxCanvas) / contentWidth;
            ratio = Math.max(MIN_WIDTH / contentWidth, ratio);

            start.forEach(({ obj, isText, left, width }) => {
                const newLeft = side === 'right'
                    ? minLeft + (left - minLeft) * ratio
                    : maxRight - (maxRight - left) * ratio;
                if (isText) {
                    obj.set({ left: newLeft, width: Math.max(10, width * ratio) });
                } else {
                    obj.set({ left: newLeft });
                }
                obj.setCoords();
            });

            // New wrap widths → new heights → reflow, still anchored to the
            // pre-drag snapshot (stable while dragging).
            layout.applyFabricLayout(this._prepared);
            canvas.requestRenderAll();
            this._positionZones();
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            canvas.fire('object:modified', {});
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    /**
     * Drop a container definition. Members stay on the canvas; for a NESTED
     * container they are promoted into the parent (member ids + grandchildren
     * replace the child's slot), so the document flow survives. Undoable.
     */
    _dissolve(container) {
        const canvas = this._canvas();
        if (!canvas) return;

        const containers = this._containers();
        const parent = this._parentOf(container);
        if (parent) {
            parent.memberInputIds = [
                ...(parent.memberInputIds || []),
                ...(container.memberInputIds || []),
            ];
            parent.memberContainerIds = (parent.memberContainerIds || []).flatMap(
                (id) => (id === container.id ? (container.memberContainerIds || []).slice() : [id]),
            );
        }

        const index = containers.indexOf(container);
        if (index !== -1) {
            containers.splice(index, 1);
        }
        if (this._settingsContainerId === container.id) {
            this._closeSettings();
        }
        this._dropDegenerate();
        this._normalizeDesign();
        this.renderZones();
        canvas.fire('object:modified', {});
        this._syncSection();
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
            this._syncSettings();
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

    // --- settings popover (per-zone ⚙) ---------------------------------------

    _toggleSettings(container) {
        if (this._settingsContainerId === container.id) {
            this._closeSettings();
            return;
        }
        this._openSettings(container);
    }

    _openSettings(container) {
        this._closeSettings();
        if (!this.hasLayerTarget) return;

        const nested = this._parentOf(container) !== null;

        const el = document.createElement('div');
        el.className = 'container-zone-popover';
        el.addEventListener('mousedown', (event) => event.stopPropagation());

        const title = document.createElement('div');
        title.className = 'container-zone-popover__title';
        title.textContent = nested ? 'Vnořený kontejner' : 'Kontejner';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'container-zone-popover__close';
        close.setAttribute('aria-label', 'Zavřít');
        close.textContent = '×';
        close.addEventListener('click', () => this._closeSettings());
        title.appendChild(close);
        el.appendChild(title);

        // Gap: empty = designed gaps (pre-rework behavior), a number = uniform
        // document-flow spacing — positions then only determine order.
        const gapLabel = document.createElement('label');
        gapLabel.className = 'form-label small mb-1';
        gapLabel.textContent = 'Mezera mezi prvky (px)';
        el.appendChild(gapLabel);

        const gapInput = document.createElement('input');
        gapInput.type = 'number';
        gapInput.min = '0';
        gapInput.step = '1';
        gapInput.placeholder = 'z návrhu';
        gapInput.className = 'form-control form-control-sm mb-1';
        gapInput.value = (typeof container.gap === 'number' && isFinite(container.gap))
            ? String(Math.round(container.gap * 10) / 10)
            : '';
        gapInput.addEventListener('input', () => {
            const raw = gapInput.value.trim();
            if (raw === '') {
                delete container.gap;
            } else {
                const value = parseFloat(raw);
                if (!(value >= 0)) return;
                container.gap = value;
            }
            this._normalizeDesign();
            this.repositionZones();
            this.canvasEditorOutlet.markUnsaved();
        });
        // Commit = one undo snapshot for the whole slider session.
        gapInput.addEventListener('change', () => {
            const canvas = this._canvas();
            if (canvas) canvas.fire('object:modified', {});
        });
        el.appendChild(gapInput);

        const gapHint = document.createElement('div');
        gapHint.className = 'form-text small mb-2';
        gapHint.textContent = 'Prázdné = mezery podle návrhu. S jednotnou mezerou určuje svislá poloha prvků jen jejich pořadí.';
        el.appendChild(gapHint);

        if (!nested) {
            const maxLabel = document.createElement('label');
            maxLabel.className = 'form-label small mb-1';
            maxLabel.textContent = 'Max. výška (px)';
            el.appendChild(maxLabel);

            const maxInput = document.createElement('input');
            maxInput.type = 'number';
            maxInput.min = '20';
            maxInput.className = 'form-control form-control-sm mb-1';
            maxInput.value = String(Math.round(container.maxHeight));
            maxInput.addEventListener('input', () => {
                const value = parseFloat(maxInput.value);
                if (!(value > 0)) return;
                container.maxHeight = value;
                this.repositionZones();
                this._syncSection();
                this.canvasEditorOutlet.markUnsaved();
            });
            maxInput.addEventListener('change', () => {
                const canvas = this._canvas();
                if (canvas) canvas.fire('object:modified', {});
            });
            el.appendChild(maxInput);

            const maxHint = document.createElement('div');
            maxHint.className = 'form-text small mb-0';
            maxHint.textContent = 'Limit obsahu při vyplňování. U vnořených kontejnerů platí limit toho vnějšího.';
            el.appendChild(maxHint);
        } else {
            const nestedHint = document.createElement('div');
            nestedHint.className = 'form-text small mb-0';
            nestedHint.textContent = 'Vnořený kontejner roste podle obsahu — výšku omezuje vnější kontejner.';
            el.appendChild(nestedHint);
        }

        this.layerTarget.appendChild(el);
        this._settingsEl = el;
        this._settingsContainerId = container.id;
        this._positionSettings();
    }

    _closeSettings() {
        if (this._settingsEl) {
            this._settingsEl.remove();
        }
        this._settingsEl = null;
        this._settingsContainerId = null;
    }

    /** Keep the popover attached to its zone; drop it when the zone is gone. */
    _syncSettings() {
        if (!this._settingsContainerId) return;
        const exists = this._zones.some(({ container }) => container.id === this._settingsContainerId);
        if (!exists) {
            this._closeSettings();
            return;
        }
        this._positionSettings();
    }

    _positionSettings() {
        if (!this._settingsEl || !this._settingsContainerId) return;
        const entry = this._zones.find(({ container }) => container.id === this._settingsContainerId);
        if (!entry) return;

        const zone = entry.zone;
        const layerRect = this.layerTarget.getBoundingClientRect();
        const zoneRect = zone.getBoundingClientRect();
        const popRect = this._settingsEl.getBoundingClientRect();

        let left = zoneRect.right - layerRect.left + 10;
        if (left + popRect.width > layerRect.width - 4) {
            left = Math.max(4, zoneRect.left - layerRect.left - popRect.width - 10);
        }
        const top = Math.max(4, zoneRect.top - layerRect.top);

        this._settingsEl.style.left = `${left}px`;
        this._settingsEl.style.top = `${top}px`;
    }

    // --- popover section (active member) ------------------------------------

    _syncSection(activeObject) {
        if (!this.hasSectionTarget) return;
        const canvas = this._canvas();
        const layout = this._layout();
        const active = activeObject !== undefined
            ? activeObject
            : (canvas ? canvas.getActiveObject() : null);

        const container = active && active.inputId && layout && layout.isMemberCandidate(active)
            ? this._containerOf(active.inputId)
            : null;

        this.sectionTargets.forEach((el) => el.classList.toggle('d-none', !container));
        if (container) {
            const root = this._rootOf(container);
            this.maxHeightInputTargets.forEach((input) => {
                input.value = String(Math.round(root.maxHeight));
            });
        }
    }

    _syncCreateButton(activeObject) {
        if (!this.hasCreateButtonTarget) return;
        const isSelection = activeObject && (activeObject.type || '').toLowerCase() === 'activeselection';
        const enabled = Boolean(isSelection && this._classifySelection(activeObject));
        this.createButtonTarget.disabled = !enabled;
        this.createButtonTarget.title = enabled
            ? 'Vytvořit kontejner'
            : 'Kontejner vytvoříte z 2+ textů či obrázků; výběr prvků z existujících kontejnerů je vnoří jako celek';
    }
}
