import { Controller } from "@hotwired/stimulus";
import { clearBusy, setBusy } from "../busy_button.js";
import { askUnfilled, UNFILLED, CONFIRM_FIELD } from "../ai-scan/export-guard.js";

/**
 * Direct publish to Facebook / Instagram from the user-fill page.
 *
 * Instagram publishes to the PROJECT's connected account (one per project,
 * docs/architecture/social-publishing.md): the destination is fixed (the
 * server resolves it from the variant's project — no `targetId`), a 9:16
 * template goes out as a story (no caption). Facebook still publishes to the
 * user's own Pages via their personal Facebook link (destination picker).
 *
 * Attached to the fill FORM (alongside variant-fill-overlay), so `this.element`
 * IS the form: submitting re-posts the exact current fill state via
 * `new FormData(this.element)` — the same fields the PNG download sends — plus
 * `platform` / `targetId` / `caption` appended explicitly. The response is
 * JSON and no navigation happens, so the Live component and every
 * data-live-ignore subtree keep their state.
 *
 * Destinations (the user's Facebook Pages + linked Instagram accounts) are
 * fetched lazily ONCE per page load on first modal open and filtered
 * client-side by platform. Radio inputs are deliberately unnamed so they never
 * leak into the form's FormData.
 *
 * Publish is blocked while the overlay controller has the export button
 * disabled (container overflow) — same gating, read from the DOM instead of
 * coupling the two controllers.
 */
export default class extends Controller {
    static targets = ["modal", "title", "destinations", "caption", "captionBox", "captionCount", "storyNote", "submit", "status", "formPane", "donePane", "doneTitle", "doneLead", "doneLink", "fitNote"];
    static values = {
        publishUrl: String,
        destinationsUrl: String,
        profileUrl: String,
        instagramAccount: String,
        instagramStory: Boolean,
        instagramFits: { type: Boolean, default: true },
        socialUrl: String,
    };

    connect() {
        this.platform = null;
        this.pages = null;
        this.publishing = false;
        this._onKeydown = (event) => {
            // Escape inside a native dialog on top (the unfilled-pictures question) is its own.
            if (event.key === "Escape" && this.modalTarget.classList.contains("is-open") && !document.querySelector("dialog[open]")) {
                this.close();
            }
        };
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
    }

    open(event) {
        this.platform = event.params.platform === "instagram" ? "instagram" : "facebook";
        const story = this.platform === "instagram" && this.instagramStoryValue;
        this.titleTarget.textContent = this.platform === "instagram"
            ? (story ? "Publikovat story na Instagram" : "Publikovat na Instagram")
            : "Publikovat na Facebook";

        this.formPaneTarget.hidden = false;
        this.donePaneTarget.hidden = true;
        this.captionBoxTarget.hidden = story;
        this.storyNoteTarget.hidden = !story;
        this.fitNoteTarget.hidden = this.platform !== "instagram" || this.instagramFitsValue;
        this.countCaption();

        this._setStatus("", "muted");
        this.submitTarget.disabled = false;

        if (this._overflowing()) {
            this._setStatus("Texty přesahují vymezené oblasti — před publikováním je upravte.", "error");
            this.submitTarget.disabled = true;
        }

        this.modalTarget.classList.add("is-open");
        document.addEventListener("keydown", this._onKeydown);

        if (this.platform === "instagram") {
            this._renderInstagramDestination();
        } else {
            this._loadDestinations();
        }
    }

    countCaption() {
        if (this.hasCaptionCountTarget) {
            this.captionCountTarget.textContent = `${this.captionTarget.value.length} / 2200`;
        }
    }

    close() {
        this.modalTarget.classList.remove("is-open");
        document.removeEventListener("keydown", this._onKeydown);
    }

