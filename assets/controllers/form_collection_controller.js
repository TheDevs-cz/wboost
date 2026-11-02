import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["collection"];
    // append: new rows go to the END of the collection target (the add
    // button may then live anywhere inside the controller, e.g. a toolbar).
    static values = { append: Boolean };

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

        if (this.appendValue) {
            this.collectionTarget.insertAdjacentHTML('beforeend', form);
            this.index++;
            return;
        }

        // Find the add button (the click may land on its icon)
        const addButton = event.target.closest('button, a') || event.target;

        // Insert the new form element before the add button
        addButton.insertAdjacentHTML('beforebegin', form);

        this.index++;
    }

    remove(event) {
        event.preventDefault(); // Prevent the default behavior of the button

        event.target.closest(".form-group").remove();
    }
}
