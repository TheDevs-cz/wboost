import { Controller } from "@hotwired/stimulus";

/**
 * The fonts page's type specimens: one sample line per face, rendered in
 * that face (the page registers every project face as @font-face), with a
 * shared "Ukázkový text" field that rewrites every specimen at once — Google
 * Fonts style. The text is remembered per browser (localStorage) so a
 * designer checking a brand's tagline keeps it across visits; storage may be
 * unavailable (private mode), in which case the default sample stays.
 *
 * Also hosts the "copy wire string" buttons: the exact `"<Font> (<Face>)"`
 * family the API, MCP and canvas JSON address a face by.
 */
export default class extends Controller {
    static targets = ["input", "specimen", "size"];

    static values = {
        storageKey: { type: String, default: "wboost.font-specimen" },
        defaultText: { type: String, default: "Příliš žluťoučký kůň úpěl ďábelské ódy 0123456789" },
    };

    connect() {
        let stored = null;
        try {
            stored = window.localStorage.getItem(this.storageKeyValue);
        } catch (error) {
            // Storage unavailable — the default sample stays.
        }
        const text = stored && stored.trim() !== "" ? stored : this.defaultTextValue;
        if (this.hasInputTarget) this.inputTarget.value = text;
        this._apply(text);
    }

    input() {
        const text = this.inputTarget.value.trim() === "" ? this.defaultTextValue : this.inputTarget.value;
        this._apply(text);
        try {
            window.localStorage.setItem(this.storageKeyValue, this.inputTarget.value);
        } catch (error) {
            // Non-fatal.
        }
    }

    /** The size slider (px) scales every specimen together. */
    resize() {
        if (!this.hasSizeTarget) return;
        const px = Number.parseInt(this.sizeTarget.value, 10);
        if (!Number.isFinite(px)) return;
        this.specimenTargets.forEach((element) => {
            element.style.fontSize = `${px}px`;
        });
    }

    async copy(event) {
        const family = event.params && event.params.family;
        if (!family) return;
        const button = event.currentTarget;
        try {
            await navigator.clipboard.writeText(family);
            this._flash(button, "Zkopírováno");
        } catch (error) {
            this._flash(button, "Nelze zkopírovat");
        }
    }

    _apply(text) {
        this.specimenTargets.forEach((element) => {
            element.textContent = text;
        });
    }

    _flash(button, label) {
        const original = button.getAttribute("title") || "";
        const icon = button.querySelector("i");
        const originalIcon = icon ? icon.className : null;
        button.setAttribute("title", label);
        if (icon) icon.className = "mdi mdi-check";
        setTimeout(() => {
            button.setAttribute("title", original);
            if (icon && originalIcon) icon.className = originalIcon;
        }, 1500);
    }
}
