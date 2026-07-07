import { Controller } from "@hotwired/stimulus";

/**
 * Photoshop-style layers panel for the admin canvas editor (left panel).
 *
 * Lists every object on the Fabric canvas TOPMOST FIRST (the reverse of
 * Fabric's objects array, whose order IS the z-order). Each row:
 *   - hover  → a DOM highlight box over the object in the unscaled stage
 *              layer (same coordinate math as the floating toolbar; never
 *              drawn on the canvas bitmap),
 *   - click  → selects the object and opens its floating property popover,
 *   - ↑ / ↓  → restacks the object one step (same announce convention as
 *              canvas-alignment: fire object:modified so the form goes
 *              dirty and the history controller snapshots the change).
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
    }

    disconnect() {
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
            this.listTarget.appendChild(this._buildRow(objects[index], index, objects.length));
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

    moveUp(event) {
        this._restackOneStep(event, 'up');
    }

    moveDown(event) {
        this._restackOneStep(event, 'down');
    }

    _restackOneStep(event, direction) {
        const obj = this._objectFromEvent(event);
        if (!obj) return;
        const canvas = this.canvasEditorOutlet.canvas;
        if (direction === 'up') {
            canvas.bringObjectForward(obj);
        } else {
            canvas.sendObjectBackwards(obj);
        }
        canvas.renderAll();
        // Restacking fires no Fabric events — announce it (dirty + history),
        // the same convention canvas-alignment uses for its z-order buttons.
        canvas.fire('object:modified', {});

        this.rebuild();
        this._refocusArrow(canvas.getObjects().indexOf(obj), direction);
    }

    /** Keep keyboard focus on "the same object's arrow" across the rebuild. */
    _refocusArrow(index, direction) {
        if (index < 0) return;
        const row = this.listTarget.querySelector(`.canvas-layer-row[data-index="${index}"]`);
        if (!row) return;
        const arrow = row.querySelector(direction === 'up' ? '[data-layer-arrow="up"]' : '[data-layer-arrow="down"]');
        if (arrow && !arrow.disabled) {
            arrow.focus();
        } else if (arrow) {
            // Hit the top/bottom — the pressed arrow is now disabled; keep
            // focus in the row so the panel stays keyboard-navigable.
            row.querySelector('.canvas-layer-row__main')?.focus();
        }
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

    _buildRow(obj, index, count) {
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
        main.title = `${label} — ${typeLabel} (kliknutím upravíte)`;
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

        const actions = document.createElement('div');
        actions.className = 'canvas-layer-row__actions';
        actions.appendChild(this._arrowButton('up', 'Posunout vrstvu výš', index === count - 1));
        actions.appendChild(this._arrowButton('down', 'Posunout vrstvu níž', index === 0));
        row.appendChild(actions);

        return row;
    }

    _arrowButton(direction, title, disabled) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'canvas-layer-row__arrow';
        btn.title = title;
        btn.setAttribute('aria-label', title);
        btn.dataset.layerArrow = direction;
        btn.dataset.action = direction === 'up' ? 'canvas-layers#moveUp' : 'canvas-layers#moveDown';
        btn.disabled = disabled;
        const icon = document.createElement('i');
        icon.className = `mdi ${direction === 'up' ? 'mdi-chevron-up' : 'mdi-chevron-down'}`;
        icon.setAttribute('aria-hidden', 'true');
        btn.appendChild(icon);
        return btn;
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
