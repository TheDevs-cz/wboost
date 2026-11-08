import { Controller } from "@hotwired/stimulus";

/**
 * A range slider mirrored into the number field the form actually submits
 * (manual logo "Šířka loga v manuálu", % of the logo's DEFAULT size —
 * `LogoScale`): 10–200 %, 100 % = výchozí. Slider positions near 100 % snap
 * onto it (a detent), so going back to the default by hand is easy. No
 * separate value label (F10): the number field shows the value; the
 * "Výchozí" reset button (shown only while the value is not 100 %) puts both
 * back to 100 — it changes the same field, so the form's Uložit saves it like
 * any edit (100 is stored as "no value").
 */
const MIN = 10;
const MAX = 200;
const DEFAULT = 100;
const DETENT = 4;

export default class extends Controller {
    static targets = ["range", "number", "reset"];

    fromRange() {
        let value = clamp(Number.parseInt(this.rangeTarget.value, 10));
        if (Math.abs(value - DEFAULT) <= DETENT) {
            value = DEFAULT;
        }
        this.rangeTarget.value = String(value);
        this.numberTarget.value = String(value);
        this.toggleReset(value);
    }

    fromNumber() {
        const typed = Number.parseInt(this.numberTarget.value, 10);
        // An empty field = the default; an out-of-range number is left for
        // the server-side Range constraint to report, the slider just clamps.
        const value = Number.isFinite(typed) ? clamp(typed) : DEFAULT;
        this.rangeTarget.value = String(value);
        this.toggleReset(value);
    }

    reset() {
        this.rangeTarget.value = String(DEFAULT);
        this.numberTarget.value = String(DEFAULT);
        this.numberTarget.dispatchEvent(new Event("change", { bubbles: true }));
        this.toggleReset(DEFAULT);
        this.numberTarget.focus();
    }

    toggleReset(value) {
        if (this.hasResetTarget) {
            this.resetTarget.hidden = value === DEFAULT;
        }
    }
}

function clamp(value) {
    if (!Number.isFinite(value)) {
        return DEFAULT;
    }

    return Math.max(MIN, Math.min(MAX, value));
}
