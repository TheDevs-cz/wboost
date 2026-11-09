import { pxPerUnit, unitDecimals } from "./image_placement.js";

/**
 * "Uživatel může posunout" — the fill page's MOVE TOOL (axis-move plan M3).
 *
 * Owned by variant_fill_overlay_controller (one instance per fill form, only
 * when a surface carries `data-movable` = FillMovableElements rows). Kept as
 * its own module so the overlay only wires hooks (`host`) — the group /
 * document pages (M4) and the container-row controls (P4) live next to it
 * without touching the gesture code.
 *
 * STATE. The element offsets are FILL STATE in the two Live-bound mirrors per
 * element (`[data-offset-mirror="<id>"][data-offset-axis="x|y"]`, canvas px,
 * 0 = designed position). This module writes them ONCE per drag (on
 * release), per typed value and per arrow-key / stepper nudge — never per
 * pointermove, which would fire a Live request per pixel. While a drag runs
 * the position lives in `draft` and every reader (the overlay's boxes and
 * container reflow, the text echo) uses offsets() = mirrors + draft.
 *
 * GESTURES (Jan 2026-11-09):
 *  - press-and-drag the ✥ tool: the element moves along the ALLOWED axes
 *    only, clamped to the server's bounds (FillMovableElements → the
 *    lenient resolver's numbers), magnetic to its designed spot within
 *    6 screen px; a dashed range band shows where it may go (asymmetric per
 *    direction) and flashes when the drag hits a limit;
 *  - click (≤ 3 px of travel) / Enter on the tool selects the element —
 *    "move mode": the box itself becomes draggable, arrow keys nudge it by
 *    1 canvas px (Shift = 10), and a chip shows "Poloha" + "Vrátit";
 *    Esc / Hotovo / a press elsewhere leaves it;
 *  - "Poloha" rows (popover / sheet head / "Všechna pole" / the chip):
 *    exact values in the variant's unit, ± steppers with long-press repeat.
 *
 * LIVE FEEDBACK. An echo-capable text (or a container whose every member is
 * one) repaints live through the text echo (`fill-move:draft` event). Every
 * other kind gets its box outline + a see-through GHOST — a crop of the
 * current preview pixels at its pre-drag place — following the pointer; the
 * ghost stays at the dropped place until the matching settle render lands
 * (clearGhosts(), called by the overlay's settle hook).
 */

const DRAG_SLOP = 3;          // px of travel before a press becomes a drag
const MAGNET = 6;             // screen px around the designed spot that snap to it
const REPEAT_DELAY = 400;     // stepper long-press: first repeat after …
const REPEAT_EVERY = 70;      // … then every … ms
const GHOST_TTL = 20000;      // never let a ghost outlive a lost settle

function round2(value) {
    if (typeof value !== "number" || !Number.isFinite(value)) return 0;
    const rounded = Math.floor(Math.abs(value) * 100 + 0.5) / 100;
    if (rounded === 0) return 0;
    return value < 0 ? -rounded : rounded;
}

function numeric(raw) {
    const text = String(raw ?? "").trim().replace(",", ".");
    if (text === "") return 0;
    const value = Number(text);
    return Number.isFinite(value) ? value : 0;
}

function isEditable(node) {
    if (!node || node.nodeType !== 1) return false;
    if (node.isContentEditable) return true;
    const tag = node.tagName;
    if (tag === "TEXTAREA" || tag === "SELECT") return true;
    if (tag !== "INPUT") return false;
    const type = (node.type || "text").toLowerCase();
    return !["button", "checkbox", "radio", "range", "submit", "reset", "color", "file", "image"].includes(type);
}

