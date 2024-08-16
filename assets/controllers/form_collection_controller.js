import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["collection"];

    connect() {
        if (this.hasCollectionTarget) {
            this.index = this.collectionTarget.querySelectorAll(".form-group").length;
        } else {
            this.index = 0;
        }
    }

    add(event) {
        event.preventDefault(); // Prevent the default behavior of the button

        let prototype = this.collectionTarget.dataset.prototype;
        let form = prototype.replace(/__name__/g, this.index);

        // Find the add button
        const addButton = event.target;

        // Insert the new form element before the add button
        addButton.insertAdjacentHTML('beforebegin', form);

        this.index++;
    }

    remove(event) {
        event.preventDefault(); // Prevent the default behavior of the button

        event.target.closest(".form-group").remove();
    }
}
