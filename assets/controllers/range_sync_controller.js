import { Controller } from "@hotwired/stimulus";

/**
 * A range slider mirrored into the number field the form actually submits
 * (manual logo "Šířka loga v manuálu"): 0 on the slider = an empty field =
 * the default size. No separate value label (F10): the number field shows
 * the value, its "výchozí" placeholder the default; the optional "Výchozí"
 * reset button (shown only while a custom value is set) clears both — it
 * changes the same field, so the form's Uložit saves it like any edit.
 */
export default class extends Controller {
    static targets = ["range", "number", "reset"];

    fromRange() {
        const value = Number.parseInt(this.rangeTarget.value, 10) || 0;
        this.numberTarget.value = value > 0 ? String(value) : "";
        this.toggleReset(value);
    }

    fromNumber() {
        const value = Number.parseInt(this.numberTarget.value, 10);
        const clamped = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
        this.rangeTarget.value = String(clamped);
        this.toggleReset(clamped);
    }

    reset() {
        this.rangeTarget.value = "0";
        this.numberTarget.value = "";
        this.numberTarget.dispatchEvent(new Event("change", { bubbles: true }));
        this.toggleReset(0);
        this.numberTarget.focus();
    }

    toggleReset(value) {
        if (this.hasResetTarget) {
            this.resetTarget.hidden = !(value > 0);
        }
    }
}
