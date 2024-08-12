import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["checkbox"]

    connect() {
        this.checkboxTarget.addEventListener("change", this.toggle.bind(this));
    }

    toggle(event) {
        const url = event.target.checked
            ? event.target.dataset.urlChecked
            : event.target.dataset.urlUnchecked;

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // Handle the successful response data
            })
            .catch(error => {
                // Handle error
            });
    }
}
