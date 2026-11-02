import { Controller } from "@hotwired/stimulus";

/**
 * A range slider mirrored into the number field the form actually submits
 * (manual logo "Šířka loga v manuálu"): 0 on the slider = an empty field =
 * the default size. The label shows "N %" or "Výchozí".
 */
export default class extends Controller {
    static targets = ["range", "number", "label"];

    fromRange() {
        const value = Number.parseInt(this.rangeTarget.value, 10) || 0;
        this.numberTarget.value = value > 0 ? String(value) : "";
        this.updateLabel(value);
    }

    fromNumber() {
        const value = Number.parseInt(this.numberTarget.value, 10);
        const clamped = Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0;
        this.rangeTarget.value = String(clamped);
        this.updateLabel(clamped);
    }

    updateLabel(value) {
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = value > 0 ? `${value} %` : "Výchozí";
        }
    }
}
