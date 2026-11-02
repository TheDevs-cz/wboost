import { Controller } from '@hotwired/stimulus';

/*
 * Checkbox pick list with a search field (admin invite: "Sdílet projekty").
 * Filters the item rows by data-text and shows "N projektů" for the ticked
 * ones. The checkboxes are the real form fields — this only hides rows.
 */
const fold = (text) => (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const word = (n) => (n === 1 ? 'projekt' : n >= 2 && n <= 4 ? 'projekty' : 'projektů');

export default class extends Controller {
    static targets = ['search', 'item', 'none', 'count'];

    connect() {
        this.count();
    }

    filter() {
        const q = fold(this.searchTarget.value.trim());
        let shown = 0;
        this.itemTargets.forEach((item) => {
            const ok = !q || fold(item.dataset.text).includes(q);
            item.hidden = !ok;
            if (ok) shown += 1;
        });
        if (this.hasNoneTarget) this.noneTarget.hidden = shown > 0;
    }

    count() {
        if (!this.hasCountTarget) return;
        const n = this.itemTargets.filter((item) => item.querySelector('input:checked')).length;
        this.countTarget.textContent = n ? `${n} ${word(n)}` : '';
    }
}
