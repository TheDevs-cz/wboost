import { Controller } from "@hotwired/stimulus";
import { Canvas, FabricImage, Rect } from "fabric";

/**
 * Interactive image-fill canvas for the user-fill page (Stage 5 hybrid).
 *
 * The background is the SERVER backdrop (text + variant background, placeholders
 * hidden), exposed by the Live Component in an element this controller reads by
 * id and re-reads on change (text edits). On top, each fillable image slot is a
 * live Fabric object the user can move / resize / rotate within the designer's
 * limits, clipped to the designer's frame. Every change is mirrored into the
 * hidden `images[<uuid>][...]` fields so the plain form POST drives the same
 * server render the API uses — the produced PNG is always authoritative.
 *
 * The placement math is a 1:1 port of the server-side ImagePlacement so the live
 * preview and the export agree pixel-for-pixel: an image is fitted object-contain
 * into the frame (base scale s0 = min(fw/iw, fh/ih)); the user's `scale` multiplies
 * s0, `offsetX/Y` pan from the frame centre (canvas px), `rotation` is degrees.
 */
export default class extends Controller {
    static targets = ["canvas", "wrapper"];
    static values = {
        placeholders: Array,
        width: Number,
        height: Number,
        backdropId: String,
    };

    connect() {
        this.objects = {};
        this.placeholdersById = {};
        (this.placeholdersValue || []).forEach((ph) => { this.placeholdersById[ph.inputId] = ph; });

        this.canvas = new Canvas(this.canvasTarget, {
            width: this.widthValue,
            height: this.heightValue,
            selection: false,
            preserveObjectStacking: true,
        });

        this._fitToWrapper();
        this._boundFit = () => this._fitToWrapper();
        window.addEventListener('resize', this._boundFit);

        this._applyBackdrop();
        this._observeBackdrop();

        (this.placeholdersValue || []).forEach((ph) => this._addStandIn(ph));

        this.canvas.on('object:modified', (event) => this._onObjectModified(event));
    }

    disconnect() {
        if (this._boundFit) window.removeEventListener('resize', this._boundFit);
        if (this._backdropObserver) this._backdropObserver.disconnect();
        if (this.canvas) this.canvas.dispose();
    }

    // --- Rendering / layout -------------------------------------------------

    _fitToWrapper() {
        const wrapper = this.hasWrapperTarget ? this.wrapperTarget : this.canvasTarget.parentElement;
        const available = wrapper ? wrapper.clientWidth : this.widthValue;
        const scale = available > 0 ? Math.min(1, available / this.widthValue) : 1;
        this.canvas.setDimensions({ width: this.widthValue * scale, height: this.heightValue * scale });
        this.canvas.setZoom(scale);
        this.canvas.requestRenderAll();
    }

    _backdropElement() {
        return document.getElementById(this.backdropIdValue);
    }

    _applyBackdrop() {
        const element = this._backdropElement();
        const src = element ? element.getAttribute('data-src') : '';
        if (!src) return;

        FabricImage.fromURL(src, { crossOrigin: 'anonymous' }).then((img) => {
            img.set({ left: 0, top: 0, originX: 'left', originY: 'top', selectable: false, evented: false });
            this.canvas.backgroundImage = img;
            this.canvas.requestRenderAll();
        }).catch(() => {});
    }

    _observeBackdrop() {
        const element = this._backdropElement();
        if (!element) return;
        this._backdropObserver = new MutationObserver(() => this._applyBackdrop());
        this._backdropObserver.observe(element, { attributes: true, attributeFilter: ['data-src'] });
    }

    // --- Placeholder objects ------------------------------------------------

    async _addStandIn(placeholder) {
        if (!placeholder.frame || !placeholder.defaultImageUrl) return;
        try {
            const img = await FabricImage.fromURL(placeholder.defaultImageUrl, { crossOrigin: 'anonymous' });
            const frame = placeholder.frame;
            const naturalWidth = img.width || 1;
            const naturalHeight = img.height || 1;
            img.set({
                originX: 'left', originY: 'top',
                left: frame.x, top: frame.y,
                scaleX: frame.width / naturalWidth,
                scaleY: frame.height / naturalHeight,
                angle: 0,
                selectable: false, evented: false,
            });
            img._placeholderId = placeholder.inputId;
            this._replaceObject(placeholder.inputId, img);
        } catch (error) { /* a missing stand-in just leaves the slot empty */ }
    }

    _replaceObject(inputId, fabricObject) {
        const existing = this.objects[inputId];
        if (existing && existing.object) {
            this.canvas.remove(existing.object);
        }
        this.objects[inputId] = { object: fabricObject };
        if (fabricObject) {
            this.canvas.add(fabricObject);
        }
        this.canvas.requestRenderAll();
    }

    async pickImage(event) {
        const { inputid, imageid, url } = event.params;
        await this._fillPlaceholder(inputid, imageid, url);
    }

