import { Controller } from '@hotwired/stimulus';

/**
 * Hex colour picker — the replacement for every native `<input type="color">`
 * in the admin canvas editor. Safari's native picker has no hex field, so a
 * designer could not type/paste a brand colour; this one works the same in
 * every browser and never touches `type="color"`.
 *
 * Contract (keeps the existing handlers untouched): the controller sits on an
 * `<input type="button">` (or `<button>` with a `value`) whose `value` is the
 * current `#rrggbb`. Opening shows ONE shared popover (appended to <body>,
 * `position: fixed`, viewport-clamped, so it is never clipped by the element
 * popover it opens from). While the user drags / types it writes the trigger's
 * `value` and dispatches a bubbling `input` event (rAF-throttled) — exactly
 * what the native picker did, so `input->…` actions preview live. Closing by
 * outside click / Enter / the "Hotovo" button dispatches `change` once when
 * the colour differs from the one it opened with (the undo step); Escape
 * reverts to that colour instead. Programmatic `trigger.value = '#…'` is how
 * the owning controllers sync it — for an input of type button that reflects
 * to the `value` attribute, which the controller observes to repaint.
 *
 * Values:
 *   paint     paint the trigger itself with the colour (gradient stops); off for
 *             the rainbow "custom colour" chips, which keep their ring.
 *   swatches  quick-pick colours shown in the popover; empty → the editor's
 *             project palette (`data-canvas-editor-brand-colors-value` on an
 *             ancestor), so the palette stays the colour source of truth.
 */
export default class extends Controller {
    static values = {
        paint: { type: Boolean, default: false },
        swatches: { type: Array, default: [] },
    };

    connect() {
        this._onClick = (event) => {
            event.preventDefault();
            event.stopPropagation();
            picker().toggle(this);
        };
        this.element.addEventListener('click', this._onClick);
        this.element.setAttribute('aria-haspopup', 'dialog');
        this.element.setAttribute('aria-expanded', 'false');
        this._observer = new MutationObserver(() => this._repaint());
        this._observer.observe(this.element, { attributes: true, attributeFilter: ['value'] });
        this._repaint();
    }

    disconnect() {
        this.element.removeEventListener('click', this._onClick);
        if (this._observer) this._observer.disconnect();
        if (activePicker && activePicker.owner === this) activePicker.close({ commit: true, refocus: false });
    }

    get color() {
        return parseHex(this.element.value) || '#000000';
    }

    paletteColors() {
        const own = this.swatchesValue.map(parseHex).filter(Boolean);
        if (own.length > 0) return unique(own);
        const host = this.element.closest('[data-canvas-editor-brand-colors-value]');
        if (!host) return [];
        try {
            const list = JSON.parse(host.getAttribute('data-canvas-editor-brand-colors-value') || '[]');
            return Array.isArray(list) ? unique(list.map(parseHex).filter(Boolean)) : [];
        } catch {
            return [];
        }
    }

    /** Called by the popover: write the colour + announce it like the native input. */
    emit(color, type) {
        this.element.value = color;
        this._repaint();
        this.element.dispatchEvent(new Event(type, { bubbles: true }));
    }

    _repaint() {
        const color = parseHex(this.element.value);
        if (this.paintValue) {
            this.element.style.setProperty('--color-picker-swatch', color || 'transparent');
        }
        this.element.title = color ? `${this.element.getAttribute('aria-label') || 'Barva'}: ${color.toUpperCase()}` : (this.element.title || '');
    }
}

// --- colour maths -------------------------------------------------------------

