import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";
import { withMoveCursor } from "./sort_drag_cursor.js";

/**
 * Admin-only reordering of the fill pages' "Všechna pole" panel (single
 * variant + group). Mounted on the `.fill-popovers` container only when the
 * viewer is an admin; the fields are dragged by their
 * `[data-fill-field-order-handle]` (shown in panel mode only — the floating
 * popovers are positioned in viewport coords, so their DOM order is
 * invisible there) and every drop POSTs the new inputId order, which the
 * server stores on the template (Template::$fillFieldOrder) and applies for
 * everyone who fills it.
 *
 * Moving the popovers inside their container is harmless to the overlay:
 * nothing looks them up by position, only by `data-inputid`.
 *
 * forceFallback + fallbackOnBody: the native HTML5 drag misbehaves inside the
 * modal-like panel, and the panel is `transform`ed (centred), which would
 * offset a position:fixed fallback clone left inside it.
 */
export default class extends Controller {
    static targets = ["status"];

    static values = {
        url: String,
    };

    connect() {
        this._sortable = Sortable.create(this.element, withMoveCursor({
            draggable: ".fill-popover",
            handle: "[data-fill-field-order-handle]",
            direction: "vertical",
            animation: 150,
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 4,
            ghostClass: "fill-popover--reorder-ghost",
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) {
                    this.save();
                }
            },
        }));
    }

    disconnect() {
        if (this._sortable) {
            this._sortable.destroy();
            this._sortable = null;
        }
        clearTimeout(this._statusTimer);
    }

    order() {
        return Array.from(this.element.querySelectorAll(":scope > .fill-popover[data-inputid]"))
            .map((element) => element.dataset.inputid);
    }

    async save() {
        this.showStatus("Ukládám…", "text-muted");

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ order: this.order() }),
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.showStatus("Pořadí uloženo", "text-success", 2500);
        } catch (error) {
            this.showStatus("Pořadí se nepodařilo uložit", "text-danger");
        }
    }

    showStatus(text, className, clearAfter = 0) {
        if (!this.hasStatusTarget) {
            return;
        }

        clearTimeout(this._statusTimer);
        this.statusTarget.textContent = `· ${text}`;
        this.statusTarget.className = `fill-field-order__status ${className}`;

        if (clearAfter > 0) {
            this._statusTimer = setTimeout(() => {
                this.statusTarget.textContent = "";
            }, clearAfter);
        }
    }
}
