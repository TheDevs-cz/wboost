import { Controller } from '@hotwired/stimulus';

/*
 * Shared "Nový projekt" / "Změnit název a logo" dialog (#project-form-modal,
 * templates/_project_form_dialog.html.twig) on /projects and the project
 * overview. Any link carrying `data-project-form-modal` — header + phone
 * primary action, empty state, the card / overview ⋯ menu — opens this
 * Bootstrap modal instead of navigating (`="dashboard"` adds `back=dashboard`
 * so the save returns to the overview): the form is
 * loaded from `<href>?modal=1` (add_project / edit_project render
 * _project_form_modal.html.twig) into .modal-content and submitted with fetch
 * as multipart FormData, so the logo upload keeps working. A 422 answer is the
 * re-rendered fragment with the validation errors; success answers JSON
 * `{redirect}` (the flash is already queued) and the page navigates there.
 * Modified clicks and every failure fall back to the full page (real href).
 */
export default class extends Controller {
    static targets = ['content'];

    connect() {
        this.modal = window.bootstrap.Modal.getOrCreateInstance(this.element);
        this.seq = 0;
        this.onClick = this.onClick.bind(this);
        this.onSubmit = this.onSubmit.bind(this);
        this.onShown = () => this.focusField();
        this.onHidden = () => { this.seq++; this.contentTarget.innerHTML = ''; };
        document.addEventListener('click', this.onClick);
        this.element.addEventListener('submit', this.onSubmit);
        this.element.addEventListener('shown.bs.modal', this.onShown);
        this.element.addEventListener('hidden.bs.modal', this.onHidden);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        this.element.removeEventListener('submit', this.onSubmit);
        this.element.removeEventListener('shown.bs.modal', this.onShown);
        this.element.removeEventListener('hidden.bs.modal', this.onHidden);
    }

    onClick(e) {
        const link = e.target.closest('a[data-project-form-modal]');
        if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        this.open(link.href, link.dataset.projectFormModal);
    }

    async open(href, back = '') {
        const seq = ++this.seq;
        this.contentTarget.innerHTML = '<div class="modal-body pf-loading"><span class="spinner-border" role="status"><span class="visually-hidden">Načítám…</span></span></div>';
        this.modal.show();

        try {
            const response = await fetch(this.modalUrl(href, back), { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            // A redirect here means the session ended (login page) — let the full page handle it.
            if (!response.ok || response.redirected) throw new Error(String(response.status));
            const html = await response.text();
            if (seq !== this.seq) return;
            this.contentTarget.innerHTML = html;
            this.focusField();
        } catch (err) {
            if (seq === this.seq) window.location.assign(href);
        }
    }

    async onSubmit(e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !this.contentTarget.contains(form)) return;
        e.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        if (button) button.classList.add('is-loading');
        const seq = this.seq;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json, text/html' },
                credentials: 'same-origin',
            });
            if (seq !== this.seq) return;
            const type = response.headers.get('Content-Type') || '';
            if (response.ok && type.includes('application/json')) {
                const data = await response.json();
                window.location.assign(data.redirect || window.location.href);
                return;
            }
            if ((response.status === 422 || response.ok) && !response.redirected && type.includes('text/html')) {
                this.contentTarget.innerHTML = await response.text();
                this.focusField(true);
                return;
            }
            throw new Error(String(response.status));
        } catch (err) {
            if (seq !== this.seq) return;
            if (button) button.classList.remove('is-loading');
            this.showError(form);
        }
    }

    showError(form) {
        let alert = form.querySelector('.pf-err');
        if (!alert) {
            alert = document.createElement('div');
            alert.className = 'alert alert-danger pf-err m-0';
            alert.setAttribute('role', 'alert');
            form.prepend(alert);
        }
        alert.textContent = 'Uložení se nezdařilo. Zkontrolujte připojení a zkuste to prosím znovu.';
    }

    focusField(invalidFirst = false) {
        const root = this.contentTarget;
        const field = (invalidFirst && root.querySelector('.is-invalid'))
            || root.querySelector('input[type="text"]');
        if (field && this.element.classList.contains('show')) field.focus();
    }

    modalUrl(href, back) {
        const url = new URL(href, window.location.href);
        url.searchParams.set('modal', '1');
        if (back) url.searchParams.set('back', back);
        return url.toString();
    }
}