export class FillElementMove {
    /**
     * @param {object} options
     * @param {HTMLElement} options.root  the fill form
     * @param {string} options.unit       the variant's unit (px | mm | cm)
     * @param {object} options.host       the overlay's hooks:
     *   surfaces()             → [{ el, movable: {id: row}, boxes, canvasWidth, preview }]
     *   scaleFor(surface)      → unscaled stage px per canvas px
     *   zoom()                 → the stage zoom (screen px per stage px)
     *   frameOf(id, surface)   → the element's LIVE frame (offset included) | null
     *   isHidden(id)           → the element is hidden (eye / container hide)
     *   changed(row, {draft})  → offsets changed: re-layout the boxes (+ echo / spinner)
     */
    constructor({ root, unit, host }) {
        this.root = root;
        this.unit = unit || "px";
        this.host = host;
        this.draft = null;
        this.activeId = null;
        this._press = null;
        this._ghosts = new Map();
        this._band = null;
        this._origin = null;
        this._readout = null;
        this._limitTimer = null;
        this._repeat = null;
        this._swallowClick = false;

        this._onPointerDown = (event) => this._pointerDown(event);
        this._onPointerMove = (event) => this._pointerMove(event);
        this._onPointerUp = (event) => this._pointerUp(event, false);
        this._onPointerCancel = (event) => this._pointerUp(event, true);
        this._onClick = (event) => this._click(event);
        this._onChange = (event) => this._change(event);
        this._onKeydownInput = (event) => this._inputKeydown(event);
        this._onOutside = (event) => this._outsidePress(event);
        this._onStepUp = () => this._stopRepeat();

        root.addEventListener("pointerdown", this._onPointerDown);
        root.addEventListener("click", this._onClick, true);
        root.addEventListener("change", this._onChange);
        root.addEventListener("keydown", this._onKeydownInput);
        document.addEventListener("pointerdown", this._onOutside, true);
        window.addEventListener("pointerup", this._onStepUp);
        window.addEventListener("pointercancel", this._onStepUp);

        this.sync();
    }

    destroy() {
        this._endPress();
        this._stopRepeat();
        this.clearGhosts();
        this.deselect();
        this.root.removeEventListener("pointerdown", this._onPointerDown);
        this.root.removeEventListener("click", this._onClick, true);
        this.root.removeEventListener("change", this._onChange);
        this.root.removeEventListener("keydown", this._onKeydownInput);
        document.removeEventListener("pointerdown", this._onOutside, true);
        window.removeEventListener("pointerup", this._onStepUp);
        window.removeEventListener("pointercancel", this._onStepUp);
        [this._band, this._origin, this._readout].forEach((el) => el && el.remove());
    }

    // --- State ---------------------------------------------------------------

    /** The row of an element (first surface carrying it), with its surface. */
    rowOf(id, surface = null) {
        const surfaces = surface ? [surface] : this.host.surfaces();
        for (const candidate of surfaces) {
            const row = candidate.movable && candidate.movable[id];
            if (row) return { row, surface: candidate };
        }
        return null;
    }

    _mirror(id, axis) {
        return this.root.querySelector(`[data-offset-mirror="${CSS.escape(id)}"][data-offset-axis="${axis}"]`);
    }

    /** The committed offset of one element (its mirrors). */
    committed(id) {
        const x = this._mirror(id, "x");
        const y = this._mirror(id, "y");
        return { x: round2(numeric(x && x.value)), y: round2(numeric(y && y.value)) };
    }

    /** Every element's offset as the next render would bake it, the running
     *  drag included — id → {x, y}, non-zero only. */
    offsets() {
        const result = {};
        this.root.querySelectorAll("[data-offset-mirror]").forEach((mirror) => {
            const id = mirror.getAttribute("data-offset-mirror");
            const entry = result[id] || { x: 0, y: 0 };
            entry[mirror.getAttribute("data-offset-axis") === "y" ? "y" : "x"] = round2(numeric(mirror.value));
            result[id] = entry;
        });
        if (this.draft) result[this.draft.id] = { x: this.draft.x, y: this.draft.y };
        Object.keys(result).forEach((id) => {
            if (result[id].x === 0 && result[id].y === 0) delete result[id];
        });
        return result;
    }

    current(id) {
        if (this.draft && this.draft.id === id) return { x: this.draft.x, y: this.draft.y };
        return this.committed(id);
    }

    /** Clamp a wanted offset into the element's range: a disallowed axis is
     *  0, each direction stops at the server's bound. `magnetPx` (canvas px)
     *  snaps a near-zero axis home. */
    constrain(row, wanted, magnetPx = 0) {
        const b = row.bounds || { minX: 0, maxX: 0, minY: 0, maxY: 0 };
        const allowance = row.allowance || {};
        const axis = (value, allowed, min, max) => {
            if (!allowed) return { value: 0, limit: false };
            let v = Number.isFinite(value) ? value : 0;
            const limit = v < min - 0.005 || v > max + 0.005;
            v = Math.min(Math.max(v, min), max);
            if (magnetPx > 0 && Math.abs(v) < magnetPx && min <= 0 && max >= 0) v = 0;
            return { value: round2(v), limit };
        };
        const x = axis(wanted.x, allowance.horizontal, b.minX, b.maxX);
        const y = axis(wanted.y, allowance.vertical, b.minY, b.maxY);
        return { x: x.value, y: y.value, limitX: x.limit, limitY: y.limit };
    }

