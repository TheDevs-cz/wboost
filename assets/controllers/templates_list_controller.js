import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";
import { withMoveCursor } from "./sort_drag_cursor.js";

/**
 * View tools of the Šablony listing (Nocturne redesign, prototype
 * renderTemplates): collapsible category sections, "Sbalit / Rozbalit vše",
 * a name search, the card-size slider and the category drag-reorder.
 *
 * - Every page load starts with all category sections collapsed (incl.
 *   "Nezařazené") and only "Nejpoužívanější" (`top`) open; a lone section
 *   (no categories, or a single one) starts open. The open state is NOT
 *   remembered (feedback F4) — the old `wb-tpclosed-<projectId>` key is
 *   dropped on connect.
 * - Search hides non-matching cards (`data-name`, lower-cased), hides empty
 *   sections and the "Nejpoužívanější" row, opens every section while a
 *   query is active and shows the "Nic jsme nenašli" state.
 * - The slider (0–100, `wb-sz2-tp`, like the prototype) sets --tpmin (card
 *   width), --tph (preview height) and --tpfs (title size) on the element.
 * - Category reorder (only when `sortUrl` is set, i.e. project_edit and ≥ 2
 *   visible categories): SortableJS on the `groups` target, dragged by the
 *   header grip `.tp-ggrip`, so it works on collapsed headers and never
 *   competes with the per-grid card sorting (`.dragula-handle`). Only
 *   `[data-category-id]` sections move; "Nezařazené" stays last. The new
 *   visible order is re-slotted into `categoryOrder` (every category id in
 *   position order, empty/hidden ones included) and POSTed whole to
 *   sort_template_categories. Disabled while a search is active.
 */
const SIZE = { min: 170, max: 480, def: 250 };

export default class extends Controller {
    static targets = ["group", "groups", "all", "search", "size", "none"];
    static values = { project: String, sortUrl: String, categoryOrder: Array };

    connect() {
        const sections = this.groupTargets.filter((group) => !("top" in group.dataset));
        this.closed = new Set(sections.length > 1 ? sections.map((group) => group.dataset.key) : []);
        this.query = "";
        try {
            window.localStorage.removeItem(`wb-tpclosed-${this.projectValue}`);
        } catch (error) {
            // Storage unavailable — nothing to clean up.
        }

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
        this.setupCategorySort();
    }

    disconnect() {
        if (this.sortable) {
            this.sortable.destroy();
            this.sortable = null;
        }
    }

    setupCategorySort() {
        if (!this.sortUrlValue || !this.hasGroupsTarget) return;

        this.sortable = Sortable.create(this.groupsTarget, withMoveCursor({
            handle: ".tp-ggrip",
            draggable: "[data-category-id]",
            direction: "vertical",
            // Fallback drag like every other list (see dragula_controller):
            // the .gu-mirror clone follows the pointer, the section itself
            // keeps only .gu-transit and stays in flow.
            forceFallback: true,
            ghostClass: "gu-transit",
            dragClass: "gu-mirror",
            // "Nezařazené" is not draggable and must stay last: anything
            // dropped against it goes before it.
            onMove: (event) => (event.related && !event.related.dataset.categoryId ? -1 : true),
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) this.saveCategoryOrder();
            },
        }));
    }

    saveCategoryOrder() {
        const visible = Array.from(this.groupsTarget.querySelectorAll(":scope > [data-category-id]"))
            .map((section) => section.dataset.categoryId);
        const visibleSet = new Set(visible);
        const queue = [...visible];
        const order = this.categoryOrderValue.map((id) => (visibleSet.has(id) ? queue.shift() : id));
        // A category the page knows but the order list does not (should not
        // happen) is appended rather than dropped.
        order.push(...queue);
        this.categoryOrderValue = order;

        fetch(this.sortUrlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
            body: JSON.stringify({ sorted: order }),
        })
            .then((response) => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
            })
            .catch((error) => console.error("Saving the category order failed:", error));
    }

    toggle(event) {
        const section = event.currentTarget.closest("[data-key]");
        if (!section || this.query !== "") return;
        const key = section.dataset.key;
        if (this.closed.has(key)) this.closed.delete(key);
        else this.closed.add(key);
        this.render();
    }

    toggleAll() {
        const keys = this.groupTargets.filter((group) => !("top" in group.dataset)).map((group) => group.dataset.key);
        const anyClosed = keys.some((key) => this.closed.has(key));
        if (anyClosed) keys.forEach((key) => this.closed.delete(key));
        else keys.forEach((key) => this.closed.add(key));
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
        if (this.sortable) this.sortable.option("disabled", searching);

        if (this.hasAllTarget) {
            const keys = this.groupTargets.filter((group) => !("top" in group.dataset)).map((group) => group.dataset.key);
            const anyClosed = keys.some((key) => this.closed.has(key));
            this.allTarget.hidden = searching;
            this.allTarget.innerHTML = anyClosed
                ? '<span class="i i-chev-down s16" aria-hidden="true"></span><span>Rozbalit vše</span>'
                : '<span class="i i-chev-up s16" aria-hidden="true"></span><span>Sbalit vše</span>';
        }
    }
}
