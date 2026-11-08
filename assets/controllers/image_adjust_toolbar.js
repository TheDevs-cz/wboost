import {
    ADJUST_SCALE_MIN,
    adjustScaleMax,
    clamp,
    coverRelativeScale,
    offsetForX,
    offsetForY,
    placementToBox,
    roundTo,
    scaleForHeight,
    scaleForWidth,
    unitDecimals,
} from './image_placement.js';

/**
 * Behaviour of one `_fill_image_adjust.html.twig` toolbar — shared by the
 * single fill page (variant-image-fill, Fabric) and the group / document
 * pages (group-fill, ghosts). Plain class, no Stimulus: each controller owns
 * WHERE the toolbar sits and what a placement change does; this only maps
 * the controls onto ONE placement in canvas terms
 *
 *   { scale, offsetX, offsetY, rotation }   (scale × contain, centre pan px)
 *
 * and back. `getState()` returns that placement plus the slot's `frame` and
 * the picture's `natural` size; `apply(patch, { final })` receives the
 * changed fields (`final` = a committed value — slider release, typed
 * number, button — vs. a live slider drag).
 *
 * Typed numbers commit on change / Enter (Enter must never submit the fill
 * form — that is the PNG download); a focused field is never overwritten by
 * a sync, so typing is not fought by the live preview.
 */
export class ImageAdjustToolbar {
    constructor(element, { unit, getState, apply, close }) {
        this.element = element;
        this.unit = unit || 'px';
        this.getState = getState;
        this.applyPatch = apply;
        this.close = close;
        this.decimals = unitDecimals(this.unit);

        this._onInput = (event) => this._handleInput(event, false);
        this._onChange = (event) => this._handleInput(event, true);
        this._onClick = (event) => this._handleClick(event);
        this._onKeydown = (event) => this._handleKeydown(event);
        element.addEventListener('input', this._onInput);
        element.addEventListener('change', this._onChange);
        element.addEventListener('click', this._onClick);
        element.addEventListener('keydown', this._onKeydown);
    }

    destroy() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onChange);
        this.element.removeEventListener('click', this._onClick);
        this.element.removeEventListener('keydown', this._onKeydown);
    }

    control(name) {
        return this.element.querySelector(`[data-adjust="${name}"]`);
    }

    /** First focusable control — what keyboard activation lands on. */
    focusFirst() {
        const target = this.element.querySelector('input, button:not(.fill-adjust__close)');
        target?.focus({ preventScroll: true });
    }

    /** Mirror the current placement into every control (skips the focused one). */
    sync() {
        const state = this.getState();
        if (!state || !state.frame || !state.natural) return;

        const scaleRange = this.control('scale');
        if (scaleRange) {
            const max = Math.round(adjustScaleMax(state.frame, state.natural) * 100);
            if (Number(scaleRange.max) !== max) scaleRange.max = String(max);
            const percent = Math.round(state.scale * 100);
            if (document.activeElement !== scaleRange) scaleRange.value = String(percent);
            scaleRange.setAttribute('aria-valuetext', `${percent} %`);
            const output = this.control('scale-value');
            if (output) output.textContent = `${percent} %`;
        }

        const rotationRange = this.control('rotation');
        if (rotationRange) {
            const degrees = Math.round(state.rotation || 0);
            if (document.activeElement !== rotationRange) rotationRange.value = String(degrees);
            rotationRange.setAttribute('aria-valuetext', `${degrees}°`);
            const output = this.control('rotation-value');
            if (output) output.textContent = `${degrees}°`;
        }

        const box = placementToBox(state.frame, state.natural, state.scale, state.offsetX, state.offsetY, this.unit);
        ['width', 'height', 'x', 'y'].forEach((key) => {
            const field = this.control(key);
            if (!field || document.activeElement === field) return;
            field.value = String(roundTo(box[key], this.decimals));
        });
    }

    _number(field) {
        // Czech keyboards type a decimal comma; <input type=number> may hand
        // it over as '' — read the raw value too.
        const raw = String(field.value ?? '').replace(',', '.').trim();
        const value = parseFloat(raw);

        return Number.isFinite(value) ? value : null;
    }

    _handleInput(event, committed) {
        const field = event.target.closest?.('[data-adjust]');
        if (!field) return;
        const name = field.dataset.adjust;
        const state = this.getState();
        if (!state || !state.frame || !state.natural) return;

        if (name === 'scale') {
            const value = this._number(field);
            if (value === null) return;
            const max = adjustScaleMax(state.frame, state.natural);
            this.applyPatch({ scale: clamp(value / 100, ADJUST_SCALE_MIN, max) }, { final: committed });
            this.sync();
            return;
        }
        if (name === 'rotation') {
            const value = this._number(field);
            if (value === null) return;
            this.applyPatch({ rotation: clamp(value, -180, 180) }, { final: committed });
            this.sync();
            return;
        }

        // Typed numbers apply on commit only (change / Enter): a half-typed
        // "1" on the way to "120" must not flash a tiny picture.
        if (!committed) return;
        const value = this._number(field);
        if (value === null) {
            this.sync();
            return;
        }
        this._applyTyped(name, value, state);
    }

    _applyTyped(name, value, state) {
        const max = adjustScaleMax(state.frame, state.natural);
        if (name === 'width' || name === 'height') {
            if (!(value > 0)) {
                this.sync();
                return;
            }
            const scale = name === 'width'
                ? scaleForWidth(state.frame, state.natural, value, this.unit)
                : scaleForHeight(state.frame, state.natural, value, this.unit);
            this.applyPatch({ scale: clamp(scale, ADJUST_SCALE_MIN, max) }, { final: true });
        } else if (name === 'x') {
            this.applyPatch({ offsetX: offsetForX(state.frame, state.natural, state.scale, value, this.unit) }, { final: true });
        } else if (name === 'y') {
            this.applyPatch({ offsetY: offsetForY(state.frame, state.natural, state.scale, value, this.unit) }, { final: true });
        } else {
            return;
        }
        this.sync();
    }

    _handleClick(event) {
        const button = event.target.closest?.('[data-adjust-action]');
        if (!button) return;
        event.preventDefault();
        const action = button.dataset.adjustAction;
        if (action === 'close') {
            this.close?.();
            return;
        }
        const state = this.getState();
        if (!state || !state.frame || !state.natural) return;

        if (action === 'cover') {
            this.applyPatch({ scale: coverRelativeScale(state.frame, state.natural), ...this._centred(state) }, { final: true });
        } else if (action === 'contain') {
            this.applyPatch({ scale: 1, ...this._centred(state) }, { final: true });
        } else if (action === 'center') {
            this.applyPatch({ offsetX: 0, offsetY: 0 }, { final: true });
        }
        this.sync();
    }

    /** Fill / fit recentre too — a covered picture is only gap-free centred.
     *  Slots that forbid moving are always centred anyway (no offset field). */
    _centred(state) {
        return state.allowMove === false ? {} : { offsetX: 0, offsetY: 0 };
    }

    _handleKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close?.({ restoreFocus: true });
            return;
        }
        if (event.key === 'Enter') {
            const field = event.target.closest?.('input[data-adjust]');
            if (!field) return;
            // Never submit the fill form (= the PNG download).
            event.preventDefault();
            const value = this._number(field);
            const state = this.getState();
            if (value !== null && state) this._applyTyped(field.dataset.adjust, value, state);
        }
    }
}