    /** Write an element's offset into its mirrors (only the changed axes get
     *  their `input` — the Live model, the history, the echo, the settle
     *  hash all see one ordinary edit). */
    commit(id, x, y) {
        const found = this.rowOf(id);
        if (!found) return;
        const next = this.constrain(found.row, { x, y });
        let changed = false;
        [["x", next.x], ["y", next.y]].forEach(([axis, value]) => {
            const mirror = this._mirror(id, axis);
            if (!mirror) return;
            const text = String(value);
            if (round2(numeric(mirror.value)) === value && mirror.value !== "") return;
            mirror.value = text;
            mirror.dispatchEvent(new Event("input", { bubbles: true }));
            changed = true;
        });
        this.sync();
        // Nothing changed = no render will come to take a ghost down.
        if (!changed) this._dropGhost(id);
        this.host.changed(found.row, { draft: false, committed: changed });
    }

    // --- Pointer: drag the tool / the selected box ----------------------------

    _pointerDown(event) {
        if (event.pointerType === "mouse" && event.button !== 0) return;
        const target = event.target;
        if (!target || typeof target.closest !== "function") return;

        const stepper = target.closest("[data-move-step]");
        if (stepper && this.root.contains(stepper)) {
            this._startRepeat(stepper, event);
            return;
        }

        let source = target.closest("[data-move-tool]");
        let id = source ? source.getAttribute("data-move-tool") : null;
        if (!source) {
            // Move mode: the selected element's box body drags too — but its
            // other tools (pencil, eye) keep their own click.
            const box = target.closest(".fill-box.is-moving");
            if (!box || target.closest(".fill-box__tool")) return;
            source = box;
            id = box.dataset.inputid;
        }
        if (!id || this.host.isHidden(id)) return;
        const surfaceEl = source.closest("[data-movable]");
        const surface = this.host.surfaces().find((candidate) => candidate.el === surfaceEl) || null;
        const found = this.rowOf(id, surface);
        if (!found) return;

        event.preventDefault();
        event.stopPropagation();
        try { source.setPointerCapture(event.pointerId); } catch (err) { /* synthetic events */ }

        this._press = {
            id,
            row: found.row,
            surface: found.surface,
            source,
            fromTool: source.hasAttribute("data-move-tool"),
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            start: this.committed(id),
            frame: this.host.frameOf(id, found.surface),
            dragging: false,
        };
        window.addEventListener("pointermove", this._onPointerMove);
        window.addEventListener("pointerup", this._onPointerUp);
        window.addEventListener("pointercancel", this._onPointerCancel);
    }

    _pointerMove(event) {
        const press = this._press;
        if (!press || event.pointerId !== press.pointerId) return;
        const dx = event.clientX - press.startX;
        const dy = event.clientY - press.startY;
        if (!press.dragging) {
            if (Math.hypot(dx, dy) <= DRAG_SLOP) return;
            press.dragging = true;
            this._beginDrag(press);
        }
        event.preventDefault();
        const k = this._screenPerCanvas(press.surface);
        const next = this.constrain(press.row, { x: press.start.x + dx / k, y: press.start.y + dy / k }, MAGNET / k);
        this.draft = { id: press.id, x: next.x, y: next.y };
        if (next.limitX || next.limitY) this._flashLimit();
        this.host.changed(press.row, { draft: true });
        this.reposition();
    }

    _pointerUp(event, cancelled) {
        const press = this._press;
        if (!press || event.pointerId !== press.pointerId) return;
        this._endPress();
        if (press.dragging) {
            this._swallowClick = true;
            const draft = this.draft;
            this.draft = null;
            document.documentElement.classList.remove("fill-move-dragging");
            press.source.classList.remove("is-dragging");
            if (cancelled || !draft) {
                this._dropGhost(press.id);
                this.host.changed(press.row, { draft: false, committed: false });
            } else {
                this.commit(press.id, draft.x, draft.y);
            }
            this.reposition();
            return;
        }
        if (press.fromTool && !cancelled) {
            // A click on the tool: select / deselect ("move mode").
            this._swallowClick = true;
            this.toggle(press.id);
        }
    }

