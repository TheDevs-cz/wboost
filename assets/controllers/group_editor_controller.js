import { Controller } from "@hotwired/stimulus";
import { FabricImage, StaticCanvas } from "fabric";

import { PREVIEW_MAX_WIDTH, buildVariantPayload, coverForDimensions, restoreCustomProperties } from './canvas_payload.js';
import { GroupSync } from './group_sync.js';

const SYNC_DEBOUNCE = 150;
const HISTORY_DEBOUNCE = 400;
const HISTORY_MAX = 15;
// Offscreen per-variant canvases are thumbnail-sized: a print variant at full
// size would cost hundreds of MB across a group. The stored preview is
// re-rendered from them at a multiplier (see submitForm) rather than blitted,
// so it is not limited by this.
const SHADOW_WIDTH = 400;

/**
 * Orchestrator of the template-group editor page. Composes with the regular
 * `canvas-editor` controller (mounted on the same element) instead of
 * replacing it — the sibling canvas controllers keep working against the ONE
 * interactive Fabric canvas, whose identity never changes; tabs are switched
 * by resizing + reloading that canvas.
 *
 * Every member variant additionally owns an offscreen thumbnail-scale
 * StaticCanvas "shadow" (objects in full logical variant coordinates,
 * displayed via setZoom): the shadows are the propagation targets and the
 * source of each variant's save payload + preview PNG.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];

    static targets = [
        "variantsData", "rail", "multiEditButton", "card", "includeToggle",
        "dirtyDot", "badge", "undoButton", "redoButton",
    ];

    static values = {
        saveUrl: String,
        csrf: String,
    };

    // Outlet callbacks can fire before connect() — initialize state here.
    initialize() {
        this.variants = [];
        // "Úprava více variant" — ON by default (2026-08-10, was OFF): the
        // group's point is variants that track each other, so edits fan out
        // to every included variant until the designer opts out. Structural
        // changes (add / delete / explicit re-sync) ignore this flag
        // entirely, see GroupSync's two target sets; a background pick
        // FOLLOWS it (per-variant backgrounds must stay authorable). Keep the initial
        // state in lockstep with the server-rendered rail chrome
        // (template_group_editor.html.twig: --multi class, btn-primary,
        // aria-pressed) — _refreshRail only syncs it after hydration.
        this.multiEdit = true;
        this.activeId = null;
        this.history = [];
        this.redoStack = [];
        this._switching = false;
        this._restoring = false;
        this._booted = false;
        this._shadowsHydrated = false;
        // Structural ops (add / remove / background / re-sync / reconcile)
        // run serialized on this chain, and never before every shadow exists
        // — see _enqueueStructural.
        this._opChain = Promise.resolve();
        this._shadowsReady = new Promise((resolve) => {
            this._resolveShadowsReady = resolve;
        });
    }

    canvasEditorOutletConnected(outlet) {
        // The interactive canvas exists from the orchestrator's connect().
        // Boot once — parse variants, hydrate shadows, hook events.
        if (!this._booted) {
            this._booted = true;
            this._boot(outlet);
        }
    }

    async _boot(editor) {
        this.variants = JSON.parse(this.variantsDataTarget.textContent).map((v) => ({
            ...v,
            shadow: null,
            included: true,
            dirty: false,
        }));

        if (this.variants.length === 0) {
            this._shadowsHydrated = true;
            this._resolveShadowsReady();
            return;
        }

        this.activeId = this.variants[0].id;

        this.sync = new GroupSync({
            activeCanvas: () => editor.canvas,
            activeDims: () => this._activeDims(),
            targets: () => (this.multiEdit
                ? this.variants.filter((v) => v.id !== this.activeId && v.included && v.shadow)
                : []),
            allTargets: () => this.variants.filter((v) => v.id !== this.activeId && v.shadow),
        });

        // Fabric event hooks on the ONE interactive canvas. Guard everything
        // on "not loading / not switching / not restoring" — loadFromJSON
        // fires add/remove events for every object it (re)creates.
        const canvas = editor.canvas;

        canvas.on('object:modified', () => this._afterMutation({ immediate: true }));
        canvas.on('text:changed', () => this._afterMutation());
        canvas.on('text:editing:exited', () => this._afterMutation({ immediate: true }));

        canvas.on('object:added', (e) => {
            // Background layers are strictly per-dimension: adding/replacing
            // the active variant's background must never fan a clone into the
            // sibling shadows — even when the add happens outside the _quiet
            // window (e.g. the layer-mode "Pozadí" pick).
            if (this._quiet(editor) || !e.target || !e.target.inputId || e.target.isBackground === true) {
                return;
            }
            // Capture the source object AND its variant's dimensions now —
            // the op may run later (shadow hydration, a queued predecessor)
            // and the projection ratios must be the ones of the variant the
            // object was actually added on.
            const obj = e.target;
            const dims = this._activeDims();
            this._enqueueStructural(() => this.sync.projectNewObject(obj, dims));
        });

        canvas.on('object:removed', (e) => {
            if (this._quiet(editor) || !e.target || !e.target.inputId || e.target.isBackground === true) {
                return;
            }
            const inputId = e.target.inputId;
            this._enqueueStructural(() => this.sync.removeObject(inputId));
        });

        // Hydrate shadows once fonts are resident (same gate the interactive
        // canvas load awaits — measurement parity).
        try {
            await editor.fontsReady;
        } catch (err) {
            // best effort — a broken face must not block the editor
        }

        for (const variant of this.variants) {
            // Per-variant tolerance: one variant with a broken canvas/background
            // must not abort hydration of the rest — and must never leave
            // _shadowsReady pending, which would block every structural op
            // (adds/deletes would then reach NO variant, ever).
            try {
                await this._createShadow(variant);
            } catch (err) {
                // Null the half-assigned shadow: a partially hydrated one
                // would be included in propagation targets AND serialized
                // over the variant's real saved canvas on save. A null
                // shadow makes the variant fully inert instead (skipped by
                // targets, save and tab switching).
                variant.shadow = null;
                console.error(`Hydratace varianty ${variant.id} selhala:`, err);
            }
        }

        this._shadowsHydrated = true;

        // An edit pass deferred during hydration fans out NOW that every
        // shadow exists (syncPass rebaselines itself); otherwise just align
        // the baseline. Order matters: a plain rebaseline first would consume
        // the pending diff and the deferred pass would fan out nothing.
        if (this._syncTimer) {
            this._flushPendingSync();
        } else {
            this.sync.rebaseline();
        }

        this._refreshRail();
        this._seedHistory();
        this._resolveShadowsReady();

        // Heal divergence already persisted in the DB (adds used to respect
        // the include switches; a failed clone or an add during hydration
        // could also strand an object on a single variant): project every
        // active-canvas object missing from a sibling. Runs again on every
        // tab switch and before save. System pass — no undo point of its own.
        this._enqueueStructural(() => this.sync.reconcileStructure(), { pushHistory: false });

        // Late safety net: re-measure shadow text after every face settles.
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(() => {
                this.variants.forEach((variant) => {
                    if (!variant.shadow) return;
                    variant.shadow.getObjects().forEach((obj) => {
                        if (typeof obj.initDimensions === 'function') {
                            obj.initDimensions();
                            obj.setCoords();
                        }
                    });
                    variant.shadow.renderAll();
                });
            });
        }
    }

    _quiet(editor) {
        return editor.loadingCanvas || this._switching || this._restoring;
    }

    _activeDims() {
        const active = this._variant(this.activeId);
        return { width: active.width, height: active.height };
    }

    // ------------------------------------------------------------------ structural ops

    /**
     * Structural changes (add / remove / background / re-sync / reconcile)
     * run serialized through ONE promise chain that first waits for every
     * shadow to hydrate. Rationale:
     *
     * - Hydration window: the Fabric hooks are live the moment the editor
     *   is, but the sibling shadows hydrate over the network — an add fanned
     *   out immediately used to reach only the subset that already existed
     *   (or none), stranding the object on a single variant forever.
     * - Ordering: "add A, delete A" must apply in that order everywhere.
     * - Failure isolation: one failing op logs and never wedges the chain,
     *   and the rebaseline + history push run regardless.
     *
     * @param {Function} op  () => Set<variantId> | Promise<Set<variantId>>
     * @param {boolean} pushHistory  false for system passes (reconcile) that
     *        must not create undo points of their own
     * @returns {Promise} settles when THIS op (and all before it) finished
     */
    _enqueueStructural(op, { pushHistory = true } = {}) {
        const run = async () => {
            await this._shadowsReady;
            if (!this.sync) {
                return;
            }
            try {
                const touched = await op();
                if (touched && touched.size > 0) {
                    this._afterPropagation(touched);
                }
            } catch (err) {
                console.error('Propagace do variant skupiny selhala:', err);
            } finally {
                // A pending debounced edit pass must settle BEFORE the
                // rebaseline below, or its diff would be swallowed by the
                // fresh baseline and the edit would never propagate.
                this._flushPendingSync();
                this.sync.rebaseline();
                if (pushHistory) {
                    this._scheduleHistoryPush();
                }
            }
        };

        this._opChain = this._opChain.then(run, run);
        return this._opChain;
    }

    /**
     * Await the structural chain INCLUDING ops enqueued while awaiting —
     * tab switches, undo/redo and save must not proceed while a projection
     * is still in flight (a clone landing in a shadow after that shadow was
     * serialized would be silently clobbered on the next switch-away).
     */
    async _drainStructuralOps() {
        let tail;
        do {
            tail = this._opChain;
            await tail;
        } while (tail !== this._opChain);
    }

    // ------------------------------------------------------------------ DOM events

    /** canvas-editor:dirty — property-panel/toolbar/container mutations. */
    onDirty() {
        if (!this.sync || this._quiet(this.canvasEditorOutlet)) {
            return;
        }
        const active = this._variant(this.activeId);
        if (active) {
            active.dirty = true;
            this._refreshDirtyDots();
        }
        this._afterMutation();
    }

    /** canvas-editor:canvas:loaded — initial load + every tab switch/restore. */
    onCanvasLoaded() {
        if (this.sync) {
            this.sync.rebaseline();
        }
    }

    /** canvas-editor:background:changed — active variant picked a new background. */
    async onBackgroundChanged(event) {
        const active = this._variant(this.activeId);
        if (!active || !event.detail || !event.detail.url) {
            return;
        }

        // Layer mode: the background is an object inside the canvas document
        // (already added by setBackgroundLayer, already marked dirty). Keeping
        // backgroundUrl null here is what keeps every canvas-level re-apply
        // site (_loadShadow / _activate / _restoreSnapshot) a no-op.
        if (!event.detail.layerMode) {
            active.backgroundUrl = event.detail.url;
        }

        active.dirty = true;
        this._refreshDirtyDots();

        if (!this.sync || this._quiet(this.canvasEditorOutlet)) {
            return;
        }

        // A background pick follows the "Úprava více variant" mode + the
        // per-variant switches: mode on = the picture travels to the opted-in
        // dimensions, cover-fitted for each one's own size; mode off = it
        // stays a single-variant change, which is how a designer authors
        // per-variant backgrounds. BOTH background styles fan out — layer
        // mode through GroupSync.projectBackgroundLayer, canvas mode through
        // _projectCanvasBackground (legacy groups predate the layer rework
        // and never propagated at all, the "background ignores the mode"
        // report's second half). Queued so a pick made while the shadows are
        // still hydrating reaches every INCLUDED shadow, not the subset that
        // happened to exist.
        if (event.detail.layerMode) {
            // The source layer is resolved INSIDE the op, at run time — the
            // editor's setBackgroundLayer is async, and a dispatch-time find
            // used to grab the layer being REPLACED (or nothing at all when
            // the variant had no background yet), so the freshly picked
            // picture never reached the sibling variants. By op run time the
            // new layer is on the canvas (onAssetSelected awaits the swap
            // before dispatching; this lazy find is the belt to that).
            await this._enqueueStructural(() => {
                const source = this.canvasEditorOutlet.canvas.getObjects().find((o) => o.isBackground === true);
                return this.sync.projectBackgroundLayer(source);
            });
        } else if (event.detail.path) {
            const { url, path } = event.detail;
            await this._enqueueStructural(() => this._projectCanvasBackground(url, path));
        }
    }

    /**
     * Canvas-mode counterpart of GroupSync.projectBackgroundLayer: fan the
     * picked picture to the opted-in CANVAS-mode siblings — as each shadow's
     * canvas-level background (center-cover for its own size) plus the
     * per-variant edit-endpoint POST, because a canvas-mode background lives
     * in the background_image COLUMN (the render + every reload read it), not
     * in the saved canvas JSON. Layer-mode siblings of a mixed group are
     * skipped: they have no canvas-level slot, and an isBackground layer is
     * projectBackgroundLayer's job.
     *
     * @returns {Promise<Set<string>>} touched variant ids
     */
    async _projectCanvasBackground(url, path) {
        const touched = new Set();

        for (const target of this.sync.targets()) {
            if ((target.backgroundMode || 'canvas') === 'layer') {
                continue;
            }

            try {
                const img = await FabricImage.fromURL(url, { crossOrigin: 'anonymous' });
                coverForDimensions(img, target.width, target.height);
                target.shadow.backgroundImage = img;
                target.backgroundUrl = url;

                // The same side-channel the active variant's pick used — the
                // column is what the export renders from, so a fan-out that
                // only painted the shadow would revert on reload.
                const formData = new FormData();
                formData.append('backgroundImagePath', path);
                const response = await fetch(target.editVariantUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                touched.add(target.id);
            } catch (err) {
                console.error(`Propagace pozadí do varianty ${target.id} selhala:`, err);
            }
        }

        return touched;
    }

    // ------------------------------------------------------------------ propagation

    _afterMutation({ immediate = false } = {}) {
        if (!this.sync || this._quiet(this.canvasEditorOutlet)) {
            return;
        }

        const active = this._variant(this.activeId);
        if (active) {
            active.dirty = true;
        }

        clearTimeout(this._syncTimer);

        const run = () => {
            // Shadows still hydrating (multi-edit is on from the first
            // paint): keep deferring instead of fanning the diff out to the
            // subset of variants that happen to exist. The baseline keeps
            // the pre-edit state, so the accumulated diff reaches EVERY
            // variant once hydration completes (boot-end flush).
            if (!this._shadowsHydrated) {
                this._syncTimer = setTimeout(run, SYNC_DEBOUNCE);
                return;
            }
            this._syncTimer = null;
            const touched = this.sync.syncPass();
            this._afterPropagation(touched);
            this._scheduleHistoryPush();
            this._refreshDirtyDots();
        };

        if (immediate) {
            run();
        } else {
            this._syncTimer = setTimeout(run, SYNC_DEBOUNCE);
        }
    }

    _flushPendingSync() {
        if (this._syncTimer) {
            // While shadows hydrate the deferred pass must stay pending —
            // flushing now would fan the diff to a subset of variants.
            if (!this._shadowsHydrated) {
                return;
            }
            clearTimeout(this._syncTimer);
            this._syncTimer = null;
            if (this.sync && !this._quiet(this.canvasEditorOutlet)) {
                const touched = this.sync.syncPass();
                this._afterPropagation(touched);
            }
        }
    }

    _afterPropagation(touchedIds) {
        touchedIds.forEach((id) => {
            const variant = this._variant(id);
            if (!variant || !variant.shadow) {
                return;
            }
            variant.dirty = true;
            variant.overflowPx = GroupSync.reflowShadow(variant.shadow);
            variant.offCanvas = this._hasOffCanvasObjects(variant);
            variant.shadow.renderAll();
        });
        this._refreshDirtyDots();
        this._refreshBadges();
    }

    _hasOffCanvasObjects(variant) {
        const TOLERANCE = 2;
        return variant.shadow.getObjects().some((obj) => {
            // A cover-fitted background ALWAYS overflows one axis whenever
            // the picture's aspect differs from the canvas — that is the
            // fit working, not a stray element worth a warning badge.
            if (obj.isBackground === true) {
                return false;
            }
            if (typeof obj.getCoords !== 'function') {
                return false;
            }
            const corners = obj.getCoords();
            return corners.some((p) => (
                p.x < -TOLERANCE || p.y < -TOLERANCE
                || p.x > variant.width + TOLERANCE || p.y > variant.height + TOLERANCE
            ));
        });
    }

    // ------------------------------------------------------------------ shadows

    async _createShadow(variant) {
        const el = document.createElement('canvas');
        const scale = SHADOW_WIDTH / variant.width;
        el.width = Math.max(1, Math.round(variant.width * scale));
        el.height = Math.max(1, Math.round(variant.height * scale));

        const shadow = new StaticCanvas(el, { enableRetinaScaling: false });
        shadow.setZoom(scale);

        variant.shadow = shadow;
        await this._loadShadow(variant, variant.canvas);

        return shadow;
    }

    /** (Re)hydrate a variant's shadow from a canvas JSON string/object. */
    async _loadShadow(variant, canvasJson) {
        const shadow = variant.shadow;
        let source;

        if (typeof canvasJson === 'string') {
            try {
                source = canvasJson.trim() !== '' ? JSON.parse(canvasJson) : {};
            } catch (err) {
                console.error('Invalid variant canvas JSON', err);
                source = {};
            }
        } else {
            source = canvasJson || {};
        }

        await shadow.loadFromJSON(source);
        restoreCustomProperties(shadow, source);

        shadow.wboostContainers = Array.isArray(source.containers)
            ? source.containers.map((c) => ({
                ...c,
                memberInputIds: Array.isArray(c.memberInputIds) ? c.memberInputIds.slice() : [],
                memberContainerIds: Array.isArray(c.memberContainerIds) ? c.memberContainerIds.slice() : [],
            }))
            : [];

        // Ruler guides ride the document too — restore them onto the shadow so
        // buildVariantPayload round-trips them through group saves (guides are
        // per-variant; the propagation engine never touches them).
        shadow.wboostGuides = Array.isArray(source.guides)
            ? source.guides.map((g) => ({ ...g }))
            : [];

        // Always (re)apply the variant's own background — a fresh group
        // variant has an empty canvas document with no background in it.
        if (variant.backgroundUrl) {
            try {
                const img = await FabricImage.fromURL(variant.backgroundUrl, { crossOrigin: 'anonymous' });
                coverForDimensions(img, variant.width, variant.height);
                shadow.backgroundImage = img;
            } catch (err) {
                console.error('Shadow background failed to load:', err);
            }
        }

        variant.overflowPx = GroupSync.reflowShadow(shadow);
        variant.offCanvas = this._hasOffCanvasObjects(variant);
        shadow.renderAll();
    }

    // ------------------------------------------------------------------ tabs

    async activateVariant(event) {
        const variantId = event.params ? event.params.id : null;
        await this._activate(variantId);
    }

    async _activate(variantId, { skipSerialize = false } = {}) {
        const editor = this.canvasEditorOutlet;
        const incoming = this._variant(variantId);

        if (!incoming || this._switching || this._restoring || variantId === this.activeId) {
            return;
        }
        if (!incoming.shadow) {
            return; // still hydrating
        }

        // Settle everything in flight BEFORE the switch starts: the debounced
        // edit pass must run while the OLD variant is still active and NOT
        // under the _switching flag (whose _quiet gate would silently drop
        // it), and queued structural projections must land in the incoming
        // shadow before it is serialized onto the interactive canvas — a
        // clone arriving later would be clobbered by the next switch-away.
        this._flushPendingSync();
        await this._drainStructuralOps();

        // The drain yielded to the event loop — re-check the guards.
        if (this._switching || this._restoring || variantId === this.activeId || !incoming.shadow) {
            return;
        }

        this._switching = true;

        try {
            // Commit inline text editing + drop selection so floating chrome hides.
            const activeObject = editor.canvas.getActiveObject();
            if (activeObject && activeObject.isEditing && typeof activeObject.exitEditing === 'function') {
                activeObject.exitEditing();
            }
            editor.canvas.discardActiveObject();
            editor.dispatchSelectionChanged();

            const outgoing = this._variant(this.activeId);

            // Clicking an un-toggled chip switches to it — the variant being
            // edited is always edited. Mutated AFTER the sync flush above so
            // edits pending from before the switch never propagate into it.
            // ("Edit one variant in isolation" is the mode switch now, not a
            // tab-switch side effect.)
            incoming.included = true;

            if (!skipSerialize && outgoing && outgoing.shadow) {
                // Serialize the interactive canvas into the outgoing shadow so
                // nothing is lost; the shadow becomes authoritative again.
                const payload = buildVariantPayload(editor.canvas);
                outgoing.canvas = payload.canvas;
                await this._loadShadow(outgoing, payload.canvas);
            }

            this.activeId = variantId;

            editor.canvas.setDimensions({ width: incoming.width, height: incoming.height });
            editor.editVariantUrlValue = incoming.editVariantUrl;
            // Mode BEFORE load: loadCanvasWithoutHistory branches on it (layer
            // mode clears any leftover canvas-level background instead of
            // re-covering it). backgroundUrl is null for layer-mode variants,
            // which keeps the canvas-level re-apply below a natural no-op.
            editor.backgroundModeValue = incoming.backgroundMode || 'canvas';
            editor.backgroundImageValue = incoming.backgroundUrl || '';

            const incomingPayload = buildVariantPayload(incoming.shadow);
            await editor.loadCanvasWithoutHistory(incomingPayload.canvas);

            // The shadow JSON carries the background baked with the SHADOW's
            // cover transform (logical coords — identical), but a fresh empty
            // variant has none: apply the variant background explicitly, the
            // same override the single-variant editor does on connect.
            if (incoming.backgroundUrl) {
                await editor.setBackgroundImage(incoming.backgroundUrl);
            }

            this.sync.rebaseline();
            this._refreshRail();
        } finally {
            this._switching = false;
        }

        // Heal from the freshly active variant's point of view: whatever it
        // has that a sibling lacks (historic divergence included) fans out
        // now. Add-only + idempotent, so a consistent group is a no-op.
        this._enqueueStructural(() => this.sync.reconcileStructure(), { pushHistory: false });
    }

    // ------------------------------------------------------------------ multi-variant mode

    /**
     * "Úprava více variant" — the standing mode for EDITS (moves, resizes,
     * styles, metadata, z-order, containers). Off = they stay in the variant
     * the designer is looking at. Structural changes never consult it.
     *
     * Flipping rebaselines: whatever was changed while the mode was off must
     * not fan out retroactively on the next mutation. The debounced pass is
     * settled FIRST, under the old mode, for the same reason.
     */
    toggleMultiEdit() {
        this._flushPendingSync();

        this.multiEdit = !this.multiEdit;

        if (this.multiEdit) {
            const active = this._variant(this.activeId);
            if (active) {
                active.included = true;
            }
        }

        if (this.sync) {
            this.sync.rebaseline();
        }

        this._refreshRail();
    }

    toggleInclude(event) {
        const variantId = event.params ? event.params.id : null;
        const variant = this._variant(variantId);

        if (!variant) {
            return;
        }
        if (variantId === this.activeId) {
            // The active variant is always included — the checkbox is
            // disabled in the UI; guard anyway.
            event.target.checked = true;
            return;
        }

        variant.included = event.target.checked;
        this._refreshRail();
    }

    // ------------------------------------------------------------------ re-sync

    resyncActiveObject() {
        const editor = this.canvasEditorOutlet;
        const objects = editor.canvas.getActiveObjects();

        if (!this.sync || objects.length === 0) {
            return;
        }

        this._enqueueStructural(() => {
            const touched = new Set();
            objects.forEach((obj) => {
                if (!obj.inputId) {
                    return;
                }
                this.sync.resync(obj).forEach((id) => touched.add(id));
            });
            return touched;
        });
    }

    // ------------------------------------------------------------------ history (global)

    _seedHistory() {
        this.history = [this._snapshot()];
        this.redoStack = [];
        this._refreshHistoryButtons();
    }

    _scheduleHistoryPush() {
        clearTimeout(this._historyTimer);
        this._historyTimer = setTimeout(() => this._pushHistory(), HISTORY_DEBOUNCE);
    }

    _pushHistory() {
        if (this._restoring || this._switching) {
            return;
        }
        if (this.history.length >= HISTORY_MAX) {
            this.history.shift();
        }
        this.history.push(this._snapshot());
        this.redoStack = [];
        this._refreshHistoryButtons();
    }

    _snapshot() {
        const editor = this.canvasEditorOutlet;
        const states = {};

        this.variants.forEach((variant) => {
            if (variant.id === this.activeId) {
                states[variant.id] = buildVariantPayload(editor.canvas).canvas;
            } else if (variant.shadow) {
                states[variant.id] = buildVariantPayload(variant.shadow).canvas;
            }
        });

        return {
            activeVariantId: this.activeId,
            includedIds: this.variants.filter((v) => v.included).map((v) => v.id),
            states,
        };
    }

    async undo() {
        if (this.history.length <= 1) {
            return;
        }
        // An in-flight structural projection landing in a shadow AFTER the
        // snapshot restore re-loaded it would resurrect the very state being
        // undone — settle the chain first.
        await this._drainStructuralOps();
        if (this._restoring || this._switching || this.history.length <= 1) {
            return;
        }
        this.redoStack.push(this.history.pop());
        await this._restoreSnapshot(this.history[this.history.length - 1]);
        this._refreshHistoryButtons();
    }

    async redo() {
        if (this.redoStack.length === 0) {
            return;
        }
        await this._drainStructuralOps();
        if (this._restoring || this._switching || this.redoStack.length === 0) {
            return;
        }
        const snapshot = this.redoStack.pop();
        this.history.push(snapshot);
        await this._restoreSnapshot(snapshot);
        this._refreshHistoryButtons();
    }

    async _restoreSnapshot(snapshot) {
        const editor = this.canvasEditorOutlet;
        this._restoring = true;

        try {
            this.variants.forEach((variant) => {
                variant.included = snapshot.includedIds.includes(variant.id);
            });

            for (const variant of this.variants) {
                const state = snapshot.states[variant.id];
                if (!state || !variant.shadow) {
                    continue;
                }
                variant.canvas = state;
                await this._loadShadow(variant, state);
                variant.dirty = true;
            }

            this.activeId = snapshot.activeVariantId;
            const active = this._variant(this.activeId);

            if (active) {
                editor.canvas.setDimensions({ width: active.width, height: active.height });
                editor.editVariantUrlValue = active.editVariantUrl;
                // Mode BEFORE load (layer mode clears any leftover canvas-level
                // background); backgroundUrl is null for layer-mode variants,
                // so the re-apply below stays canvas-mode-only.
                editor.backgroundModeValue = active.backgroundMode || 'canvas';
                await editor.loadCanvasWithoutHistory(snapshot.states[active.id] || '{}');
                if (active.backgroundUrl) {
                    await editor.setBackgroundImage(active.backgroundUrl);
                }
            }

            this.sync.rebaseline();
            this._refreshRail();
            this._refreshDirtyDots();
        } finally {
            this._restoring = false;
        }
    }

    // ------------------------------------------------------------------ save

    async submitForm() {
        const editor = this.canvasEditorOutlet;

        this._flushPendingSync();

        // What gets persisted must be structurally consistent: settle every
        // queued projection, then run one reconcile pass so no variant is
        // saved missing an object its siblings have — the divergence that
        // made a placeholder fill (and its export) apply to one variant only.
        if (this.sync) {
            await this._enqueueStructural(() => this.sync.reconcileStructure(), { pushHistory: false });
        }

        const formData = new FormData();
        formData.append('_token', this.csrfValue);

        const savedIds = [];

        for (const variant of this.variants) {
            // Included variants always save; excluded ones save too when
            // they carry unsaved edits (e.g. after switching away from an
            // isolated edit) — the toggle controls propagation, not
            // persistence.
            if (!variant.included && !variant.dirty) {
                continue;
            }

            let payload;
            let preview = '';

            if (variant.id === this.activeId) {
                payload = editor.collectEditorPayload();
                try {
                    preview = editor.getScaledCanvasDataURI(400);
                } catch (err) {
                    console.warn('Preview generation skipped (tainted canvas):', err);
                }
            } else {
                if (!variant.shadow) {
                    continue;
                }
                payload = buildVariantPayload(variant.shadow);
                try {
                    // toDataURL RE-RENDERS at multiplier × zoom rather than
                    // upscaling the 400px bitmap, so the thumbnail is as sharp
                    // as the design allows — text re-rasterizes, pictures
                    // re-sample from their originals.
                    preview = variant.shadow.toDataURL({
                        format: 'png',
                        multiplier: Math.min(PREVIEW_MAX_WIDTH, variant.width) / SHADOW_WIDTH,
                    });
                } catch (err) {
                    console.warn('Preview generation skipped (tainted canvas):', err);
                }
            }

            formData.append(`variants[${variant.id}][canvas]`, payload.canvas);
            formData.append(`variants[${variant.id}][textInputs]`, payload.textInputs);
            formData.append(`variants[${variant.id}][imageInputs]`, payload.imageInputs);
            formData.append(`variants[${variant.id}][imagePreview]`, preview);
            savedIds.push(variant.id);
        }

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

            if (data.status === 'success') {
                editor.markSaved();
                this.variants.forEach((variant) => {
                    if (savedIds.includes(variant.id)) {
                        variant.dirty = false;
                    }
                });
                this._refreshDirtyDots();
                return true;
            }

            console.error('Ukládání se nepovedlo:', data.message);
            alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
            return false;
        } catch (error) {
            console.error('Error during save:', error);
            alert('Ukládání se nepovedlo. Prosím zkuste to znovu později.');
            return false;
        }
    }

    // ------------------------------------------------------------------ rail

    _refreshRail() {
        if (this.hasRailTarget) {
            this.railTarget.classList.toggle('group-variant-rail--multi', this.multiEdit);
        }

        if (this.hasMultiEditButtonTarget) {
            const button = this.multiEditButtonTarget;
            button.classList.toggle('btn-primary', this.multiEdit);
            button.classList.toggle('btn-outline-secondary', !this.multiEdit);
            button.setAttribute('aria-pressed', this.multiEdit ? 'true' : 'false');
        }

        this.cardTargets.forEach((card) => {
            const id = card.dataset.variantId;
            const variant = this._variant(id);
            if (!variant) {
                return;
            }
            card.classList.toggle('group-variant-active', id === this.activeId);
            card.classList.toggle('group-variant-excluded', !variant.included);
        });

        this.includeToggleTargets.forEach((toggle) => {
            const id = toggle.dataset.variantId;
            const variant = this._variant(id);
            if (!variant) {
                return;
            }
            toggle.checked = variant.included;
            toggle.disabled = id === this.activeId;
        });

        this._refreshDirtyDots();
        this._refreshBadges();
    }

    _refreshDirtyDots() {
        this.dirtyDotTargets.forEach((dot) => {
            const variant = this._variant(dot.dataset.variantId);
            dot.classList.toggle('d-none', !variant || !variant.dirty);
        });
    }

    _refreshBadges() {
        this.badgeTargets.forEach((badge) => {
            const variant = this._variant(badge.dataset.variantId);
            if (!variant) {
                return;
            }

            if (variant.overflowPx > 0) {
                badge.textContent = `Přesah ${Math.ceil(variant.overflowPx)} px`;
                badge.classList.remove('d-none');
            } else if (variant.offCanvas) {
                badge.textContent = 'Prvky mimo plátno';
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        });
    }

    _refreshHistoryButtons() {
        if (this.hasUndoButtonTarget) {
            this._toggleDisabled(this.undoButtonTarget, this.history.length <= 1);
        }
        if (this.hasRedoButtonTarget) {
            this._toggleDisabled(this.redoButtonTarget, this.redoStack.length === 0);
        }
    }

    _toggleDisabled(button, disabled) {
        button.classList.toggle('disabled', disabled);
        if (disabled) {
            button.setAttribute('disabled', 'disabled');
        } else {
            button.removeAttribute('disabled');
        }
    }

    _variant(id) {
        return this.variants.find((v) => v.id === id) || null;
    }
}
