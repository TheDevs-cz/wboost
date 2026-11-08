import { Controller } from "@hotwired/stimulus";
import { isShapeObject } from "./canvas_shapes.js";

/**
 * Admin-set OPACITY (0–100 %) for texts, images (placeholders included) and —
 * through the multi-select bar — any mix of elements incl. shapes. Shapes keep
 * their own slider in the shape popover (canvas-shape-properties); this
 * controller owns the text popover, the image popover and the multi bar.
 *
 * The carrier is Fabric's NATIVE `opacity` (0…1), so it serializes into the
 * canvas JSON with no custom property and every render surface — the headless
 * render template, the fill page's echo canvas (enlivened verbatim), the
 * interactive image-fill canvas (via the placeholder payload) — paints it
 * with no further teaching. It is a DESIGN property: nothing on the fill page
 * or in the API can override it.
 *
 * Never offered for the background layer (`isBackground`), and the multi bar
 * skips a background inside a selection. In a multi-selection the value is
 * written to each MEMBER — never to the ActiveSelection itself, whose own
 * opacity would only tint the transient group and never reach the JSON.
 *
 * Undo: the slider's `input` events repaint live; only `change` (release)
 * fires the synthetic `object:modified` that pushes one history snapshot.
 *
 * Targets (each may appear once per surface): `row` (hidden when nothing
 * eligible is selected), `slider` (range 0–100), `value` (the "NN %" label).
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["row", "slider", "value"];

    canvasEditorOutletConnected(outlet) {
        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    updateFromSelection(event) {
        const active = event && event.detail ? event.detail.activeObject : null;
        const objects = eligibleObjects(active);
        const eligible = objects.length > 0;

        this.rowTargets.forEach((row) => row.classList.toggle('d-none', !eligible));
        if (!eligible) return;

        const percents = objects.map(opacityPercent);
        const mixed = percents.some((percent) => percent !== percents[0]);
        this._show(percents[0], mixed);
    }

    update(event) {
        if (!this.hasCanvasEditorOutlet) return;
        const canvas = this.canvasEditorOutlet.canvas;
        const active = canvas.getActiveObject();
        const objects = eligibleObjects(active);
        if (objects.length === 0) return;

        const percent = Math.min(100, Math.max(0, Math.round(Number(event.target.value) || 0)));
        objects.forEach((obj) => obj.set('opacity', percent / 100));
        this._show(percent, false);

        canvas.requestRenderAll();
        this.canvasEditorOutlet.markUnsaved();
        if (event.type === 'change') {
            canvas.fire('object:modified', { target: active });
        }
    }

    _show(percent, mixed) {
        this.sliderTargets.forEach((slider) => { slider.value = String(percent); });
        this.valueTargets.forEach((label) => { label.textContent = mixed ? 'různá' : `${percent} %`; });
    }
}

/**
 * The objects an opacity edit applies to: the single active text / image, or
 * every non-background member of an ActiveSelection (shapes included — the
 * multi bar is the one place a shape's opacity is set alongside others).
 */
function eligibleObjects(active) {
    if (!active) return [];
    const type = (active.type || '').toLowerCase();
    if (type === 'activeselection') {
        return (typeof active.getObjects === 'function' ? active.getObjects() : [])
            .filter((obj) => obj.isBackground !== true && (isTextOrImage(obj) || isShapeObject(obj)));
    }
    return isTextOrImage(active) && active.isBackground !== true ? [active] : [];
}

function isTextOrImage(obj) {
    const type = (obj.type || '').toLowerCase();
    return type === 'textbox' || type === 'image';
}

function opacityPercent(obj) {
    return Math.round((typeof obj.opacity === 'number' ? obj.opacity : 1) * 100);
}
