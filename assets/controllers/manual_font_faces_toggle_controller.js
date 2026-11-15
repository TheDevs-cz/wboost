import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String
    }

    static targets = ['checkbox']

    connect() {
        this.checkboxTargets.forEach(checkbox => {
            checkbox.addEventListener('change', this.submit.bind(this));
        });
    }

    // Autosave on every tick. Each post carries the WHOLE set, so two in
    // flight could land out of order and store the older one: one post at a
    // time, and a change meanwhile sends the newest set once more after it.
    submit() {
        if (this.inFlight) {
            this.again = true;
            return;
        }
        this.inFlight = true;
        this.again = false;

        // Gather all checked checkboxes
        const checkedCheckboxes = this.checkboxTargets
            .filter(checkbox => checkbox.checked)
            .map(checkbox => checkbox.value);

        // Send the data via fetch
        fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ fontFaces: checkedCheckboxes })
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Success:', data);
            })
            .catch(error => {
                console.error('Error:', error);
            })
            .finally(() => {
                this.inFlight = false;
                if (this.again) {
                    this.submit();
                }
            });
    }
}