/** `#abc`, `abc`, `#aabbcc`, `AABBCC` (surrounding spaces ok) → `#aabbcc`; else null. */
export function parseHex(value) {
    if (typeof value !== 'string') return null;
    let hex = value.trim().replace(/^#/, '');
    if (/^[0-9a-f]{3}$/i.test(hex)) hex = hex.split('').map((c) => c + c).join('');
    return /^[0-9a-f]{6}$/i.test(hex) ? `#${hex.toLowerCase()}` : null;
}

function unique(list) {
    return [...new Set(list)];
}

function hexToHsv(hex) {
    const n = parseInt(hex.slice(1), 16);
    const r = ((n >> 16) & 255) / 255;
    const g = ((n >> 8) & 255) / 255;
    const b = (n & 255) / 255;
    const max = Math.max(r, g, b);
    const d = max - Math.min(r, g, b);
    let h = 0;
    if (d !== 0) {
        if (max === r) h = ((g - b) / d) % 6;
        else if (max === g) h = (b - r) / d + 2;
        else h = (r - g) / d + 4;
        h *= 60;
        if (h < 0) h += 360;
    }
    return { h, s: max === 0 ? 0 : d / max, v: max };
}

function hsvToHex({ h, s, v }) {
    const f = (n) => {
        const k = (n + h / 60) % 6;
        const c = v - v * s * Math.max(0, Math.min(k, 4 - k, 1));
        return Math.round(c * 255).toString(16).padStart(2, '0');
    };
    return `#${f(5)}${f(3)}${f(1)}`;
}

/** The current selection when it is a real range inside a contenteditable. */
function editableSelection() {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return null;
    const range = selection.getRangeAt(0);
    const node = range.commonAncestorContainer;
    const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
    return element && element.isContentEditable ? range.cloneRange() : null;
}

function restoreSelection(range) {
    if (!range || !range.startContainer.isConnected) return;
    const selection = window.getSelection();
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(range);
}

const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

// --- the shared popover ---------------------------------------------------------

let activePicker = null;

function picker() {
    if (!activePicker) activePicker = new ColorPickerPopover();
    return activePicker;
}

class ColorPickerPopover {
    constructor() {
        this.owner = null;
        this.hsv = { h: 0, s: 0, v: 0 };
        this.el = this._build();
        this._frame = 0;
        this._onOutside = (event) => {
            if (!this.owner) return;
            const trigger = this.owner.element;
            const label = trigger.closest('label');
            if (this.el.contains(event.target) || trigger.contains(event.target) || (label && label.contains(event.target))) return;
            this.close({ commit: true, refocus: false });
        };
        this._onViewport = () => { if (this.owner) this._position(); };
    }

    toggle(owner) {
        if (this.owner === owner) {
            this.close({ commit: true, refocus: true });
            return;
        }
        if (this.owner) this.close({ commit: true, refocus: false });
        this.open(owner);
    }

    open(owner) {
        // Inside a Bootstrap modal (the "Vzorový text" WYSIWYG) the popover
        // must live IN the modal — its focus trap would yank focus out of the
        // hex field otherwise. `.modal` is itself fixed and untransformed, so
        // fixed coordinates stay viewport-relative there.
        const host = owner.element.closest('.modal') || document.body;
        if (this.el.parentElement !== host) host.append(this.el);
        this.owner = owner;
        // A text selection inside a WYSIWYG (rich_text_editor) is what a
        // `change` colours — but focusing the hex field moves the document
        // selection into it. Remember it now, put it back before `change`.
        this.savedRange = editableSelection();
        this.original = owner.color;
        this.current = this.original;
        this.hsv = hexToHsv(this.original);
        this._renderSwatches(owner.paletteColors());
        this._setHexField(this.original, { valid: true });
        this._render();
        this.el.hidden = false;
        owner.element.setAttribute('aria-expanded', 'true');
        this._position();
        document.addEventListener('pointerdown', this._onOutside, true);
        window.addEventListener('resize', this._onViewport);
        window.addEventListener('scroll', this._onViewport, true);
        this.hexInput.focus({ preventScroll: true });
        this.hexInput.select();
    }

    /** commit=true lands one `change` (if the colour moved); false reverts. */
    close({ commit = true, refocus = true } = {}) {
        const owner = this.owner;
        if (!owner) return;
        this._flush();
        if (commit) {
            // A half-typed but valid hex counts (blur would have applied it).
            const typed = parseHex(this.hexInput.value);
            if (typed && typed !== this.current) {
                this.current = typed;
                owner.emit(typed, 'input');
            }
            if (this.current !== this.original) {
                restoreSelection(this.savedRange);
                owner.emit(this.current, 'change');
            }
        } else if (this.current !== this.original) {
            owner.emit(this.original, 'input');
        }
        this.owner = null;
        this.savedRange = null;
        const hadFocus = this.el.contains(document.activeElement);
        this.el.hidden = true;
        owner.element.setAttribute('aria-expanded', 'false');
        document.removeEventListener('pointerdown', this._onOutside, true);
        window.removeEventListener('resize', this._onViewport);
        window.removeEventListener('scroll', this._onViewport, true);
        if (refocus && hadFocus && owner.element.isConnected) owner.element.focus({ preventScroll: true });
    }

    // --- building -----------------------------------------------------------

    _build() {
        const el = document.createElement('div');
        el.className = 'color-picker-pop';
        el.hidden = true;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-label', 'Výběr barvy');
        el.innerHTML = `
            <div class="color-picker-pop__sv" tabindex="0" role="slider" aria-label="Sytost a jas" aria-valuemin="0" aria-valuemax="100">
                <span class="color-picker-pop__sv-thumb"></span>
            </div>
            <div class="color-picker-pop__hue" tabindex="0" role="slider" aria-label="Odstín" aria-valuemin="0" aria-valuemax="360">
                <span class="color-picker-pop__hue-thumb"></span>
            </div>
            <div class="color-picker-pop__row">
                <span class="color-picker-pop__preview" aria-hidden="true"></span>
                <label class="color-picker-pop__hex-label">
                    <span>HEX</span>
                    <input type="text" class="form-control form-control-sm color-picker-pop__hex" maxlength="7" spellcheck="false" autocomplete="off" autocapitalize="off" aria-label="Barva v HEX (#RRGGBB)" placeholder="#RRGGBB">
                </label>
                <button type="button" class="btn btn-sm btn-primary color-picker-pop__done">Hotovo</button>
            </div>
            <p class="color-picker-pop__error" hidden>Zadejte barvu jako #RRGGBB (nebo #RGB).</p>
            <div class="color-picker-pop__swatches" role="group" aria-label="Barvy z palety projektu" hidden></div>
        `;
        this.sv = el.querySelector('.color-picker-pop__sv');
        this.svThumb = el.querySelector('.color-picker-pop__sv-thumb');
        this.hue = el.querySelector('.color-picker-pop__hue');
        this.hueThumb = el.querySelector('.color-picker-pop__hue-thumb');
        this.preview = el.querySelector('.color-picker-pop__preview');
        this.hexInput = el.querySelector('.color-picker-pop__hex');
        this.error = el.querySelector('.color-picker-pop__error');
        this.swatches = el.querySelector('.color-picker-pop__swatches');

        // Keys and clicks stay inside: the editor's window shortcuts (Delete,
        // arrows, ⌘Z) and the element popover's Escape / outside-click
        // handlers must not see what happens in here.
        el.addEventListener('keydown', (event) => this._onKeydown(event));
        el.addEventListener('click', (event) => event.stopPropagation());
        el.addEventListener('mousedown', (event) => event.stopPropagation());

        this._bindDrag(this.sv, (x, y) => { this.hsv.s = x; this.hsv.v = 1 - y; });
        this._bindDrag(this.hue, (x) => { this.hsv.h = x * 360; });

        this.hexInput.addEventListener('input', () => {
            const color = parseHex(this.hexInput.value);
            if (!color) return;  // still typing — no error flash, nothing applied
            this._setError(false);
            this._apply(color, { syncField: false });
        });
        this.hexInput.addEventListener('blur', () => this._commitHexField());
        el.querySelector('.color-picker-pop__done').addEventListener('click', () => this.close({ commit: true }));
        this.swatches.addEventListener('click', (event) => {
            const button = event.target.closest('[data-color]');
            if (button) this._apply(button.dataset.color);
        });
        return el;
    }

    _renderSwatches(colors) {
        this.swatches.replaceChildren(...colors.map((color) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'color-picker-pop__swatch';
            button.dataset.color = color;
            button.style.setProperty('--color-picker-swatch', color);
            button.title = color.toUpperCase();
            button.setAttribute('aria-label', color.toUpperCase());
            return button;
        }));
        this.swatches.hidden = colors.length === 0;
    }

    _bindDrag(area, update) {
        area.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            area.focus({ preventScroll: true });
            area.setPointerCapture(event.pointerId);
            const move = (e) => {
                const rect = area.getBoundingClientRect();
                update(
                    clamp((e.clientX - rect.left) / rect.width, 0, 1),
                    clamp((e.clientY - rect.top) / rect.height, 0, 1),
                );
                this._fromHsv();
            };
            const up = () => {
                area.removeEventListener('pointermove', move);
                area.removeEventListener('pointerup', up);
                area.removeEventListener('pointercancel', up);
            };
            area.addEventListener('pointermove', move);
            area.addEventListener('pointerup', up);
            area.addEventListener('pointercancel', up);
            move(event);
        });
    }

    // --- state --------------------------------------------------------------

    _fromHsv() {
        this.current = hsvToHex(this.hsv);
        this._setHexField(this.current, { valid: true });
        this._render();
        this._schedule();
    }

    _apply(color, { syncField = true } = {}) {
        this.current = color;
        // Greys carry no hue — keep the slider where it was instead of
        // snapping it to red.
        const hsv = hexToHsv(color);
        this.hsv = hsv.s === 0 || hsv.v === 0 ? { ...hsv, h: this.hsv.h } : hsv;
        if (syncField) this._setHexField(color, { valid: true });
        this._render();
        this._schedule();
    }

    _commitHexField() {
        if (!this.owner) return;
        const color = parseHex(this.hexInput.value);
        if (!color) {
            this._setError(true);
            return;
        }
        this._setError(false);
        if (color !== this.current) this._apply(color);
        this.hexInput.value = color.toUpperCase();
    }

    _setHexField(color, { valid }) {
        this.hexInput.value = color.toUpperCase();
        this._setError(!valid);
    }

    _setError(on) {
        this.hexInput.classList.toggle('is-invalid', on);
        this.hexInput.setAttribute('aria-invalid', on ? 'true' : 'false');
        this.error.hidden = !on;
    }

    /** Live preview, at most once per frame (dragging fires far faster). */
    _schedule() {
        if (this._frame) return;
        this._frame = requestAnimationFrame(() => {
            this._frame = 0;
            this._emitLive();
        });
    }

    _flush() {
        if (!this._frame) return;
        cancelAnimationFrame(this._frame);
        this._frame = 0;
        this._emitLive();
    }

    _emitLive() {
        if (this.owner && this.owner.element.value !== this.current) this.owner.emit(this.current, 'input');
    }

    _render() {
        const { h, s, v } = this.hsv;
        this.sv.style.setProperty('--color-picker-hue', `hsl(${Math.round(h)} 100% 50%)`);
        this.svThumb.style.left = `${s * 100}%`;
        this.svThumb.style.top = `${(1 - v) * 100}%`;
        this.hueThumb.style.left = `${(h / 360) * 100}%`;
        this.el.style.setProperty('--color-picker-current', this.current);
        this.sv.setAttribute('aria-valuenow', String(Math.round(s * 100)));
        this.sv.setAttribute('aria-valuetext', `sytost ${Math.round(s * 100)} %, jas ${Math.round(v * 100)} %`);
        this.hue.setAttribute('aria-valuenow', String(Math.round(h)));
        this.swatches.querySelectorAll('[data-color]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.color === this.current);
        });
    }

    _position() {
        const trigger = this.owner.element.getBoundingClientRect();
        const pop = this.el.getBoundingClientRect();
        const margin = 8;
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        let top = trigger.bottom + 6;
        if (top + pop.height > vh - margin && trigger.top - 6 - pop.height >= margin) {
            top = trigger.top - 6 - pop.height;
        }
        top = clamp(top, margin, Math.max(margin, vh - pop.height - margin));
        const left = clamp(trigger.left, margin, Math.max(margin, vw - pop.width - margin));
        this.el.style.top = `${Math.round(top)}px`;
        this.el.style.left = `${Math.round(left)}px`;
    }

    // --- keyboard -----------------------------------------------------------

    _onKeydown(event) {
        event.stopPropagation();
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close({ commit: false });
            return;
        }
        if (event.target === this.hexInput) {
            if (event.key === 'Enter') {
                event.preventDefault();
                const color = parseHex(this.hexInput.value);
                if (!color) { this._setError(true); return; }
                this._commitHexField();
                this.close({ commit: true });
            }
            return;
        }
        if (event.key === 'Enter' && (event.target === this.sv || event.target === this.hue)) {
            event.preventDefault();
            this.close({ commit: true });
            return;
        }
        const step = event.shiftKey ? 10 : 1;
        const dx = { ArrowLeft: -step, ArrowRight: step }[event.key] || 0;
        const dy = { ArrowUp: -step, ArrowDown: step }[event.key] || 0;
        if (!dx && !dy) return;
        if (event.target === this.sv) {
            event.preventDefault();
            this.hsv.s = clamp(this.hsv.s + dx / 100, 0, 1);
            this.hsv.v = clamp(this.hsv.v - dy / 100, 0, 1);
            this._fromHsv();
        } else if (event.target === this.hue) {
            event.preventDefault();
            this.hsv.h = clamp(this.hsv.h + (dx || -dy), 0, 360);
            this._fromHsv();
        }
    }
}
