/**
 * The "Řádek n" blocks of an open container sheet: framing, collapsing and
 * drag & drop reordering.
 *
 * The sheet is a VIEW over flat cards ordered with CSS `order` (no card moves
 * in the DOM, see _fill_container_sheets.html.twig), so a row cannot have a
 * real wrapper. Instead the head and the cards of one row carry classes that
 * draw them as ONE framed block (`.is-row-member`, `.is-row-last`).
 *
 * The row actions themselves stay in the overlay (`onMove` / `onStep`), one
 * undo step each; this class only decides which action the user asked for.
 */

const DRAG_THRESHOLD = 4;
const SCROLL_EDGE = 48;
const SCROLL_STEP = 14;

/**
 * Sheet entries → one group per row slot: its section and the elements of
 * everything nested below it (deeper entries up to the next row or sibling).
 */
export function rowGroups(entries, elementFor) {
    const groups = [];
    let current = null;
    (entries || []).forEach((entry) => {
        const depth = entry.depth || 0;
        if (entry.kind === "section" && entry.row !== undefined && entry.row !== null) {
            const section = elementFor(entry);
            current = section ? { rowId: entry.row, slot: entry.slot, depth, section, members: [] } : null;
            if (current) groups.push(current);
            return;
        }
        if (current && entry.kind !== "rowAdd" && depth > current.depth) {
            const element = elementFor(entry);
            if (element) current.members.push(element);
            return;
        }
        current = null;
    });
    return groups;
}

const key = (rowId, slot) => `${rowId}:${slot}`;

export class FillRowSheet {
    constructor(panel, groups, { onMove, onStep }) {
        this.panel = panel;
        this.groups = groups;
        this.onMove = onMove;
        this.onStep = onStep;
        // A freshly opened sheet shows only each row's FIRST slot expanded;
        // the rest start collapsed (summary in the head). A focused field
        // (reveal) or a newly added row opens its block.
        this.collapsed = new Set(groups.filter((group) => group.slot > 0).map((group) => key(group.rowId, group.slot)));
        this.drag = null;

        this.groups.forEach((group) => {
            group.section.style.setProperty("--row-depth", String(group.depth));
            group.members.forEach((element) => {
                element.classList.add("is-row-member");
                element.style.setProperty("--row-depth", String(group.depth));
            });
            const last = group.members[group.members.length - 1];
            if (last) last.classList.add("is-row-last");
        });

        this._onClick = (event) => this._click(event);
        this._onKeydown = (event) => this._keydown(event);
        this._onPointerDown = (event) => this._pointerDown(event);
        panel.addEventListener("click", this._onClick);
        panel.addEventListener("keydown", this._onKeydown);
        panel.addEventListener("pointerdown", this._onPointerDown);
        this.refresh();
    }

    destroy() {
        this._endDrag();
        this.panel.removeEventListener("click", this._onClick);
        this.panel.removeEventListener("keydown", this._onKeydown);
        this.panel.removeEventListener("pointerdown", this._onPointerDown);
        this.groups.forEach((group) => {
            group.section.classList.remove("is-collapsed");
            group.section.style.removeProperty("--row-depth");
            group.members.forEach((element) => {
                element.classList.remove("is-row-member", "is-row-last", "is-row-collapsed");
                element.style.removeProperty("--row-depth");
            });
        });
    }

    /** Mirror the collapsed state + the number of active rows into the DOM. */
    refresh() {
        this.groups.forEach((group) => {
            const collapsed = this.collapsed.has(key(group.rowId, group.slot));
            const closed = collapsed || group.members.length === 0;
            group.section.classList.toggle("is-collapsed", closed);
            group.members.forEach((element) => element.classList.toggle("is-row-collapsed", collapsed));

            const toggle = group.section.querySelector("[data-row-toggle]");
            if (toggle) {
                const label = collapsed ? "Rozbalit řádek" : "Sbalit řádek";
                toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
                toggle.title = label;
                toggle.setAttribute("aria-label", label);
                toggle.hidden = group.members.length === 0;
            }
            const summary = group.section.querySelector("[data-row-summary]");
            if (summary) {
                summary.textContent = collapsed ? this._summary(group) : "";
                summary.hidden = !collapsed;
            }
            const grip = group.section.querySelector("[data-row-grip]");
            if (grip) grip.disabled = this._active(group.rowId).length < 2;
        });
    }

    /** Collapsed state follows the row's content through a move / remove. */
    permute(rowId, sources) {
        const next = new Set(Array.from(this.collapsed).filter((entry) => !entry.startsWith(`${rowId}:`)));
        this.groups.filter((group) => group.rowId === rowId).forEach((group) => {
            const source = sources.has(group.slot) ? sources.get(group.slot) : group.slot;
            if (source !== null && this.collapsed.has(key(rowId, source))) next.add(key(rowId, group.slot));
        });
        this.collapsed = next;
        this.refresh();
    }

    /** Opens the row holding `element` (a field is being focused in it). */
    reveal(element) {
        const group = this.groups.find((candidate) => candidate.members.includes(element));
        if (group && this.collapsed.delete(key(group.rowId, group.slot))) this.refresh();
    }

