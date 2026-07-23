import { Controller } from '@hotwired/stimulus';
import { NEUTRAL_PLACEMENT, clamp, frameBox, ghostStyle } from './image_placement.js';

const ZOOM_MIN = 1;
const ZOOM_MAX = 4;
const ROTATION_MIN = -180;
const ROTATION_MAX = 180;
/** Keyboard nudge, as a fraction of the frame (arrow keys on a focused picture). */
const NUDGE_RATIO = 0.01;

/**
 * Group fill & export page: one unified form per group whose values fan out
 * to every member variant. This controller owns the LIVE PREVIEWS — after
 * the user stops typing it POSTs the whole form to each variant's preview
 * endpoint in parallel and swaps the returned PNGs in via blob URLs — plus
 * the image-picker modals and small form UX (Enter must not submit; the
 * submit button shows progress while the browser downloads the ZIP).
 *
 * The element this controller attaches to IS the <form>, so `new
 * FormData(this.element)` always carries the exact state the export POST
 * would send — previews and the ZIP can never disagree.
 *
 * ## Image placement
 *
 * A picture in an adjustable slot is placed by DRAGGING IT IN THE PREVIEW (plus
 * wheel to zoom, arrows to nudge, and the panel's range inputs for precision).
 * Because a server render takes seconds, the drag is shown by a "ghost": a
 * clipped, CSS-transformed copy of the picture drawn over the preview with the
 * exact math the server uses ({@see image_placement.js}), so the ghost and the
 * PNG that replaces it agree pixel-for-pixel. The ghost only lives from the
 * first interaction until that dimension's fresh render lands — the server
 * render is always the truth on screen.
 *
 * Placement is SHARED across dimensions by default (pans travel as a fraction
 * of the frame, so one value lands correctly in every variant's own frame). A
 * dimension can be UNLINKED, after which dragging it writes only its own
 * `imagePlacements[<variantId>][<inputId>]` fields and the shared value stops
 * reaching it.
 */
export default class extends Controller {
    static targets = [
        'preview', 'imageThumb', 'imageValue', 'imageOptions', 'exportButton',
        'placementLayer', 'placementControls', 'placementField', 'overrideField',
        'overrideBar', 'overrideButton', 'zoomRange', 'zoomLabel', 'rotationRange', 'rotationLabel',
    ];

    static values = {
        debounce: { type: Number, default: 900 },
        slots: { type: Array, default: [] },
        variants: { type: Array, default: [] },
    };

    initialize() {
        this.refreshTimer = null;
        this.exportTimer = null;
        // Keyed by preview endpoint URL (one per variant).
        this.aborters = new Map();
        this.objectUrls = new Map();

        // --- Placement state ------------------------------------------------
        // Chosen picture per slot: {imageId, url, natural: {width, height}|null}.
        this.pictures = {};
        // The shared placement per slot, and per-variant overrides once unlinked.
        this.sharedPlacement = {};
        this.overridePlacement = {};
        this.dragState = null;
    }

    connect() {
        this.slotsValue.forEach((slot) => {
            this.sharedPlacement[slot.inputId] = { ...NEUTRAL_PLACEMENT };
        });
        this.renderGhosts();
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
        clearTimeout(this.exportTimer);
        this.aborters.forEach((aborter) => aborter.abort());
        this.aborters.clear();
        this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
        this.objectUrls.clear();
        this._endDrag();
    }

    changed() {
        clearTimeout(this.refreshTimer);
        this.previewTargets.forEach((img) => img.closest('.group-fill-preview-frame')?.classList.add('is-pending'));
        this.refreshTimer = setTimeout(() => this.refreshAll(), this.debounceValue);
    }