    async _fillPlaceholder(inputId, imageId, url) {
        const placeholder = this.placeholdersById[inputId];
        if (!placeholder || !placeholder.frame) return;

        let img;
        try {
            img = await FabricImage.fromURL(url, { crossOrigin: 'anonymous' });
        } catch (error) {
            return;
        }

        const frame = placeholder.frame;
        const naturalWidth = img.width || 1;
        const naturalHeight = img.height || 1;
        const containScale = Math.min(frame.width / naturalWidth, frame.height / naturalHeight) || 1;
        const adjustable = placeholder.allowMove || placeholder.allowResize || placeholder.allowRotate;

        img.set({
            originX: 'center', originY: 'center',
            left: frame.x + frame.width / 2,
            top: frame.y + frame.height / 2,
            scaleX: containScale, scaleY: containScale,
            angle: 0,
            lockMovementX: !placeholder.allowMove,
            lockMovementY: !placeholder.allowMove,
            lockScalingX: !placeholder.allowResize,
            lockScalingY: !placeholder.allowResize,
            lockRotation: !placeholder.allowRotate,
            hasControls: placeholder.allowResize || placeholder.allowRotate,
            selectable: adjustable,
            evented: adjustable,
            clipPath: this._frameClip(frame),
        });
        img._placeholderId = inputId;
        img._containScale = containScale;

        // Scaling must stay UNIFORM: the placement contract (and the hidden
        // form fields) carry a single `scale`, so a distorted preview could
        // never match the server render. `lockUniScaling` was removed in
        // Fabric v6 — hide the middle handles instead (corner drags are
        // uniform by default via the canvas's uniformScaling).
        img.setControlsVisibility({ ml: false, mt: false, mr: false, mb: false });

        this._replaceObject(inputId, img);
        if (adjustable) {
            this.canvas.setActiveObject(img);
        }
        this.canvas.requestRenderAll();

        this._setField(inputId, 'hide', '');
        this._setField(inputId, 'imageId', imageId);
        this._writeTransform(inputId, img, frame);
    }

    _frameClip(frame) {
        return new Rect({
            originX: 'center', originY: 'center',
            left: frame.x + frame.width / 2,
            top: frame.y + frame.height / 2,
            width: frame.width, height: frame.height,
            absolutePositioned: true,
        });
    }

    uploadImage(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (!file) return;

        const inputId = event.params.inputid;
        const uploadUrl = event.params.uploadurl;

        const formData = new FormData();
        formData.append('file', file);

        // With several allowed folders the picker renders a select and the server
        // requires an explicit choice; a single folder resolves server-side.
        const directorySelect = this.element.querySelector(`select[data-upload-directory="${inputId}"]`);
        if (directorySelect && directorySelect.value) {
            formData.append('directoryId', directorySelect.value);
        }

        // Inline busy / success / error feedback (no blocking alert).
        const setStatus = (text, kind) => {
            const status = this.element.querySelector(`[data-upload-status="${inputId}"]`);
            if (!status) return;
            status.textContent = text;
            status.className = 'small mt-1 ' + (kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted');
            status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        };

        input.disabled = true;
        setStatus('Nahrávám…', 'busy');

        fetch(uploadUrl, { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data) => {
                if (data && data.id && data.url) {
                    this._fillPlaceholder(inputId, data.id, data.url);
                    setStatus('Obrázek nahrán a vybrán.', 'ok');
                } else {
                    setStatus('Nahrání obrázku se nepovedlo.', 'error');
                }
            })
            .catch(() => { setStatus('Nahrání obrázku se nepovedlo. Zkuste to znovu.', 'error'); })
            .finally(() => { input.value = ''; input.disabled = false; });
    }

    toggleHide(event) {
        const inputId = event.params.inputid;
        if (event.target.checked) {
            this._replaceObject(inputId, null);
            this._setField(inputId, 'hide', '1');
            this._setField(inputId, 'imageId', '');
        } else {
            this._setField(inputId, 'hide', '');
            const placeholder = this.placeholdersById[inputId];
            if (placeholder) {
                this._addStandIn(placeholder);
            }
        }
    }

    // --- Placement <-> form-field mirroring ---------------------------------

    _onObjectModified(event) {
        const object = event.target;
        if (!object || !object._placeholderId || !object._containScale) return;
        const placeholder = this.placeholdersById[object._placeholderId];
        if (!placeholder || !placeholder.frame) return;
        this._writeTransform(object._placeholderId, object, placeholder.frame);
    }

    _writeTransform(inputId, object, frame) {
        const containScale = object._containScale || 1;
        const center = object.getCenterPoint();
        this._setField(inputId, 'scale', String((object.scaleX || containScale) / containScale));
        this._setField(inputId, 'offsetX', String(center.x - (frame.x + frame.width / 2)));
        this._setField(inputId, 'offsetY', String(center.y - (frame.y + frame.height / 2)));
        this._setField(inputId, 'rotation', String(object.angle || 0));
    }

    _setField(inputId, field, value) {
        const element = this.element.querySelector(`input[data-placeholder="${inputId}"][data-field="${field}"]`);
        if (element) {
            element.value = value;
        }
    }
}
