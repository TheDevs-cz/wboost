import { Controller } from "@hotwired/stimulus";
import { ActiveSelection } from "fabric";

/**
 * Object alignment + z-order + delete buttons. All these buttons enable
 * iff there's an active selection on the canvas; alignment ops require an
 * `activeselection` (multi-select) specifically.
 *
 * Fabric v7 gotchas this controller works around:
 *   - `obj.type` is reported LOWERCASED ('activeselection', not the v5
 *     'activeSelection') — compare case-insensitively.
 *   - The ActiveSelection wrapper is NOT in the canvas objects array, so
 *     collection ops must target `canvas.getActiveObjects()`: `remove(sel)`
 *     silently no-ops and `bringObjectToFront(sel)` would PUSH the wrapper
 *     into the objects array (it would then serialize into the save).
 *   - Member left/top are RELATIVE to the selection transform while grouped —
 *     measure/move in absolute coordinates after discarding the selection.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = [
        "bringToFrontButton", "sendToBackButton", "deleteObjectButton",
        "alignLeftButton", "alignRightButton", "alignCenterButton",
        "alignTopButton", "alignBottomButton", "alignMiddleButton",
    ];

    canvasEditorOutletConnected(outlet) {
        this.updateButtonStates(outlet.canvas.getActiveObject());
    }

    onSelectionChanged(event) {
        this.updateButtonStates(event.detail.activeObject);
    }

    bringToFront() {
        this._restack('front');
    }

    sendToBack() {
        this._restack('back');
    }

    /**
     * Restack the selected object(s). Always operates on the underlying
     * objects (getActiveObjects), never on the ActiveSelection wrapper —
     * see the class docblock. Relative order inside the moved block is kept.
     */
    _restack(where) {
        const canvas = this.canvasEditorOutlet.canvas;
        const objects = canvas.getActiveObjects();
        if (!objects.length) {
            return;
        }

        const stack = canvas.getObjects();
        const byStack = [...objects].sort((a, b) => stack.indexOf(a) - stack.indexOf(b));
        if (where === 'front') {
            byStack.forEach((obj) => canvas.bringObjectToFront(obj));
        } else {
            byStack.reverse().forEach((obj) => canvas.sendObjectToBack(obj));
        }

        canvas.discardActiveObject();
        canvas.renderAll();
        // Restacking fires no Fabric events — announce it so the form is
        // marked dirty and the undo stack picks the change up.
        canvas.fire('object:modified', {});
    }

    deleteObject() {
        const canvas = this.canvasEditorOutlet.canvas;
        const objects = canvas.getActiveObjects();
        if (objects.length) {
            // Discard FIRST (while the objects are still active) so
            // selection:cleared fires and the floating chrome hides; then
            // remove the real objects (not the ActiveSelection wrapper).
            canvas.discardActiveObject();
            canvas.remove(...objects);
            canvas.renderAll();
        }
    }

    alignLeft()   { this._align('left'); }
    alignCenter() { this._align('center'); }
    alignRight()  { this._align('right'); }
    alignTop()    { this._align('top'); }
    alignMiddle() { this._align('middle'); }
    alignBottom() { this._align('bottom'); }

    _align(alignment) {
        const canvas = this.canvasEditorOutlet.canvas;
        const activeObject = canvas.getActiveObject();
        // Case-insensitive: v7 reports 'activeselection' — the old camelCase
        // comparison never matched, which made every align a silent no-op.
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'activeselection') {
            return;
        }

        const objects = activeObject.getObjects();
        if (objects.length < 2) {
            return;
        }

        // Leave the selection so member coordinates are absolute again (the
        // same convention canvas_container_controller uses), measure + move
        // in canvas space, then re-select below so align clicks can be chained.
        canvas.discardActiveObject();
        objects.forEach((obj) => obj.setCoords());
        const positions = objects.map(obj => obj.getBoundingRect());

        if (alignment === 'left' || alignment === 'right' || alignment === 'center') {
            let positionValue;
            if (alignment === 'left') {
                positionValue = Math.min(...positions.map(pos => pos.left));
            } else if (alignment === 'right') {
                positionValue = Math.max(...positions.map(pos => pos.left + pos.width));
            } else {
                const minLeft = Math.min(...positions.map(pos => pos.left));
                const maxRight = Math.max(...positions.map(pos => pos.left + pos.width));
                positionValue = (minLeft + maxRight) / 2;
            }

            objects.forEach(obj => {
                const boundingRect = obj.getBoundingRect();
                let deltaX;
                if (alignment === 'left') {
                    deltaX = positionValue - boundingRect.left;
                } else if (alignment === 'right') {
                    deltaX = positionValue - (boundingRect.left + boundingRect.width);
                } else {
                    deltaX = positionValue - (boundingRect.left + boundingRect.width / 2);
                }
                obj.left += deltaX;
                obj.setCoords();
            });
        } else {
            let positionValue;
            if (alignment === 'top') {
                positionValue = Math.min(...positions.map(pos => pos.top));
            } else if (alignment === 'bottom') {
                positionValue = Math.max(...positions.map(pos => pos.top + pos.height));
            } else {
                const minTop = Math.min(...positions.map(pos => pos.top));
                const maxBottom = Math.max(...positions.map(pos => pos.top + pos.height));
                positionValue = (minTop + maxBottom) / 2;
            }

            objects.forEach(obj => {
                const boundingRect = obj.getBoundingRect();
                let deltaY;
                if (alignment === 'top') {
                    deltaY = positionValue - boundingRect.top;
                } else if (alignment === 'bottom') {
                    deltaY = positionValue - (boundingRect.top + boundingRect.height);
                } else {
                    deltaY = positionValue - (boundingRect.top + boundingRect.height / 2);
                }
                obj.top += deltaY;
                obj.setCoords();
            });
        }

        const selection = new ActiveSelection(objects, { canvas });
        canvas.setActiveObject(selection);
        canvas.requestRenderAll();

        // Announce the change: marks the form dirty (orchestrator), pushes an
        // undo snapshot (history controller), and container members re-derive
        // their design geometry from the new positions.
        canvas.fire('object:modified', {});
        // setActiveObject() fires no Fabric selection events — rebroadcast so
        // the floating multi-bar re-anchors to the fresh selection bounds.
        this.canvasEditorOutlet.dispatchSelectionChanged();
    }

    updateButtonStates(activeObject) {
        const disabled = !activeObject;

        // Built only from targets that actually exist. The buttons now live in
        // the floating mini-toolbar / multi-select bar, which are themselves
        // shown only when there's an active object — so most setups carry no
        // targets here and this safely no-ops. The guards keep it from throwing
        // "Missing target element" on any singular getter.
        const buttons = [
            "bringToFrontButton", "sendToBackButton", "deleteObjectButton",
            "alignLeftButton", "alignRightButton", "alignCenterButton",
            "alignTopButton", "alignBottomButton", "alignMiddleButton",
        ]
            .filter((name) => this[`has${name.charAt(0).toUpperCase()}${name.slice(1)}Target`])
            .map((name) => this[`${name}Target`]);

        buttons.forEach(button => {
            button.classList.toggle('disabled', disabled);
            if (disabled) {
                button.setAttribute('disabled', 'disabled');
            } else {
                button.removeAttribute('disabled');
            }
        });
    }
}
