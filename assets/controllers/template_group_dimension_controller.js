import { Controller } from "@hotwired/stimulus";

/**
 * Shows either the social-dimension select or the custom width/height fields
 * on the "add dimension to group" page, based on the chosen module radio.
 */
export default class extends Controller {
    static targets = ["socialSection", "customSection"];

    connect() {
        this.toggleModule();
    }

    toggleModule() {
        const checked = this.element.querySelector('input[name$="[module]"]:checked');
        const module = checked ? checked.value : "social";

        if (this.hasSocialSectionTarget) {
            this.socialSectionTarget.style.display = module === "social" ? "" : "none";
        }

        if (this.hasCustomSectionTarget) {
            this.customSectionTarget.style.display = module === "custom" ? "" : "none";
        }
    }
}