    _active(rowId) {
        return this.groups
            .filter((group) => group.rowId === rowId && !group.section.classList.contains("is-row-inactive"))
            .sort((a, b) => a.slot - b.slot);
    }

    _groupOf(element) {
        const section = element.closest(".fill-sheet__section--row");
        return section ? this.groups.find((group) => group.section === section) || null : null;
    }

    _summary(group) {
        return group.members
            .map((element) => {
                const field = element.querySelector('textarea, [contenteditable="true"]');
                if (!field) return "";
                return String(field.value ?? field.textContent ?? "").replace(/\s+/g, " ").trim();
            })
            .filter(Boolean)
            .join(" · ");
    }

    _click(event) {
        const group = this._groupOf(event.target);
        if (!group || group.members.length === 0) return;
        const button = event.target.closest("button");
        if (button && !button.hasAttribute("data-row-toggle")) return;
        const entry = key(group.rowId, group.slot);
        if (!this.collapsed.delete(entry)) this.collapsed.add(entry);
        this.refresh();
    }

    _keydown(event) {
        const grip = event.target.closest?.("[data-row-grip]");
        if (!grip || (event.key !== "ArrowUp" && event.key !== "ArrowDown")) return;
        const group = this._groupOf(grip);
        if (!group) return;
        event.preventDefault();
        event.stopPropagation();
        this.onStep(group.rowId, group.slot, event.key === "ArrowUp" ? "up" : "down");
    }

    _pointerDown(event) {
        const grip = event.target.closest?.("[data-row-grip]");
        if (!grip || grip.disabled || event.button !== 0) return;
        const group = this._groupOf(grip);
        if (!group) return;
        event.preventDefault();
        grip.focus({ preventScroll: true });
        grip.setPointerCapture(event.pointerId);
        const move = (moveEvent) => this._pointerMove(moveEvent);
        const up = () => this._finishDrag(true);
        const cancel = () => this._finishDrag(false);
        const escape = (keyEvent) => {
            if (keyEvent.key !== "Escape") return;
            keyEvent.preventDefault();
            keyEvent.stopPropagation();
            this._finishDrag(false);
        };
        grip.addEventListener("pointermove", move);
        grip.addEventListener("pointerup", up);
        grip.addEventListener("pointercancel", cancel);
        document.addEventListener("keydown", escape, true);
        this.drag = {
            group, grip, startY: event.clientY, active: false, to: group.slot, indicator: null,
            unbind: () => {
                grip.removeEventListener("pointermove", move);
                grip.removeEventListener("pointerup", up);
                grip.removeEventListener("pointercancel", cancel);
                document.removeEventListener("keydown", escape, true);
            },
        };
    }

    _pointerMove(event) {
        const drag = this.drag;
        if (!drag) return;
        if (!drag.active) {
            if (Math.abs(event.clientY - drag.startY) < DRAG_THRESHOLD) return;
            this._startDrag(event);
        }
        const bounds = this.panel.getBoundingClientRect();
        if (event.clientY < bounds.top + SCROLL_EDGE) this.panel.scrollTop -= SCROLL_STEP;
        else if (event.clientY > bounds.bottom - SCROLL_EDGE) this.panel.scrollTop += SCROLL_STEP;
        this._updateDrop(event.clientY);
    }

    /** Every row folds to its head while dragging: short, comparable targets
     *  instead of screens of fields. The dragged head stays under the pointer. */
    _startDrag(event) {
        const drag = this.drag;
        const before = drag.group.section.getBoundingClientRect().top;
        const offset = event.clientY - before;
        drag.active = true;
        this.panel.classList.add("is-rows-dragging");
        drag.group.section.classList.add("is-row-dragging");
        this.panel.scrollTop += drag.group.section.getBoundingClientRect().top - (event.clientY - offset);

        drag.indicator = document.createElement("div");
        drag.indicator.className = "fill-sheet__row-drop is-in-sheet";
        this.panel.appendChild(drag.indicator);
    }

    _updateDrop(clientY) {
        const drag = this.drag;
        const others = this._active(drag.group.rowId).filter((group) => group !== drag.group);
        const rects = others.map((group) => group.section.getBoundingClientRect());
        const to = rects.filter((rect) => rect.top + rect.height / 2 < clientY).length;
        drag.to = to;

        const panelRect = this.panel.getBoundingClientRect();
        let y;
        if (rects.length === 0) return;
        if (to < rects.length) y = rects[to].top - 5;
        else y = rects[rects.length - 1].bottom + 3;
        drag.indicator.style.top = `${y - panelRect.top + this.panel.scrollTop - this.panel.clientTop}px`;
    }

    _finishDrag(commit) {
        const drag = this.drag;
        if (!drag) return;
        this._endDrag();
        if (commit && drag.active && drag.to !== drag.group.slot) {
            this.onMove(drag.group.rowId, drag.group.slot, drag.to);
        }
    }

    _endDrag() {
        const drag = this.drag;
        if (!drag) return;
        this.drag = null;
        drag.unbind();
        if (drag.indicator) drag.indicator.remove();
        drag.group.section.classList.remove("is-row-dragging");
        this.panel.classList.remove("is-rows-dragging");
    }
}
