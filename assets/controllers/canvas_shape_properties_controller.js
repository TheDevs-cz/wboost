import { Controller } from "@hotwired/stimulus";
import { applyEditorLock } from "./canvas_custom_properties.js";
import {
    SHAPE_KINDS,
    buildGradient,
    defaultStrokeWidth,
    describeFill,
    isShapeObject,
    toHexColor,
} from "./canvas_shapes.js";

/**
 * Editor-side styling for SHAPE objects — the third sibling of
 * canvas-input-properties (text) and canvas-image-properties (images).
 *
 * Everything it writes is a NATIVE Fabric property (fill, stroke, strokeWidth,
 * strokeDashArray, rx/ry, opacity), so it round-trips through the canvas JSONB
 * and re-renders identically in the headless export with no server-side work.
 * The one custom property involved is `editorLocked`, shared verbatim with
 * images.
 *
 * These fields live inside the floating shape popover; the popover's visibility
 * is owned by canvas-floating-toolbar, so this controller only populates and
 * mutates.
 *
 * Commit model, mirroring the text toolbar: `input` events preview live and
 * mark the form dirty (which is what the group editor's debounced propagation
 * listens to); `change` events additionally fire a synthetic `object:modified`
 * so the edit lands as ONE undo step instead of one per slider tick.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "kindLabel", "name",
        "solidButton", "gradientButton", "solidSection", "gradientSection",
        "swatch", "fillColor", "fillPicker",
        "gradientFrom", "gradientTo", "gradientType", "gradientAngle", "gradientAngleValue", "gradientAngleRow",
        "strokeColor", "strokePicker", "strokeWidth", "strokeStyle", "strokeSection", "strokeClear",
        "cornerRadiusRow", "cornerRadius",
        "opacity", "opacityValue",
        "editorLocked",
    ];

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const shape = event.detail.activeObject;
        if (!isShapeObject(shape)) {
            return;
        }

        if (this.hasKindLabelTarget) {
            const kind = SHAPE_KINDS[shape.shapeKind];
            this.kindLabelTarget.textContent = kind ? kind.label : 'Tvar';
        }
        if (this.hasNameTarget) this.nameTarget.value = shape.name || '';
        if (this.hasEditorLockedTarget) this.editorLockedTarget.checked = shape.editorLocked === true;

        const fill = describeFill(shape);
        this._setFillModeUi(fill.mode);
        if (this.hasFillColorTarget) this.fillColorTarget.value = fill.color;
        if (this.hasFillPickerTarget) this.fillPickerTarget.value = fill.color;
        if (this.hasGradientFromTarget) this.gradientFromTarget.value = fill.from;
        if (this.hasGradientToTarget) this.gradientToTarget.value = fill.to;
        if (this.hasGradientTypeTarget) this.gradientTypeTarget.value = fill.type;
        if (this.hasGradientAngleTarget) this.gradientAngleTarget.value = String(fill.angle);
        this._syncSwatches(fill.mode === 'solid' ? fill.color : null);
        this._syncGradientAngleUi(fill.type, fill.angle);

        const strokeWidth = Number(shape.strokeWidth) || 0;
        const hasStroke = Boolean(shape.stroke) && strokeWidth > 0;
        if (this.hasStrokeColorTarget) this.strokeColorTarget.value = hasStroke ? toHexColor(shape.stroke) : '';
        if (this.hasStrokePickerTarget) this.strokePickerTarget.value = toHexColor(shape.stroke, '#000000');
        if (this.hasStrokeWidthTarget) this.strokeWidthTarget.value = hasStroke ? String(round(strokeWidth)) : '';
        if (this.hasStrokeStyleTarget) this.strokeStyleTarget.value = dashStyleOf(shape.strokeDashArray);
        if (this.hasStrokeClearTarget) this.strokeClearTarget.classList.toggle('d-none', !hasStroke);

        // Corner radius is a rectangle affordance: on an Ellipse the very same
        // rx/ry keys ARE the radii (i.e. the size), so offering the control
        // there would silently resize the shape.
        const isRect = (shape.type || '').toLowerCase() === 'rect';
        if (this.hasCornerRadiusRowTarget) this.cornerRadiusRowTarget.classList.toggle('d-none', !isRect);
        if (this.hasCornerRadiusTarget && isRect) this.cornerRadiusTarget.value = String(round(shape.rx || 0));

        const opacityPercent = Math.round((typeof shape.opacity === 'number' ? shape.opacity : 1) * 100);
        if (this.hasOpacityTarget) this.opacityTarget.value = String(opacityPercent);
        if (this.hasOpacityValueTarget) this.opacityValueTarget.textContent = `${opacityPercent} %`;
    }

    // --- fill: solid colour vs gradient ----------------------------------

    /**
     * Switch the fill between a flat colour and a gradient. Both branches
     * REBUILD the fill from the currently shown controls rather than just
     * toggling sections, so the canvas always matches what the popover says.
     */
    setFillMode(event) {
        const mode = event.params ? event.params.mode : 'solid';
        const shape = this._activeShape();
        this._setFillModeUi(mode);
        if (!shape) return;

        if (mode === 'gradient') {
            shape.set('fill', this._gradientFromControls());
        } else {
            shape.set('fill', this.hasFillColorTarget ? toHexColor(this.fillColorTarget.value) : '#000000');
        }
        this._commit(shape, { history: true });
    }

    /** Brand-manual swatch click (colour rides as a Stimulus action param). */
    pickFillColor(event) {
        const color = event.params ? event.params.color : null;
        if (!color) return;
        this._applySolidFill(color);
    }

    /** Free `<input type="color">` — `input` fires while dragging, so the
     *  canvas previews the colour live. */
    pickCustomFill(event) {
        this._applySolidFill(event.target.value, { syncPicker: false });
    }

    /** Hex text field — ignore anything that is not a complete colour yet, so
     *  the canvas never flashes black while the user is still typing. */
    updateFillHex(event) {
        const color = normalizeHexInput(event.target.value);
        if (!color) return;
        this._applySolidFill(color, { syncHexInput: false });
    }

    _applySolidFill(color, { syncHexInput = true, syncPicker = true } = {}) {
        const shape = this._activeShape();
        if (!shape) return;

        const hex = toHexColor(color);
        this._setFillModeUi('solid');
        shape.set('fill', hex);

        if (syncHexInput && this.hasFillColorTarget) this.fillColorTarget.value = hex;
        if (syncPicker && this.hasFillPickerTarget) this.fillPickerTarget.value = hex;
        this._syncSwatches(hex);

        this._commit(shape);
    }

    /** Any of the gradient controls (both colours, type, angle). */
    updateGradient() {
        const shape = this._activeShape();
        if (!shape) return;
        const gradient = this._gradientFromControls();
        shape.set('fill', gradient);
        this._syncGradientAngleUi(
            this.hasGradientTypeTarget ? this.gradientTypeTarget.value : 'linear',
            this.hasGradientAngleTarget ? Number(this.gradientAngleTarget.value) : 90,
        );
        this._commit(shape);
    }

    /** Swap the gradient's two colours — the fastest way to flip a direction
     *  without doing angle arithmetic in your head. */
    swapGradient() {
        if (!this.hasGradientFromTarget || !this.hasGradientToTarget) return;
        const from = this.gradientFromTarget.value;
        this.gradientFromTarget.value = this.gradientToTarget.value;
        this.gradientToTarget.value = from;
        this.updateGradient();
    }

    _gradientFromControls() {
        return buildGradient({
            type: this.hasGradientTypeTarget ? this.gradientTypeTarget.value : 'linear',
            from: this.hasGradientFromTarget ? toHexColor(this.gradientFromTarget.value) : '#000000',
            to: this.hasGradientToTarget ? toHexColor(this.gradientToTarget.value, '#ffffff') : '#ffffff',
            angle: this.hasGradientAngleTarget ? Number(this.gradientAngleTarget.value) : 90,
        });
    }

    _setFillModeUi(mode) {
        const gradient = mode === 'gradient';
        if (this.hasSolidButtonTarget) this.solidButtonTarget.classList.toggle('active', !gradient);
        if (this.hasGradientButtonTarget) this.gradientButtonTarget.classList.toggle('active', gradient);
        if (this.hasSolidSectionTarget) this.solidSectionTarget.classList.toggle('d-none', gradient);
        if (this.hasGradientSectionTarget) this.gradientSectionTarget.classList.toggle('d-none', !gradient);
    }

    /** Mirror the active fill onto the swatch ring so all three colour
     *  affordances (chips, picker, hex) agree. */
    _syncSwatches(color) {
        const normalized = (color || '').toLowerCase();
        this.swatchTargets.forEach((swatch) => {
            const swatchColor = (swatch.dataset.canvasShapePropertiesColorParam || '').toLowerCase();
            swatch.classList.toggle('is-active', swatchColor !== '' && swatchColor === normalized);
        });
    }

    /** The angle only means anything for a linear gradient. */
    _syncGradientAngleUi(type, angle) {
        if (this.hasGradientAngleRowTarget) {
            this.gradientAngleRowTarget.classList.toggle('d-none', type === 'radial');
        }
        if (this.hasGradientAngleValueTarget) {
            this.gradientAngleValueTarget.textContent = `${Math.round(angle) || 0}°`;
        }
    }

    // --- stroke ----------------------------------------------------------

    /**
     * Picking a border colour on a shape that has none also gives it a
     * WIDTH — a stroke with width 0 paints nothing, and "I picked a colour and
     * nothing happened" is the obvious trap. The default is canvas-relative,
     * like every other shape dimension.
     */
    pickStrokeColor(event) {
        const shape = this._activeShape();
        if (!shape) return;

        const hex = toHexColor(event.target.value);
        shape.set('stroke', hex);
        if ((Number(shape.strokeWidth) || 0) <= 0) {
            const width = defaultStrokeWidth(
                this.canvasEditorOutlet.canvas.getWidth(),
                this.canvasEditorOutlet.canvas.getHeight(),
            );
            shape.set('strokeWidth', width);
            if (this.hasStrokeWidthTarget) this.strokeWidthTarget.value = String(width);
        }
        this._applyDashStyle(shape);
        if (this.hasStrokeColorTarget) this.strokeColorTarget.value = hex;
        if (this.hasStrokeClearTarget) this.strokeClearTarget.classList.remove('d-none');

        this._commit(shape);
    }

    updateStrokeHex(event) {
        const color = normalizeHexInput(event.target.value);
        if (!color) return;
        const shape = this._activeShape();
        if (!shape) return;
        shape.set('stroke', color);
        if (this.hasStrokePickerTarget) this.strokePickerTarget.value = color;
        if (this.hasStrokeClearTarget) this.strokeClearTarget.classList.remove('d-none');
        this._commit(shape);
    }

    updateStrokeWidth(event) {
        const shape = this._activeShape();
        if (!shape) return;
        const width = Math.max(0, Number(event.target.value) || 0);
        shape.set('strokeWidth', width);
        // A width without a colour paints nothing — adopt the picker's colour
        // so the number the designer typed is visible immediately.
        if (width > 0 && !shape.stroke) {
            const hex = this.hasStrokePickerTarget ? toHexColor(this.strokePickerTarget.value) : '#000000';
            shape.set('stroke', hex);
            if (this.hasStrokeColorTarget) this.strokeColorTarget.value = hex;
        }
        this._applyDashStyle(shape);
        if (this.hasStrokeClearTarget) this.strokeClearTarget.classList.toggle('d-none', width <= 0);
        this._commit(shape, { history: true });
    }

    updateStrokeStyle() {
        const shape = this._activeShape();
        if (!shape) return;
        this._applyDashStyle(shape);
        this._commit(shape, { history: true });
    }

    /** Drop the border entirely (colour AND width) — one click back to a flat
     *  fill, rather than making the designer clear two fields. */
    clearStroke() {
        const shape = this._activeShape();
        if (!shape) return;
        shape.set({ stroke: null, strokeWidth: 0, strokeDashArray: null });
        if (this.hasStrokeColorTarget) this.strokeColorTarget.value = '';
        if (this.hasStrokeWidthTarget) this.strokeWidthTarget.value = '';
        if (this.hasStrokeStyleTarget) this.strokeStyleTarget.value = 'solid';
        if (this.hasStrokeClearTarget) this.strokeClearTarget.classList.add('d-none');
        this._commit(shape, { history: true });
    }

    /**
     * Dash pattern derived from the CURRENT stroke width, so a dashed border
     * still reads as dashed at any weight (a fixed [6,4] on a 20 px outline is
     * a solid line with nicks in it). The style is re-derived from the array on
     * read (dashStyleOf) rather than stored as another custom property.
     */
    _applyDashStyle(shape) {
        const style = this.hasStrokeStyleTarget ? this.strokeStyleTarget.value : 'solid';
        const width = Math.max(1, Number(shape.strokeWidth) || 1);

        if (style === 'dashed') {
            shape.set({ strokeDashArray: [width * 3, width * 2], strokeLineCap: 'butt' });
        } else if (style === 'dotted') {
            // Zero-length dashes + round caps = true dots, at any weight.
            shape.set({ strokeDashArray: [0, width * 2], strokeLineCap: 'round' });
        } else {
            shape.set({ strokeDashArray: null, strokeLineCap: 'butt' });
        }
    }

    // --- geometry-ish + metadata -----------------------------------------

    updateCornerRadius(event) {
        const shape = this._activeShape();
        if (!shape || (shape.type || '').toLowerCase() !== 'rect') return;
        const radius = Math.max(0, Number(event.target.value) || 0);
        shape.set({ rx: radius, ry: radius });
        this._commit(shape, { history: event.type === 'change' });
    }

    updateOpacity(event) {
        const shape = this._activeShape();
        if (!shape) return;
        const percent = Math.min(100, Math.max(0, Number(event.target.value) || 0));
        shape.set('opacity', percent / 100);
        if (this.hasOpacityValueTarget) this.opacityValueTarget.textContent = `${percent} %`;
        this._commit(shape, { history: event.type === 'change' });
    }

    updateName(event) {
        const shape = this._activeShape();
        if (!shape) return;
        shape.name = event.target.value;
        this.canvasEditorOutlet.markUnsaved();
    }

    /**
     * EDITOR-ONLY lock, identical in meaning to the image one: freeze the shape
     * against accidental drags while working on nearby elements. Never reaches
     * the server render — it only flips Fabric's interaction flags.
     */
    updateEditorLock(event) {
        const shape = this._activeShape();
        if (!shape) return;
        shape.editorLocked = event.target.checked;
        applyEditorLock(shape);
        this._commit(shape, { history: true });
    }

    /** Inline lock toggle from the floating mini-toolbar / the layers panel. */
    toggleEditorLock() {
        const shape = this._activeShape();
        if (!shape) return;
        shape.editorLocked = !shape.editorLocked;
        applyEditorLock(shape);
        if (this.hasEditorLockedTarget) this.editorLockedTarget.checked = shape.editorLocked === true;
        // applyEditorLock just rewrote selectable/evented — let the backdrop
        // sweep re-decide passthrough for a newly unlocked full-canvas shape.
        this.canvasEditorOutlet.refreshBackdropStates();
        this._commit(shape, { history: true });
    }

    // --- plumbing ---------------------------------------------------------

    /**
     * @param {Object} shape
     * @param {{history?: boolean}} options  history: also fire a synthetic
     *        `object:modified`, which is what pushes an undo snapshot. Left off
     *        for continuous inputs (colour drag, opacity slider) so one gesture
     *        is one undo step, not thirty.
     */
    _commit(shape, { history = false } = {}) {
        if (typeof shape.setCoords === 'function') shape.setCoords();
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        if (history) {
            canvas.fire('object:modified', { target: shape });
        }
    }

    _activeShape() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        return isShapeObject(activeObject) ? activeObject : null;
    }
}

/** Which of the three offered stroke styles a dash array represents. */
function dashStyleOf(dashArray) {
    if (!Array.isArray(dashArray) || dashArray.length === 0) return 'solid';
    return Number(dashArray[0]) === 0 ? 'dotted' : 'dashed';
}

/** `#rrggbb` from a hex field, or null while the value is still incomplete. */
function normalizeHexInput(raw) {
    let value = (raw || '').trim();
    if (value === '') return null;
    if (!value.startsWith('#')) value = `#${value}`;
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(value) ? toHexColor(value) : null;
}

/** Round to one decimal — projected values across dimensions are rarely whole. */
function round(value) {
    return Math.round((Number(value) || 0) * 10) / 10;
}
