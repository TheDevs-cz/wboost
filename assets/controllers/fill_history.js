/**
 * Undo / redo for the fill pages (single variant, group, document) — the
 * user's FILL STATE as history steps, per page session (nothing persists).
 *
 * The state is whatever the page says it is (`readState()`): the overlay
 * reads every NAMED control of the fill form (text / font / hide / shape
 * mirrors, and anything a future feature adds as a form field) plus the
 * image controller's own picture + placement state. Steps are compared as
 * JSON strings, so a new kind of field is captured without touching this
 * module.
 *
 * Coalescing: any edit signal (`input` / `change` on the form, the image
 * controllers' bubbling events) re-arms an idle timer; the step is taken
 * when the user has been idle for `idleMs` — a typed word, a slider slide or
 * a run of arrow-key nudges is ONE step. While a pointer is down (a picture
 * drag, a handle, a slider thumb) no step is taken; releasing it takes one
 * shortly after, so a drag is always exactly one step. Leaving a field
 * (focusout) or pressing Zpět / Znovu commits a pending step first.
 *
 * Restoring goes through `applyState()` — the page writes the fields and
 * dispatches the same `input` / `change` events a user edit does, so the
 * Live component / group renderer, the text echo and the settle hash all
 * see an ordinary edit. The restored state then becomes the baseline
 * as READ BACK (never as stored), so a restore can never create a phantom
 * step of its own.
 *
 * Shortcuts: ⌘/Ctrl+Z, ⌘/Ctrl+Shift+Z and Ctrl+Y — but NOT while focus is in
 * a text field or a WYSIWYG, where the native / editor text undo owns them.
 */

const EDIT_EVENTS = [
    "input",
    "change",
    "variant-image-fill:frame-changed",
    "variant-image-fill:picked",
    "fill-history:touch",
];

const TEXT_INPUT_TYPES = new Set([
    "text", "search", "email", "url", "tel", "password", "number",
    "date", "datetime-local", "month", "time", "week",
]);

/** Does this element own ⌘Z itself (native text undo / the WYSIWYG's stack)? */
export function ownsTextUndo(element) {
    if (!element || element === document.body) return false;
    if (element.isContentEditable) return true;
    if (element.tagName === "TEXTAREA" || element.tagName === "SELECT") return true;
    if (element.tagName === "INPUT") return TEXT_INPUT_TYPES.has((element.type || "text").toLowerCase());
    return false;
}

export class FillHistory {
    /**
     * @param {object} options
     * @param {HTMLElement} options.root       the fill form (edit events bubble here)
     * @param {() => object} options.readState
     * @param {(state: object) => (Promise|void)} options.applyState
     * @param {(info: {canUndo: boolean, canRedo: boolean}) => void} options.onChange
     * @param {number} [options.idleMs]
     * @param {number} [options.limit]
     */
    constructor({ root, readState, applyState, onChange, idleMs = 900, limit = 100 }) {
        this.root = root;
        this.readState = readState;
        this.applyState = applyState;
        this.onChange = onChange || (() => {});
        this.idleMs = idleMs;
        this.limit = limit;

        this._undo = [];
        this._redo = [];
        this._current = null;
        this._timer = null;
        this._pointerDown = false;
        this._busy = false;
        this._queue = Promise.resolve();

        this._onEdit = () => this.touch();
        this._onFocusOut = () => this.touch(0);
        this._onPointerDown = () => { this._pointerDown = true; };
        this._onPointerUp = () => {
            if (!this._pointerDown) return;
            this._pointerDown = false;
            this.touch(250);
        };
        this._onKeydown = (event) => this._keydown(event);

        EDIT_EVENTS.forEach((type) => root.addEventListener(type, this._onEdit));
        root.addEventListener("focusout", this._onFocusOut);
        document.addEventListener("pointerdown", this._onPointerDown, true);
        document.addEventListener("pointerup", this._onPointerUp, true);
        document.addEventListener("pointercancel", this._onPointerUp, true);
        document.addEventListener("keydown", this._onKeydown);

        // Baseline after the sibling controllers connected (the group page
        // writes its placement fields, seeds restore pictures, …).
        this._startTimer = setTimeout(() => this._baseline(), 0);
    }

