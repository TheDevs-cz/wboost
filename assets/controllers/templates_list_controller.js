import { Controller } from "@hotwired/stimulus";

/**
 * View tools of the Šablony listing (Nocturne redesign, prototype
 * renderTemplates): collapsible category sections, "Sbalit / Rozbalit vše",
 * a name search and the card-size slider. Nothing goes to the server.
 *
 * - Collapsed sections are remembered per project and browser
 *   (`wb-tpclosed-<projectId>`, JSON list of section keys — category ids,
 *   `none` for "Nezařazené", `top` for "Nejpoužívanější").
 * - Search hides non-matching cards (`data-name`, lower-cased), hides empty
 *   sections and the "Nejpoužívanější" row, opens every section while a
 *   query is active and shows the "Nic jsme nenašli" state.
 * - The slider (0–100, `wb-sz2-tp`, like the prototype) sets --tpmin (card
 *   width), --tph (preview height) and --tpfs (title size) on the element.
 */
const SIZE = { min: 170, max: 480, def: 250 };

export default class extends Controller {
    static targets = ["group", "all", "search", "size", "none"];
    static values = { project: String };

    connect() {
        this.closed = new Set(this.readClosed());
        this.query = "";

        let size = Math.round(((SIZE.def - SIZE.min) / (SIZE.max - SIZE.min)) * 100);
        try {
            const raw = window.localStorage.getItem("wb-sz2-tp");
            const stored = Number(raw);
            if (raw !== null && Number.isFinite(stored)) size = Math.max(0, Math.min(100, stored));
        } catch (error) {
            // Storage unavailable — default size.
        }
        if (this.hasSizeTarget) this.sizeTarget.value = String(size);
        this.applySize(size);
        this.render();
    }

    toggle(event) {
        const section = event.currentTarget.closest("[data-key]");
        if (!section || this.query !== "") return;
        const key = section.dataset.key;
        if (this.closed.has(key)) this.closed.delete(key);
        else this.closed.add(key);
        this.saveClosed();
        this.render();
    }

    toggleAll() {
        const keys = this.groupTargets.filter((group) => !("top" in group.dataset)).map((group) => group.dataset.key);
        const anyClosed = keys.some((key) => this.closed.has(key));
        if (anyClosed) keys.forEach((key) => this.closed.delete(key));
        else keys.forEach((key) => this.closed.add(key));
        this.saveClosed();
        this.render();
    }

    search(event) {
        this.query = (event.currentTarget.value || "").trim().toLowerCase();
        this.render();
    }

    resize(event) {
        const value = Number(event.currentTarget.value);
        this.applySize(value);
        try {
            window.localStorage.setItem("wb-sz2-tp", String(value));
        } catch (error) {
            // Non-fatal.
        }
    }

    applySize(value) {
        const px = Math.round(SIZE.min + ((SIZE.max - SIZE.min) * value) / 100);
        this.element.style.setProperty("--tpmin", `${px}px`);
        this.element.style.setProperty("--tph", `${Math.round(px * 1.12)}px`);
        this.element.style.setProperty("--tpfs", `${(13 + value * 0.05).toFixed(1)}px`);
        this.element.classList.toggle("tp-sm", value < 22);
        if (this.hasSizeTarget) {
            this.sizeTarget.style.setProperty("--p", `${value}%`);
            this.sizeTarget.setAttribute("aria-valuetext", `${value} %`);
        }
    }

    render() {
        const searching = this.query !== "";
        let anyVisible = false;

        this.groupTargets.forEach((group) => {
            const isTop = "top" in group.dataset;
            let matches = 0;
            group.querySelectorAll(".tp-card").forEach((card) => {
                const show = !searching || (card.dataset.name || "").includes(this.query);
                if (card.hidden === show) card.hidden = !show;
                if (show) matches++;
            });

            const hideGroup = searching && (isTop || matches === 0);
            group.hidden = hideGroup;
            if (!hideGroup && !isTop) anyVisible = anyVisible || matches > 0;

            const open = searching || !this.closed.has(group.dataset.key);
            group.classList.toggle("closed", !open);
            const body = group.querySelector(".tp-body");
            if (body) body.hidden = !open;
            const toggle = group.querySelector(".tp-tg");
            if (toggle) toggle.setAttribute("aria-expanded", String(open));
        });

        if (this.hasNoneTarget) this.noneTarget.hidden = !searching || anyVisible;

        if (this.hasAllTarget) {
            const keys = this.groupTargets.filter((group) => !("top" in group.dataset)).map((group) => group.dataset.key);
            const anyClosed = keys.some((key) => this.closed.has(key));
            this.allTarget.hidden = searching;
            this.allTarget.innerHTML = anyClosed
                ? '<span class="i i-chev-down s16" aria-hidden="true"></span><span>Rozbalit vše</span>'
                : '<span class="i i-chev-up s16" aria-hidden="true"></span><span>Sbalit vše</span>';
        }
    }

    storageKey() {
        return `wb-tpclosed-${this.projectValue}`;
    }

    readClosed() {
        try {
            const parsed = JSON.parse(window.localStorage.getItem(this.storageKey()) || "[]");
            return Array.isArray(parsed) ? parsed.map(String) : [];
        } catch (error) {
            return [];
        }
    }

    saveClosed() {
        try {
            window.localStorage.setItem(this.storageKey(), JSON.stringify([...this.closed]));
        } catch (error) {
            // Non-fatal.
        }
    }
}
