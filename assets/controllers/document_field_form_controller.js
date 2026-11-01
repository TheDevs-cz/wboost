import { Controller } from '@hotwired/stimulus';

/**
 * The shared-field modal on the document page ("Společná pole"): each page's
 * binding select lists the page's bindable texts, image slots and
 * recolourable shapes (one <optgroup data-kind> each); this keeps only the group matching the
 * chosen kind enabled and drops a pick of the other kind. Without JS the
 * server still refuses a mismatched binding (flash).
 */
export default class extends Controller {
    static targets = ['kind', 'binding'];

    connect() {
        this.kindChanged();
    }

    kindChanged() {
        const kind = this.hasKindTarget ? this.kindTarget.value : 'text';

        this.bindingTargets.forEach((select) => {
            select.querySelectorAll('optgroup[data-kind]').forEach((group) => {
                const active = group.dataset.kind === kind;
                group.disabled = !active;
                group.hidden = !active;
            });

            const picked = select.selectedOptions[0];
            if (picked && picked.parentElement && picked.parentElement.disabled) {
                select.value = '';
            }
        });
    }
}