    closeBackdrop(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    async submit() {
        if (this.publishing) return;

        if (this._overflowing()) {
            this._setStatus("Texty přesahují vymezené oblasti — před publikováním je upravte.", "error");
            return;
        }

        const instagram = this.platform === "instagram";
        if (instagram && !this.instagramAccountValue) {
            this._renderInstagramDestination();
            return;
        }

        const selected = instagram ? null : this.destinationsTarget.querySelector("input[type=radio]:checked");
        if (!instagram && !selected) {
            this._setStatus("Vyberte, kam chcete příspěvek publikovat.", "error");
            return;
        }

        const formData = new FormData(this.element);
        formData.append("platform", this.platform);
        if (selected) {
            formData.append("targetId", selected.dataset.pageId);
        }
        formData.append("caption", instagram && this.instagramStoryValue ? "" : this.captionTarget.value);
        if (this._allowUnfilled) {
            formData.append(CONFIRM_FIELD, "1");
            this._allowUnfilled = false;
        }
        let again = false;

        this.publishing = true;
        setBusy(this.submitTarget, { label: "Publikuji…" });
        this._setStatus("Publikuji…", "busy");

        try {
            const response = await fetch(this.publishUrlValue, {
                method: "POST",
                body: formData,
                headers: { Accept: "application/json" },
            });
            const data = await response.json().catch(() => null);

            // A template with unfilled pictures (AI scan, ux.md T4): publishing
            // is an export — ask first, then repeat with `allowUnfilled=1`.
            // (No `return` here: the confirmed repeat is sent AFTER the
            // `finally` below has released the dialog — a `return` skipped it,
            // and "Exportovat přesto" published nothing.)
            if (response.status === 409 && data && data.code === UNFILLED && !formData.has(CONFIRM_FIELD)) {
                this._setStatus("", "");
                again = await askUnfilled(data);
            } else if (response.ok && data && data.ok) {
                this._showDone(data);
            } else {
                const message = (data && data.error) || "Publikování se nepovedlo. Zkuste to prosím znovu.";
                this._setStatus(message, "error", data && data.reconnect);
            }
        } catch (e) {
            this._setStatus("Publikování se nepovedlo. Zkuste to prosím znovu.", "error");
        } finally {
            this.publishing = false;
            clearBusy(this.submitTarget);
            this.submitTarget.disabled = false;
        }

        if (again) {
            this._allowUnfilled = true;
            await this.submit();
        }
    }

    /**
     * Swap the form for the success view. The caption is cleared so reopening
     * the dialog starts a fresh post instead of offering a double publish.
     */
    _showDone(data) {
        const instagram = data.platform === "instagram" || this.platform === "instagram";
        const story = data.kind === "story";
        this.doneTitleTarget.textContent = instagram
            ? (story ? "Story je na Instagramu" : "Publikováno na Instagram")
            : "Publikováno na Facebook";
        const where = data.account ? `@${data.account}` : (instagram ? "účet projektu" : "vybranou stránku");
        this.doneLeadTarget.textContent = story
            ? `Story na ${where} je venku. Na Instagramu vydrží 24 hodin.`
            : `Příspěvek najdete na ${where}.`;

        if (data.permalink) {
            this.doneLinkTarget.href = data.permalink;
            this.doneLinkTarget.lastChild.textContent = story ? "Zobrazit na Instagramu" : "Zobrazit příspěvek";
            this.doneLinkTarget.hidden = false;
        } else {
            this.doneLinkTarget.hidden = true;
        }

        this.captionTarget.value = "";
        this.countCaption();
        this._setStatus("", "muted");
        this.formPaneTarget.hidden = true;
        this.donePaneTarget.hidden = false;
        (data.permalink ? this.doneLinkTarget : this.donePaneTarget.querySelector("button")).focus();
    }

    _renderInstagramDestination() {
        const p = document.createElement("p");
        p.className = "small mb-0";

        if (this.instagramAccountValue) {
            const b = document.createElement("b");
            b.textContent = `@${this.instagramAccountValue}`;
            p.append(b, document.createTextNode(" — instagramový účet projektu"));
        } else {
            p.className = "text-danger small mb-0";
            p.append(document.createTextNode("Projekt nemá propojený Instagram. "));
            const link = document.createElement("a");
            link.href = this.socialUrlValue;
            // A new tab: the unsaved fill on this page must survive.
            link.target = "_blank";
            link.rel = "noopener";
            link.textContent = "Propojit Instagram (nová karta)";
            p.append(link);
            this.submitTarget.disabled = true;
        }

        this.destinationsTarget.replaceChildren(p);
    }

    async _loadDestinations() {
        if (this.pages === null) {
            this._renderMessage("Načítám…");
            try {
                const response = await fetch(this.destinationsUrlValue, { headers: { Accept: "application/json" } });
                const data = await response.json();

                if (!data.connected) {
                    this._renderReconnect();
                    return;
                }
                this.pages = data.pages || [];
            } catch (e) {
                this._renderMessage("Načtení stránek se nepovedlo. Zavřete okno a zkuste to znovu.");
                return;
            }
        }

        this._renderDestinations();
    }

    _renderDestinations() {
        const pages = this.pages;

        this.destinationsTarget.replaceChildren();

        if (pages.length === 0) {
            this._renderMessage("Nemáte žádnou facebookovou stránku, na kterou by šlo publikovat.");
            return;
        }

        pages.forEach((page, index) => {
            const id = `publish-destination-${page.id}`;
            const wrapper = document.createElement("div");
            wrapper.className = "form-check";

            const radio = document.createElement("input");
            radio.type = "radio";
            radio.className = "form-check-input";
            radio.id = id;
            // No `name`: must not leak into the fill form's FormData. Grouping
            // is emulated manually below.
            radio.dataset.pageId = page.id;
            radio.checked = index === 0;
            radio.addEventListener("change", () => {
                this.destinationsTarget.querySelectorAll("input[type=radio]").forEach((other) => {
                    if (other !== radio) other.checked = false;
                });
            });

            const label = document.createElement("label");
            label.className = "form-check-label small";
            label.htmlFor = id;
            label.textContent = page.name;

            wrapper.append(radio, label);
            this.destinationsTarget.append(wrapper);
        });
    }

    _renderMessage(text) {
        const p = document.createElement("p");
        p.className = "text-muted small mb-0";
        p.textContent = text;
        this.destinationsTarget.replaceChildren(p);
    }

    _renderReconnect() {
        const p = document.createElement("p");
        p.className = "text-danger small mb-0";
        p.textContent = "Připojení k Facebooku vypršelo. ";
        const link = document.createElement("a");
        link.href = this.profileUrlValue;
        link.textContent = "Znovu propojit účet";
        p.append(link);
        this.destinationsTarget.replaceChildren(p);
        this.submitTarget.disabled = true;
    }

    _overflowing() {
        const exportButton = this.element.querySelector('[data-variant-fill-overlay-target="exportButton"]');
        return Boolean(exportButton && exportButton.disabled);
    }

    _setStatus(text, kind, reconnect = false) {
        const status = this.statusTarget;
        status.replaceChildren();
        status.className = "small mb-2 " + (kind === "error" ? "text-danger" : kind === "ok" ? "text-success" : "text-muted");

        if (text) {
            status.append(document.createTextNode(text));
        }

        if (reconnect) {
            if (this.platform === "instagram") {
                this._appendLink(this.socialUrlValue, "Otevřít propojení Instagramu (nová karta)", true);
            } else {
                this._appendLink(this.profileUrlValue, "Přejít na propojení účtu", false);
            }
        }

        status.setAttribute("role", kind === "error" ? "alert" : "status");
    }

    _appendLink(href, text, newTab) {
        this.statusTarget.append(document.createTextNode(" "));
        const link = document.createElement("a");
        link.href = href;
        link.textContent = text;
        if (newTab) {
            link.target = "_blank";
            link.rel = "noopener";
        }
        this.statusTarget.append(link);
    }
}
