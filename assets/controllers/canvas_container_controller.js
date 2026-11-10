import { Controller } from "@hotwired/stimulus";
import { ActiveSelection } from "fabric";

import { CANVAS_CUSTOM_PROPERTIES, applyEditorLock, applyTextboxDefaults } from './canvas_custom_properties.js';
import { applyShapeDefaults, isShapeObject } from './canvas_shapes.js';
import { designHeight, trimOriginRect } from './canvas_viewport.js';
import { buildContainerMoveSection } from './canvas_user_move.js';
import { applyRepeatRules, normalizeRepeat, repeatBlockReason } from './canvas_payload.js';
import { MAX_ROWS, rowCapacity } from './container_rows.js';
import { applyRowMerge, planRowMerge } from './container_row_merge.js';

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
    static targets = ["layer", "createButton", "mergeButton", "section", "panel", "panelList", "rowButton"];

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
        // Repeatable rows (container-rows P3): row id → {pitch, fits,
        // limitKind, groupFits} from the last _refreshRowInfo() pass — what
        // the ghost outlines, the zone badge and the ⚙ popover read.
        this._rowInfo = new Map();
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
        this._syncMergeButton(active);
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

    /**
     * "Opakovat jako řádek" (element popover): wrap the selected text into a
     * NESTED single-item container marked `repeat`, inside the container it
     * already flows in — how a designer authors a one-text row (a column of
     * scorers); "Vytvořit kontejner" needs 2+ items. Max is prefilled with
     * how many rows fit; the row's ⚙ popover opens for the rest. One undo
     * step.
     */
    makeActiveRow() {
        const canvas = this._canvas();
        const active = canvas ? canvas.getActiveObject() : null;
        if (!active || !active.inputId) return;
        const parent = this._containerOf(active.inputId);
        if (!parent || this._rowBlockReason(active) !== null) return;

        const containers = this._containers();
        const row = {
            id: crypto.randomUUID(),
            // Inert for a nested container (only the root bounds) but must
            // be positive to survive the sanitizer.
            maxHeight: Math.max(20, Math.ceil(active.height * (active.scaleY || 1))),
            memberInputIds: [active.inputId],
            memberContainerIds: [],
            repeat: { max: 1, min: 1, default: 1 },
        };
        parent.memberInputIds = (parent.memberInputIds || []).filter((id) => id !== active.inputId);
        parent.memberContainerIds = [...(parent.memberContainerIds || []), row.id];
        containers.push(row);

        this._refreshRowInfo();
        const info = this._rowInfo.get(row.id);
        row.repeat = { max: Math.max(1, Math.min(MAX_ROWS, info ? info.groupFits : 1)), min: 1, default: 1 };

        canvas.discardActiveObject();
        this._dropDegenerate();
        this._normalizeDesign();
        this.renderZones();
        canvas.requestRenderAll();
        canvas.fire('object:modified', {});
        this.canvasEditorOutlet.dispatchSelectionChanged();
        if (this._containers().includes(row)) {
            this._openSettings(row);
        }
    }

    /** Why the selected member cannot become a repeatable row (null = it can). */
    _rowBlockReason(active) {
        const parent = active && active.inputId ? this._containerOf(active.inputId) : null;
        if (!parent) return 'not a member';
        const candidate = { id: '__row-candidate', memberInputIds: [active.inputId], memberContainerIds: [] };
        const defs = this._containers().map((c) => (c.id === parent.id
            ? { ...c, memberContainerIds: [...(c.memberContainerIds || []), candidate.id] }
            : c));
        defs.push(candidate);
        return repeatBlockReason(defs, candidate);
    }

    // --- "Sloučit do opakovaného řádku" (container-rows P6) -----------------

    /**
     * Merge hand-numbered copies of a row into ONE repeatable row (the
     * multi-select bar). Select one element of each row (or the single texts
     * of a column); the topmost row stays as the template with
     * `repeat {max: what fits, ≥ N; min 1; default N; slots}` — `slots`
     * keeps the removed rows' ids alive as the repeated rows' ids, so saved
     * exports, drafts, API callers and documents keep landing in the right
     * row (container_row_merge.js has the contract). In the group editor the
     * SAME merge (same row id, same slots) is applied to every size, or to
     * none. Differences that change nothing but a few pixels are listed for
     * a confirm; real differences refuse with the reason. One undo step.
     */
    mergeSelectionIntoRow() {
        const canvas = this._canvas();
        if (!canvas) return;
        const selection = canvas.getActiveObject();
        if (!selection || (selection.type || '').toLowerCase() !== 'activeselection') {
            this._mergeToast('Vyberte řádky ke sloučení', ['Označte aspoň dva stejné řádky v kontejneru — z řádku o více prvcích stačí jeden prvek.']);
            return;
        }

        const selectedIds = selection.getObjects().map((o) => o.inputId).filter(Boolean);
        const plan = this._planMergeFor(selection);
        if (!plan.ok) {
            this._mergeToast('Řádky nejde sloučit', [plan.reason]);
            return;
        }

        // Group editor: every OTHER size must merge the very same rows the
        // very same way (shared ids → one shared row count on the fill page).
        const surfaces = typeof canvas.wboostGroupSurfaces === 'function' ? canvas.wboostGroupSurfaces() : null;
        const siblings = [];
        if (surfaces) {
            if (surfaces.hydrated === false) {
                this._mergeToast('Řádky zatím nejde sloučit', ['Počkejte, než se načtou ostatní velikosti skupiny.']);
                return;
            }
            for (const other of surfaces.others || []) {
                const otherPlan = planRowMerge({
                    objects: other.shadow.getObjects(),
                    containers: Array.isArray(other.shadow.wboostContainers) ? other.shadow.wboostContainers : [],
                    selectedIds,
                });
                if (!otherPlan.ok) {
                    this._mergeToast(`Řádky nejde sloučit ve velikosti ${other.label}`, [otherPlan.reason]);
                    return;
                }
                const sameMerge = otherPlan.mode === plan.mode && otherPlan.count === plan.count
                    && otherPlan.templateRowId === plan.templateRowId && otherPlan.templateMemberId === plan.templateMemberId
                    && JSON.stringify(otherPlan.slots) === JSON.stringify(plan.slots);
                if (!sameMerge) {
                    this._mergeToast(`Řádky nejde sloučit ve velikosti ${other.label}`, ['Řádky jsou tam seřazené jinak než v této velikosti — srovnejte jejich pořadí shora dolů.']);
                    return;
                }
                siblings.push({ other, plan: otherPlan });
            }
        }

        const soft = [
            ...plan.soft,
            ...siblings.flatMap(({ other, plan: otherPlan }) => otherPlan.soft.map((line) => `${other.label} — ${line}`)),
        ];
        if (soft.length) {
            const shown = soft.slice(0, 8).map((line) => `• ${line}`);
            if (soft.length > shown.length) shown.push(`• … a ${soft.length - shown.length} dalších`);
            const ok = window.confirm([
                `Sloučit ${plan.count} řádky do jednoho opakovaného řádku?`,
                '',
                'Řádky se v drobnostech liší — sloučené řádky převezmou vzhled a nastavení 1. řádku:',
                ...shown,
                '',
                'Uložené exporty, koncepty i volání API se dál načtou do správných řádků.',
            ].join('\n'));
            if (!ok) return;
        }

        canvas.discardActiveObject();
        const rowId = plan.mode === 'texts' ? crypto.randomUUID() : plan.templateRowId;

        // ONE undo step: the removals below must not each snapshot
        // (canvas_history_controller honours the flag); the closing
        // object:modified takes the single snapshot.
        canvas.wboostHistoryBatch = true;
        try {
            this._applyMerge(canvas, plan, rowId);
            siblings.forEach(({ other, plan: otherPlan }) => this._applyMerge(other.shadow, otherPlan, rowId));
        } finally {
            canvas.wboostHistoryBatch = false;
        }

        // Max = how many rows FIT (the smallest size decides), never below
        // the merged count — the fill page opens with all N rows.
        this._refreshRowInfo();
        const info = this._rowInfo.get(rowId);
        const max = Math.max(plan.count, Math.min(MAX_ROWS, info ? info.groupFits : plan.count));
        [canvas, ...siblings.map(({ other }) => other.shadow)].forEach((target) => {
            const row = (target.wboostContainers || []).find((c) => c.id === rowId);
            if (row && row.repeat) row.repeat = { ...row.repeat, max };
        });

        this._dropDegenerate();
        this._normalizeDesign();
        this.renderZones();
        canvas.requestRenderAll();
        if (siblings.length && typeof canvas.wboostGroupShadowsChanged === 'function') {
            canvas.wboostGroupShadowsChanged(siblings.map(({ other }) => other.id));
        }
        canvas.fire('object:modified', {});
        this.canvasEditorOutlet.dispatchSelectionChanged();

        this._mergeToast(`Sloučeno: ${plan.count} řádky → 1 opakovaný řádek`, [
            `Uživatel uvidí ${plan.count} řádky a může přidávat až do ${max}. Staré exporty se načtou do správných řádků.`,
        ], true);
        const row = this._containers().find((c) => c.id === rowId);
        if (row) this._openSettings(row);
    }

    /** One canvas (the live one or a group shadow): definitions, removals, moves. */
    _applyMerge(target, plan, rowId) {
        const { containers } = applyRowMerge(Array.isArray(target.wboostContainers) ? target.wboostContainers : [], plan, { rowId, max: plan.count });
        // Definitions FIRST: the removals below then find no container to
        // prune (and the parent already holds its repeatable row, so it is
        // never "degenerate" in between).
        target.wboostContainers = containers;
        const removed = new Set(plan.removeInputIds);
        const doomed = target.getObjects().filter((o) => o.inputId && removed.has(o.inputId));
        if (doomed.length) target.remove(...doomed);
        target.getObjects().forEach((o) => {
            const dy = o.inputId ? plan.moves[o.inputId] : undefined;
            if (typeof dy === 'number' && dy !== 0) {
                o.set('top', o.top + dy);
                o.setCoords();
            }
        });
    }

    _planMergeFor(selection) {
        return planRowMerge({
            objects: this._objects(),
            containers: this._containers(),
            selectedIds: selection.getObjects().map((o) => o.inputId).filter(Boolean),
            // Members of an ActiveSelection carry relative coords.
            geometry: (obj) => ({
                left: this._absLeft(obj),
                top: this._absTop(obj),
                width: (obj.width || 0) * (obj.scaleX || 1),
                height: (obj.height || 0) * (obj.scaleY || 1),
            }),
        });
    }

    _syncMergeButton(activeObject) {
        if (!this.hasMergeButtonTarget) return;
        const isSelection = activeObject && (activeObject.type || '').toLowerCase() === 'activeselection';
        const plan = isSelection ? this._planMergeFor(activeObject) : null;
        const ok = Boolean(plan && plan.ok);
        // Never `disabled`: a click on a selection that cannot merge says WHY
        // (a disabled button swallows the click and, in some browsers, the
        // tooltip too).
        this.mergeButtonTarget.setAttribute('aria-disabled', ok ? 'false' : 'true');
        this.mergeButtonTarget.classList.toggle('is-unavailable', !ok);
        this.mergeButtonTarget.title = ok
            ? `Sloučit do opakovaného řádku — ${plan.count} řádky se stanou jedním, který si uživatel přidává (staré exporty zůstanou)`
            : `Sloučit do opakovaného řádku — ${plan ? plan.reason : 'vyberte aspoň dva stejné řádky v kontejneru'}`;
    }

    _mergeToast(title, lines, success = false) {
        let host = document.getElementById('gallery-toast-container');
        if (!host) {
            host = document.createElement('div');
            host.id = 'gallery-toast-container';
            host.className = 'gallery-toast-container';
            document.body.appendChild(host);
        }
        const toast = document.createElement('div');
        toast.className = `gallery-toast container-merge-toast${success ? ' container-merge-toast--success' : ''}`;
        toast.setAttribute('role', success ? 'status' : 'alert');
        const heading = document.createElement('strong');
        heading.textContent = title;
        toast.appendChild(heading);
        lines.forEach((line) => {
            const p = document.createElement('div');
            p.className = 'small';
            p.textContent = line;
            toast.appendChild(p);
        });
        host.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('gallery-toast--show'));
        setTimeout(() => {
            toast.classList.remove('gallery-toast--show');
            setTimeout(() => toast.remove(), 300);
        }, success ? 6000 : 9000);
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

        // Repeatable-row structure first (a dissolve / re-parent can leave a
        // row on a root or a second row in one parent) — the minimum below
        // depends on which rows survive.
        let defs = applyRepeatRules(this._containers());
        for (;;) {
            const valid = new Set(
                defs
                    .filter((c) => this._memberObjects(c).length + layout.childContainersOf(defs, c).length >= this._minItems(c, defs))
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

    /**
     * Items a container needs to exist: 2, but a repeatable row may be ONE
     * (a column of single scorers) and a parent may hold nothing but its
     * repeatable row (≡ sanitizedContainers / PHP CanvasContainer).
     */
    _minItems(container, defs = this._containers()) {
        if (container.repeat) return 1;
        const byId = new Map(defs.map((c) => [c.id, c]));
        return (container.memberContainerIds || []).some((id) => byId.get(id) && byId.get(id).repeat) ? 1 : 2;
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
                canvasHeight: typeof canvas.getHeight === 'function' ? designHeight(canvas) : canvas.height,
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
        // Typing changes the row height → pitch, fits and the ghosts.
        if (this._rowInfo.size) this._refreshRowInfo();
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

        this._refreshRowInfo();

        ordered.forEach((container) => {
            const members = this._deepMemberObjects(container);
            if (members.length < this._minItems(container)) return;
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
            duplicate.innerHTML = '<span class="i i-copy s12" aria-hidden="true"></span>';
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
            settings.innerHTML = '<span class="i i-settings s12" aria-hidden="true"></span>';
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
            // Repeatable row: dashed ghosts of rows 2…max (where the extra
            // rows would land) + the root's limit line. Plain DOM in the
            // zone layer like the zone itself — never on the canvas bitmap,
            // so nothing can leak into the saved design.
            const rowChrome = container.repeat && this._rowInfo.has(container.id)
                ? this._buildRowChrome(container)
                : null;
            // Members are cached for the per-frame reposition pass — the
            // after:render hook must not re-resolve membership every frame.
            this._zones.push({ container, zone, label, nested, members, css: '', rowChrome });
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

    // --- repeatable rows (container-rows P3) -------------------------------

    /**
     * The shared engine run over geometry POJOs of a surface — side-effect
     * free (the live objects are never written), so it is safe on load and
     * mid-edit. Returns the POJOs (laid out) + the engine's results.
     */
    _designRun(objects, containers, canvasHeight, getTop, getLeft) {
        const layout = this._layout();
        if (!layout) return null;
        const pojos = objects
            .filter((o) => o.inputId)
            .map((o) => ({
                type: o.type,
                inputId: o.inputId,
                imagePlaceholder: o.imagePlaceholder,
                isBackground: o.isBackground,
                top: getTop(o),
                left: getLeft(o),
                width: o.width,
                height: o.height,
                scaleX: o.scaleX || 1,
                scaleY: o.scaleY || 1,
                visible: o.visible,
            }));
        const prepared = layout.prepareFabricContainers(pojos, containers, { canvasHeight });
        return { pojos, results: layout.applyFabricLayout(prepared) };
    }

    /**
     * Group editor: the OTHER sizes of the group ({label, height, shadow}) —
     * published by group_editor_controller on the canvas. Single editor: none.
     */
    _groupSurfaces() {
        const canvas = this._canvas();
        const provider = canvas && typeof canvas.wboostGroupSurfaces === 'function' ? canvas.wboostGroupSurfaces : null;
        return provider ? provider() : null;
    }

    /**
     * Re-derive every repeatable row's pitch / "fits N" on THIS canvas and —
     * in a group — on every other size (the row count is SHARED, so the
     * smallest size decides: Jan, 2026-11-09). Cached in `_rowInfo` for the
     * ghosts, the zone badge and the ⚙ popover.
     */
    _refreshRowInfo() {
        this._rowInfo = new Map();
        const canvas = this._canvas();
        const layout = this._layout();
        if (!canvas || !layout) return;
        const containers = this._containers();
        const rows = containers.filter((c) => c.repeat);
        if (!rows.length) return;

        const run = this._designRun(this._objects(), containers, designHeight(canvas), (o) => this._absTop(o), (o) => this._absLeft(o));
        if (!run) return;

        const group = this._groupSurfaces();
        const otherRuns = group
            ? group.others
                .filter((surface) => surface.shadow && Array.isArray(surface.shadow.wboostContainers))
                .map((surface) => ({
                    surface,
                    containers: surface.shadow.wboostContainers,
                    run: this._designRun(surface.shadow.getObjects(), surface.shadow.wboostContainers, surface.height, (o) => o.top, (o) => o.left),
                }))
            : [];

        rows.forEach((row) => {
            const capacity = rowCapacity(run.pojos, containers, row.id, {
                layout, results: run.results, canvasHeight: designHeight(canvas),
            });
            if (!capacity) return;

            const perSize = [];
            if (group && group.active) {
                perSize.push({ label: group.active.label, fits: capacity.fits, active: true });
            }
            otherRuns.forEach(({ surface, containers: shadowContainers, run: shadowRun }) => {
                if (!shadowRun || !shadowContainers.some((c) => c.id === row.id && c.repeat)) return;
                const other = rowCapacity(shadowRun.pojos, shadowContainers, row.id, {
                    layout, results: shadowRun.results, canvasHeight: surface.height,
                });
                if (other) perSize.push({ label: surface.label, fits: other.fits, active: false });
            });

            this._rowInfo.set(row.id, {
                pitch: capacity.geometry.pitch,
                // One row's flow height — what a ghost outline is tall.
                rowHeight: Math.max(1, capacity.geometry.pitch - capacity.geometry.gap),
                fits: capacity.fits,
                limitKind: capacity.limitKind,
                // The root's bound, relative to the row's top on THIS canvas
                // — the ghost layer re-anchors it to the live row each frame.
                limitOffset: capacity.limitBottom - capacity.geometry.extent.top,
                groupFits: perSize.length ? Math.min(...perSize.map((p) => p.fits)) : capacity.fits,
                perSize,
            });
        });
    }

    /**
     * Ghost outlines of rows 2…max under a repeatable row (default rows
     * stronger, rows past what fits red) + the bound line they run into. A
     * small controller object: `place(geometry)` per reposition frame,
     * `hide()`, `remove()`.
     */
    _buildRowChrome(container) {
        const info = this._rowInfo.get(container.id);
        const layer = this.layerTarget;
        const color = this._zoneColor(this._depthOf(container));
        const ghosts = [];
        const max = Math.min(MAX_ROWS, container.repeat.max);
        for (let k = 1; k < max; k++) {
            const ghost = document.createElement('div');
            ghost.className = 'container-row-ghost';
            ghost.style.setProperty('--zone-color', color);
            ghost.classList.toggle('container-row-ghost--default', k < container.repeat.default);
            // Row k+1 needs k+1 ≤ fits (the smallest size in a group).
            ghost.classList.toggle('container-row-ghost--over', k + 1 > info.groupFits);
            const tag = document.createElement('span');
            tag.className = 'container-row-ghost__label';
            tag.textContent = k + 1 > info.groupFits ? `Řádek ${k + 1} · nevejde se` : `Řádek ${k + 1}`;
            ghost.appendChild(tag);
            ghost.setAttribute('aria-hidden', 'true');
            layer.appendChild(ghost);
            ghosts.push(ghost);
        }

        const limit = document.createElement('div');
        limit.className = 'container-row-limit';
        const limitTag = document.createElement('span');
        limitTag.className = 'container-row-limit__label';
        limitTag.textContent = info.limitKind === 'page'
            ? 'Spodní okraj plátna (− mezera za kontejnerem)'
            : 'Max. výška nadřazeného kontejneru';
        limit.appendChild(limitTag);
        limit.setAttribute('aria-hidden', 'true');
        layer.appendChild(limit);

        const all = [...ghosts, limit];
        return {
            place: ({ left, right, top, g, offX, offY, PAD }) => {
                const pitch = info.pitch;
                const rowHeight = info.rowHeight;
                ghosts.forEach((ghost, index) => {
                    const k = index + 1;
                    ghost.style.display = '';
                    ghost.style.left = `${offX + (left - PAD) * g.scale}px`;
                    ghost.style.width = `${(right - left + 2 * PAD) * g.scale}px`;
                    ghost.style.top = `${offY + (top + k * pitch) * g.scale}px`;
                    ghost.style.height = `${rowHeight * g.scale}px`;
                });
                limit.style.display = '';
                limit.style.left = `${offX + (left - PAD - 12) * g.scale}px`;
                limit.style.width = `${(right - left + 2 * PAD + 24) * g.scale}px`;
                limit.style.top = `${offY + (top + info.limitOffset) * g.scale}px`;
            },
            hide: () => all.forEach((el) => { el.style.display = 'none'; }),
            remove: () => all.forEach((el) => el.remove()),
        };
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
        icon.className = 'i i-view-agenda s14';
        const text = document.createElement('span');
        text.className = 'text-truncate';
        text.textContent = label;
        row.append(icon, text);
        if (container.repeat) {
            // "↻ ×8" — this container is a repeatable row.
            const badge = document.createElement('span');
            badge.className = 'container-panel-row__badge';
            badge.title = `Opakovatelný řádek: ${container.repeat.min}–${container.repeat.max}, výchozí ${container.repeat.default}`;
            badge.textContent = `↻ ×${container.repeat.max}`;
            row.appendChild(badge);
        }

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
            if (members.length < this._minItems(container)) {
                if (entry.css !== 'hidden') {
                    zone.style.display = 'none';
                    if (entry.rowChrome) entry.rowChrome.hide();
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
            // A hidable container says so (and shows its fill-page label).
            const hideBadge = container.hidable === true
                ? ` · lze skrýt${typeof container.name === 'string' && container.name !== '' ? ` („${container.name}“)` : ''}`
                : '';
            const rowInfo = container.repeat ? this._rowInfo.get(container.id) : null;
            const rowShort = rowInfo ? rowInfo.groupFits < container.repeat.max : false;
            const text = container.repeat
                ? `Řádek ×${container.repeat.max}${container.repeat.default > 1 ? ` · výchozí ${container.repeat.default}` : ''}${rowShort ? ` · vejde se jen ${rowInfo.groupFits}` : ''}${hideBadge}`
                : nested
                ? `Vnořený kontejner${gapBadge}${hideBadge}`
                : (overflowing
                    ? `Kontejner · obsah přesahuje o ${Math.ceil(overflowPx)} px`
                    : `Kontejner · max ${Math.round(container.maxHeight)} px${gapBadge}${hideBadge}`);

            // Skip the DOM writes when nothing changed — this runs on EVERY
            // Fabric render frame via after:render.
            const css = `${left.toFixed(1)}|${top.toFixed(1)}|${right.toFixed(1)}|${height.toFixed(1)}|${overflowing}|${text}|${g.scale.toFixed(4)}|${offX.toFixed(1)}|${offY.toFixed(1)}`;
            if (entry.css === css) {
                return;
            }
            entry.css = css;

            if (entry.rowChrome) {
                entry.rowChrome.place({ left, right, top, g, offX, offY, PAD });
            }
            zone.classList.toggle('container-zone--row', Boolean(container.repeat));
            zone.classList.toggle('container-zone--row-short', rowShort);
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
        this._zones.forEach(({ zone, rowChrome }) => {
            zone.remove();
            if (rowChrome) rowChrome.remove();
        });
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
        if (members.length < this._minItems(container)) return;

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
            if (def.hidable === true) copy.hidable = true;
            if (typeof def.name === 'string' && def.name !== '') copy.name = def.name;
            // "Uživatel může posunout" — immutable value, sharing it is safe.
            if (def.userMove) copy.userMove = def.userMove;
            // Repeatable rows: a row INSIDE the duplicated tree stays a row
            // (its copy lives in the copied parent — one row per parent
            // holds). The duplicated container ITSELF never keeps `repeat`:
            // its copy joins the same parent, which already has its row.
            // A merged row's legacy `slots` stay with the ORIGINAL: the copy's
            // members have new ids, and two rows claiming the same legacy ids
            // would fight over the same stored values.
            if (def.repeat && def.id !== container.id) {
                copy.repeat = { max: def.repeat.max, min: def.repeat.min, default: def.repeat.default };
            }
            containers.push(copy);
        });

        // A nested original duplicates as a SIBLING inside the same parent.
        const parent = this._parentOf(container);
        if (parent) {
            const copyId = containerIdMap.get(container.id);
            const copyDef = containers.find((c) => c.id === copyId);
            if (container.repeat && copyDef
                && copyDef.memberInputIds.length + copyDef.memberContainerIds.length < 2) {
                // A single-item ROW (one scorer): without `repeat` its copy
                // would be a degenerate 1-item container — the copied item
                // joins the parent as a plain flow item instead.
                parent.memberInputIds = [...(parent.memberInputIds || []), ...copyDef.memberInputIds];
                parent.memberContainerIds = [...(parent.memberContainerIds || []), ...copyDef.memberContainerIds];
                containers.splice(containers.indexOf(copyDef), 1);
            } else {
                parent.memberContainerIds = [
                    ...(parent.memberContainerIds || []),
                    copyId,
                ];
            }
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
        // Scene coordinates start at the TRIM corner (print bleed shifts it
        // inside the element) — see canvas_viewport.js.
        return { contRect: trimOriginRect(contRect, canvas, scale), layerRect, scale };
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
        // canvas-user-move draws the range band of the container whose
        // popover is open.
        el.dataset.containerId = container.id;
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
        gapInput.className = 'form-control form-control-sm mb-2';
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
        afterInput.className = 'form-control form-control-sm mb-2';
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

        // Whole-container hide ("Lze skrýt"): the fill pages + API then offer
        // ONE toggle that hides the container with everything inside it
        // (texts, images, shapes, nested containers). The label is what the
        // fill page's toggle row says.
        const hideRow = document.createElement('div');
        hideRow.className = 'form-check mb-2';
        const hideInput = document.createElement('input');
        hideInput.type = 'checkbox';
        hideInput.className = 'form-check-input';
        hideInput.id = `container-hidable-${container.id}`;
        hideInput.checked = container.hidable === true;
        const hideLabel = document.createElement('label');
        hideLabel.className = 'form-check-label small';
        hideLabel.htmlFor = hideInput.id;
        hideLabel.textContent = 'Schovatelnost';
        hideRow.appendChild(hideInput);
        hideRow.appendChild(hideLabel);
        el.appendChild(hideRow);

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.maxLength = 80;
        nameInput.placeholder = 'Popisek přepínače, např. „Bublina s citací“';
        nameInput.className = 'form-control form-control-sm mb-2';
        nameInput.setAttribute('aria-label', 'Popisek přepínače pro skrytí kontejneru');
        nameInput.value = typeof container.name === 'string' ? container.name : '';
        nameInput.hidden = !hideInput.checked;
        el.appendChild(nameInput);

        hideInput.addEventListener('change', () => {
            if (hideInput.checked) {
                container.hidable = true;
            } else {
                delete container.hidable;
            }
            nameInput.hidden = !hideInput.checked;
            this.canvasEditorOutlet.markUnsaved();
            const canvas = this._canvas();
            if (canvas) canvas.fire('object:modified', {});
        });
        nameInput.addEventListener('input', () => {
            const value = nameInput.value.trim();
            if (value === '') {
                delete container.name;
            } else {
                container.name = value;
            }
            this.canvasEditorOutlet.markUnsaved();
        });
        nameInput.addEventListener('change', () => {
            const canvas = this._canvas();
            if (canvas) canvas.fire('object:modified', {});
        });

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

        el.appendChild(this._buildRepeatSection(container));

        if (!nested) {
            const maxLabel = document.createElement('label');
            maxLabel.className = 'form-label small mb-1';
            maxLabel.textContent = 'Maximální výška kontejneru (px)';
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
        } else {
            const nestedHint = document.createElement('div');
            nestedHint.className = 'form-text small mb-0';
            nestedHint.textContent = 'Vnořený kontejner roste podle obsahu — výšku omezuje vnější kontejner.';
            el.appendChild(nestedHint);
        }

        // "Uživatel může posunout celý kontejner" — TOP-LEVEL only: a nested
        // container flows in its parent, so an allowance there is inert (kept
        // so un-nesting restores it, and said so).
        if (!nested) {
            el.appendChild(buildContainerMoveSection(container, {
                unit: this.hasCanvasEditorOutlet ? this.canvasEditorOutlet.unitValue : 'px',
                idPrefix: `container-umove-${container.id}`,
                onChange: () => {
                    this.canvasEditorOutlet.markUnsaved();
                    const canvas = this._canvas();
                    if (canvas) {
                        canvas.fire('object:modified', {});
                        canvas.requestRenderAll();
                    }
                },
            }));
        } else if (container.userMove) {
            const moveHint = document.createElement('div');
            moveHint.className = 'form-text small mb-2';
            moveHint.textContent = 'Posun pro uživatele je nastavený, ale u vnořeného kontejneru se neuplatní — posouvat lze jen celý vnější kontejner.';
            el.appendChild(moveHint);
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
        // The range band (canvas-user-move) follows the open popover.
        const canvas = this._canvas();
        if (canvas) canvas.requestRenderAll();
    }

    /**
     * "Opakovatelný řádek" in the ⚙ popover (container-rows P3): the box +
     * Max. řádků (prefilled with how many fit — the smallest size in a
     * group — capped at 30), Výchozí počet and Min. počet. Disabled with the
     * reason on a container that may not be a row (top-level, a second row
     * in its parent, inside another row, holding a row). Every edit writes
     * `repeat` only when the numbers are valid (1 ≤ min ≤ výchozí ≤ max ≤ 30)
     * and commits ONE undo step on `change`.
     */
    _buildRepeatSection(container) {
        const section = document.createElement('div');
        section.className = 'container-repeat mb-2';

        const reason = container.repeat ? null : repeatBlockReason(this._containers(), container);

        const row = document.createElement('div');
        row.className = 'form-check mb-1';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'form-check-input';
        box.id = `container-repeat-${container.id}`;
        box.checked = Boolean(container.repeat);
        box.disabled = reason !== null;
        const boxLabel = document.createElement('label');
        boxLabel.className = 'form-check-label small';
        boxLabel.htmlFor = box.id;
        boxLabel.textContent = 'Opakovatelný řádek';
        row.append(box, boxLabel);
        section.appendChild(row);

        const hint = document.createElement('div');
        hint.className = 'form-text small mb-1';
        section.appendChild(hint);

        const fields = document.createElement('div');
        fields.className = 'container-repeat__fields';
        const numberField = (labelText, key, title) => {
            const wrap = document.createElement('label');
            wrap.className = 'container-repeat__field small';
            wrap.title = title;
            const caption = document.createElement('span');
            caption.textContent = labelText;
            const input = document.createElement('input');
            input.type = 'number';
            input.min = '1';
            input.max = String(MAX_ROWS);
            input.step = '1';
            input.className = 'form-control form-control-sm';
            input.dataset.repeatKey = key;
            wrap.append(caption, input);
            fields.appendChild(wrap);
            return input;
        };
        const maxInput = numberField('Max. řádků', 'max', 'Nejvíc řádků, které uživatel může mít (nejvýš 30)');
        const defaultInput = numberField('Výchozí počet', 'default', 'Kolik řádků se ukáže při otevření vyplňování');
        const minInput = numberField('Min. počet', 'min', 'Méně řádků uživatel mít nemůže');
        section.appendChild(fields);

        const error = document.createElement('div');
        error.className = 'form-text small text-danger mb-1';
        error.hidden = true;
        section.appendChild(error);

        const fit = document.createElement('div');
        fit.className = 'container-repeat__fit form-text small mb-1';
        section.appendChild(fit);

        const sync = () => {
            const on = Boolean(container.repeat);
            fields.hidden = !on;
            fit.hidden = !on;
            if (reason) {
                hint.textContent = reason;
                hint.classList.add('container-repeat__blocked');
                return;
            }
            hint.classList.remove('container-repeat__blocked');
            hint.textContent = on
                ? 'Při vyplňování půjde řádek přidat (+) a odebrat (−). Nový řádek převezme ukázkový text tohoto řádku.'
                : 'Uživatel si při vyplňování přidá tolik řádků, kolik potřebuje (např. výsledky, střelci).';
            // A row merged from hand-numbered copies (P6): its slots keep
            // the old rows' ids — say so, and warn when max cuts them off.
            const merged = on && Array.isArray(container.repeat.slots) ? container.repeat.slots.length + 1 : 0;
            if (merged > 1) {
                hint.textContent += ` Řádek vznikl sloučením ${merged} řádků — jejich uložené hodnoty se načtou do řádků 1–${merged}.`;
                if (container.repeat.max < merged) {
                    hint.textContent += ` ⚠ Max. řádků je menší než ${merged}: starší exporty přijdou o řádky ${container.repeat.max + 1}–${merged}.`;
                }
            }
            if (!on) return;
            if (document.activeElement !== maxInput) maxInput.value = String(container.repeat.max);
            if (document.activeElement !== defaultInput) defaultInput.value = String(container.repeat.default);
            if (document.activeElement !== minInput) minInput.value = String(container.repeat.min);
            this._renderFitInfo(fit, container);
        };

        const fitsNow = () => {
            this._refreshRowInfo();
            const info = this._rowInfo.get(container.id);
            return info ? info.groupFits : 1;
        };

        box.addEventListener('change', () => {
            if (box.checked) {
                // Prefill: rows that fit by the design (the smallest size of
                // a group), at least 1, at most 30.
                container.repeat = { max: 1, min: 1, default: 1 };
                const fits = fitsNow();
                container.repeat = { max: Math.max(1, Math.min(MAX_ROWS, fits)), min: 1, default: 1 };
            } else {
                const merged = Array.isArray(container.repeat && container.repeat.slots) ? container.repeat.slots.length + 1 : 0;
                if (merged > 1 && !window.confirm(`Řádek vznikl sloučením ${merged} řádků. Když opakování zrušíte, uložené exporty už řádky 2–${merged} nenačtou (lze vrátit tlačítkem Zpět). Pokračovat?`)) {
                    box.checked = true;
                    return;
                }
                delete container.repeat;
                const layout = this._layout();
                const items = this._memberObjects(container).length
                    + (layout ? layout.childContainersOf(this._containers(), container).length : 0);
                if (items < 2) {
                    // A single-item row is a container only BECAUSE it
                    // repeats — without `repeat` its item goes back into the
                    // parent's flow (undoable as one step).
                    this._dissolve(container);
                    return;
                }
            }
            error.hidden = true;
            this.renderZones();
            this.canvasEditorOutlet.markUnsaved();
            const canvas = this._canvas();
            if (canvas) canvas.fire('object:modified', {});
            sync();
        });

        const onNumbers = () => {
            const read = (input) => (input.value.trim() === '' ? NaN : Number(input.value));
            // `slots` (a merged row's legacy ids) ride along untouched.
            const next = normalizeRepeat({ max: read(maxInput), default: read(defaultInput), min: read(minInput), slots: container.repeat ? container.repeat.slots : undefined });
            if (!next) {
                error.textContent = `Musí platit 1 ≤ min. počet ≤ výchozí počet ≤ max. řádků ≤ ${MAX_ROWS} (celá čísla).`;
                error.hidden = false;
                return false;
            }
            error.hidden = true;
            container.repeat = next;
            this.renderZones();
            this.canvasEditorOutlet.markUnsaved();
            sync();
            return true;
        };
        [maxInput, defaultInput, minInput].forEach((input) => {
            input.addEventListener('input', onNumbers);
            // Commit = one undo snapshot per edited field.
            input.addEventListener('change', () => {
                if (!onNumbers()) return;
                const canvas = this._canvas();
                if (canvas) canvas.fire('object:modified', {});
            });
        });

        sync();
        return section;
    }

    /** "Vejde se N řádků" (+ per size in a group) and the overflow warning. */
    _renderFitInfo(el, container) {
        el.textContent = '';
        const info = this._rowInfo.get(container.id);
        if (!info || !container.repeat) return;
        const rows = (n) => (n === 1 ? 'řádek' : (n >= 2 && n <= 4 ? 'řádky' : 'řádků'));

        const line = document.createElement('div');
        if (info.perSize.length > 1) {
            line.textContent = `Podle návrhu se vejde: ${info.perSize.map((p) => `${p.label} ${p.fits}`).join(' · ')} — počet řádků je společný, rozhoduje nejmenší (${info.groupFits}).`;
        } else {
            line.textContent = `Podle návrhu se vejde ${info.fits} ${rows(info.fits)}${info.fits >= MAX_ROWS ? ' (víc než limit)' : ''}.`;
        }
        el.appendChild(line);

        if (container.repeat.max > info.groupFits) {
            const warn = document.createElement('div');
            warn.className = 'container-repeat__warn';
            const short = info.perSize.filter((p) => p.fits < container.repeat.max).map((p) => p.label);
            warn.textContent = `⚠ ${container.repeat.max} ${rows(container.repeat.max)} se do výšky ${container.repeat.max >= 2 && container.repeat.max <= 4 ? 'nevejdou' : 'nevejde'}${short.length && info.perSize.length > 1 ? ` (${short.join(', ')})` : ''} — uživatel přidá nejvýš ${Math.max(info.groupFits, container.repeat.min)}. Snižte max. řádků, nebo zvětšete max. výšku nadřazeného kontejneru.`;
            el.appendChild(warn);
        }
    }

    _closeSettings() {
        const wasOpen = Boolean(this._settingsEl);
        if (this._settingsEl) {
            this._settingsEl.remove();
        }
        this._settingsEl = null;
        this._settingsContainerId = null;
        if (wasOpen) {
            const canvas = this._canvas();
            if (canvas) canvas.requestRenderAll();
        }
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
        // "Opakovat jako řádek": texts only, and only where a row may live.
        const canRow = Boolean(container) && layout.isTextboxObject(active) && this._rowBlockReason(active) === null;
        this.rowButtonTargets.forEach((el) => el.classList.toggle('d-none', !canRow));
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
