import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";

/**
 * Photoshop-style layers panel for the admin canvas editor (left panel).
 *
 * Lists every object on the Fabric canvas TOPMOST FIRST (the reverse of
 * Fabric's objects array, whose order IS the z-order). Each row:
 *   - hover → a DOM highlight box over the object in the unscaled stage
 *             layer (same coordinate math as the floating toolbar; never
 *             drawn on the canvas bitmap),
 *   - click → selects the object and opens its floating property popover,
 *   - drag  → restacks the object (SortableJS on the list; the new DOM
 *             order reversed = the new Fabric stack; same announce
 *             convention as canvas-alignment: fire object:modified so the
 *             form goes dirty and the history controller snapshots it).
 *
 * The list rebuilds off the orchestrator's events: `canvas-editor:dirty`
 * fires on every mutation (add / remove / modify / restack / typing) and
 * `canvas-editor:canvas:loaded` after initial load and undo/redo restores.
 * Object identities change across loads, so rows reference objects only by
 * their CURRENT stack index and every rebuild re-reads canvas.getObjects().
 */
export default class extends Controller {
    static outlets = ["canvas-editor", "canvas-floating-toolbar"];
    static targets = ["list", "stage"];

    // Outlet callbacks can fire before connect() — initialize state here.
    initialize() {
        this._hoverEl = null;
        this._rebuildTimer = null;
        this._sortable = null;
        this._dragging = false;
    }

    connect() {
        // Same fallback-drag convention as dragula_controller: the fixed-
        // position mirror clone (.gu-mirror) follows the cursor while the
        // in-flow row keeps only the ghost treatment. The instance lives on
        // the CONTAINER, so it survives every rebuild's replaceChildren().
        this._sortable = Sortable.create(this.listTarget, {
            draggable: '.canvas-layer-row',
            direction: 'vertical',
            animation: 150,
            forceFallback: true,
            ghostClass: 'gu-transit',
            dragClass: 'gu-mirror',
            onStart: () => {
                this._dragging = true;
                this._clearHover();
            },
            onEnd: () => {
                this._dragging = false;
                this._applySortedOrder();
            },
        });
    }

    disconnect() {
        if (this._sortable) {
            this._sortable.destroy();
            this._sortable = null;
        }
        this._clearHover();
        clearTimeout(this._rebuildTimer);
    }

    canvasEditorOutletConnected() {
        // Covers the empty-canvas case (a fresh variant never dispatches
        // canvas:loaded); a non-empty canvas rebuilds again after its load.
        this.rebuild();
    }

    onCanvasLoaded() {
        this.rebuild();
    }

    onCanvasDirty() {
        // Coalesce bursts (typing re-fires dirty per keystroke).
        clearTimeout(this._rebuildTimer);
        this._rebuildTimer = setTimeout(() => this.rebuild(), 120);
    }

    onSelectionChanged() {
        this._syncActive();
    }

    rebuild() {
        if (!this.hasListTarget || !this.hasCanvasEditorOutlet) return;
        // Never replace the rows mid-drag (a debounced dirty rebuild would
        // yank the dragged element) — _applySortedOrder rebuilds on drop.
        if (this._dragging) return;
        const canvas = this.canvasEditorOutlet.canvas;
        if (!canvas) return;

        this._clearHover();
        const objects = canvas.getObjects();
        this.listTarget.replaceChildren();

        if (!objects.length) {
            const empty = document.createElement('p');
            empty.className = 'text-muted small my-1';
            empty.textContent = 'Na plátně zatím nejsou žádné prvky.';
            this.listTarget.appendChild(empty);
            return;
        }

        // Topmost layer first (Photoshop convention).
        for (let index = objects.length - 1; index >= 0; index--) {
            this.listTarget.appendChild(this._buildRow(objects[index], index));
        }
        this._syncActive();
    }

    // --- row interactions ---------------------------------------------------

    select(event) {
        const obj = this._objectFromEvent(event);
        if (!obj) return;
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.setActiveObject(obj);
        canvas.requestRenderAll();
        // setActiveObject fires no Fabric selection events — rebroadcast FIRST
        // so the floating toolbar shows the mini-toolbar for the new selection
        // (its selection handler closes popovers), THEN open the popover.
        this.canvasEditorOutlet.dispatchSelectionChanged();
        if (this.hasCanvasFloatingToolbarOutlet) {
            this.canvasFloatingToolbarOutlet.openPopover();
        }
    }

    /**
     * A drag ended: the rows' NEW DOM order (topmost first) reversed is the
     * desired Fabric stack. Each row still carries its PRE-drag stack index,
     * so the objects can be resolved before any restacking mutates indexes.
     */
    _applySortedOrder() {
        if (!this.hasCanvasEditorOutlet) return;
        const canvas = this.canvasEditorOutlet.canvas;
        if (!canvas) return;

        const objects = canvas.getObjects();
        const desired = Array.from(this.listTarget.querySelectorAll('.canvas-layer-row'))
            .map((row) => objects[Number(row.dataset.index)])
            .filter(Boolean)
            .reverse();

        // A rebuild raced the drag (rows out of sync with the canvas) —
        // re-render from the source of truth instead of guessing.
        if (desired.length !== objects.length) {
            this.rebuild();
            return;
        }

        let changed = false;
        desired.forEach((obj, index) => {
            if (canvas.getObjects().indexOf(obj) !== index) {
                canvas.moveObjectTo(obj, index);
                changed = true;
            }
        });

        if (changed) {
            canvas.renderAll();
            // Restacking fires no Fabric events — announce it (dirty +
            // history), the same convention canvas-alignment uses.
            canvas.fire('object:modified', {});
        }

        // Re-render even when nothing changed: Sortable moved the DOM node,
        // and the rows' data-index attributes must match the fresh stack.
        this.rebuild();
    }