    destroy() {
        clearTimeout(this._timer);
        clearTimeout(this._startTimer);
        EDIT_EVENTS.forEach((type) => this.root.removeEventListener(type, this._onEdit));
        this.root.removeEventListener("focusout", this._onFocusOut);
        document.removeEventListener("pointerdown", this._onPointerDown, true);
        document.removeEventListener("pointerup", this._onPointerUp, true);
        document.removeEventListener("pointercancel", this._onPointerUp, true);
        document.removeEventListener("keydown", this._onKeydown);
    }

    get canUndo() {
        // A pending (not yet committed) edit counts — cheap on purpose: no
        // state read per keystroke; a no-op edit disables it again on commit.
        return this._undo.length > 0 || this._timer !== null;
    }

    get canRedo() {
        return this._redo.length > 0;
    }

    /** An edit happened: (re)arm the idle timer that takes the step. */
    touch(delay = this.idleMs) {
        if (this._busy || this._current === null) return;
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this.commit(), delay);
        // Buttons light up as soon as there is something to undo.
        this._notify();
    }

    /** Take a step now when the state moved since the last one. */
    commit() {
        clearTimeout(this._timer);
        this._timer = null;
        if (this._busy || this._current === null) return;
        if (this._pointerDown) {
            // Mid-drag: the step lands when the pointer is released.
            return;
        }
        const now = this._read();
        if (now === this._current) {
            this._notify();
            return;
        }
        this._undo.push(this._current);
        if (this._undo.length > this.limit) this._undo.shift();
        this._redo = [];
        this._current = now;
        this._notify();
    }

    undo() {
        return this._enqueue(() => {
            this._pointerDown = false;
            this.commit();
            if (this._undo.length === 0) return null;
            const target = this._undo.pop();
            this._redo.push(this._current);
            return target;
        });
    }

    redo() {
        return this._enqueue(() => {
            this._pointerDown = false;
            this.commit();
            if (this._redo.length === 0) return null;
            const target = this._redo.pop();
            this._undo.push(this._current);
            return target;
        });
    }

    // --- internals --------------------------------------------------------

    _baseline() {
        this._current = this._read();
        this._notify();
    }

    _read() {
        return JSON.stringify(this.readState());
    }

    /** Restores run strictly one after another (an image restore awaits a load). */
    _enqueue(pick) {
        this._queue = this._queue.then(async () => {
            if (this._current === null) this._baseline();
            const target = pick();
            if (target === null) return;
            this._busy = true;
            try {
                await this.applyState(JSON.parse(target));
            } finally {
                this._busy = false;
                clearTimeout(this._timer);
                this._timer = null;
                // Baseline = the state as it READS now (a picture that failed
                // to load, a normalized value) — never a phantom step.
                this._current = this._read();
                this._notify();
            }
        }).catch(() => {});
        return this._queue;
    }

    _keydown(event) {
        if (event.defaultPrevented || event.altKey || !(event.metaKey || event.ctrlKey)) return;
        const key = (event.key || "").toLowerCase();
        const isUndo = key === "z" && !event.shiftKey;
        const isRedo = (key === "z" && event.shiftKey) || (key === "y" && event.ctrlKey && !event.metaKey);
        if (!isUndo && !isRedo) return;
        if (ownsTextUndo(event.target) || ownsTextUndo(document.activeElement)) return;
        // A Bootstrap modal (the group page's picker) is a different context.
        if (document.querySelector(".modal.show")) return;
        event.preventDefault();
        if (isUndo) this.undo(); else this.redo();
    }

    _notify() {
        this.onChange({ canUndo: this.canUndo, canRedo: this.canRedo });
    }
}
