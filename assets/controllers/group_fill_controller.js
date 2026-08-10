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
 * The PICTURE is picked once for the whole group; PLACEMENT is always per
 * dimension. A crop that works in 4:5 rarely works in 9:16, so there is no
 * shared placement to unlink from — every dimension owns
 * `imagePlacements[<variantId>][<inputId>]` from the first render, and only
 * the dimension you touched re-renders.
 *
 * Two surfaces edit the same values: dragging the picture in a preview, and a
 * row per dimension under that picture in the left panel (zoom / rotation /
 * centre). Pans are stored as a FRACTION of the frame, the one form that
 * survives a preview being displayed at an arbitrary size.
 */
export default class extends Controller {
    static targets = [
        'preview', 'imageThumb', 'imageValue', 'imageOptions', 'exportButton',
        'placementLayer', 'placementControls', 'overrideField',
        'variantZoomRange', 'variantZoomLabel', 'variantRotationRange', 'variantRotationLabel',
    ];

    static values = {
        debounce: { type: Number, default: 900 },
        slots: { type: Array, default: [] },
        variants: { type: Array, default: [] },
    };

    initialize() {
        this.refreshTimer = null;
        this.exportTimer = null;
        this.exportButtonHtml = null;
        // Which dimension the debounced refresh is queued for: undefined = none
        // queued, null = all of them, otherwise one variantId.
        this._pendingScope = undefined;
        // Keyed by preview endpoint URL (one per variant).
        this.aborters = new Map();
        this.objectUrls = new Map();

        // --- Placement state ------------------------------------------------
        // Chosen picture per slot: {imageId, url, natural: {width, height}|null}.
        this.pictures = {};
        // Placement per DIMENSION per slot — `placement[variantId][inputId]`.
        // There is no shared placement: a crop that works in 4:5 rarely works
        // in 9:16, so every dimension owns its own from the first render.
        this.placement = {};
        this.dragState = null;
    }

    connect() {
        this.variantsValue.forEach((variant) => {
            this.placement[variant.variantId] = {};
            this.slotsValue.forEach((slot) => {
                this.placement[variant.variantId][slot.inputId] = { ...NEUTRAL_PLACEMENT };
            });
        });
        this.slotsValue.forEach((slot) => this._writePlacementFields(slot.inputId));
        this.renderGhosts();

        // Coming BACK to this page (the export failed and the user pressed
        // Back) restores the DOM exactly as it was left — mid-export, i.e. with
        // a disabled button spinning "Generuji ZIP…". Reset it, or the retry
        // they came back for looks impossible.
        this._onPageShow = (event) => event.persisted && this.exportFinished();
        window.addEventListener('pageshow', this._onPageShow);
    }

    disconnect() {
        window.removeEventListener('pageshow', this._onPageShow);
        clearTimeout(this.refreshTimer);
        clearTimeout(this.exportTimer);
        this.aborters.forEach((aborter) => aborter.abort());
        this.aborters.clear();
        this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
        this.objectUrls.clear();
        this._endDrag();
    }

    /** A text / picture / hide change — it lands in every dimension. */
    changed() {
        this._scheduleRefresh(null);
    }

    /**
     * Only ONE dimension's pixels can have changed (a per-dimension placement
     * edit on an unlinked dimension). Every render is a Gotenberg call that
     * takes seconds, so re-rendering the others would spin a spinner over
     * previews that are already correct and burn the renderer's capacity —
     * which is a hard dependency of this page, not a background job.
     */
    changedFor(variantId) {
        this._scheduleRefresh(variantId);
    }

