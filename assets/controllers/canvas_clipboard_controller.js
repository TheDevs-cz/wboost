import { Controller } from "@hotwired/stimulus";
import { ActiveSelection } from "fabric";

import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';

/**
 * Copy / paste / duplicate for the canvas editor. The orchestrator dispatches
 * `canvas-editor:copy` / `canvas-editor:paste` window events when the user
 * presses Cmd/Ctrl+C / Cmd/Ctrl+V; toolbar buttons call duplicate() directly.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["duplicateButton"];

    connect() {
        this.clipboard = null;
    }

    canvasEditorOutletConnected(outlet) {
        this.updateButton(outlet.canvas.getActiveObject());
    }

    onSelectionChanged(event) {
        this.updateButton(event.detail.activeObject);
    }

    async copy() {
        if (!this.hasCanvasEditorOutlet) {
            return;
        }
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject) {
            return;
        }
        // Fabric v7: clone() returns a Promise and respects custom property
        // whitelist directly — no more extendToObject/restoreToObject hack.
        this.clipboard = await activeObject.clone(CANVAS_CUSTOM_PROPERTIES);
    }

    async paste() {
        if (!this.clipboard || !this.hasCanvasEditorOutlet) {
            return;
        }
        const canvas = this.canvasEditorOutlet.canvas;

        // Clone the clipboard object so successive pastes produce independent
        // copies. v7 clone respects the custom-property whitelist natively.
        const clonedObj = await this.clipboard.clone(CANVAS_CUSTOM_PROPERTIES);

        canvas.discardActiveObject();

        clonedObj.set({
            left: clonedObj.left + 10,
            top: clonedObj.top + 10,
            evented: true,
        });

        if (clonedObj instanceof ActiveSelection) {
            clonedObj.canvas = canvas;
            clonedObj.forEachObject((obj) => {
                // Always overwrite inputId on paste to avoid id collisions.
                obj.inputId = crypto.randomUUID();
                canvas.add(obj);
            });
            clonedObj.setCoords();
        } else {
            // Always overwrite inputId on paste to avoid id collisions.
            clonedObj.inputId = crypto.randomUUID();
            canvas.add(clonedObj);
        }

        canvas.setActiveObject(clonedObj);
        canvas.requestRenderAll();
    }

    duplicate() {
        // copy() is async and stores into this.clipboard; await it before paste
        // so paste() sees the freshly-cloned object.
        this.copy().then(() => this.paste());
    }

    updateButton(activeObject) {
        if (!this.hasDuplicateButtonTarget) {
            return;
        }
        const disabled = !activeObject;
        this.duplicateButtonTarget.classList.toggle('disabled', disabled);
        if (disabled) {
            this.duplicateButtonTarget.setAttribute('disabled', 'disabled');
        } else {
            this.duplicateButtonTarget.removeAttribute('disabled');
        }
    }
}
