import { Controller } from "@hotwired/stimulus";
import { ActiveSelection } from "fabric";

import { CANVAS_CUSTOM_PROPERTIES, applyEditorLock, applyTextboxDefaults } from './canvas_custom_properties.js';
import { applyShapeDefaults, isShapeObject } from './canvas_shapes.js';

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
    static targets = ["layer", "createButton", "section", "panel", "panelList"];

    /** Zone/panel accent per nesting depth — parent and child containers must
     *  read as DIFFERENT colors at a glance (cycled past depth 2). */
    static ZONE_COLORS = ['#39afd1', '#9d71f7', '#2fb380'];

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
        // Zone chrome visibility. Lives here with the rest of the state
        // because canvasEditorOutletConnected() (which renders zones) can fire
        // before connect() reads the toggle.
        this._zonesVisible = true;
    }

    connect() {
        this._boundReposition = () => this.repositionZones();
        window.addEventListener('resize', this._boundReposition);

        const toggle = this.element.querySelector('#container-zones-control');
        this._zonesVisible = toggle ? toggle.checked : true;
        this._applyZoneVisibility();
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
        // (see _beginLabelDrag). During a drag the zone follows via the
        // after:render hook (every drag frame ends in a render; a dedicated
        // object:moving pass would just do the same work twice per frame).
        this._onObjectModified = () => this._afterDesignChange();
        this._onTextChanged = (e) => this._reflowFor(e.target);
        this._onObjectRemoved = (e) => {
            if (!outlet.loadingCanvas) this._pruneRemoved(e.target);
        };
        this._onAfterRender = () => { if (this._zones.length) this._positionZones(); };

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
        // Moving the selection elsewhere (a Vrstvy click opening its element
        // popover, clicking another object, Esc) dismisses an open container
        // settings popover. The panel-row / dblclick openers are unaffected:
        // their selection events fire synchronously BEFORE they open it.
        this._closeSettings();
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
        const canvas = this._canvas();
        if (!layout || !canvas) return;
        this._prepared = layout.prepareFabricContainers(
            this._objects(),
            this._containers(),
            {
                getTop: (o) => this._absTop(o),
                getLeft: (o) => this._absLeft(o),
                // Logical canvas height = the variant's px height (zoom is a
                // CSS transform) — gates the page-bottom overflow.
                canvasHeight: typeof canvas.getHeight === 'function' ? canvas.getHeight() : canvas.height,
            },
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

    /**
     * Show/hide the dashed zone chrome (top-bar switch). Purely visual: the
     * container DEFINITIONS are untouched, so the design keeps reflowing, the
     * "Kontejnery" panel keeps listing them and a save still persists them —
     * this only gets the boxes, labels and handles out of the designer's way
     * while they judge the composition.
     *
     * CSS-driven rather than per-zone inline styles, because _positionZones()
     * writes `zone.style.display` on every render frame and would fight us;
     * clearing that inline value simply lets the class rule apply.
     */
    toggleZones(event) {
        this._zonesVisible = event.target.checked;
        this._applyZoneVisibility();
    }

    _applyZoneVisibility() {
        if (!this.hasLayerTarget) return;
        this.layerTarget.classList.toggle('container-zones-off', !this._zonesVisible);
        // An open settings popover anchors to its zone's rect; a hidden zone
        // measures 0×0 and would strand it in the stage's top-left corner.
        if (!this._zonesVisible) this._closeSettings();
    }

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
            const members = this._deepMemberObjects(container);
            if (members.length < 2) return;
            const nested = this._parentOf(container) !== null;
            const depth = this._depthOf(container);

            const zone = document.createElement('div');
            zone.className = nested ? 'container-zone container-zone--nested' : 'container-zone';
            // Depth-coded accent (border, label, handles) — the CSS reads the
            // vars with the legacy cyan as fallback.
            zone.style.setProperty('--zone-color', this._zoneColor(depth));
            zone.style.setProperty('--zone-tint', this._zoneTint(depth, nested));

            // The label doubles as the MOVE handle for the whole container
            // (members are dragged individually with a plain Fabric drag)
            // and as its SELECT handle: click = select the container's
            // members, Shift+click = add them to the current selection.
            const label = document.createElement('span');
            label.className = 'container-zone__label';
            label.title = 'Klik vybere kontejner (Shift přidá k výběru), dvojklik otevře nastavení, tažením ho přesunete';
            label.addEventListener('mousedown', (event) => this._beginLabelDrag(event, container));
            // Double-click = open the container's ⚙ settings popover (the
            // two single clicks select it first, which is fine).
            label.addEventListener('dblclick', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this._closeSettings();
                this._openSettings(container);
            });
            // Overlapping chrome escape hatch: hovering a label lifts ITS
            // zone above colliding siblings so its icons are clickable.
            label.addEventListener('mouseenter', () => zone.classList.add('container-zone--raise'));
            label.addEventListener('mouseleave', () => zone.classList.remove('container-zone--raise'));
            zone.appendChild(label);

            // Duplicate the whole container INCLUDING its objects (deep —
            // nested children come along). The copy lands offset and the
            // collision-push / parent flow settles it below the original.
            const duplicate = document.createElement('button');
            duplicate.type = 'button';
            duplicate.className = 'container-zone__duplicate';
            duplicate.title = 'Duplikovat kontejner včetně obsahu';
            duplicate.setAttribute('aria-label', 'Duplikovat kontejner');
            duplicate.innerHTML = '<i class="mdi mdi-content-copy"></i>';
            duplicate.addEventListener('mousedown', (event) => event.stopPropagation());
            duplicate.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this._duplicateContainer(container);
            });
            zone.appendChild(duplicate);

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
            // Members are cached for the per-frame reposition pass — the
            // after:render hook must not re-resolve membership every frame.
            this._zones.push({ container, zone, label, nested, members, css: '' });
        });

        this._positionZones();
        this._syncSettings();
        // The panel mirrors the zone STRUCTURE, so it re-renders exactly when
        // the zones do — here, not in repositionZones (that one fires on
        // zoom/scroll and only moves existing chrome).
        this._renderPanel();
    }

    repositionZones() {
        this._positionZones();
    }

    // --- containers panel (left panel, the Vrstvy pattern) -------------------

    _zoneColor(depth) {
        const palette = this.constructor.ZONE_COLORS;
        return palette[depth % palette.length];
    }

    _zoneTint(depth, nested) {
        const hex = this._zoneColor(depth).slice(1);
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${nested ? 0.02 : 0.04})`;
    }

    /** id → "Kontejner N – name", numbered across ALL containers in designed
     *  top order — the same numbering the ⚙ popover's parent select uses, so
     *  the panel and the popover always talk about the same "Kontejner 2". */
    _panelLabels() {
        const withTops = this._containers().map((c) => {
            const members = this._deepMemberObjects(c);
            return {
                id: c.id,
                top: members.length ? Math.min(...members.map((o) => this._absTop(o))) : Infinity,
                name: this._containerDisplayName(c),
            };
        }).sort((a, b) => a.top - b.top);

        return new Map(withTops.map(({ id, name }, index) => [
            id,
            name ? `Kontejner ${index + 1} – ${name}` : `Kontejner ${index + 1}`,
        ]));
    }

    /** Rebuild the "Kontejnery" left-panel list (called from renderZones):
     *  tree order (roots by designed top, children indented under their
     *  parent), row color = the zone's depth color. Hover highlights the zone
     *  on the stage and DIMS every other one — the overlapping-chrome escape
     *  hatch; click selects the container like a zone-label click. */
    _renderPanel() {
        if (!this.hasPanelTarget || !this.hasPanelListTarget) return;
        const zonesById = new Map(this._zones.map((entry) => [entry.container.id, entry]));
        this.panelTarget.classList.toggle('d-none', zonesById.size === 0);
        this.panelListTarget.textContent = '';
        if (zonesById.size === 0) return;

        const layout = this._layout();
        const containers = this._containers();
        const labels = this._panelLabels();
        const topOf = (c) => {
            const members = this._deepMemberObjects(c);
            return members.length ? Math.min(...members.map((o) => this._absTop(o))) : Infinity;
        };

        const addRows = (list, depth) => {
            [...list].sort((a, b) => topOf(a) - topOf(b)).forEach((container) => {
                if (zonesById.has(container.id)) {
                    this.panelListTarget.appendChild(
                        this._panelRow(container, depth, labels.get(container.id) || 'Kontejner'),
                    );
                }
                if (layout) {
                    addRows(layout.childContainersOf(containers, container), depth + 1);
                }
            });
        };
        addRows(layout ? layout.rootContainers(containers) : containers, 0);
    }

    _panelRow(container, depth, label) {
        const row = document.createElement('div');
        row.className = 'container-panel-row';
        row.setAttribute('role', 'listitem');
        row.style.setProperty('--zone-color', this._zoneColor(depth));
        row.style.marginLeft = `${depth * 14}px`;
        row.title = 'Klik vybere kontejner na plátně';

        const icon = document.createElement('i');
        icon.className = 'mdi mdi-view-agenda-outline';
        const text = document.createElement('span');
        text.className = 'text-truncate';
        text.textContent = label;
        row.append(icon, text);

        row.addEventListener('mouseenter', () => this._setPanelFocus(container, true));
        row.addEventListener('mouseleave', () => this._setPanelFocus(container, false));
        // One click, the Vrstvy pattern: focus the container (select its
        // members on canvas) AND open its ⚙ settings popover.
        row.addEventListener('click', () => {
            this._selectContainer(container, false);
            this._closeSettings();
            this._openSettings(container);
        });
        return row;
    }

    _setPanelFocus(container, on) {
        if (!this.hasLayerTarget) return;
        const entry = this._zones.find((e) => e.container.id === container.id);
        this._zones.forEach((e) => e.zone.classList.remove('container-zone--focus'));
        this.layerTarget.classList.toggle('container-zones-dim', on && Boolean(entry));
        if (on && entry) {
            entry.zone.classList.add('container-zone--focus');
        }
    }

    _positionZones() {
        if (!this._zones.length) return;
        const g = this._geometry();
        if (!g) return;
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const PAD = 6;

        this._zones.forEach((entry) => {
            const { container, zone, label, nested } = entry;
            // The design-hidden filter runs live: the layers eye can flip
            // `visible` without a membership change (no renderZones pass).
            const members = entry.members.filter((o) => o.visible !== false);
            if (members.length < 2) {
                if (entry.css !== 'hidden') {
                    zone.style.display = 'none';
                    entry.css = 'hidden';
                }
                return;
            }

            let top = Infinity;
            let bottom = -Infinity;
            let left = Infinity;
            let right = -Infinity;
            members.forEach((o) => {
                const oTop = this._absTop(o);
                const oLeft = this._absLeft(o);
                top = Math.min(top, oTop);
                bottom = Math.max(bottom, oTop + o.height * (o.scaleY || 1));
                left = Math.min(left, oLeft);
                right = Math.max(right, oLeft + o.width * (o.scaleX || 1));
            });

            const height = nested ? (bottom - top) : container.maxHeight;
            const overflowPx = nested ? 0 : bottom - (top + container.maxHeight);
            const overflowing = overflowPx > 0.5;

            const gapBadge = (typeof container.gap === 'number' && isFinite(container.gap))
                ? ` · mezery ${Math.round(container.gap)} px`
                : '';
            const text = nested
                ? `Vnořený kontejner${gapBadge}`
                : (overflowing
                    ? `Kontejner · obsah přesahuje o ${Math.ceil(overflowPx)} px`
                    : `Kontejner · max ${Math.round(container.maxHeight)} px${gapBadge}`);

            // Skip the DOM writes when nothing changed — this runs on EVERY
            // Fabric render frame via after:render.
            const css = `${left.toFixed(1)}|${top.toFixed(1)}|${right.toFixed(1)}|${height.toFixed(1)}|${overflowing}|${text}|${g.scale.toFixed(4)}|${offX.toFixed(1)}|${offY.toFixed(1)}`;
            if (entry.css === css) {
                return;
            }
            entry.css = css;

            zone.style.display = '';
            zone.style.left = `${offX + (left - PAD) * g.scale}px`;
            zone.style.top = `${offY + top * g.scale}px`;
            zone.style.width = `${(right - left + 2 * PAD) * g.scale}px`;
            zone.style.height = `${height * g.scale}px`;
            zone.classList.toggle('container-zone--overflow', overflowing);
            label.textContent = text;
        });

        this._positionSettings();
    }

    _clearZones() {
        this._zones.forEach(({ zone }) => zone.remove());
        this._zones = [];
        // A re-render mid-hover must not leave the stage dimmed forever.
        if (this.hasLayerTarget) {
            this.layerTarget.classList.remove('container-zones-dim');
        }
    }

    /** Zone label drag = move the WHOLE container (all members together,
     *  descendants included), snapped through the shared machine
     *  (canvas.wboostSnapping — same guides/hysteresis as Fabric drags,
     *  targets incl. other containers, ⌘ bypasses). Each frame positions
     *  members ABSOLUTELY from the gesture-start snapshot + total pointer
     *  delta + snap correction, so a correction never feeds back into the
     *  next frame. A press that never leaves the 3px slop is a CLICK and
     *  selects the container instead (see _selectContainer — shift merges
     *  into the current selection, which is how two containers get selected
     *  for "Vytvořit kontejner" nesting). */
    _beginLabelDrag(event, container) {
        event.preventDefault();
        event.stopPropagation();
        const g = this._geometry();
        const canvas = this._canvas();
        if (!g || !canvas) return;

        const startX = event.clientX;
        const startY = event.clientY;
        // Resolved once — per-mousemove membership lookups are wasted work.
        const dragMembers = this._deepMemberObjects(container);
        const start = dragMembers.map((obj) => ({ obj, left: obj.left, top: obj.top }));
        const bounds = this._zoneBounds(container, dragMembers);
        const snapping = canvas.wboostSnapping || null;
        if (snapping) snapping.beginGesture(dragMembers);
        let travelled = false;

        const onMove = (e) => {
            if (!travelled && Math.abs(e.clientX - startX) <= 3 && Math.abs(e.clientY - startY) <= 3) {
                return; // still inside the click slop — nothing moves yet
            }
            travelled = true;
            let dx = (e.clientX - startX) / g.scale;
            let dy = (e.clientY - startY) / g.scale;
            if (snapping && bounds) {
                const corr = snapping.snapGestureRect({
                    left: bounds.left + dx, right: bounds.right + dx,
                    top: bounds.top + dy, bottom: bounds.bottom + dy,
                    cx: (bounds.left + bounds.right) / 2 + dx,
                    cy: (bounds.top + bounds.bottom) / 2 + dy,
                }, e);
                dx += corr.dx;
                dy += corr.dy;
            }
            start.forEach(({ obj, left, top }) => {
                obj.set({ left: left + dx, top: top + dy });
                obj.setCoords();
            });
            canvas.requestRenderAll();
        };
        const onUp = (e) => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            if (snapping) snapping.endGesture();
            if (!travelled) {
                this._selectContainer(container, event.shiftKey || e.shiftKey);
                return;
            }
            // Dirty + undo snapshot + design re-derivation (gaps unchanged —
            // everything moved by the same delta).
            canvas.fire('object:modified', {});
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    /** The zone box the container snaps BY: union of its visible deep
     *  members, extended to the designer-set maxHeight for top-level
     *  containers (the dashed bottom line the user sees) — the same shape
     *  the snapping controller offers as a target for other drags. */
    _zoneBounds(container, members) {
        const visible = members.filter((o) => o.visible !== false);
        if (!visible.length) return null;
        const rects = visible.map((o) => o.getBoundingRect());
        const left = Math.min(...rects.map((r) => r.left));
        const top = Math.min(...rects.map((r) => r.top));
        const right = Math.max(...rects.map((r) => r.left + r.width));
        let bottom = Math.max(...rects.map((r) => r.top + r.height));
        if (!this._parentOf(container) && Number.isFinite(container.maxHeight) && container.maxHeight > 0) {
            bottom = Math.max(bottom, top + container.maxHeight);
        }
        return { left, top, right, bottom };
    }

    /**
     * Click (no travel) on a zone label selects the container = an
     * ActiveSelection of its visible deep member objects, like clicking any
     * other object; SHIFT adds them to the current selection. Label-click
     * container A + shift-label-click container B → members of both are
     * selected → the multi-select bar's "Vytvořit kontejner" classifies
     * their roots as children and nests them under a new parent.
     */
    _selectContainer(container, additive) {
        const canvas = this._canvas();
        if (!canvas) return;
        const members = this._deepMemberObjects(container)
            .filter((o) => o.visible !== false && o.selectable !== false);
        if (!members.length) return;

        const merged = new Set(additive ? canvas.getActiveObjects() : []);
        members.forEach((o) => merged.add(o));
        // Keep canvas stacking order inside the selection (the alignment
        // controller's pattern) instead of click order.
        const objects = this._objects().filter((o) => merged.has(o));
        if (!objects.length) return;

        canvas.discardActiveObject();
        if (objects.length === 1) {
            canvas.setActiveObject(objects[0]);
        } else {
            canvas.setActiveObject(new ActiveSelection(objects, { canvas }));
        }
        canvas.requestRenderAll();
        // setActiveObject fires no selection events when the member set is
        // unchanged — rebroadcast so the floating multi-bar and the
        // create-container button state re-sync either way.
        if (this.hasCanvasEditorOutlet) {
            this.canvasEditorOutlet.dispatchSelectionChanged();
        }
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
            // pre-drag snapshot (stable while dragging). Zones follow via
            // after:render.
            layout.applyFabricLayout(this._prepared);
            canvas.requestRenderAll();
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
     * Duplicate a container INCLUDING all of its objects: clones the whole
     * tree (direct members + nested children, design-hidden ones too) with
     * fresh inputIds, mirrors the definitions with fresh container ids
     * (maxHeight/gap/spaceAfter copied), and — for a nested original — joins
     * the copy to the SAME parent as a sibling section. The copy starts at a
     * +20/+20 offset; the normalize pass (sibling collision-push / parent
     * flow) then settles it below the original. Undoable as one step.
     */
    async _duplicateContainer(container) {
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;

        const containers = this._containers();

        const treeDefs = [];
        const collectTree = (def, seen) => {
            if (!def || seen.has(def.id)) return;
            seen.add(def.id);
            treeDefs.push(def);
            layout.childContainersOf(containers, def).forEach((child) => collectTree(child, seen));
        };
        collectTree(container, new Set());

        // Member objects across the tree, in canvas stack order so the clones
        // keep their relative z-order (they land as a block on top, like a
        // paste). Resolved WITHOUT the visibility filter — a design-hidden
        // member is still part of the container and must survive the copy.
        const wanted = new Set();
        treeDefs.forEach((def) => (def.memberInputIds || []).forEach((id) => wanted.add(id)));
        const originals = this._objects().filter((o) => o.inputId && wanted.has(o.inputId));
        if (originals.length === 0) return;

        const OFFSET = 20;
        const inputIdMap = new Map();
        for (const original of originals) {
            const clone = await original.clone(CANVAS_CUSTOM_PROPERTIES);
            const newId = crypto.randomUUID();
            inputIdMap.set(original.inputId, newId);
            clone.inputId = newId;
            clone.set({ left: clone.left + OFFSET, top: clone.top + OFFSET });
            // Clone drops the live interaction flags — re-derive them exactly
            // like the load path does.
            const type = (clone.type || '').toLowerCase();
            if (isShapeObject(clone)) {
                applyShapeDefaults(clone);
                applyEditorLock(clone);
            } else if (type === 'image') {
                applyEditorLock(clone);
            } else if (type === 'textbox') {
                applyTextboxDefaults(clone);
            }
            canvas.add(clone);
        }

        const containerIdMap = new Map(treeDefs.map((def) => [def.id, crypto.randomUUID()]));
        treeDefs.forEach((def) => {
            const copy = {
                id: containerIdMap.get(def.id),
                maxHeight: def.maxHeight,
                memberInputIds: (def.memberInputIds || [])
                    .map((id) => inputIdMap.get(id))
                    .filter(Boolean),
                memberContainerIds: (def.memberContainerIds || [])
                    .map((id) => containerIdMap.get(id))
                    .filter(Boolean),
            };
            if (typeof def.gap === 'number' && isFinite(def.gap)) copy.gap = def.gap;
            if (typeof def.spaceAfter === 'number' && isFinite(def.spaceAfter)) copy.spaceAfter = def.spaceAfter;
            containers.push(copy);
        });

        // A nested original duplicates as a SIBLING inside the same parent.
        const parent = this._parentOf(container);
        if (parent) {
            parent.memberContainerIds = [
                ...(parent.memberContainerIds || []),
                containerIdMap.get(container.id),
            ];
        }

        this._dropDegenerate();
        this._normalizeDesign();
        this.renderZones();
        canvas.requestRenderAll();
        canvas.fire('object:modified', {});
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
        // Zones hidden: the popover has nothing to anchor to (see
        // _applyZoneVisibility). The panel row still selects the container.
        if (!this._zonesVisible) return;

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

        // Space AFTER the container: the clearance it keeps when pushing the
        // container below it / against the bottom of the canvas.
        const afterLabel = document.createElement('label');
        afterLabel.className = 'form-label small mb-1';
        afterLabel.textContent = 'Mezera za kontejnerem (px)';
        el.appendChild(afterLabel);

        const afterInput = document.createElement('input');
        afterInput.type = 'number';
        afterInput.min = '0';
        afterInput.step = '1';
        afterInput.placeholder = '0';
        afterInput.className = 'form-control form-control-sm mb-1';
        afterInput.value = (typeof container.spaceAfter === 'number' && isFinite(container.spaceAfter))
            ? String(Math.round(container.spaceAfter * 10) / 10)
            : '';
        afterInput.addEventListener('input', () => {
            const raw = afterInput.value.trim();
            if (raw === '') {
                delete container.spaceAfter;
            } else {
                const value = parseFloat(raw);
                if (!(value >= 0)) return;
                container.spaceAfter = value;
            }
            this._normalizeDesign();
            this.repositionZones();
            this.canvasEditorOutlet.markUnsaved();
        });
        afterInput.addEventListener('change', () => {
            const canvas = this._canvas();
            if (canvas) canvas.fire('object:modified', {});
        });
        el.appendChild(afterInput);

        const afterHint = document.createElement('div');
        afterHint.className = 'form-text small mb-2';
        afterHint.textContent = 'Minimální odstup od dalšího kontejneru pod ním a od spodního okraje plátna.';
        el.appendChild(afterHint);

        // Explicit nesting control — the discoverable counterpart of
        // "select members of two containers → Vytvořit kontejner".
        const parentOptions = this._nestingTargets(container);
        if (parentOptions.length > 0 || this._parentOf(container)) {
            const nestLabel = document.createElement('label');
            nestLabel.className = 'form-label small mb-1';
            nestLabel.textContent = 'Vnořit do kontejneru';
            el.appendChild(nestLabel);

            const nestSelect = document.createElement('select');
            nestSelect.className = 'form-select form-select-sm mb-1';
            const noneOption = document.createElement('option');
            noneOption.value = '';
            noneOption.textContent = '— samostatný —';
            nestSelect.appendChild(noneOption);
            parentOptions.forEach(({ id, label }) => {
                const option = document.createElement('option');
                option.value = id;
                option.textContent = label;
                nestSelect.appendChild(option);
            });
            const currentParent = this._parentOf(container);
            nestSelect.value = currentParent && parentOptions.some((o) => o.id === currentParent.id)
                ? currentParent.id
                : '';
            nestSelect.addEventListener('change', () => {
                this._reparent(container, nestSelect.value || null);
            });
            el.appendChild(nestSelect);

            const nestHint = document.createElement('div');
            nestHint.className = 'form-text small mb-2';
            nestHint.textContent = 'Vnořený kontejner se posouvá jako jeden blok v toku nadřazeného (mezery řídí nadřazený kontejner).';
            el.appendChild(nestHint);
        }

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

        // Dissolution lives here too — the ⚙ popover is the container's whole
        // configuration surface (element popovers only carry the
        // element-specific "Odebrat z kontejneru").
        const dissolve = document.createElement('button');
        dissolve.type = 'button';
        dissolve.className = 'btn btn-sm btn-outline-danger w-100 mt-1';
        dissolve.textContent = nested
            ? 'Zrušit kontejner (prvky zůstanou v nadřazeném)'
            : 'Zrušit kontejner (prvky zůstanou)';
        dissolve.addEventListener('click', (event) => {
            event.preventDefault();
            this._dissolve(container);
        });
        el.appendChild(dissolve);

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

    /**
     * Containers this one may be nested INTO: everything except itself and
     * its own descendants (a cycle), labelled by vertical order + the first
     * member's name so the designer can tell them apart.
     */
    _nestingTargets(container) {
        const layout = this._layout();
        if (!layout) return [];
        const containers = this._containers();

        const descendants = new Set();
        const collectDescendants = (c) => {
            layout.childContainersOf(containers, c).forEach((child) => {
                if (descendants.has(child.id)) return;
                descendants.add(child.id);
                collectDescendants(child);
            });
        };
        collectDescendants(container);

        const withTops = containers
            .filter((c) => c.id !== container.id && !descendants.has(c.id))
            .map((c) => {
                const members = this._deepMemberObjects(c);
                return {
                    container: c,
                    top: members.length ? Math.min(...members.map((o) => this._absTop(o))) : Infinity,
                    name: this._containerDisplayName(c),
                };
            })
            .sort((a, b) => a.top - b.top);

        return withTops.map(({ container: c, name }, index) => ({
            id: c.id,
            label: name ? `Kontejner ${index + 1} – ${name}` : `Kontejner ${index + 1}`,
        }));
    }

    _containerDisplayName(container) {
        const first = this._memberObjects(container)[0];
        if (!first) return '';
        if (typeof first.name === 'string' && first.name.trim() !== '') {
            return first.name.trim().slice(0, 30);
        }
        if (typeof first.text === 'string' && first.text.trim() !== '') {
            return first.text.trim().slice(0, 30);
        }
        return '';
    }

    /** Move a container under a new parent (or make it standalone). */
    _reparent(container, newParentId) {
        const canvas = this._canvas();
        if (!canvas) return;
        const containers = this._containers();

        containers.forEach((c) => {
            if (Array.isArray(c.memberContainerIds) && c.memberContainerIds.includes(container.id)) {
                c.memberContainerIds = c.memberContainerIds.filter((id) => id !== container.id);
            }
        });
        if (newParentId) {
            const parent = containers.find((c) => c.id === newParentId);
            if (parent) {
                parent.memberContainerIds = [...(parent.memberContainerIds || []), container.id];
            }
        }

        this._dropDegenerate();
        this._normalizeDesign();
        this.renderZones();
        canvas.fire('object:modified', {});
        // Rebuild the popover — the nested/top-level field set changed.
        const stillExists = this._containers().includes(container);
        this._closeSettings();
        if (stillExists) {
            this._openSettings(container);
        }
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