    // Enter in a fill field must never trigger the ZIP download — only the
    // explicit export button submits.
    blockEnter(event) {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type === 'text') {
            event.preventDefault();
        }
    }

    pickImage(event) {
        const option = event.currentTarget;

        this.selectImage(
            option.dataset.inputId,
            option.dataset.imageId || '',
            option.dataset.imageUrl || '',
            option,
        );
    }

    // Mirrors the chosen picture into the hidden form field (the value the
    // preview + ZIP export actually run on), the side-panel thumbnail and the
    // picker's selection ring.
    selectImage(inputId, imageId, imageUrl, option) {
        const hiddenField = this.imageValueTargets.find((element) => element.dataset.inputId === inputId);
        if (hiddenField) {
            hiddenField.value = imageId;
        }

        const thumb = this.imageThumbTargets.find((element) => element.dataset.inputId === inputId);
        if (thumb) {
            thumb.style.backgroundImage = imageUrl !== '' ? `url('${imageUrl}')` : 'none';
        }

        const options = this.imageOptionsTargets.find((element) => element.dataset.inputId === inputId);
        if (options) {
            options.querySelectorAll('.group-fill-image-option').forEach((element) => {
                element.classList.toggle('selected', element === option);
            });
        }

        // A new picture starts centred: keeping the previous crop would place a
        // different photo by coordinates that meant something only for the old one.
        this._setPicture(inputId, imageId, imageUrl);

        this.changed();
    }

    /**
     * "Upload your own image" inside a picker modal. The file goes to the
     * group-scoped placeholder upload endpoint (which validates the slot and
     * the target folder server-side), then lands in the project gallery — so
     * it is appended to this picker as a regular option and auto-picked.
     */
    uploadImage(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (!file) {
            return;
        }

        const inputId = event.params.inputid;
        const uploadUrl = event.params.uploadurl;

        const formData = new FormData();
        formData.append('file', file);

        // With several allowed folders the picker renders a select and the
        // server requires an explicit choice; a single target resolves
        // server-side.
        const directorySelect = this.element.querySelector(`select[data-upload-directory="${inputId}"]`);
        if (directorySelect && directorySelect.value) {
            formData.append('directoryId', directorySelect.value);
        }

        input.disabled = true;
        this.setUploadStatus(inputId, 'Nahrávám…', 'busy');

        fetch(uploadUrl, { method: 'POST', body: formData, headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data) => {
                if (!data || !data.id || !data.url) {
                    this.setUploadStatus(inputId, 'Nahrání obrázku se nepovedlo.', 'error');
                    return;
                }

                const option = this.appendImageOption(inputId, data.id, data.url);
                this.selectImage(inputId, data.id, data.url, option);
                this.setUploadStatus(inputId, 'Obrázek nahrán a vybrán.', 'ok');
            })
            .catch(() => {
                this.setUploadStatus(inputId, 'Nahrání obrázku se nepovedlo. Zkuste to znovu.', 'error');
            })
            .finally(() => {
                input.value = '';
                input.disabled = false;
            });
    }

    appendImageOption(inputId, imageId, imageUrl) {
        const options = this.imageOptionsTargets.find((element) => element.dataset.inputId === inputId);
        if (!options) {
            return null;
        }

        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'group-fill-image-option';
        option.dataset.action = 'group-fill#pickImage';
        option.dataset.inputId = inputId;
        option.dataset.imageId = imageId;
        option.dataset.imageUrl = imageUrl;
        option.dataset.bsDismiss = 'modal';

        const img = document.createElement('img');
        img.src = imageUrl;
        img.alt = '';
        img.loading = 'lazy';
        option.appendChild(img);

        options.appendChild(option);

        return option;
    }

    // Inline busy / success / error feedback (no blocking alert).
    setUploadStatus(inputId, text, kind) {
        const status = this.element.querySelector(`[data-upload-status="${inputId}"]`);
        if (!status) {
            return;
        }

        status.textContent = text;
        status.className = `small mt-1 ${kind === 'error' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted'}`;
        status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    }

    // The download itself is handled natively by the browser (Content-
    // Disposition: attachment), which gives no completion event — show
    // progress optimistically and re-enable after a generous window.
    exportStarted() {
        if (!this.hasExportButtonTarget) {
            return;
        }

        const button = this.exportButtonTarget;
        const originalHtml = button.innerHTML;

        setTimeout(() => {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Generuji ZIP…';
        }, 0);

        clearTimeout(this.exportTimer);
        this.exportTimer = setTimeout(() => {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }, 20000);
    }

    refreshAll() {
        const formData = new FormData(this.element);
        this.previewTargets.forEach((img) => this.refreshOne(img, formData));
    }

    async refreshOne(img, formData) {
        const endpoint = img.dataset.previewEndpoint;
        if (!endpoint) {
            return;
        }

        this.aborters.get(endpoint)?.abort();
        const aborter = new AbortController();
        this.aborters.set(endpoint, aborter);

        const frame = img.closest('.group-fill-preview-frame');
        frame?.classList.add('is-loading');
        frame?.classList.remove('is-error');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                signal: aborter.signal,
            });

            if (!response.ok) {
                throw new Error(`Preview render failed with HTTP ${response.status}`);
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);

            const previousUrl = this.objectUrls.get(endpoint);
            if (previousUrl) {
                URL.revokeObjectURL(previousUrl);
            }
            this.objectUrls.set(endpoint, objectUrl);

            img.src = objectUrl;

            // The fresh render now contains the placement the ghost was
            // standing in for — drop the stand-in so the PNG is what's on
            // screen. (Only if nothing was moved while it was rendering.)
            if (this.aborters.get(endpoint) === aborter) {
                this._clearGhosts(img.dataset.variantId);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            frame?.classList.add('is-error');
        } finally {
            // A newer refresh may already own this endpoint — only the
            // latest request clears the loading chrome.
            if (this.aborters.get(endpoint) === aborter) {
                this.aborters.delete(endpoint);
                frame?.classList.remove('is-loading', 'is-pending');
            }
        }
    }

    // ======================================================================
    // Image placement
    // ======================================================================

    /**
     * Record the picture chosen for a slot and reset its placement to neutral
     * (a crop only means something for the photo it was made on). The natural
     * size is read from the decoded image — the ghost math needs it, and it is
     * the same number the server reads from the file.
     */
    _setPicture(inputId, imageId, imageUrl) {
        if (!this._slot(inputId)) {
            return; // not an adjustable slot — nothing to place
        }

        this.sharedPlacement[inputId] = { ...NEUTRAL_PLACEMENT };
        Object.values(this.overridePlacement).forEach((slots) => { delete slots[inputId]; });
        this._writePlacementFields(inputId);

        if (!imageId || !imageUrl) {
            delete this.pictures[inputId];
            this.renderGhosts();
            this._syncPlacementControls();
            return;
        }

        this.pictures[inputId] = { imageId, url: imageUrl, natural: null };
        this._syncPlacementControls();

        const probe = new Image();
        probe.crossOrigin = 'anonymous';
        probe.onload = () => {
            const picture = this.pictures[inputId];
            if (picture && picture.imageId === imageId) {
                picture.natural = { width: probe.naturalWidth || 1, height: probe.naturalHeight || 1 };
                this.renderGhosts();
            }
        };
        probe.src = imageUrl;
    }

    _slot(inputId) {
        return this.slotsValue.find((slot) => slot.inputId === inputId) || null;
    }

    _variant(variantId) {
        return this.variantsValue.find((variant) => variant.variantId === variantId) || null;
    }

    /** The placement a given dimension renders with: its own if unlinked, else the shared one. */
    _effectivePlacement(variantId, inputId) {
        return this.overridePlacement[variantId]?.[inputId] ?? this.sharedPlacement[inputId] ?? { ...NEUTRAL_PLACEMENT };
    }

    _isUnlinked(variantId) {
        return this.overridePlacement[variantId] != null;
    }

    /**
     * Apply a change to whichever placement that dimension is driven by — its
     * own when unlinked, otherwise the shared one (which moves every linked
     * dimension at once).
     */
    _mutatePlacement(variantId, inputId, mutate) {
        const target = this._isUnlinked(variantId)
            ? (this.overridePlacement[variantId][inputId] ??= { ...this._effectivePlacement(variantId, inputId) })
            : (this.sharedPlacement[inputId] ??= { ...NEUTRAL_PLACEMENT });

        mutate(target);

        this._writePlacementFields(inputId);
        this.renderGhosts();
        this._syncPlacementControls();
        this.changed();
    }

    /**
     * Mirror the placement state into the hidden fields — the ONLY thing the
     * preview POST and the ZIP export read. A dimension that follows the shared
     * placement posts empty override fields, which the server reads as "no
     * override" and falls back.
     */
    _writePlacementFields(inputId) {
        const shared = this.sharedPlacement[inputId] ?? NEUTRAL_PLACEMENT;

        this.placementFieldTargets
            .filter((field) => field.dataset.inputId === inputId)
            .forEach((field) => { field.value = this._fieldValue(shared, field.dataset.field); });

        this.overrideFieldTargets
            .filter((field) => field.dataset.inputId === inputId)
            .forEach((field) => {
                const own = this.overridePlacement[field.dataset.variantId]?.[inputId];
                field.value = own ? this._fieldValue(own, field.dataset.field) : '';
            });
    }

    _fieldValue(placement, field) {
        const value = placement[field];

        if (value == null) {
            return '';
        }

        // Round hard enough to keep the POST small, fine enough that a nudge
        // still moves the render (a ratio step of 0.0001 is sub-pixel).
        return String(field === 'rotation' ? Math.round(value) : Math.round(value * 10000) / 10000);
    }

    /** Draw / update the drag stand-ins over every preview that has a picture to place. */
    renderGhosts() {
        this.placementLayerTargets.forEach((layer) => {
            const variantId = layer.dataset.variantId;
            const variant = this._variant(variantId);
            const preview = this.previewTargets.find((img) => img.dataset.variantId === variantId);

            if (!variant || !preview) {
                return;
            }

            const displayWidth = preview.clientWidth;
            const k = displayWidth > 0 && variant.width > 0 ? displayWidth / variant.width : 0;

            this.slotsValue.forEach((slot) => {
                const frame = variant.frames?.[slot.inputId];
                const picture = this.pictures[slot.inputId];
                const existing = layer.querySelector(`[data-ghost="${slot.inputId}"]`);

                // No frame in this dimension, no picture yet, or its size not
                // measured: nothing truthful to draw. The box itself stays as
                // soon as there IS something to place — it is the grab handle,
                // so it must exist BEFORE the first drag; only the picture
                // inside it is revealed (by `.is-active`) once the user starts
                // placing, because until then the server preview is the truth.
                if (!frame || !picture?.natural || k <= 0) {
                    existing?.remove();
                    return;
                }

                const box = existing ?? this._createGhost(layer, slot, variantId);
                const rect = frameBox(frame, k);
                Object.assign(box.style, {
                    left: `${rect.left}px`,
                    top: `${rect.top}px`,
                    width: `${rect.width}px`,
                    height: `${rect.height}px`,
                });

                const img = box.querySelector('img');
                img.src = picture.url;
                Object.assign(img.style, ghostStyle(frame, picture.natural, this._effectivePlacement(variantId, slot.inputId), k));
            });
        });
    }

    _createGhost(layer, slot, variantId) {
        const box = document.createElement('div');
        box.className = 'group-fill-ghost';
        box.dataset.ghost = slot.inputId;
        box.tabIndex = 0;
        box.setAttribute('role', 'application');
        box.setAttribute('aria-label', `${slot.name || 'Obrázek'} — umístění (táhněte myší, šipky posunou, kolečko přiblíží)`);
        box.title = slot.allowMove ? 'Táhněte obrázkem' : 'Kolečkem přiblížíte';

        const img = document.createElement('img');
        img.alt = '';
        img.draggable = false;
        box.appendChild(img);

        box.addEventListener('pointerdown', (event) => this._startDrag(event, variantId, slot));
        box.addEventListener('wheel', (event) => this._wheelZoom(event, variantId, slot), { passive: false });
        box.addEventListener('keydown', (event) => this._nudge(event, variantId, slot));

        layer.appendChild(box);

        return box;
    }

    /**
     * Reveal the stand-ins. They stay up until that dimension's next render
     * lands — before the first interaction the server preview alone is the
     * truth, so there is nothing to stand in for.
     *
     * `variantId === null` means "the shared placement moved": every dimension
     * still following it shows a stand-in, and the unlinked ones — which did
     * NOT move — are deliberately left showing their untouched render.
     */
    _activateGhosts(variantId) {
        this.placementLayerTargets.forEach((layer) => {
            const isTarget = variantId === null
                ? !this._isUnlinked(layer.dataset.variantId)
                : layer.dataset.variantId === variantId;

            if (isTarget) {
                layer.classList.add('is-active');
            }
        });
        this.renderGhosts();
    }

    /**
     * Hand the dimension back to its (now current) server render — never while
     * it is being dragged, or the stand-in would blink out from under the
     * pointer and the drag would appear to reset.
     */
    _clearGhosts(variantId) {
        if (this.dragState?.variantId === variantId) {
            return;
        }

        this.placementLayerTargets
            .filter((layer) => layer.dataset.variantId === variantId)
            .forEach((layer) => layer.classList.remove('is-active'));
    }

    // --- Direct manipulation ------------------------------------------------

    _startDrag(event, variantId, slot) {
        if (!slot.allowMove || event.button !== 0) {
            return;
        }

        const variant = this._variant(variantId);
        const frame = variant?.frames?.[slot.inputId];
        const preview = this.previewTargets.find((img) => img.dataset.variantId === variantId);
        if (!frame || !preview || preview.clientWidth <= 0) {
            return;
        }

        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);

        const k = preview.clientWidth / variant.width;
        const start = this._effectivePlacement(variantId, slot.inputId);

        this.dragState = {
            variantId,
            inputId: slot.inputId,
            pointerId: event.pointerId,
            element: event.currentTarget,
            startX: event.clientX,
            startY: event.clientY,
            // Drag deltas are display px; the stored pan is a fraction of the
            // frame, so one drag reads the same in every dimension.
            perPxX: 1 / (frame.width * k),
            perPxY: 1 / (frame.height * k),
            baseX: start.offsetXRatio ?? 0,
            baseY: start.offsetYRatio ?? 0,
        };

        this._boundDragMove ??= (moveEvent) => this._dragMove(moveEvent);
        this._boundDragEnd ??= () => this._endDrag();
        window.addEventListener('pointermove', this._boundDragMove);
        window.addEventListener('pointerup', this._boundDragEnd);
        window.addEventListener('pointercancel', this._boundDragEnd);

        this._activateGhosts(variantId);
    }

    _dragMove(event) {
        const drag = this.dragState;
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }

        const offsetXRatio = drag.baseX + (event.clientX - drag.startX) * drag.perPxX;
        const offsetYRatio = drag.baseY + (event.clientY - drag.startY) * drag.perPxY;

        this._mutatePlacement(drag.variantId, drag.inputId, (placement) => {
            placement.offsetXRatio = offsetXRatio;
            placement.offsetYRatio = offsetYRatio;
        });
    }

    _endDrag() {
        if (this._boundDragMove) {
            window.removeEventListener('pointermove', this._boundDragMove);
            window.removeEventListener('pointerup', this._boundDragEnd);
            window.removeEventListener('pointercancel', this._boundDragEnd);
        }

        const drag = this.dragState;
        drag?.element?.releasePointerCapture?.(drag.pointerId);
        this.dragState = null;
    }

    _wheelZoom(event, variantId, slot) {
        if (!slot.allowResize) {
            return;
        }

        event.preventDefault();
        this._activateGhosts(variantId);

        // Trackpad pinch arrives as ctrl+wheel with much finer deltas.
        const step = event.ctrlKey ? 0.01 : 0.05;
        const direction = event.deltaY < 0 ? 1 : -1;

        this._mutatePlacement(variantId, slot.inputId, (placement) => {
            placement.scale = clamp((placement.scale ?? 1) + direction * step, ZOOM_MIN, ZOOM_MAX);
        });
    }

    _nudge(event, variantId, slot) {
        const deltas = {
            ArrowLeft: [-NUDGE_RATIO, 0],
            ArrowRight: [NUDGE_RATIO, 0],
            ArrowUp: [0, -NUDGE_RATIO],
            ArrowDown: [0, NUDGE_RATIO],
        };
        const delta = deltas[event.key];

        if (!delta || !slot.allowMove) {
            return;
        }

        event.preventDefault();
        this._activateGhosts(variantId);
        this._mutatePlacement(variantId, slot.inputId, (placement) => {
            placement.offsetXRatio = (placement.offsetXRatio ?? 0) + delta[0];
            placement.offsetYRatio = (placement.offsetYRatio ?? 0) + delta[1];
        });
    }

    // --- Panel controls (shared placement) ----------------------------------

    zoomChanged(event) {
        const inputId = event.target.dataset.inputId;
        this.sharedPlacement[inputId] = {
            ...(this.sharedPlacement[inputId] ?? NEUTRAL_PLACEMENT),
            scale: clamp(parseFloat(event.target.value) || 1, ZOOM_MIN, ZOOM_MAX),
        };
        this._afterSharedChange(inputId);
    }

    rotationChanged(event) {
        const inputId = event.target.dataset.inputId;
        this.sharedPlacement[inputId] = {
            ...(this.sharedPlacement[inputId] ?? NEUTRAL_PLACEMENT),
            rotation: clamp(parseInt(event.target.value, 10) || 0, ROTATION_MIN, ROTATION_MAX),
        };
        this._afterSharedChange(inputId);
    }

    resetPlacement(event) {
        const inputId = event.params.inputid;
        this.sharedPlacement[inputId] = { ...NEUTRAL_PLACEMENT };
        this._afterSharedChange(inputId);
    }

    _afterSharedChange(inputId) {
        this._writePlacementFields(inputId);
        // Every dimension still following the shared placement moves at once.
        this._activateGhosts(null);
        this._syncPlacementControls();
        this.changed();
    }

    /**
     * Unlink / relink one dimension. Unlinking seeds the dimension's own
     * placement from what it currently shows, so nothing moves at the moment of
     * unlinking; relinking drops it back onto the shared placement.
     */
    toggleOverride(event) {
        const variantId = event.params.variantid;

        if (this._isUnlinked(variantId)) {
            delete this.overridePlacement[variantId];
        } else {
            const own = {};
            this.slotsValue.forEach((slot) => {
                own[slot.inputId] = { ...this._effectivePlacement(variantId, slot.inputId) };
            });
            this.overridePlacement[variantId] = own;
        }

        this.slotsValue.forEach((slot) => this._writePlacementFields(slot.inputId));
        this._activateGhosts(variantId);
        this._syncPlacementControls();
        this.changed();
    }

    /**
     * Keep the chrome truthful: the placement panel only makes sense once a
     * picture is chosen, the ranges mirror the shared placement, and each
     * dimension's chip states whether it follows it.
     */
    _syncPlacementControls() {
        const anyPicture = Object.keys(this.pictures).length > 0;

        this.placementControlsTargets.forEach((panel) => {
            panel.classList.toggle('d-none', !this.pictures[panel.dataset.inputId]);
        });

        this.overrideBarTargets.forEach((bar) => bar.classList.toggle('d-none', !anyPicture));

        this.zoomRangeTargets.forEach((range) => {
            const scale = this.sharedPlacement[range.dataset.inputId]?.scale ?? 1;
            range.value = String(scale);
            const label = this.zoomLabelTargets.find((element) => element.dataset.inputId === range.dataset.inputId);
            if (label) {
                label.textContent = `${Math.round(scale * 100)} %`;
            }
        });

        this.rotationRangeTargets.forEach((range) => {
            const rotation = this.sharedPlacement[range.dataset.inputId]?.rotation ?? 0;
            range.value = String(rotation);
            const label = this.rotationLabelTargets.find((element) => element.dataset.inputId === range.dataset.inputId);
            if (label) {
                label.textContent = `${Math.round(rotation)}°`;
            }
        });

        this.overrideButtonTargets.forEach((button) => {
            const unlinked = this._isUnlinked(button.dataset.variantId);
            button.innerHTML = unlinked
                ? '<i class="mdi mdi-link-variant-off"></i> Vlastní umístění — zpět na společné'
                : '<i class="mdi mdi-link-variant"></i> Umístění podle společného';
            button.classList.toggle('text-warning', unlinked);
        });
    }
}
