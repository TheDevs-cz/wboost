import { Controller } from "@hotwired/stimulus";

/**
 * Fill-page editor for ONE CHECKLIST component (an input the designer added
 * via "Přidat zaškrtávací seznam"). Unlike the free-form WYSIWYG this is a
 * fixed-shape editor: one row per item with a real checkbox, gated by the
 * four admin capability flags — canToggle (check/uncheck), canEditText,
 * canAdd, canRemove. Whatever the flags, the VALUE model is the plain
 * checkbox-list envelope shared with the WYSIWYG and the server:
 * `{"runs":[{"text":"item\nitem"}],"lines":["cb","cbx",...]}` — unstyled
 * runs (typography comes from the designed textbox), one 'cb'/'cbx' entry
 * per item.
 *
 * Sync mirrors rich_text_editor_controller: the envelope is written into the
 * input's Live-bound mirror field (input event → debounced server
 * re-render) and a bubbling `checklist-editor:changed` event lets the
 * overlay recompute container reflow instantly. Removing every item writes
 * an EXPLICIT empty string — that suppresses the admin sample (renders
 * empty), which is exactly what "user deleted all items" means.
 */
export default class extends Controller {
    static targets = ["rows", "addButton", "counter"];
    static values = {
        inputId: String,
        items: { type: Array, default: [] },
        canToggle: { type: Boolean, default: true },
        canEditText: { type: Boolean, default: true },
        canAdd: { type: Boolean, default: true },
        canRemove: { type: Boolean, default: true },
        maxLength: { type: Number, default: 0 },
    };

    connect() {
        this.items = this.itemsValue.map((item) => ({
            text: typeof item.text === "string" ? item.text : "",
            checked: item.checked === true,
        }));
        if (this.hasAddButtonTarget) {
            this.addButtonTarget.classList.toggle("d-none", !this.canAddValue);
        }
        this._render();
        this._updateCounter();
    }

    addItem() {
        if (!this.canAddValue) return;
        this.items.push({ text: "", checked: false });
        this._render();
        this._focusRow(this.items.length - 1);
        this._sync();
    }

    toggleItem(event) {
        const index = this._rowIndex(event.target);
        if (index === null || !this.canToggleValue) return;
        this.items[index].checked = event.target.checked;
        this._sync();
    }

    editItem(event) {
        const index = this._rowIndex(event.target);
        if (index === null) return;

        // maxLength counts the CONCATENATED plain text (newlines included),
        // matching the server's mb_strlen check — trim the edited field back
        // when the total would overflow.
        if (this.maxLengthValue > 0) {
            const others = this.items.reduce(
                (sum, item, i) => (i === index ? sum : sum + [...item.text].length),
                this.items.length - 1, // one "\n" per boundary
            );
            const allowed = Math.max(0, this.maxLengthValue - others);
            const typed = [...event.target.value];
            if (typed.length > allowed) {
                event.target.value = typed.slice(0, allowed).join("");
            }
        }

        this.items[index].text = event.target.value;
        this._sync();
    }

    removeItem(event) {
        const index = this._rowIndex(event.target);
        if (index === null || !this.canRemoveValue) return;
        this.items.splice(index, 1);
        this._render();
        this._sync();
    }

    /** Enter never submits the surrounding form; with canAdd it inserts a
     *  fresh item below the current row (checklist convention). */
    keydown(event) {
        if (event.key !== "Enter") return;
        event.preventDefault();
        if (!this.canAddValue) return;
        const index = this._rowIndex(event.target);
        if (index === null) return;
        this.items.splice(index + 1, 0, { text: "", checked: false });
        this._render();
        this._focusRow(index + 1);
        this._sync();
    }

    // --- rendering --------------------------------------------------------

    _render() {
        if (!this.hasRowsTarget) return;
        const rows = this.rowsTarget;
        rows.textContent = "";

        this.items.forEach((item, index) => {
            const row = document.createElement("div");
            row.className = "d-flex align-items-center gap-2";
            row.dataset.checklistRow = String(index);

            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.className = "form-check-input flex-shrink-0 m-0";
            checkbox.checked = item.checked;
            checkbox.disabled = !this.canToggleValue;
            checkbox.setAttribute("aria-label", "Zaškrtnout položku");
            checkbox.setAttribute("data-action", "change->checklist-editor#toggleItem");
            row.appendChild(checkbox);

            const text = document.createElement("input");
            text.type = "text";
            text.className = "form-control form-control-sm";
            text.value = item.text;
            text.readOnly = !this.canEditTextValue;
            text.setAttribute("aria-label", "Text položky");
            text.setAttribute("data-action", "input->checklist-editor#editItem keydown->checklist-editor#keydown");
            row.appendChild(text);

            if (this.canRemoveValue) {
                const remove = document.createElement("button");
                remove.type = "button";
                remove.className = "btn btn-sm btn-outline-danger flex-shrink-0 px-1 py-0";
                remove.title = "Odebrat položku";
                remove.innerHTML = '<i class="mdi mdi-close" aria-hidden="true"></i>';
                remove.setAttribute("data-action", "checklist-editor#removeItem");
                row.appendChild(remove);
            }

            rows.appendChild(row);
        });

        if (this.items.length === 0) {
            const empty = document.createElement("p");
            empty.className = "small text-muted mb-0";
            empty.textContent = "Žádné položky — prvek se vykreslí prázdný.";
            rows.appendChild(empty);
        }
    }

    _rowIndex(element) {
        const row = element.closest("[data-checklist-row]");
        if (!row) return null;
        const index = Number.parseInt(row.dataset.checklistRow, 10);
        return Number.isInteger(index) && index >= 0 && index < this.items.length ? index : null;
    }

    _focusRow(index) {
        const row = this.hasRowsTarget ? this.rowsTarget.querySelector(`[data-checklist-row="${index}"]`) : null;
        const field = row ? row.querySelector('input[type="text"]') : null;
        if (field && !field.readOnly) field.focus();
    }

    // --- mirror sync ------------------------------------------------------

    _sync() {
        const mirrorValue = this.items.length === 0
            ? ""
            : JSON.stringify({
                runs: [{
                    text: this.items.map((item) => item.text).join("\n"),
                    fontFamily: null,
                    color: null,
                    underline: false,
                }],
                lines: this.items.map((item) => (item.checked ? "cbx" : "cb")),
            });

        const mirror = document.querySelector(`[data-text-mirror="${this.inputIdValue}"]`);
        if (mirror && mirror.value !== mirrorValue) {
            mirror.value = mirrorValue;
            mirror.dispatchEvent(new Event("input", { bubbles: true }));
        }

        this._updateCounter();
        this.dispatch("changed", { detail: { inputId: this.inputIdValue } });
    }

    _updateCounter() {
        if (!this.hasCounterTarget) return;
        const length = this.items.reduce(
            (sum, item, i) => sum + [...item.text].length + (i > 0 ? 1 : 0),
            0,
        );
        this.counterTarget.textContent = this.maxLengthValue
            ? `${length} / ${this.maxLengthValue} znaků`
            : "";
    }
}
