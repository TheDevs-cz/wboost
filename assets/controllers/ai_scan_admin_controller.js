/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { toast, toastAfterNavigation } from '../shell/toast.js';
import { api } from '../ai-scan/kit.js';

/*
 * Access requests of a project on the admin's edit-project page (ux.md §9.2)
 * and of every project on Admin › Statistiky › AI skeny (§9.4, one controller
 * per row there):
 * `Zapnout` → POST ai_scan_access_request_resolve …/approve (switches the
 * project on, approves its other open requests, e-mails the requesters) and
 * reloads, because the switch, its state and the usage line are server
 * rendered; `Zamítnout` → …/dismiss and the row leaves in place.
 */
export default class extends Controller {
    static values = { project: String };

    async resolve(event) {
        const button = event.currentTarget;
        const row = button.closest('[data-ai-scan-request]');
        const buttons = [...row.querySelectorAll('button')];
        buttons.forEach((b) => { b.disabled = true; });
        const answer = await api.post(button.dataset.url);

        if (!answer.ok && answer.code !== 'wrong_state') {
            buttons.forEach((b) => { b.disabled = false; });
            toast('Žádost se nepodařilo vyřídit. Zkuste to znovu.', { error: true });
            return;
        }

        // `wrong_state`: somebody resolved it meanwhile — show the page as it is now.
        if (button.dataset.decision === 'approve' || !answer.ok) {
            if (answer.ok) toastAfterNavigation(`AI sken zapnut pro projekt ${this.projectValue}`);
            window.location.reload();
            return;
        }

        // The list the row is in: this element on the edit-project page, the
        // rows' common parent on the admin tab (there every row is a controller).
        const list = row.closest('.as-reqs') || this.element;
        const card = list.closest('#ai-zadosti');
        row.remove();
        const left = list.querySelectorAll('[data-ai-scan-request]').length;

        // Admin tab: the counts beside the heading and on the tab follow, and
        // the card leaves with its last request.
        if (card) {
            document.querySelectorAll('#ai-zadosti h2 .pd-n, #st-tab-ai-skeny .pd-n').forEach((count) => {
                if (left) count.textContent = String(left);
                else count.remove();
            });
        }
        if (!left) (card || list).remove();
        toast('Žádost zamítnuta');
    }
}
