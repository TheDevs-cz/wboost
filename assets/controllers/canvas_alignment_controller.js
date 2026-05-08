import { Controller } from "@hotwired/stimulus";

/**
 * Object alignment + z-order + delete buttons. All these buttons enable
 * iff there's an active selection on the canvas; alignment ops require an
 * `activeSelection` (multi-select) specifically.
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
        const canvas = this.canvasEditorOutlet.canvas;
        const activeObject = canvas.getActiveObject();
        if (activeObject) {
            // Fabric v7: stacking order methods moved to the canvas.
            canvas.bringObjectToFront(activeObject);
            canvas.discardActiveObject();
            canvas.renderAll();
        }
    }

    sendToBack() {
        const canvas = this.canvasEditorOutlet.canvas;
        const activeObject = canvas.getActiveObject();
        if (activeObject) {
            // Fabric v7: stacking order methods moved to the canvas.
            canvas.sendObjectToBack(activeObject);
            canvas.discardActiveObject();
            canvas.renderAll();
        }
    }

    deleteObject() {
        const canvas = this.canvasEditorOutlet.canvas;
        const activeObject = canvas.getActiveObject();
        if (activeObject) {
            canvas.remove(activeObject);
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
        if (!activeObject || activeObject.type !== 'activeSelection') {
            return;
        }

        const objects = activeObject.getObjects();
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

        canvas.requestRenderAll();
    }

    updateButtonStates(activeObject) {
        const disabled = !activeObject;
        const buttons = [
            this.bringToFrontButtonTarget,
            this.sendToBackButtonTarget,
            this.deleteObjectButtonTarget,
            this.alignLeftButtonTarget,
            this.alignRightButtonTarget,
            this.alignCenterButtonTarget,
            this.alignTopButtonTarget,
            this.alignBottomButtonTarget,
            this.alignMiddleButtonTarget,
        ];

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
