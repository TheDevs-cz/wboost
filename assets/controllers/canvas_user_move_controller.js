import { Controller } from "@hotwired/stimulus";
import { isShapeObject } from "./canvas_shapes.js";
import { designHeight, designWidth, trimOriginRect } from "./canvas_viewport.js";
import {
    AXIS_OF, bandRect, displayMax, formatLimit, normalizeAllowance, normalizeUnit,
    parseMax, withAxis, withMax,
} from "./canvas_user_move.js";

/**
 * "Uživatel může posunout" in the admin editor: the popover section of texts
 * (locked ones too), shapes and decorative images, plus the dashed RANGE
 * BAND drawn around the selected movable element — or around a top-level
 * container whose ⚙ popover is open / whose members are label-selected.
 *
 * Data: the object's `userMove` custom prop (canonical shape + maths in
 * assets/editor/element_move.js; PHP twin ElementMoveAllowance). Values are
 * typed in the variant's unit (canvas-editor `unit`) and stored as canvas px.
 * The allowance object is IMMUTABLE — every edit assigns a fresh one, because
 * Fabric clones (clipboard, group sync, undo) share the reference.
 *
 * Not offered on: the background layer, fillable image placeholders (their
 * frame never moves; "Posun obrázku v rámečku" pans the picture instead — a
 * hint shows), multi-selections. A CONTAINER MEMBER shows the section
 * disabled with a hint: members never move on their own, the whole top-level
 * container does (its ⚙ popover, built by canvas_container_controller via
 * canvas_user_move.js). A member's stored allowance is kept (inert), so
 * removing it from the container restores it.
 *
 * Undo: a toggle or a committed field (`change`) = markUnsaved + ONE
 * synthetic `object:modified` (the history snapshot carries the prop via
 * CANVAS_CUSTOM_PROPERTIES; the group editor turns the dirty into its sync
 * pass, which projects the maxima per dimension).
 *
 * The band lives in the unscaled stage layer (never on the bitmap, so it
 * never reaches a thumbnail or a render), repositioned on `after:render`
 * like the container zones; hidden by the clean-preview eye.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["layer", "section", "body", "toggle", "limits", "max", "unit", "memberHint", "placeholderHint"];

    initialize() {
        this._band = null;
        this._bandKey = '';
        this._syncedObject = null;
        this._syncedUnit = null;
    }

    canvasEditorOutletConnected(outlet) {
        const canvas = outlet.canvas;
        this._onAfterRender = () => this._positionBand();
        canvas.on('after:render', this._onAfterRender);
        this._sync();
    }

    canvasEditorOutletDisconnected(outlet) {
        if (outlet.canvas && this._onAfterRender) outlet.canvas.off('after:render', this._onAfterRender);
        this._removeBand();
    }

    disconnect() {
        this._removeBand();
    }

    updateFromSelection() {
        this._sync();
    }

    // --- actions --------------------------------------------------------------

    toggleAxis(event) {
        const obj = this._editable();
        if (!obj) return;
        const axis = event.target.dataset.axis;
        const section = event.target.closest('[data-canvas-user-move-target="section"]');
        const fieldMaxima = {};
        if (section) {
            section.querySelectorAll('[data-canvas-user-move-target="max"]').forEach((input) => {
                if (AXIS_OF[input.dataset.dir] !== axis) return;
                const px = parseMax(input.value, this._unit());
                if (px !== undefined) fieldMaxima[input.dataset.dir] = px;
            });
        }
        this._commit(obj, withAxis(obj.userMove, axis, event.target.checked, fieldMaxima));
    }

    updateMax(event) {
        const obj = this._editable();
        if (!obj) return;
        const input = event.target;
        const px = parseMax(input.value, this._unit());
        if (px === undefined) {
            input.classList.add('is-invalid');
            return;
        }
        input.classList.remove('is-invalid');
        this._commit(obj, withMax(obj.userMove, input.dataset.dir, px));
    }

    /** Enter applies (and must not submit the editor's save form); ↑/↓ step
     *  one display unit, Shift ×10 — the image geometry fields' keys. */
    maxKeydown(event) {
        const input = event.target;
        if (event.key === 'Enter') {
            event.preventDefault();
            input.blur();
            return;
        }
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
        event.preventDefault();
        const unit = this._unit();
        const step = (event.shiftKey ? 10 : 1) * (unit === 'px' ? 1 : (unit === 'mm' ? 1 : 0.1));
        const current = parseFloat(String(input.value).replace(',', '.'));
        const base = Number.isFinite(current) ? current : 0;
        input.value = String(Math.max(0, Math.round((base + (event.key === 'ArrowUp' ? step : -step)) * 100) / 100));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // --- state ----------------------------------------------------------------

    _commit(obj, next) {
        const canvas = this.canvasEditorOutlet.canvas;
        obj.set('userMove', next || undefined);
        if (!next) delete obj.userMove;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        canvas.fire('object:modified', { target: obj });
        this._sync();
    }

    /** What the section shows for the active object. */
    _state(obj) {
        if (!obj) return 'none';
        const type = (obj.type || '').toLowerCase();
        if (type === 'activeselection' || obj.isBackground === true) return 'none';
        let eligible = false;
        if (type === 'textbox') eligible = true;
        else if (type === 'image') {
            if (obj.imagePlaceholder === true) return 'placeholder';
            eligible = true;
        } else if (isShapeObject(obj)) eligible = true;
        if (!eligible) return 'none';
        return this._containerOfObject(obj) ? 'member' : 'element';
    }

    _editable() {
        if (!this.hasCanvasEditorOutlet) return null;
        const obj = this.canvasEditorOutlet.canvas.getActiveObject();
        return this._state(obj) === 'element' ? obj : null;
    }

    _sync() {
        if (!this.hasCanvasEditorOutlet) return;
        const obj = this.canvasEditorOutlet.canvas.getActiveObject();
        const state = this._state(obj);
        const unit = this._unit();
        const allowance = state === 'element' || state === 'member' ? normalizeAllowance(obj.userMove) : null;

        this.sectionTargets.forEach((el) => el.classList.toggle('d-none', state === 'none'));
        this.memberHintTargets.forEach((el) => el.classList.toggle('d-none', state !== 'member'));
        this.placeholderHintTargets.forEach((el) => el.classList.toggle('d-none', state !== 'placeholder'));
        this.bodyTargets.forEach((el) => {
            el.classList.toggle('d-none', state === 'placeholder');
            el.classList.toggle('is-disabled', state === 'member');
        });
        this.toggleTargets.forEach((input) => {
            input.checked = Boolean(allowance && allowance[input.dataset.axis]);
            input.disabled = state !== 'element';
        });
        this.limitsTargets.forEach((row) => {
            row.classList.toggle('is-off', !(allowance && allowance[row.dataset.axis]));
        });
        // A switched-off axis keeps whatever the designer typed for THIS
        // object, so switching it back on restores the limits (toggleAxis
        // reads them); another object starts from its own stored values.
        const sameObject = obj === this._syncedObject && unit === this._syncedUnit;
        this._syncedObject = obj;
        this._syncedUnit = unit;
        this.maxTargets.forEach((input) => {
            const axisOn = Boolean(allowance && allowance[AXIS_OF[input.dataset.dir]]);
            input.disabled = state !== 'element' || !axisOn;
            if (document.activeElement === input) return;
            input.classList.remove('is-invalid');
            if (axisOn) {
                input.value = displayMax(allowance[input.dataset.dir], unit);
            } else if (!sameObject) {
                input.value = '';
            }
        });
        this.unitTargets.forEach((el) => { el.textContent = unit; });

        this._bandKey = '';
        this._positionBand();
    }

    _unit() {
        return normalizeUnit(this.hasCanvasEditorOutlet ? this.canvasEditorOutlet.unitValue : 'px');
    }

    _containers() {
        const canvas = this.hasCanvasEditorOutlet ? this.canvasEditorOutlet.canvas : null;
        return canvas && Array.isArray(canvas.wboostContainers) ? canvas.wboostContainers : [];
    }

    _containerOfObject(obj) {
        if (!obj || !obj.inputId) return null;
        return this._containers().find((c) => (c.memberInputIds || []).includes(obj.inputId)) || null;
    }

    _isNested(container) {
        return this._containers().some((c) => (c.memberContainerIds || []).includes(container.id));
    }

    _deepMembers(container) {
        const canvas = this.canvasEditorOutlet.canvas;
        const containers = this._containers();
        const byId = new Map(containers.map((c) => [c.id, c]));
        const ids = new Set();
        const seen = new Set();
        const stack = [container.id];
        while (stack.length) {
            const id = stack.shift();
            if (seen.has(id) || !byId.has(id)) continue;
            seen.add(id);
            const def = byId.get(id);
            (def.memberInputIds || []).forEach((memberId) => ids.add(memberId));
            (def.memberContainerIds || []).forEach((childId) => stack.push(childId));
        }
        return canvas.getObjects().filter((o) => o.inputId && ids.has(o.inputId) && o.visible !== false);
    }

    // --- range band -------------------------------------------------------------

    /**
     * What the band is drawn around right now: the active movable element,
     * else a movable top-level container whose ⚙ popover is open or whose
     * members are exactly the current (label-click) selection.
     */
    _bandSubject() {
        const canvas = this.canvasEditorOutlet.canvas;
        const active = canvas.getActiveObject();
        if (this._state(active) === 'element') {
            const allowance = normalizeAllowance(active.userMove);
            if (allowance) return { allowance, frame: rectOf(active.getBoundingRect()) };
        }

        const popover = this.element.querySelector('.container-zone-popover[data-container-id]');
        let container = popover
            ? this._containers().find((c) => c.id === popover.dataset.containerId)
            : null;
        if (!container && active && (active.type || '').toLowerCase() === 'activeselection') {
            const selected = new Set(active.getObjects());
            container = this._containers().find((c) => {
                if (!normalizeAllowance(c.userMove) || this._isNested(c)) return false;
                const members = this._deepMembers(c).filter((o) => o.selectable !== false);
                return members.length === selected.size && members.every((o) => selected.has(o));
            }) || null;
        }
        if (!container || this._isNested(container)) return null;
        const allowance = normalizeAllowance(container.userMove);
        if (!allowance) return null;
        const members = this._deepMembers(container);
        if (!members.length) return null;
        // Frame = the union of the members' boxes (what the user moves), not
        // the maxHeight-extended zone.
        const rects = members.map((o) => o.getBoundingRect());
        const left = Math.min(...rects.map((r) => r.left));
        const top = Math.min(...rects.map((r) => r.top));
        const right = Math.max(...rects.map((r) => r.left + r.width));
        const bottom = Math.max(...rects.map((r) => r.top + r.height));
        return { allowance, frame: { left, top, width: right - left, height: bottom - top } };
    }

    _positionBand() {
        if (!this.hasCanvasEditorOutlet || !this.hasLayerTarget) return;
        const canvas = this.canvasEditorOutlet.canvas;
        const subject = this._bandSubject();
        if (!subject) {
            if (this._band) this._band.el.style.display = 'none';
            this._bandKey = '';
            return;
        }

        const band = bandRect(subject.allowance, subject.frame, designWidth(canvas), designHeight(canvas));
        const g = this._geometry();
        if (!band || !g) return;

        const unit = this._unit();
        const offX = g.contRect.left - g.layerRect.left;
        const offY = g.contRect.top - g.layerRect.top;
        const key = [band.left, band.top, band.right, band.bottom, subject.frame.left, subject.frame.top,
            subject.frame.width, subject.frame.height, g.scale, offX, offY, unit,
            JSON.stringify(subject.allowance)].map((v) => (typeof v === 'number' ? v.toFixed(2) : v)).join('|');
        if (key === this._bandKey && this._band && this._band.el.style.display !== 'none') return;
        this._bandKey = key;

        const els = this._ensureBand();
        els.el.style.display = '';
        els.el.style.left = `${offX + band.left * g.scale}px`;
        els.el.style.top = `${offY + band.top * g.scale}px`;
        els.el.style.width = `${Math.max(0, band.right - band.left) * g.scale}px`;
        els.el.style.height = `${Math.max(0, band.bottom - band.top) * g.scale}px`;

        // Designed position inside the band (the ghost the user starts from).
        els.home.style.left = `${(subject.frame.left - band.left) * g.scale}px`;
        els.home.style.top = `${(subject.frame.top - band.top) * g.scale}px`;
        els.home.style.width = `${subject.frame.width * g.scale}px`;
        els.home.style.height = `${subject.frame.height * g.scale}px`;

        const a = subject.allowance;
        const caps = {
            up: a.vertical ? `↑ ${formatLimit(a.maxUp, unit)}` : null,
            down: a.vertical ? `↓ ${formatLimit(a.maxDown, unit)}` : null,
            left: a.horizontal ? `← ${formatLimit(a.maxLeft, unit)}` : null,
            right: a.horizontal ? `→ ${formatLimit(a.maxRight, unit)}` : null,
        };
        Object.entries(caps).forEach(([side, text]) => {
            const cap = els.caps[side];
            cap.style.display = text ? '' : 'none';
            if (text) cap.textContent = text;
        });
        els.el.classList.toggle('umove-band--v', a.vertical && !a.horizontal);
        els.el.classList.toggle('umove-band--h', a.horizontal && !a.vertical);
    }

    _ensureBand() {
        if (this._band && this._band.el.isConnected) return this._band;
        const el = document.createElement('div');
        el.className = 'umove-band';
        el.setAttribute('aria-hidden', 'true');
        const home = document.createElement('div');
        home.className = 'umove-band__home';
        el.appendChild(home);
        const caps = {};
        ['up', 'down', 'left', 'right'].forEach((side) => {
            const cap = document.createElement('span');
            cap.className = `umove-band__cap umove-band__cap--${side}`;
            el.appendChild(cap);
            caps[side] = cap;
        });
        this.layerTarget.appendChild(el);
        this._band = { el, home, caps };
        return this._band;
    }

    _removeBand() {
        if (this._band) this._band.el.remove();
        this._band = null;
        this._bandKey = '';
    }

    /** Same metrics as the container zones (canvas_container_controller). */
    _geometry() {
        const canvas = this.canvasEditorOutlet.canvas;
        const canvasEl = canvas.getElement ? canvas.getElement() : null;
        if (!canvasEl) return null;
        const container = canvasEl.parentElement || canvasEl;
        const contRect = container.getBoundingClientRect();
        const layerRect = this.layerTarget.getBoundingClientRect();
        const logicalWidth = (typeof canvas.getWidth === 'function' ? canvas.getWidth() : canvas.width) || canvasEl.width;
        const scale = logicalWidth ? contRect.width / logicalWidth : 1;
        return { contRect: trimOriginRect(contRect, canvas, scale), layerRect, scale };
    }
}

function rectOf(r) {
    return { left: r.left, top: r.top, width: r.width, height: r.height };
}