    _scheduleRefresh(variantId) {
        clearTimeout(this.refreshTimer);

        // Coalescing across the debounce window: a pending refresh for a
        // DIFFERENT scope widens to all. Narrowing instead would leave the
        // other previews marked pending with pixels nobody is going to redraw.
        const scope = this._pendingScope === undefined || this._pendingScope === variantId
            ? variantId
            : null;
        this._pendingScope = scope;

        const previews = scope === null
            ? this.previewTargets
            : this.previewTargets.filter((img) => img.dataset.variantId === scope);

        previews.forEach((img) => img.closest('.group-fill-preview-frame')?.classList.add('is-pending'));
        this.refreshTimer = setTimeout(() => {
            this._pendingScope = undefined;
            this.refreshAll(previews);
        }, this.debounceValue);
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

    // Uploading into a picker modal is owned by the shared fill-gallery
    // controller (folder navigation + dropzone); its freshly inserted thumbs
    // carry this controller's regular pickImage wiring, so a single-file
    // upload auto-picks by clicking its own thumb.

    // The download itself is handled natively by the browser (Content-
    // Disposition: attachment), which gives no completion event — show
    // progress optimistically and re-enable after a generous window.
    exportStarted(event) {
        // A per-variant PNG button submits the SAME form through its
        // formaction — the busy state belongs to the clicked button, not to
        // the ZIP button (whose label must not flip to "Generuji ZIP…").
        // Both mutations are deferred a tick so the form submission leaves
        // with the submitter still enabled (a disabled submitter is dropped
        // from the POST and its formaction would be ignored).
        const submitter = event ? event.submitter : null;
        if (submitter && submitter.hasAttribute('data-group-fill-variant-download')) {
            const originalHtml = submitter.innerHTML;
            setTimeout(() => {
                submitter.disabled = true;
                submitter.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
            }, 0);
            setTimeout(() => {
                submitter.disabled = false;
                submitter.innerHTML = originalHtml;
            }, 20000);
            return;
        }

        if (!this.hasExportButtonTarget) {
            return;
        }

        const button = this.exportButtonTarget;
        this.exportButtonHtml ??= button.innerHTML;

        setTimeout(() => {
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Generuji ZIP…';
        }, 0);

        clearTimeout(this.exportTimer);
        this.exportTimer = setTimeout(() => this.exportFinished(), 20000);
    }

    exportFinished() {
        if (!this.hasExportButtonTarget || this.exportButtonHtml === null) {
            return;
        }

        clearTimeout(this.exportTimer);
        this.exportButtonTarget.disabled = false;
        this.exportButtonTarget.innerHTML = this.exportButtonHtml;
    }

    refreshAll(previews = null) {
        const formData = new FormData(this.element);
        (previews ?? this.previewTargets).forEach((img) => this.refreshOne(img, formData));
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

        Object.values(this.placement).forEach((slots) => { slots[inputId] = { ...NEUTRAL_PLACEMENT }; });
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

    /** What this dimension renders that slot with. */
    _effectivePlacement(variantId, inputId) {
        return this.placement[variantId]?.[inputId] ?? { ...NEUTRAL_PLACEMENT };
    }

    /**
     * Edit one dimension's placement for one slot — the only kind there is, so
     * only that dimension can have changed and only it needs re-rendering.
     */
    _mutatePlacement(variantId, inputId, mutate) {
        const slots = (this.placement[variantId] ??= {});
        mutate(slots[inputId] ??= { ...NEUTRAL_PLACEMENT });

        this._writePlacementFields(inputId);
        this.renderGhosts();
        this._syncPlacementControls();
        this.changedFor(variantId);
    }

    /**
     * Mirror the placement state into the hidden `imagePlacements[...]` fields
     * — the ONLY thing the preview POST and the ZIP export read. Every
     * dimension always posts its own, so the server's shared-placement
     * fallback simply never comes into play here.
     */
    _writePlacementFields(inputId) {
        this.overrideFieldTargets
            .filter((field) => field.dataset.inputId === inputId)
            .forEach((field) => {
                const own = this._effectivePlacement(field.dataset.variantId, inputId);
                field.value = this._fieldValue(own, field.dataset.field);
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
     * Only ever ONE dimension: placement is per-dimension, so no edit can move
     * a picture in a preview the user was not working in.
     */
    _activateGhosts(variantId) {
        this.placementLayerTargets
            .filter((layer) => layer.dataset.variantId === variantId)
            .forEach((layer) => layer.classList.add('is-active'));
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

    // --- Per-dimension controls -------------------------------------------
    //
    // One row per dimension under each picture in the left panel. Everything
    // about how a picture SITS is per dimension — only the picture itself is
    // picked once for the whole group.

    variantZoomChanged(event) {
        this._mutateVariantPlacement(event.target, (placement) => {
            placement.scale = clamp(parseFloat(event.target.value) || 1, ZOOM_MIN, ZOOM_MAX);
        });
    }

    variantRotationChanged(event) {
        this._mutateVariantPlacement(event.target, (placement) => {
            placement.rotation = clamp(parseInt(event.target.value, 10) || 0, ROTATION_MIN, ROTATION_MAX);
        });
    }

    resetVariantPlacement(event) {
        const { variantid: variantId, inputid: inputId } = event.params;
        this._mutatePlacement(variantId, inputId, (placement) => {
            Object.assign(placement, NEUTRAL_PLACEMENT);
        });
        this._activateGhosts(variantId);
    }

    _mutateVariantPlacement(control, mutate) {
        const { variantId, inputId } = control.dataset;
        this._mutatePlacement(variantId, inputId, mutate);
        this._activateGhosts(variantId);
    }

    /**
     * Keep the chrome truthful: a slot's placement rows only make sense once a
     * picture is chosen, and every range mirrors what its dimension currently
     * renders with.
     */
    _syncPlacementControls() {
        this.placementControlsTargets.forEach((panel) => {
            panel.classList.toggle('d-none', !this.pictures[panel.dataset.inputId]);
        });

        const mirror = (ranges, labels, key, format) => {
            ranges.forEach((range) => {
                const { variantId, inputId } = range.dataset;
                const value = this._effectivePlacement(variantId, inputId)[key] ?? (key === 'scale' ? 1 : 0);
                range.value = String(value);
                const label = labels.find(
                    (element) => element.dataset.variantId === variantId && element.dataset.inputId === inputId,
                );
                if (label) {
                    label.textContent = format(value);
                }
            });
        };

        mirror(this.variantZoomRangeTargets, this.variantZoomLabelTargets, 'scale', (v) => `${Math.round(v * 100)} %`);
        mirror(this.variantRotationRangeTargets, this.variantRotationLabelTargets, 'rotation', (v) => `${Math.round(v)}°`);
    }
}
