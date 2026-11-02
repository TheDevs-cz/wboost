import { Controller } from "@hotwired/stimulus";

/**
 * Client-side view tools of the project gallery (Nocturne redesign): format
 * chips ("Vše · PNG · JPG …"), a name search and the thumbnail-size slider.
 * Nothing goes to the server — it only hides tiles of the folder that is
 * already rendered.
 *
 * The element lives INSIDE the Project:ImageGallery Live component, so every
 * Live morph re-renders the chips and tiles from the server. The state lives
 * here and is re-applied after each morph (MutationObserver, childList only —
 * applying it touches attributes, so there is no feedback loop). The size is
 * remembered per browser (`wb-galsize`), like the prototype's slider.
 */
export default class extends Controller {
    static targets = ["chip", "grid", "search", "size", "none"];

    connect() {
        this.format = "";
        this.query = "";
        try {
            const stored = Number.parseInt(window.localStorage.getItem("wb-galsize") || "", 10);
            if (Number.isFinite(stored)) this.sizePx = stored;
        } catch (error) {
            // Storage unavailable — default size.
        }
        this.observer = new MutationObserver(() => this.apply());
        this.observer.observe(this.element, { childList: true, subtree: true });
        this.apply();
    }

    disconnect() {
        if (this.observer) this.observer.disconnect();
    }

    pick(event) {
        this.format = event.currentTarget.dataset.format || "";
        this.apply();
    }

    search(event) {
        this.query = (event.currentTarget.value || "").trim().toLowerCase();
        this.apply();
    }

    resize(event) {
        this.sizePx = Number.parseInt(event.currentTarget.value, 10);
        try {
            window.localStorage.setItem("wb-galsize", String(this.sizePx));
        } catch (error) {
            // Non-fatal.
        }
        this.apply();
    }

    apply() {
        // A format that no longer exists in this folder resets to "Vše".
        if (this.format !== "" && !this.chipTargets.some((chip) => chip.dataset.format === this.format)) {
            this.format = "";
        }
        this.chipTargets.forEach((chip) => {
            const on = (chip.dataset.format || "") === this.format;
            if (chip.classList.contains("on") !== on) chip.classList.toggle("on", on);
            if (chip.getAttribute("aria-pressed") !== String(on)) chip.setAttribute("aria-pressed", String(on));
        });

        let visible = 0;
        let total = 0;
        this.gridTargets.forEach((grid) => {
            if (this.sizePx) grid.style.setProperty("--galmin", `${this.sizePx}px`);
            grid.querySelectorAll(".gal-card").forEach((card) => {
                total++;
                const matchFormat = this.format === "" || card.dataset.format === this.format;
                const matchName = this.query === "" || (card.dataset.name || "").includes(this.query);
                const show = matchFormat && matchName;
                if (card.hidden === show) card.hidden = !show;
                if (show) visible++;
            });
        });
        if (this.hasSizeTarget && this.sizePx && this.sizeTarget.value !== String(this.sizePx)) {
            this.sizeTarget.value = String(this.sizePx);
        }
        if (this.hasNoneTarget) {
            const none = total > 0 && visible === 0;
            if (this.noneTarget.hidden === none) this.noneTarget.hidden = !none;
        }
    }
}
