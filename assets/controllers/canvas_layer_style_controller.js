import { Controller } from "@hotwired/stimulus";
import { isShapeObject } from "./canvas_shapes.js";
import { BLEND_MODE_GROUPS, NORMAL_BLEND_MODE, blendModeOf, isKnownBlendMode } from "./canvas_blend_modes.js";
import { SHADOW_GLOW, SHADOW_NONE, defaultEffect, effectToShadow, shadowToEffect } from "./canvas_layer_shadow.js";
import { designWidth } from "./canvas_viewport.js";
import { adjustmentsOf, applyAdjustments, neutralAdjustments } from "./canvas_image_filters.js";

/**
 * "Styl vrstvy" — Photoshop-style layer settings shared by the text, image
 * and shape popovers (`templates/editor/_layer_style_section.html.twig`, one
 * set of targets per popover).
 *
 * Režim prolnutí = Fabric's NATIVE `globalCompositeOperation` (see
 * `canvas_blend_modes.js`): no custom property, so the payload, undo, group
 * sync (`STYLE_KEYS`) and every render surface carry it untouched. Offered
 * for every text, image and shape, the background layer included (a photo
 * background can blend over a colour layer the designer put under it).
 *
 * Stín a záře = Fabric's NATIVE `shadow` (`canvas_layer_shadow.js` maps the
 * Photoshop controls onto it): Žádný / Vržený stín / Vnější záře, colour,
 * Krytí, Úhel, Vzdálenost, Velikost. Not offered on the background layer (it
 * covers the canvas — a shadow would fall outside the artwork).
 *
 * Undo: a select change is one discrete edit → one synthetic
 * `object:modified` = one history snapshot; the shadow fields repaint live on
 * `input` and commit one snapshot on `change`.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "section", "blend",
        "shadowBlock", "shadowKind", "shadowFields", "shadowColor", "shadowOpacity", "shadowOpacityValue",
        "shadowAngle", "shadowAngleRow", "shadowDistance", "shadowDistanceRow", "shadowSize",
        "frameBlock", "frameRadius", "frameBorderColor", "frameBorderWidth",
        "adjustBlock", "adjustInput",
    ];

    connect() {
        this.blendTargets.forEach((select) => fillBlendOptions(select));
    }

    blendTargetConnected(select) {
        fillBlendOptions(select);
    }

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const active = event && event.detail ? event.detail.activeObject : null;
        const eligible = isStylable(active);
        this.sectionTargets.forEach((section) => section.classList.toggle('d-none', !eligible));
        if (!eligible) return;

        const mode = blendModeOf(active);
        this.blendTargets.forEach((select) => { select.value = mode; });

        const isImage = String(active.type || '').toLowerCase() === 'image';
        this.adjustBlockTargets.forEach((block) => block.classList.toggle('d-none', !isImage));
        if (isImage) this._showAdjust(adjustmentsOf(active));

        const framable = active.isBackground !== true && String(active.type || '').toLowerCase() === 'image';
        this.frameBlockTargets.forEach((block) => block.classList.toggle('d-none', !framable));
        if (framable) this._showFrame(active);

        const shadowable = active.isBackground !== true;
        this.shadowBlockTargets.forEach((block) => block.classList.toggle('d-none', !shadowable));
        this._showShadow(shadowable ? shadowToEffect(active.shadow) : null);
    }

    updateShadowKind(event) {
        const obj = this._active();
        if (!obj || obj.isBackground === true) return;
        const kind = event.target.value;
        let effect = null;
        if (kind !== SHADOW_NONE) {
            const current = shadowToEffect(obj.shadow);
            // Switching between stín and záře keeps the colour-independent
            // size; a fresh effect starts from Photoshop-like defaults.
            const fresh = defaultEffect(kind, designWidth(this.canvasEditorOutlet.canvas));
            effect = current.kind === SHADOW_NONE ? fresh : { ...fresh, size: current.size || fresh.size };
        }
        obj.set('shadow', effectToShadow(effect));
        this._showShadow(effect || shadowToEffect(null), kind);
        this._commit(obj);
    }

    previewShadow(event) {
        this._applyShadowFields(event, false);
    }

    commitShadow(event) {
        this._applyShadowFields(event, true);
    }

    _applyShadowFields(event, commit) {
        const obj = this._active();
        if (!obj || obj.isBackground === true) return;
        const panel = this._panelOf(event.target);
        if (!panel) return;
        const kind = panel.kind.value;
        if (kind === SHADOW_NONE) return;
        const effect = {
            kind,
            color: panel.color.value || '#000000',
            opacity: Number(panel.opacity.value),
            angle: Number(panel.angle.value),
            distance: Number(panel.distance.value),
            size: Number(panel.size.value),
        };
        obj.set('shadow', effectToShadow(effect));
        this._showShadow(effect, kind);
        const canvas = this.canvasEditorOutlet.canvas;
        obj.dirty = true;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        if (commit) canvas.fire('object:modified', { target: obj });
    }

    // --- Úpravy obrázku (images; canvas_image_filters.js) -----------------

    previewAdjust(event) {
        this._applyAdjust(event, false);
    }

    commitAdjust(event) {
        this._applyAdjust(event, true);
    }

    /** Double-click a slider = back to 0 (Photoshop's Alt-reset). */
    zeroAdjust(event) {
        event.target.value = '0';
        this._applyAdjust(event, true);
    }

    resetAdjust(event) {
        const obj = this._activeImage();
        if (!obj) return;
        applyAdjustments(obj, neutralAdjustments());
        this._showAdjust(neutralAdjustments());
        this._commit(obj);
    }

    _applyAdjust(event, commit) {
        const obj = this._activeImage();
        if (!obj) return;
        const section = event.target.closest('[data-canvas-layer-style-target="section"]');
        if (!section) return;
        const model = neutralAdjustments();
        section.querySelectorAll('[data-canvas-layer-style-target="adjustInput"]').forEach((input) => {
            const key = input.dataset.adjust;
            model[key] = input.type === 'checkbox' ? input.checked : Number(input.value) || 0;
        });
        applyAdjustments(obj, model);
        this._showAdjust(model);
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        if (commit) canvas.fire('object:modified', { target: obj });
    }

    _activeImage() {
        const obj = this._active();
        return obj && String(obj.type || '').toLowerCase() === 'image' ? obj : null;
    }

    _showAdjust(model) {
        this.adjustInputTargets.forEach((input) => {
            const value = model[input.dataset.adjust];
            if (input.type === 'checkbox') {
                input.checked = Boolean(value);
            } else if (document.activeElement !== input) {
                input.value = String(value || 0);
            }
        });
        this.adjustBlockTargets.forEach((block) => {
            block.querySelectorAll('[data-adjust-value]').forEach((label) => {
                const value = Number(model[label.dataset.adjustValue]) || 0;
                label.textContent = label.dataset.adjustValue === 'hue' ? `${value}°` : String(value);
            });
        });
    }

    // --- Zaoblení rohů + Tah (images; fabric_image_frame.js draws them) ----

    previewFrame(event) {
        this._applyFrameFields(event, false);
    }

    commitFrame(event) {
        this._applyFrameFields(event, true);
    }

    frameKeydown(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        this.commitFrame(event);
    }

    _applyFrameFields(event, commit) {
        const obj = this._active();
        if (!obj || obj.isBackground === true || String(obj.type || '').toLowerCase() !== 'image') return;
        const section = event.target.closest('[data-canvas-layer-style-target="section"]');
        if (!section) return;
        const pick = (name) => section.querySelector(`[data-canvas-layer-style-target="${name}"]`);
        const radius = Math.max(0, Number(pick('frameRadius').value) || 0);
        let width = Math.max(0, Number(pick('frameBorderWidth').value) || 0);
        const color = pick('frameBorderColor').value || '#ffffff';
        // Picking a colour with no width yet switches the border on, the way
        // the shape popover's Obrys does.
        if (width === 0 && event.target === pick('frameBorderColor')) {
            width = Math.max(1, Math.round(designWidth(this.canvasEditorOutlet.canvas) / 200));
            pick('frameBorderWidth').value = String(width);
        }
        obj.set({
            frameRadius: radius > 0 ? radius : undefined,
            frameBorder: width > 0 ? { color, width } : undefined,
        });
        obj.dirty = true;
        this._showFrame(obj);
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        if (commit) canvas.fire('object:modified', { target: obj });
    }

    _showFrame(obj) {
        const radius = Number(obj.frameRadius) > 0 ? Number(obj.frameRadius) : 0;
        const border = obj.frameBorder && Number(obj.frameBorder.width) > 0 ? obj.frameBorder : null;
        const setValue = (targets, value) => targets.forEach((input) => {
            if (document.activeElement !== input) input.value = value;
        });
        setValue(this.frameRadiusTargets, radius ? String(radius) : '');
        setValue(this.frameBorderWidthTargets, border ? String(border.width) : '');
        if (border) {
            this.frameBorderColorTargets.forEach((input) => { if (input.value !== border.color) input.value = border.color; });
        }
    }

    /** Enter commits the field and never submits the editor form the
     *  popover lives in (same as the geometry fields). */
    fieldKeydown(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        this.commitShadow(event);
    }

    /** The field set (one per popover) an input belongs to. */
    _panelOf(element) {
        const section = element.closest('[data-canvas-layer-style-target="section"]');
        if (!section) return null;
        const pick = (name) => section.querySelector(`[data-canvas-layer-style-target="${name}"]`);
        return {
            kind: pick('shadowKind'), color: pick('shadowColor'), opacity: pick('shadowOpacity'),
            angle: pick('shadowAngle'), distance: pick('shadowDistance'), size: pick('shadowSize'),
        };
    }

    /** Mirror a shadow model into every popover's fields. `kindOverride`
     *  keeps a chosen Vržený stín at distance 0 from reading as záře while
     *  it is being edited. */
    _showShadow(effect, kindOverride = null) {
        const model = effect || shadowToEffect(null);
        const kind = kindOverride || model.kind;
        this.shadowKindTargets.forEach((select) => { select.value = kind; });
        this.shadowFieldsTargets.forEach((fields) => fields.classList.toggle('d-none', kind === SHADOW_NONE));
        const isGlow = kind === SHADOW_GLOW;
        this.shadowAngleRowTargets.forEach((row) => row.classList.toggle('d-none', isGlow));
        this.shadowDistanceRowTargets.forEach((row) => row.classList.toggle('d-none', isGlow));
        if (kind === SHADOW_NONE) return;
        const setValue = (targets, value) => targets.forEach((input) => {
            if (document.activeElement !== input) input.value = String(value);
        });
        this.shadowColorTargets.forEach((input) => { if (input.value !== model.color) input.value = model.color; });
        setValue(this.shadowOpacityTargets, model.opacity);
        this.shadowOpacityValueTargets.forEach((label) => { label.textContent = `${model.opacity} %`; });
        setValue(this.shadowAngleTargets, model.angle);
        setValue(this.shadowDistanceTargets, model.distance);
        setValue(this.shadowSizeTargets, model.size);
    }

    updateBlend(event) {
        const obj = this._active();
        if (!obj) return;
        const value = isKnownBlendMode(event.target.value) ? event.target.value : NORMAL_BLEND_MODE;
        if (blendModeOf(obj) === value) return;
        obj.set('globalCompositeOperation', value);
        this.blendTargets.forEach((select) => { select.value = value; });
        this._commit(obj);
    }

    _active() {
        if (!this.hasCanvasEditorOutlet) return null;
        const active = this.canvasEditorOutlet.canvas.getActiveObject();
        return isStylable(active) ? active : null;
    }

    _commit(obj) {
        const canvas = this.canvasEditorOutlet.canvas;
        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        canvas.fire('object:modified', { target: obj });
    }
}

/** One text, image or shape (the background layer included). */
export function isStylable(obj) {
    if (!obj) return false;
    const type = String(obj.type || '').toLowerCase();
    return type === 'textbox' || type === 'image' || isShapeObject(obj);
}

function fillBlendOptions(select) {
    if (select.dataset.filled === '1') return;
    select.dataset.filled = '1';
    select.innerHTML = '';
    BLEND_MODE_GROUPS.forEach((group, index) => {
        if (index > 0) {
            const separator = document.createElement('option');
            separator.disabled = true;
            separator.textContent = '──────────';
            select.appendChild(separator);
        }
        group.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        });
    });
}