    // --- hover highlight ------------------------------------------------------

    hover(event) {
        this._clearHover();
        const obj = this._objectFromEvent(event);
        if (!obj || !this.hasStageTarget) return;
        const g = this._geometry();
        if (!g) return;

        const r = obj.getBoundingRect();
        const el = document.createElement('div');
        el.className = 'layer-hover-outline';
        el.style.left = `${g.contRect.left - g.layerRect.left + r.left * g.scale}px`;
        el.style.top = `${g.contRect.top - g.layerRect.top + r.top * g.scale}px`;
        el.style.width = `${r.width * g.scale}px`;
        el.style.height = `${r.height * g.scale}px`;
        this.stageTarget.appendChild(el);
        this._hoverEl = el;
    }

    unhover() {
        this._clearHover();
    }

    _clearHover() {
        if (this._hoverEl) {
            this._hoverEl.remove();
            this._hoverEl = null;
        }
    }

    // --- row construction -----------------------------------------------------

    _buildRow(obj, index) {
        const type = (obj.type || '').toLowerCase();
        const isText = type === 'textbox';
        const isPlaceholder = !isText && !!obj.imagePlaceholder;

        const row = document.createElement('div');
        row.className = 'canvas-layer-row';
        row.dataset.index = String(index);
        row.setAttribute('role', 'listitem');
        row.dataset.action = 'mouseenter->canvas-layers#hover mouseleave->canvas-layers#unhover';

        const main = document.createElement('button');
        main.type = 'button';
        main.className = 'canvas-layer-row__main';
        main.dataset.action = 'canvas-layers#select focus->canvas-layers#hover blur->canvas-layers#unhover';

        const label = this._labelFor(obj, isText, isPlaceholder);
        const typeLabel = isText ? 'Text' : (isPlaceholder ? 'Obrázkový placeholder' : 'Obrázek');
        main.title = `${label} — ${typeLabel} (kliknutím upravíte, tažením změníte pořadí)`;
        main.setAttribute('aria-label', `${typeLabel}: ${label}`);

        const icon = document.createElement('i');
        icon.className = `canvas-layer-row__icon mdi ${isText ? 'mdi-format-text' : (isPlaceholder ? 'mdi-image-edit-outline' : 'mdi-image-outline')}`;
        icon.setAttribute('aria-hidden', 'true');
        main.appendChild(icon);

        const text = document.createElement('span');
        text.className = 'canvas-layer-row__label';
        text.textContent = label;
        main.appendChild(text);

        if (isText && obj.locked) {
            const lock = document.createElement('i');
            lock.className = 'canvas-layer-row__flag mdi mdi-lock';
            lock.title = 'Uzamčený text';
            lock.setAttribute('aria-hidden', 'true');
            main.appendChild(lock);
        }

        row.appendChild(main);

        // Drag affordance only — the whole row is the Sortable drag target.
        const grip = document.createElement('i');
        grip.className = 'canvas-layer-row__grip mdi mdi-drag-vertical';
        grip.title = 'Tažením změníte pořadí';
        grip.setAttribute('aria-hidden', 'true');
        row.appendChild(grip);

        return row;
    }

    _labelFor(obj, isText, isPlaceholder) {
        const name = (obj.name || '').trim();
        if (name !== '') return name;
        if (isText) {
            const firstLine = (obj.text || '').split('\n')[0].trim();
            return firstLine !== '' ? firstLine : 'Text';
        }
        return isPlaceholder ? 'Obrázek (placeholder)' : 'Obrázek';
    }

    // --- selection + geometry helpers ------------------------------------------

    _syncActive() {
        if (!this.hasListTarget || !this.hasCanvasEditorOutlet) return;
        const canvas = this.canvasEditorOutlet.canvas;
        if (!canvas) return;
        const active = new Set(canvas.getActiveObjects());
        const objects = canvas.getObjects();
        this.listTarget.querySelectorAll('.canvas-layer-row').forEach((row) => {
            const obj = objects[Number(row.dataset.index)];
            row.classList.toggle('is-active', !!obj && active.has(obj));
        });
    }

    _objectFromEvent(event) {
        if (!this.hasCanvasEditorOutlet) return null;
        const row = event.target.closest('.canvas-layer-row');
        if (!row) return null;
        const objects = this.canvasEditorOutlet.canvas.getObjects();
        const index = Number(row.dataset.index);
        return Number.isInteger(index) && index >= 0 && index < objects.length ? objects[index] : null;
    }

    /** Same coordinate model as the floating toolbar: chrome lives in the
     *  UNSCALED stage layer, positions derive from the live (already zoom-
     *  scaled) canvas DOM rect against Fabric's LOGICAL width. */
    _geometry() {
        const canvas = this.canvasEditorOutlet.canvas;
        const canvasEl = canvas.getElement();
        if (!canvasEl) return null;
        const container = canvasEl.parentElement || canvasEl;
        const contRect = container.getBoundingClientRect();
        const layerRect = this.stageTarget.getBoundingClientRect();
        const logicalWidth = (typeof canvas.getWidth === 'function' ? canvas.getWidth() : canvas.width) || canvasEl.width;
        const scale = logicalWidth ? contRect.width / logicalWidth : 1;
        return { contRect, layerRect, scale };
    }
}
