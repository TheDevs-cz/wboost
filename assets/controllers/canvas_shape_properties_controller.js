import { Controller } from "@hotwired/stimulus";
import { applyEditorLock } from "./canvas_custom_properties.js";
import {
    GRADIENT_MAX_STOPS,
    SHAPE_KINDS,
    buildGradient,
    defaultStrokeWidth,
    describeFill,
    gradientStopColor,
    isShapeObject,
    toHexColor,
} from "./canvas_shapes.js";
import { designHeight, designWidth } from './canvas_viewport.js';

/**
 * Editor-side styling for SHAPE objects — the third sibling of
 * canvas-input-properties (text) and canvas-image-properties (images).
 *
 * Everything it writes is a NATIVE Fabric property (fill, stroke, strokeWidth,
 * strokeDashArray, rx/ry, opacity), so it round-trips through the canvas JSONB
 * and re-renders identically in the headless export with no server-side work.
 * The custom properties involved are `editorLocked`, shared verbatim with
 * images, and the "Barva pro uživatele" trio — `colorChoice` ('fill' |
 * 'stroke' | 'both' | null), `allowedColors` (the swatch list, manual colours
 * pre-ticked + custom hexes) and `colorFree` (any colour on top) — which open
 * the shape up for recolouring on the fill page / API (ShapeColorInput is the
 * server-side reader and the authority on what is actually paintable).
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
        "gradientStops", "gradientPreview", "gradientAdd", "gradientType", "gradientAngle", "gradientAngleValue", "gradientAngleRow",
        "strokeColor", "strokePicker", "strokeWidth", "strokeStyle", "strokeSection", "strokeClear",
        "cornerRadiusRow", "cornerRadius",
        "opacity", "opacityValue",
        "editorLocked",
        "colorChoice", "colorChoiceWarning", "colorChoiceList", "colorChoiceSwatches", "colorChoiceCustom", "colorFree",
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
        this._gradientStops = fill.stops.map((stop) => ({ ...stop }));
        this._renderGradientStops();
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

        this._syncColorChoice(shape);
    }

    // --- "Barva pro uživatele" (end-user recolouring) ---------------------

    /** Turn recolouring on/off and pick which part a pick repaints. Turning
     *  it on for the first time pre-ticks the manual's colours — the designer
     *  then only narrows down (the text input's colour allowlist does the
     *  same). */
    updateColorChoice(event) {
        const shape = this._activeShape();
        if (!shape) return;
        const value = event.target.value;
        shape.colorChoice = ['fill', 'stroke', 'both'].includes(value) ? value : null;
        if (shape.colorChoice && !Array.isArray(shape.allowedColors)) {
            shape.allowedColors = this._brandColors();
        }
        this._syncColorChoice(shape);
        this._commit(shape, { history: true });
    }

    updateColorFree(event) {
        const shape = this._activeShape();
        if (!shape) return;
        shape.colorFree = event.target.checked === true;
        this._commit(shape, { history: true });
    }

    customColorKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            this.addCustomColor();
        }
    }

    /** "#rrggbb" typed by the admin — normalized like the server does
     *  (RichText::normalizeHexColor), appended to the list ticked. */
    addCustomColor() {
        const shape = this._activeShape();
        if (!shape || !this.hasColorChoiceCustomTarget) return;
        const color = normalizeHexInput(this.colorChoiceCustomTarget.value);
        if (!color) {
            this.colorChoiceCustomTarget.classList.add('is-invalid');
            return;
        }
        this.colorChoiceCustomTarget.classList.remove('is-invalid');
        this.colorChoiceCustomTarget.value = '';
        const allowed = Array.isArray(shape.allowedColors) ? shape.allowedColors.slice() : [];
        if (!allowed.includes(color)) allowed.push(color);
        shape.allowedColors = allowed;
        this._renderColorSwatches(shape);
        this._commit(shape, { history: true });
    }

    _toggleAllowedColor(color) {
        const shape = this._activeShape();
        if (!shape) return;
        const allowed = Array.isArray(shape.allowedColors) ? shape.allowedColors.slice() : [];
        const index = allowed.indexOf(color);
        if (index === -1) {
            allowed.push(color);
        } else {
            allowed.splice(index, 1);
        }
        shape.allowedColors = allowed;
        this._renderColorSwatches(shape);
        this._commit(shape, { history: true });
    }

    _brandColors() {
        const colors = this.hasCanvasEditorOutlet && Array.isArray(this.canvasEditorOutlet.brandColorsValue)
            ? this.canvasEditorOutlet.brandColorsValue
            : [];
        return colors.map((color) => String(color).toLowerCase());
    }

    _syncColorChoice(shape) {
        const choice = ['fill', 'stroke', 'both'].includes(shape.colorChoice) ? shape.colorChoice : '';
        if (this.hasColorChoiceTarget) this.colorChoiceTarget.value = choice;
        if (this.hasColorChoiceListTarget) this.colorChoiceListTarget.classList.toggle('d-none', choice === '');
        if (this.hasColorFreeTarget) this.colorFreeTarget.checked = shape.colorFree === true;
        if (choice !== '') this._renderColorSwatches(shape);
        this._syncColorChoiceWarning(shape);
    }

    /** Brand swatches first (toggleable), then any custom colour the admin
     *  added that the manual does not carry. The designed colour is always
     *  available to the user, so it is not a chip here. */
    _renderColorSwatches(shape) {
        if (!this.hasColorChoiceSwatchesTarget) return;
        const allowed = Array.isArray(shape.allowedColors) ? shape.allowedColors : [];
        const brand = this._brandColors();
        const palette = [...brand, ...allowed.filter((color) => !brand.includes(color))];
        this.colorChoiceSwatchesTarget.textContent = '';
        palette.forEach((color) => {
            const on = allowed.includes(color);
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'color-choice-swatch' + (on ? ' color-choice-swatch--on' : '');
            chip.style.backgroundColor = color;
            chip.title = color + (on ? ' — povoleno (kliknutím odeberete)' : ' — kliknutím povolíte');
            chip.setAttribute('aria-pressed', on ? 'true' : 'false');
            chip.addEventListener('click', () => this._toggleAllowedColor(color));
            this.colorChoiceSwatchesTarget.appendChild(chip);
        });
    }

    /** Say when the chosen part is not paintable with one colour — the
     *  server (ShapeColorInput) then recolours only the paintable part, or
     *  offers nothing at all. Re-run on every fill / stroke edit. */
    _syncColorChoiceWarning(shape) {
        if (!this.hasColorChoiceWarningTarget) return;
        const choice = shape.colorChoice;
        const gradientFill = describeFill(shape).mode === 'gradient';
        const hasStroke = Boolean(shape.stroke) && (Number(shape.strokeWidth) || 0) > 0;
        let message = '';
        if (choice === 'fill' && gradientFill) {
            message = 'Výplň je přechod — uživatel ji zatím přebarvit nemůže. Nastavte plnou barvu.';
        } else if (choice === 'stroke' && !hasStroke) {
            message = 'Tvar nemá obrys — uživatel nemá co přebarvit.';
        } else if (choice === 'both' && gradientFill && !hasStroke) {
            message = 'Výplň je přechod a tvar nemá obrys — uživatel nemá co přebarvit.';
        } else if (choice === 'both' && gradientFill) {
            message = 'Výplň je přechod — uživatel přebarví jen obrys.';
        } else if (choice === 'both' && !hasStroke) {
            message = 'Tvar nemá obrys — uživatel přebarví jen výplň.';
        }
        this.colorChoiceWarningTarget.textContent = message;
        this.colorChoiceWarningTarget.classList.toggle('d-none', message === '');
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

    /** Gradient type / angle controls; a `change` lands one undo step. */
    updateGradient(event) {
        this._applyGradient({ history: Boolean(event && event.type === 'change') });
        this._syncGradientAngleUi(
            this.hasGradientTypeTarget ? this.gradientTypeTarget.value : 'linear',
            this.hasGradientAngleTarget ? Number(this.gradientAngleTarget.value) : 90,
        );
    }

    /**
     * One field of one stop row (`color` | `opacity` | `offset`). `input`
     * previews live; `change` additionally lands one undo step — and, for the
     * position, re-sorts the rows (never mid-typing, which would steal focus).
     */
    updateGradientStop(event) {
        const index = Number(event.params ? event.params.index : NaN);
        const field = event.params ? event.params.field : null;
        const stop = this._gradientStops ? this._gradientStops[index] : null;
        if (!stop) return;

        if (field === 'color') {
            stop.color = toHexColor(event.target.value);
        } else if (field === 'opacity' || field === 'offset') {
            const raw = event.target.value;
            if (raw === '' || !Number.isFinite(Number(raw))) return;
            const percent = Math.min(100, Math.max(0, Math.round(Number(raw))));
            stop[field] = percent / 100;
            if (event.type === 'change') event.target.value = String(percent);
        } else {
            return;
        }

        const isChange = event.type === 'change';
        // Re-render only when the order actually changed: `change` fires on
        // blur, and rebuilding the rows would steal focus from the field the
        // designer just tabbed into.
        if (isChange && field === 'offset' && this._sortGradientStops()) {
            this._renderGradientStops();
        } else {
            this._syncGradientPreview();
        }
        this._applyGradient({ history: isChange });
    }

    /**
     * "+ Přidat barvu": a new stop in the middle of the widest gap, coloured
     * as the gradient already is at that point — adding a stop never changes
     * what the shape looks like until the designer edits it.
     */
    addGradientStop() {
        const stops = this._gradientStops || [];
        if (stops.length >= GRADIENT_MAX_STOPS || stops.length < 2) return;

        let gapIndex = 0;
        let widest = -1;
        for (let i = 0; i < stops.length - 1; i += 1) {
            const gap = stops[i + 1].offset - stops[i].offset;
            if (gap > widest) {
                widest = gap;
                gapIndex = i;
            }
        }
        const left = stops[gapIndex];
        const right = stops[gapIndex + 1];
        stops.splice(gapIndex + 1, 0, {
            offset: Math.round(((left.offset + right.offset) / 2) * 100) / 100,
            color: mixHex(left.color, right.color, 0.5),
            opacity: Math.round(((left.opacity + right.opacity) / 2) * 100) / 100,
        });

        this._renderGradientStops();
        this._applyGradient({ history: true });
    }

    removeGradientStop(event) {
        const index = Number(event.params ? event.params.index : NaN);
        const stops = this._gradientStops || [];
        if (stops.length <= 2 || !stops[index]) return;
        stops.splice(index, 1);
        this._renderGradientStops();
        this._applyGradient({ history: true });
    }

    /** Mirror the gradient (0 % ↔ 100 %) — the fastest way to flip a
     *  direction without doing angle arithmetic in your head. */
    swapGradient() {
        if (!this._gradientStops) return;
        this._gradientStops.forEach((stop) => {
            stop.offset = Math.round((1 - stop.offset) * 100) / 100;
        });
        this._sortGradientStops();
        this._renderGradientStops();
        this._applyGradient({ history: true });
    }

    _applyGradient({ history = false } = {}) {
        const shape = this._activeShape();
        if (!shape) return;
        shape.set('fill', this._gradientFromControls());
        this._commit(shape, { history });
    }

    _gradientFromControls() {
        return buildGradient({
            type: this.hasGradientTypeTarget ? this.gradientTypeTarget.value : 'linear',
            angle: this.hasGradientAngleTarget ? Number(this.gradientAngleTarget.value) : 90,
            stops: this._gradientStops,
        });
    }

    /** Sort stops by position; returns whether the order changed. */
    _sortGradientStops() {
        if (!this._gradientStops) return false;
        const before = [...this._gradientStops];
        this._gradientStops.sort((a, b) => a.offset - b.offset);
        return this._gradientStops.some((stop, i) => stop !== before[i]);
    }

    /**
     * Rebuild the stop rows from `_gradientStops`. Only on selection / add /
     * remove / re-sort — never on `input`, so the field being typed in keeps
     * focus. The rows carry Stimulus action descriptors, which the controller
     * picks up for nodes it did not render itself.
     */
    _renderGradientStops() {
        if (!this.hasGradientStopsTarget) return;
        const stops = this._gradientStops || [];
        const id = this.identifier;
        const canRemove = stops.length > 2;
        const container = this.gradientStopsTarget;
        container.replaceChildren();

        stops.forEach((stop, index) => {
            const row = document.createElement('div');
            row.className = 'shape-gradient-stop';

            const color = document.createElement('input');
            color.type = 'color';
            color.className = 'form-control form-control-sm form-control-color shape-gradient-stop__color';
            color.value = stop.color;
            color.title = 'Barva';
            color.setAttribute('aria-label', `Barva bodu ${index + 1}`);
            this._bindStopField(color, index, 'color');

            row.append(
                color,
                this._percentField(Math.round(stop.opacity * 100), index, 'opacity', `Krytí bodu ${index + 1}`),
                this._percentField(Math.round(stop.offset * 100), index, 'offset', `Pozice bodu ${index + 1}`),
            );

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-link text-muted p-0 shape-gradient-stop__remove';
            remove.title = canRemove ? 'Odebrat barvu' : 'Přechod potřebuje alespoň dvě barvy';
            remove.disabled = !canRemove;
            remove.setAttribute('aria-label', `Odebrat bod ${index + 1}`);
            remove.innerHTML = '<i class="mdi mdi-close" aria-hidden="true"></i>';
            remove.dataset.action = `${id}#removeGradientStop`;
            remove.setAttribute(`data-${id}-index-param`, String(index));
            row.append(remove);

            container.append(row);
        });

        if (this.hasGradientAddTarget) {
            this.gradientAddTarget.disabled = stops.length >= GRADIENT_MAX_STOPS;
            this.gradientAddTarget.title = stops.length >= GRADIENT_MAX_STOPS
                ? `Nejvýše ${GRADIENT_MAX_STOPS} barev`
                : 'Přidat barvu do přechodu';
        }
        this._syncGradientPreview();
    }

    _percentField(value, index, field, label) {
        const group = document.createElement('div');
        group.className = 'input-group input-group-sm shape-gradient-stop__percent';
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control';
        input.min = '0';
        input.max = '100';
        input.step = '1';
        input.value = String(value);
        input.setAttribute('aria-label', label);
        this._bindStopField(input, index, field);
        const suffix = document.createElement('span');
        suffix.className = 'input-group-text';
        suffix.textContent = '%';
        group.append(input, suffix);
        return group;
    }

    _bindStopField(input, index, field) {
        const id = this.identifier;
        input.dataset.action = `input->${id}#updateGradientStop change->${id}#updateGradientStop`;
        input.setAttribute(`data-${id}-index-param`, String(index));
        input.setAttribute(`data-${id}-field-param`, field);
    }

    /** The bar above the rows: the gradient left→right over a checkerboard,
     *  so a transparent stop reads as transparent rather than white. */
    _syncGradientPreview() {
        if (!this.hasGradientPreviewTarget) return;
        const stops = [...(this._gradientStops || [])].sort((a, b) => a.offset - b.offset);
        const css = stops
            .map((stop) => `${gradientStopColor(stop.color, stop.opacity)} ${Math.round(stop.offset * 100)}%`)
            .join(', ');
        this.gradientPreviewTarget.style.setProperty('--shape-gradient-preview', `linear-gradient(90deg, ${css})`);
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
                designWidth(this.canvasEditorOutlet.canvas),
                designHeight(this.canvasEditorOutlet.canvas),
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
        this._syncColorChoiceWarning(shape);
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

/** Linear blend of two `#rrggbb` colours (t = 0 → a, 1 → b). */
function mixHex(a, b, t) {
    const channel = (hex, i) => parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16);
    const out = [0, 1, 2].map((i) => Math.round(channel(a, i) + (channel(b, i) - channel(a, i)) * t));
    return `#${out.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}
