import { Controller } from '@hotwired/stimulus';

/*
 * Admin › Uživatelé (templates/admin/users.html.twig) — prototype
 * renderUserFilters / uPasses, client-side over the rendered rows:
 *
 *  - search over name + e-mail (#uSearch in the page header, accent-insensitive)
 *  - one exclusive chip: Vše · Žádosti · Zamítnuté · Administrátoři ·
 *    Designeři · Uživatelé · Čekají na přijetí
 *  - Projekt ▾ (AND with the chip): people with access to the project
 *    (owner + shares); the owner gets a "Vlastník" pill. Requests never match.
 *  - "Zobrazeno X z Y" footer + empty row
 *
 * Rows carry data-kind (request|rejected|invited|user), data-role,
 * data-search, data-projects / data-owns (space-separated project ids).
 */
const fold = (text) => (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default class extends Controller {
    static targets = ['row', 'empty', 'shown', 'pfDrop', 'pfChip', 'pfLabel', 'pfMenu', 'pfSearch', 'pfOption', 'pfNone', 'pfFoot'];

    connect() {
        this.state = { q: '', uf: 'all', pf: '', pfName: '' };
        this.searchInput = document.getElementById('uSearch');
        this.onSearch = () => { this.state.q = fold(this.searchInput.value.trim()); this.apply(); };
        if (this.searchInput) this.searchInput.addEventListener('input', this.onSearch);
        this.onOutside = (event) => {
            if (this.hasPfMenuTarget && !this.pfMenuTarget.hidden && !this.pfDropTarget.contains(event.target)) this.closePf(false);
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.hasPfMenuTarget && !this.pfMenuTarget.hidden) this.closePf(true);
        };
        document.addEventListener('pointerdown', this.onOutside);
        document.addEventListener('keydown', this.onKey);
        this.apply();
    }

    disconnect() {
        if (this.searchInput) this.searchInput.removeEventListener('input', this.onSearch);
        document.removeEventListener('pointerdown', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    }

    chip(event) {
        this.state.uf = event.currentTarget.dataset.uf;
        this.apply();
    }

    // ---- Projekt ▾ ---------------------------------------------------------

    togglePf() {
        if (this.pfMenuTarget.hidden) {
            this.pfMenuTarget.hidden = false;
            this.pfChipTarget.setAttribute('aria-expanded', 'true');
            this.pfSearchTarget.value = '';
            this.searchPf();
            this.pfSearchTarget.focus({ preventScroll: true });
        } else {
            this.closePf(false);
        }
    }

    closePf(focusChip) {
        this.pfMenuTarget.hidden = true;
        this.pfChipTarget.setAttribute('aria-expanded', 'false');
        if (focusChip) this.pfChipTarget.focus();
    }

    searchPf() {
        const q = fold(this.pfSearchTarget.value.trim());
        let shown = 0;
        this.pfOptionTargets.forEach((opt) => {
            const isAll = opt.dataset.pf === '';
            const match = isAll ? q === '' : fold(opt.dataset.pfName).includes(q);
            opt.hidden = !match;
            if (match && !isAll) shown += 1;
        });
        const sep = this.pfMenuTarget.querySelector('.menu-sep');
        if (sep) sep.hidden = q !== '';
        this.pfNoneTarget.hidden = shown > 0;
    }

    pfKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            const first = this.pfOptionTargets.find((o) => !o.hidden && o.dataset.pf !== '')
                || this.pfOptionTargets.find((o) => !o.hidden);
            if (first) first.click();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            const first = this.pfOptionTargets.find((o) => !o.hidden);
            if (first) first.focus();
        }
    }

    pickPf(event) {
        const opt = event.currentTarget;
        this.state.pf = opt.dataset.pf;
        this.state.pfName = opt.dataset.pfName;
        this.pfOptionTargets.forEach((o) => o.setAttribute('aria-selected', String(o === opt)));
        const label = this.pfChipTarget.querySelector('.pf-ico');
        const sym = opt.querySelector('.sym');
        if (label) {
            if (this.pfIconHtml === undefined) this.pfIconHtml = label.innerHTML;
            label.innerHTML = this.state.pf && sym ? sym.outerHTML : this.pfIconHtml;
        }
        this.closePf(true);
        this.apply();
    }

    // ---- render ------------------------------------------------------------

    passes(row) {
        const { q, uf, pf } = this.state;
        const kind = row.dataset.kind;
        if (q && !fold(row.dataset.search).includes(q)) return false;
        if (uf === 'rejected') return kind === 'rejected';
        if (kind === 'rejected') return false;
        if (pf) {
            if (kind === 'request') return false;
            if (!(row.dataset.projects || '').split(' ').includes(pf)) return false;
        }
        if (uf === 'requests') return kind === 'request';
        if (uf === 'invited') return kind === 'invited';
        if (uf !== 'all') return kind !== 'request' && row.dataset.role === uf;
        return true;
    }

    apply() {
        let shown = 0;
        this.rowTargets.forEach((row) => {
            const ok = this.passes(row);
            row.hidden = !ok;
            if (ok) shown += 1;
            const own = row.querySelector('.u-own');
            if (own) own.hidden = !(this.state.pf && (row.dataset.owns || '').split(' ').includes(this.state.pf));
        });

        this.element.querySelectorAll('[data-uf]').forEach((chip) => {
            const on = chip.dataset.uf === this.state.uf;
            chip.classList.toggle('on', on);
            chip.setAttribute('aria-pressed', String(on));
        });

        if (this.hasPfChipTarget) {
            const on = this.state.pf !== '';
            this.pfChipTarget.classList.toggle('on', on);
            this.pfChipTarget.setAttribute('aria-pressed', String(on));
            this.pfLabelTarget.textContent = on ? this.state.pfName : 'Projekt';
        }

        this.shownTarget.textContent = String(shown);
        this.emptyTarget.hidden = shown > 0;
        this.emptyTarget.textContent = this.state.pf
            ? `K projektu ${this.state.pfName} nemá přístup nikdo, kdo odpovídá filtru.`
            : 'Nikdo neodpovídá hledání ani filtru.';
        if (this.hasPfFootTarget) {
            this.pfFootTarget.hidden = !this.state.pf;
            this.pfFootTarget.textContent = this.state.pf ? `Přístup k projektu ${this.state.pfName}` : '';
        }
    }
}