    _endPress() {
        this._press = null;
        window.removeEventListener("pointermove", this._onPointerMove);
        window.removeEventListener("pointerup", this._onPointerUp);
        window.removeEventListener("pointercancel", this._onPointerCancel);
    }

    _beginDrag(press) {
        document.documentElement.classList.add("fill-move-dragging");
        press.source.classList.add("is-dragging");
        this._dropGhost(press.id);
        if (!press.row.echo) this._makeGhost(press);
    }

    /** Screen px per canvas px on a surface (box scale × stage zoom). */
    _screenPerCanvas(surface) {
        const k = this.host.scaleFor(surface) * (this.host.zoom() || 1);
        return k > 0 ? k : 1;
    }

    // --- Clicks: keyboard activation of the tool, steppers, reset, Hotovo ------

    _click(event) {
        const target = event.target;
        if (!target || typeof target.closest !== "function") return;
        const tool = target.closest("[data-move-tool]");
        if (tool) {
            event.preventDefault();
            event.stopPropagation();
            if (this._swallowClick) {
                this._swallowClick = false;
                return;
            }
            // Keyboard (Enter / Space) — no pointer press came first.
            this.toggle(tool.getAttribute("data-move-tool"), { focusChip: false });
            return;
        }
        this._swallowClick = false;

        const step = target.closest("[data-move-step]");
        if (step) {
            event.preventDefault();
            if (this._repeat && this._repeat.consumed) return; // the press already stepped
            this._step(step, event.shiftKey);
            return;
        }
        const reset = target.closest("[data-move-reset]");
        if (reset) {
            event.preventDefault();
            this.commit(reset.getAttribute("data-move-reset"), 0, 0);
            return;
        }
        if (target.closest("[data-move-done]")) {
            event.preventDefault();
            this.deselect({ focusTool: true });
        }
    }

    _stepSize() {
        // One whole unit per click: 1 px, 1 mm, 0.1 cm (= 1 mm) — in canvas px.
        return this.unit === "cm" ? pxPerUnit("cm") / 10 : pxPerUnit(this.unit);
    }

    _step(button, big) {
        const id = button.getAttribute("data-move-step");
        const axis = button.getAttribute("data-move-axis") === "y" ? "y" : "x";
        const dir = Number(button.getAttribute("data-move-dir")) < 0 ? -1 : 1;
        const current = this.committed(id);
        current[axis] += dir * this._stepSize() * (big ? 10 : 1);
        this.commit(id, current.x, current.y);
    }

    _startRepeat(button, event) {
        this._stopRepeat();
        const shift = event.shiftKey;
        this._step(button, shift);
        const repeat = { consumed: true, timer: null };
        repeat.timer = setTimeout(function tick() {
            this._step(button, shift);
            repeat.timer = setTimeout(tick.bind(this), REPEAT_EVERY);
        }.bind(this), REPEAT_DELAY);
        this._repeat = repeat;
    }

    _stopRepeat() {
        if (!this._repeat) return;
        clearTimeout(this._repeat.timer);
        // The click that follows the press must not step a second time.
        const repeat = this._repeat;
        setTimeout(() => { if (this._repeat === repeat) this._repeat = null; }, 0);
        repeat.timer = null;
    }

    // --- "Poloha" inputs -------------------------------------------------------

    _change(event) {
        const input = event.target;
        if (!input || !input.matches || !input.matches("[data-move-input]")) return;
        this._applyInput(input);
    }

    _inputKeydown(event) {
        const input = event.target;
        if (!input || !input.matches || !input.matches("[data-move-input]")) return;
        // Enter in a field of the fill FORM would submit it (= download).
        if (event.key === "Enter") {
            event.preventDefault();
            this._applyInput(input);
        }
    }

    _applyInput(input) {
        const id = input.getAttribute("data-move-input");
        const axis = input.getAttribute("data-move-axis") === "y" ? "y" : "x";
        const current = this.committed(id);
        current[axis] = numeric(input.value) * pxPerUnit(this.unit);
        this.commit(id, current.x, current.y);
        this.sync({ force: true });
    }

