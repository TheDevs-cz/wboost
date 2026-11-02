import { Controller } from '@hotwired/stimulus';

/*
 * One exclusive chip row over a list (admin › Poptávky z webu: source chips).
 * Chips carry data-value ("" = all), items data-group.
 */
export default class extends Controller {
    static targets = ['item', 'shown'];

    pick(event) {
        const value = event.currentTarget.dataset.value || '';
        this.element.querySelectorAll('.chip[data-value]').forEach((chip) => {
            const on = (chip.dataset.value || '') === value;
            chip.classList.toggle('on', on);
            chip.setAttribute('aria-pressed', String(on));
        });
        let shown = 0;
        this.itemTargets.forEach((item) => {
            const ok = value === '' || item.dataset.group === value;
            item.hidden = !ok;
            if (ok) shown += 1;
        });
        if (this.hasShownTarget) this.shownTarget.textContent = String(shown);
    }
}
