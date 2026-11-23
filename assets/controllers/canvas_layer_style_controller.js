import { Controller } from "@hotwired/stimulus";
import { isShapeObject } from "./canvas_shapes.js";
import { BLEND_MODE_GROUPS, NORMAL_BLEND_MODE, blendModeOf, isKnownBlendMode } from "./canvas_blend_modes.js";

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
 * Undo: a select change is one discrete edit → one synthetic
 * `object:modified` = one history snapshot.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["section", "blend"];

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