    /** Display value of an offset in the variant's unit. */
    _toUnit(px) {
        const decimals = unitDecimals(this.unit);
        const factor = 10 ** decimals;
        const value = Math.round((px / pxPerUnit(this.unit)) * factor) / factor;
        return Object.is(value, -0) ? 0 : value;
    }

    _format(px) {
        const value = this._toUnit(px);
        const text = value.toLocaleString("cs-CZ", { maximumFractionDigits: unitDecimals(this.unit) });
        return `${value > 0 ? "+" : ""}${text} ${this.unit}`;
    }

    /**
     * Re-read every element's offset into its UI: the "Poloha" inputs (not
     * the one being typed in, unless forced), the reset buttons, the tool's
     * hidden state (a hidden element cannot move) and the open chip.
     */
    sync({ force = false } = {}) {
        const ids = new Set();
        this.host.surfaces().forEach((surface) => Object.keys(surface.movable || {}).forEach((id) => ids.add(id)));
        ids.forEach((id) => {
            const offset = this.current(id);
            const hidden = this.host.isHidden(id);
            const escaped = CSS.escape(id);
            this.root.querySelectorAll(`[data-move-input="${escaped}"]`).forEach((input) => {
                const axis = input.getAttribute("data-move-axis") === "y" ? "y" : "x";
                input.disabled = hidden;
                if (!force && document.activeElement === input) return;
                const value = String(this._toUnit(offset[axis]));
                if (input.value !== value) input.value = value;
            });
            this.root.querySelectorAll(`[data-move-step="${escaped}"]`).forEach((button) => { button.disabled = hidden; });
            this.root.querySelectorAll(`[data-move-reset="${escaped}"]`).forEach((button) => {
                button.disabled = hidden || (offset.x === 0 && offset.y === 0);
            });
            this.root.querySelectorAll(`[data-move-position="${escaped}"]`).forEach((row) => {
                row.classList.toggle("is-disabled", hidden);
                row.classList.toggle("is-moved", offset.x !== 0 || offset.y !== 0);
            });
            this.root.querySelectorAll(`[data-move-tool="${escaped}"]`).forEach((tool) => {
                tool.hidden = hidden;
                tool.classList.toggle("is-moved", offset.x !== 0 || offset.y !== 0);
            });
            if (hidden && this.activeId === id) this.deselect();
        });
    }

    // --- Move mode ("selected") -----------------------------------------------

    toggle(id) {
        if (this.activeId === id) {
            this.deselect({ focusTool: true });
            return;
        }
        this.select(id);
    }

    select(id) {
        if (this.host.isHidden(id) || !this.rowOf(id)) return;
        this.deselect();
        this.activeId = id;
        this._boxesOf(id).forEach((box) => box.classList.add("is-moving"));
        this.root.querySelectorAll(`[data-move-tool="${CSS.escape(id)}"]`).forEach((tool) => tool.setAttribute("aria-pressed", "true"));
        const chip = this._chip(id);
        if (chip) chip.hidden = false;
        this.sync();
        this.reposition();
    }

    deselect({ focusTool = false } = {}) {
        const id = this.activeId;
        if (id === null) return;
        this.activeId = null;
        this._boxesOf(id).forEach((box) => box.classList.remove("is-moving"));
        const tools = this.root.querySelectorAll(`[data-move-tool="${CSS.escape(id)}"]`);
        tools.forEach((tool) => tool.setAttribute("aria-pressed", "false"));
        const chip = this._chip(id);
        if (chip) chip.hidden = true;
        if (focusTool) {
            const tool = Array.from(tools).find((candidate) => candidate.offsetParent !== null);
            if (tool) tool.focus({ preventScroll: true });
        }
        this.reposition();
    }

    get active() {
        return this.activeId !== null;
    }

