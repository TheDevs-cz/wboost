import { Controller } from "@hotwired/stimulus";

/**
 * Direct publish to Facebook / Instagram from the user-fill page.
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
    static targets = ["modal", "title", "destinations", "caption", "submit", "status"];
    static values = {
        publishUrl: String,
        destinationsUrl: String,
        profileUrl: String,
    };

    connect() {
        this.platform = null;
        this.pages = null;
        this.publishing = false;
        this._onKeydown = (event) => {
            if (event.key === "Escape" && this.modalTarget.classList.contains("is-open")) {
                this.close();
            }
        };
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
    }

    open(event) {
        this.platform = event.params.platform === "instagram" ? "instagram" : "facebook";
        this.titleTarget.textContent = this.platform === "instagram"
            ? "Publikovat na Instagram"
            : "Publikovat na Facebook";

        this._setStatus("", "muted");
        this.submitTarget.disabled = false;

        if (this._overflowing()) {
            this._setStatus("Texty přesahují vymezené oblasti — před publikováním je upravte.", "error");
            this.submitTarget.disabled = true;
        }

        this.modalTarget.classList.add("is-open");
        document.addEventListener("keydown", this._onKeydown);
        this._loadDestinations();
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

        const selected = this.destinationsTarget.querySelector("input[type=radio]:checked");
        if (!selected) {
            this._setStatus("Vyberte, kam chcete příspěvek publikovat.", "error");
            return;
        }

        const formData = new FormData(this.element);
        formData.append("platform", this.platform);
        formData.append("targetId", selected.dataset.pageId);
        formData.append("caption", this.captionTarget.value);

        this.publishing = true;
        this.submitTarget.disabled = true;
        this._setStatus("Publikuji…", "busy");

        try {
            const response = await fetch(this.publishUrlValue, {
                method: "POST",
                body: formData,
                headers: { Accept: "application/json" },
            });
            const data = await response.json().catch(() => null);

            if (response.ok && data && data.ok) {
                this._setStatus("Hotovo — příspěvek byl publikován.", "ok");
                return;
            }

            const message = (data && data.error) || "Publikování se nepovedlo. Zkuste to prosím znovu.";
            this._setStatus(message, "error", data && data.reconnect);
        } catch (e) {
            this._setStatus("Publikování se nepovedlo. Zkuste to prosím znovu.", "error");
        } finally {
            this.publishing = false;
            this.submitTarget.disabled = false;
        }
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
        const pages = this.platform === "instagram"
            ? this.pages.filter((page) => page.instagram)
            : this.pages;

        this.destinationsTarget.replaceChildren();

        if (pages.length === 0) {
            this._renderMessage(this.platform === "instagram"
                ? "Žádný instagramový profesionální účet — propojte Instagram s vaší facebookovou stránkou (nastavení stránky → propojené účty)."
                : "Nemáte žádnou facebookovou stránku, na kterou by šlo publikovat.");
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
            label.textContent = this.platform === "instagram"
                ? `@${page.instagram.username || page.name} (přes stránku ${page.name})`
                : page.name;

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
            status.append(document.createTextNode(" "));
            const link = document.createElement("a");
            link.href = this.profileUrlValue;
            link.textContent = "Přejít na propojení účtu";
            status.append(link);
        }

        status.setAttribute("role", kind === "error" ? "alert" : "status");
    }
}
