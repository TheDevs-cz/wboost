import { Controller } from "@hotwired/stimulus";
import { FabricImage, StaticCanvas } from "fabric";

import { buildVariantPayload, coverForDimensions, restoreCustomProperties } from './canvas_payload.js';
import { GroupSync } from './group_sync.js';

const MINI_REFRESH_DELAY = 120; // ms; setTimeout on purpose — rAF never fires in hidden tabs
const SYNC_DEBOUNCE = 150;
const HISTORY_DEBOUNCE = 400;
const HISTORY_MAX = 15;

/**
 * Orchestrator of the template-group editor page. Composes with the regular
 * `canvas-editor` controller (mounted on the same element) instead of
 * replacing it — the sibling canvas controllers keep working against the ONE
 * interactive Fabric canvas, whose identity never changes; tabs are switched
 * by resizing + reloading that canvas.
 *
 * Every member variant additionally owns an offscreen thumbnail-scale
 * StaticCanvas "shadow" (objects in full logical variant coordinates,
 * displayed via setZoom): the shadows are the propagation targets, the live
 * miniatures, and the source of each variant's save payload + preview PNG.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];

    static targets = ["variantsData", "mini", "card", "includeToggle", "dirtyDot", "badge", "undoButton", "redoButton"];

    static values = {
        saveUrl: String,
        csrf: String,
    };

    // Outlet callbacks can fire before connect() — initialize state here.
    initialize() {
        this.variants = [];
        this.activeId = null;
        this.history = [];
        this.redoStack = [];
        this._switching = false;
        this._restoring = false;
        this._booted = false;
        this._miniTimers = {};
        this._pendingResyncVariant = null;
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
            return;
        }

        this.activeId = this.variants[0].id;

        this.sync = new GroupSync({
            activeCanvas: () => editor.canvas,
            activeDims: () => {
                const active = this._variant(this.activeId);
                return { width: active.width, height: active.height };
            },
            targets: () => this.variants.filter(
                (v) => v.id !== this.activeId && v.included && v.shadow,
            ),
        });

        // Fabric event hooks on the ONE interactive canvas. Guard everything
        // on "not loading / not switching / not restoring" — loadFromJSON
        // fires add/remove events for every object it (re)creates.
        const canvas = editor.canvas;

        canvas.on('object:modified', () => this._afterMutation({ immediate: true }));
        canvas.on('text:changed', () => this._afterMutation());
        canvas.on('text:editing:exited', () => this._afterMutation({ immediate: true }));

        canvas.on('object:added', (e) => {
            if (this._quiet(editor) || !e.target || !e.target.inputId) {
                return;
            }
            this.sync.projectNewObject(e.target).then((touched) => {
                this._afterPropagation(touched);
                this.sync.rebaseline();
                this._scheduleHistoryPush();
            });
        });

        canvas.on('object:removed', (e) => {
            if (this._quiet(editor) || !e.target || !e.target.inputId) {
                return;
            }
            const touched = this.sync.removeObject(e.target.inputId);
            this._afterPropagation(touched);
            this.sync.rebaseline();
            this._scheduleHistoryPush();
        });

        // Live miniature of the ACTIVE variant: blit from the interactive
        // canvas whenever Fabric repaints it.
        canvas.on('after:render', () => this._scheduleMiniRefresh(this.activeId));

        // Hydrate shadows once fonts are resident (same gate the interactive
        // canvas load awaits — measurement parity).
        try {
            await editor.fontsReady;
        } catch (err) {
            // best effort — a broken face must not block the editor
        }

        for (const variant of this.variants) {
            await this._createShadow(variant);
            this._scheduleMiniRefresh(variant.id);
        }

        this.sync.rebaseline();
        this._refreshRail();
        this._seedHistory();

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
                    this._scheduleMiniRefresh(variant.id);
                });
            });
        }
    }

    _quiet(editor) {
        return editor.loadingCanvas || this._switching || this._restoring;
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
        this._scheduleMiniRefresh(this.activeId);
    }

    /** canvas-editor:background:changed — active variant picked a new background. */
    onBackgroundChanged(event) {
        const active = this._variant(this.activeId);
        if (active && event.detail && event.detail.url) {
            active.backgroundUrl = event.detail.url;
            active.dirty = true;
            this._refreshDirtyDots();
        }
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
            this._scheduleMiniRefresh(id);
        });
        this._refreshDirtyDots();
        this._refreshBadges();
    }

    _hasOffCanvasObjects(variant) {
        const TOLERANCE = 2;
        return variant.shadow.getObjects().some((obj) => {
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
        const scale = 400 / variant.width;
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
            ? source.containers.map((c) => ({ ...c }))
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
        this._scheduleMiniRefresh(variant.id);
    }

    // ------------------------------------------------------------------ tabs

    async activateVariant(event) {
        const variantId = event.params ? event.params.id : null;
        await this._activate(variantId);
    }

    async _activate(variantId, { skipSerialize = false } = {}) {
        const editor = this.canvasEditorOutlet;
        const incoming = this._variant(variantId);

        if (!incoming || this._switching || variantId === this.activeId) {
            return;
        }
        if (!incoming.included) {
            return; // excluded variants are not editable — re-include first
        }
        if (!incoming.shadow) {
            return; // still hydrating
        }

        this._switching = true;

        try {
            this._flushPendingSync();

            // Commit inline text editing + drop selection so floating chrome hides.
            const activeObject = editor.canvas.getActiveObject();
            if (activeObject && activeObject.isEditing && typeof activeObject.exitEditing === 'function') {
                activeObject.exitEditing();
            }
            editor.canvas.discardActiveObject();
            editor.dispatchSelectionChanged();

            const outgoing = this._variant(this.activeId);

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
    }

    // ------------------------------------------------------------------ include / exclude

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

        let touched = new Set();
        objects.forEach((obj) => {
            if (!obj.inputId) {
                return;
            }
            this.sync.resync(obj).forEach((id) => touched.add(id));
        });

        this._afterPropagation(touched);
        this.sync.rebaseline();
        this._scheduleHistoryPush();
    }

    /**
     * Two-click confirm (no native confirm() — it freezes automation and is
     * jarring): first click arms the button for 4s, second click executes.
     */
    resyncVariant(event) {
        const variantId = event.params ? event.params.id : null;
        const button = event.currentTarget;

        if (!this.sync || !variantId || variantId === this.activeId) {
            return;
        }

        if (this._pendingResyncVariant !== variantId) {
            this._pendingResyncVariant = variantId;
            button.classList.add('btn-danger');
            button.title = 'Kliknutím potvrdíte: přepíše rozložení této varianty podle aktivní varianty.';
            clearTimeout(this._resyncArmTimer);
            this._resyncArmTimer = setTimeout(() => {
                this._pendingResyncVariant = null;
                button.classList.remove('btn-danger');
                button.title = 'Srovnat celou variantu podle aktivní';
            }, 4000);
            return;
        }

        clearTimeout(this._resyncArmTimer);
        this._pendingResyncVariant = null;
        button.classList.remove('btn-danger');

        const touched = this.sync.resync(null, variantId);
        this._afterPropagation(touched);
        this._scheduleHistoryPush();
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
        this.redoStack.push(this.history.pop());
        await this._restoreSnapshot(this.history[this.history.length - 1]);
        this._refreshHistoryButtons();
    }

    async redo() {
        if (this.redoStack.length === 0) {
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

        const formData = new FormData();
        formData.append('_token', this.csrfValue);

        for (const variant of this.variants) {
            if (!variant.included) {
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
                    preview = variant.shadow.toDataURL({ format: 'png' });
                } catch (err) {
                    console.warn('Preview generation skipped (tainted canvas):', err);
                }
            }

            formData.append(`variants[${variant.id}][canvas]`, payload.canvas);
            formData.append(`variants[${variant.id}][textInputs]`, payload.textInputs);
            formData.append(`variants[${variant.id}][imageInputs]`, payload.imageInputs);
            formData.append(`variants[${variant.id}][imagePreview]`, preview);
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
                    if (variant.included) {
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

    // ------------------------------------------------------------------ miniatures + rail

    _scheduleMiniRefresh(variantId) {
        clearTimeout(this._miniTimers[variantId]);
        this._miniTimers[variantId] = setTimeout(() => this._refreshMini(variantId), MINI_REFRESH_DELAY);
    }

    _refreshMini(variantId) {
        const variant = this._variant(variantId);
        const mini = this.miniTargets.find((el) => el.dataset.variantId === variantId);

        if (!variant || !mini) {
            return;
        }

        const source = variantId === this.activeId
            ? this.canvasEditorOutlet.canvas.getElement()
            : (variant.shadow ? variant.shadow.lowerCanvasEl : null);

        if (!source || !source.width || !source.height) {
            return;
        }

        const ctx = mini.getContext('2d');
        ctx.clearRect(0, 0, mini.width, mini.height);
        ctx.drawImage(source, 0, 0, mini.width, mini.height);
    }

    _refreshRail() {
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
