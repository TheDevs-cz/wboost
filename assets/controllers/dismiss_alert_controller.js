import { Controller } from "@hotwired/stimulus";

/**
 * A warning the viewer can hide "for good" until the thing it warns about
 * changes (F9 — the manual-logos "V manuálu chybí N varianty loga" alert).
 *
 * The dismissal lives in localStorage under `keyValue` (per manual), holding
 * the `signatureValue` the server rendered at dismiss time — a fingerprint of
 * the state the warning is about (for the logos alert: every variant + its
 * file path, which carries an upload timestamp). Any upload / removal yields a
 * new signature, so the stored one no longer matches and the alert is back.
 *
 * The page also runs a tiny inline script right after the alert that hides it
 * synchronously when the stored signature matches, so a dismissed alert never
 * flashes before Stimulus connects; connect() repeats the check as a fallback.
 */
export default class extends Controller {
    static values = { key: String, signature: String };

    connect() {
        if (this.storedSignature() === this.signatureValue) {
            this.element.hidden = true;
        }
    }

    dismiss() {
        try {
            window.localStorage.setItem(this.keyValue, this.signatureValue);
        } catch (e) { /* private mode — still hide for this page view */ }
        this.element.hidden = true;
    }

    storedSignature() {
        try {
            return window.localStorage.getItem(this.keyValue);
        } catch (e) {
            return null;
        }
    }
}