    /**
     * Keys while an element is selected — Esc leaves, arrows nudge by one
     * canvas px (Shift = 10) on the allowed axes. Not while typing.
     * Returns true when the key was consumed.
     */
    handleKey(event) {
        if (this.activeId === null) return false;
        if (event.key === "Escape") {
            this.deselect({ focusTool: true });
            return true;
        }
        const moves = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        const move = moves[event.key];
        if (!move || event.ctrlKey || event.metaKey || event.altKey || isEditable(event.target)) return false;
        const found = this.rowOf(this.activeId);
        if (!found) return false;
        const allowance = found.row.allowance || {};
        if ((move[0] !== 0 && !allowance.horizontal) || (move[1] !== 0 && !allowance.vertical)) {
            event.preventDefault();
            return true; // the other axis is locked — swallow, don't pan
        }
        event.preventDefault();
        const step = event.shiftKey ? 10 : 1;
        const current = this.committed(this.activeId);
        const wanted = { x: current.x + move[0] * step, y: current.y + move[1] * step };
        const next = this.constrain(found.row, wanted);
        if (next.limitX || next.limitY) this._flashLimit();
        this.commit(this.activeId, next.x, next.y);
        this.reposition();
        return true;
    }

    _outsidePress(event) {
        if (this.activeId === null) return;
        const target = event.target;
        if (!target || typeof target.closest !== "function") return;
        const id = this.activeId;
        const escaped = CSS.escape(id);
        if (target.closest(`[data-move-chip="${escaped}"], [data-move-tool="${escaped}"], [data-move-position="${escaped}"]`)) return;
        const box = target.closest(".fill-box");
        if (box && box.classList.contains("is-moving")) return;
        this.deselect();
    }

    _boxesOf(id) {
        const result = [];
        this.host.surfaces().forEach((surface) => {
            (surface.boxes || []).forEach((box) => {
                if (box.dataset.inputid === id && !box.dataset.sheetMember) result.push(box);
            });
        });
        return result;
    }

    _chip(id) {
        return this.root.querySelector(`[data-move-chip="${CSS.escape(id)}"]`);
    }

    // --- Band, origin outline, readout, ghost ---------------------------------

    _overlayOf(surface) {
        return surface && surface.el ? surface.el.querySelector(".fill-overlay") : null;
    }

    _layer(name, surface) {
        const key = `_${name}`;
        const overlay = this._overlayOf(surface);
        if (!overlay) return null;
        let el = this[key];
        if (!el || el.parentElement !== overlay) {
            if (el) el.remove();
            el = document.createElement("div");
            el.className = `fill-move-${name}`;
            el.setAttribute("aria-hidden", "true");
            el.hidden = true;
            overlay.prepend(el);
            this[key] = el;
        }
        return el;
    }

    /** Lay out the band, the origin outline, the readout pill, the ghosts and
     *  the chip for the current drag / selection. Called by the overlay's
     *  reposition() (zoom, pan, reflow) and after every move. */
    reposition() {
        const focusId = (this._press && this._press.dragging) ? this._press.id : this.activeId;
        const found = focusId !== null ? this.rowOf(focusId, this._press && this._press.dragging ? this._press.surface : null) : null;

        [this._band, this._origin, this._readout].forEach((el) => { if (el) el.hidden = true; });

        if (found) {
            const { row, surface } = found;
            const scale = this.host.scaleFor(surface);
            const live = this.host.frameOf(row.id, surface);
            const offset = this.current(row.id);
            if (live) {
                // The frame at offset 0, then swept by the allowed range.
                const home = { x: live.x - offset.x, y: live.y - offset.y, width: live.width, height: live.height };
                const b = row.bounds;
                const band = this._layer("band", surface);
                if (band) {
                    this._place(band, {
                        x: home.x + b.minX,
                        y: home.y + b.minY,
                        width: home.width + (b.maxX - b.minX),
                        height: home.height + (b.maxY - b.minY),
                    }, scale);
                    band.hidden = false;
                }
                const origin = this._layer("origin", surface);
                if (origin && (offset.x !== 0 || offset.y !== 0)) {
                    this._place(origin, home, scale);
                    origin.hidden = false;
                }
                if (this._press && this._press.dragging) {
                    const readout = this._layer("readout", surface);
                    if (readout) {
                        const parts = [];
                        if (row.allowance.vertical) parts.push(`↕ ${this._format(offset.y)}`);
                        if (row.allowance.horizontal) parts.push(`↔ ${this._format(offset.x)}`);
                        readout.textContent = parts.join(" · ");
                        readout.style.left = `${live.x * scale}px`;
                        readout.style.top = `${live.y * scale}px`;
                        readout.hidden = false;
                    }
                }
            }
        }

        this._ghosts.forEach((ghost) => {
            const offset = this.current(ghost.id);
            const scale = this.host.scaleFor(ghost.surface);
            this._place(ghost.el, {
                x: ghost.frame.x + (offset.x - ghost.start.x),
                y: ghost.frame.y + (offset.y - ghost.start.y),
                width: ghost.frame.width,
                height: ghost.frame.height,
            }, scale);
        });

        this._positionChip();
    }

