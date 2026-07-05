import { Controller } from "@hotwired/stimulus";

import { CANVAS_CUSTOM_PROPERTIES } from './canvas_custom_properties.js';

/**
 * Undo/redo stack for the canvas editor. Snapshots full canvas JSON on
 * every modification and restores it via the orchestrator's loader.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static targets = ["undoButton", "redoButton"];

    static values = {
        maxSize: { type: Number, default: 20 },
    };

    canvasEditorOutletConnected(outlet) {
        this.history = [];
        this.redoStack = [];

        const canvas = outlet.canvas;
        this._snapshotIfClean = () => {
            if (!outlet.loadingCanvas) {
                this.addToHistory();
            }
        };

        canvas.on('object:added', this._snapshotIfClean);
        canvas.on('object:modified', this._snapshotIfClean);
        canvas.on('object:removed', this._snapshotIfClean);

        // Seed with the initial state so undo always has something to fall back to.
        this.addToHistory();
    }

    canvasEditorOutletDisconnected(outlet) {
        const canvas = outlet.canvas;
        if (!canvas || !this._snapshotIfClean) {
            return;
        }
        canvas.off('object:added', this._snapshotIfClean);
        canvas.off('object:modified', this._snapshotIfClean);
        canvas.off('object:removed', this._snapshotIfClean);
    }

    addToHistory() {
        if (!this.hasCanvasEditorOutlet) {
            return;
        }
        if (this.history.length >= this.maxSizeValue) {
            this.history.shift();
        }

        const canvas = this.canvasEditorOutlet.canvas;
        const snapshot = canvas.toJSON(CANVAS_CUSTOM_PROPERTIES);
        // Container definitions live on the canvas instance, not in Fabric's
        // serialization — carry a deep copy in every undo state so undo/redo
        // restores them too (loadCanvasWithoutHistory reads .containers).
        snapshot.containers = Array.isArray(canvas.wboostContainers)
            ? JSON.parse(JSON.stringify(canvas.wboostContainers))
            : [];
        this.history.push(snapshot);
        this.redoStack = [];
        this.updateButtonStates();
    }

    undo() {
        if (this.history.length > 1) {
            const currentState = this.history.pop();
            this.redoStack.push(currentState);

            const previousState = this.history[this.history.length - 1];
            // loadCanvasWithoutHistory is async in v7; fire-and-forget — the
            // canvas re-renders inside the function once the JSON resolves.
            // The orchestrator sets loadingCanvas=true while loading, so the
            // event listeners above won't re-snapshot mid-restore.
            this.canvasEditorOutlet.loadCanvasWithoutHistory(previousState);

            this.updateButtonStates();
        }
    }

    redo() {
        if (this.redoStack.length > 0) {
            const nextState = this.redoStack.pop();
            this.history.push(nextState);

            // Async fire-and-forget; see undo().
            this.canvasEditorOutlet.loadCanvasWithoutHistory(nextState);

            this.updateButtonStates();
        }
    }

    updateButtonStates() {
        this._toggleDisabled(this.undoButtonTarget, this.history.length <= 1);
        this._toggleDisabled(this.redoButtonTarget, this.redoStack.length === 0);
    }

    _toggleDisabled(button, disabled) {
        button.classList.toggle('disabled', disabled);
        if (disabled) {
            button.setAttribute('disabled', 'disabled');
        } else {
            button.removeAttribute('disabled');
        }
    }
}
