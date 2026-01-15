import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container', 'addButton'];
    static values = {
        prototype: String,
        index: Number,
        maxItems: Number,
    };

    connect() {
        this.indexValue = this.containerTarget.children.length;
        this.updateAddButtonVisibility();
    }

    add(event) {
        event.preventDefault();

        if (this.maxItemsValue > 0 && this.indexValue >= this.maxItemsValue) {
            return;
        }

        const prototype = this.prototypeValue.replace(/__name__/g, this.indexValue);
        const div = document.createElement('div');
        div.classList.add('variant-item', 'mb-3', 'p-3', 'border', 'rounded', 'bg-light');
        div.setAttribute('data-controller', 'meal-variant-mode');
        div.innerHTML = prototype;

        // Add remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.classList.add('btn', 'btn-sm', 'btn-outline-danger', 'mt-2');
        removeBtn.innerHTML = '<i class="mdi mdi-delete"></i> Odebrat variantu';
        removeBtn.addEventListener('click', () => this.remove(div));
        div.appendChild(removeBtn);

        this.containerTarget.appendChild(div);
        this.indexValue++;
        this.updateAddButtonVisibility();
    }

    remove(element) {
        element.remove();
        this.updateAddButtonVisibility();
    }

    removeExisting(event) {
        event.preventDefault();
        const item = event.target.closest('.variant-item');
        if (item) {
            item.remove();
            this.updateAddButtonVisibility();
        }
    }

    updateAddButtonVisibility() {
        if (this.hasAddButtonTarget) {
            const currentCount = this.containerTarget.querySelectorAll('.variant-item').length;
            this.addButtonTarget.style.display =
                (this.maxItemsValue > 0 && currentCount >= this.maxItemsValue) ? 'none' : '';
        }
    }
}