    _place(el, frame, scale) {
        el.style.left = `${frame.x * scale}px`;
        el.style.top = `${frame.y * scale}px`;
        el.style.width = `${Math.max(0, frame.width * scale)}px`;
        el.style.height = `${Math.max(0, frame.height * scale)}px`;
    }

    _flashLimit() {
        if (!this._band) return;
        this._band.classList.remove("is-limit");
        // Restart the animation.
        void this._band.offsetWidth;
        this._band.classList.add("is-limit");
        clearTimeout(this._limitTimer);
        this._limitTimer = setTimeout(() => this._band && this._band.classList.remove("is-limit"), 360);
    }

    /** A see-through crop of the element's CURRENT pixels (the preview the
     *  user sees), lifted off at its pre-drag place. The original pixels
     *  stay until the settle — this is honest about being a preview. */
    _makeGhost(press) {
        const surface = press.surface;
        const frame = press.frame;
        const overlay = this._overlayOf(surface);
        const source = surface && surface.preview;
        if (!overlay || !source || !frame || !(frame.width > 0) || !(frame.height > 0)) return;
        const intrinsic = source.tagName === "IMG" ? source.naturalWidth : source.width;
        if (!(intrinsic > 0) || !(surface.canvasWidth > 0)) return;
        const ratio = intrinsic / surface.canvasWidth;
        const scale = this.host.scaleFor(surface) * (this.host.zoom() || 1) * (window.devicePixelRatio || 1);
        const canvas = document.createElement("canvas");
        canvas.className = "fill-move-ghost";
        canvas.setAttribute("aria-hidden", "true");
        canvas.width = Math.max(1, Math.round(frame.width * scale));
        canvas.height = Math.max(1, Math.round(frame.height * scale));
        try {
            canvas.getContext("2d").drawImage(
                source,
                frame.x * ratio, frame.y * ratio, frame.width * ratio, frame.height * ratio,
                0, 0, canvas.width, canvas.height,
            );
        } catch (err) {
            canvas.classList.add("is-empty"); // outline only
        }
        overlay.appendChild(canvas);
        const ghost = { id: press.id, el: canvas, surface, frame, start: { ...press.start }, timer: null };
        ghost.timer = setTimeout(() => this._dropGhost(press.id), GHOST_TTL);
        this._ghosts.set(press.id, ghost);
    }

    _dropGhost(id) {
        const ghost = this._ghosts.get(id);
        if (!ghost) return;
        clearTimeout(ghost.timer);
        ghost.el.remove();
        this._ghosts.delete(id);
    }

    /** The settle that renders the CURRENT state landed: the real pixels are
     *  where the ghosts are. */
    clearGhosts() {
        if (this._press && this._press.dragging) return;
        Array.from(this._ghosts.keys()).forEach((id) => this._dropGhost(id));
    }

    /** The chip floats below the selected box (above when there is no room),
     *  clamped on-screen; phones dock it to the bottom edge (CSS). */
    _positionChip() {
        if (this.activeId === null) return;
        const chip = this._chip(this.activeId);
        if (!chip || chip.hidden) return;
        if (window.matchMedia && window.matchMedia("(max-width: 700px)").matches) {
            chip.style.removeProperty("top");
            chip.style.removeProperty("left");
            return;
        }
        const box = this._boxesOf(this.activeId).find((candidate) => candidate.style.display !== "none");
        if (!box) return;
        const rect = box.getBoundingClientRect();
        const margin = 10;
        const size = chip.getBoundingClientRect();
        let top = rect.bottom + margin;
        if (top + size.height > window.innerHeight - margin && rect.top - size.height - margin >= margin) {
            top = rect.top - size.height - margin;
        }
        let left = rect.left;
        top = Math.max(margin, Math.min(top, window.innerHeight - size.height - margin));
        left = Math.max(margin, Math.min(left, window.innerWidth - size.width - margin));
        chip.style.top = `${top}px`;
        chip.style.left = `${left}px`;
    }
}
