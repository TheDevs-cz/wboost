import { Controller } from '@hotwired/stimulus';

/*
 * Projects dashboard (/projects, templates/projects.html.twig) — prototype
 * renderDashboard/renderFilters, client-side over the rendered cards:
 *
 *  - greeting eyebrow by the browser's local hour (#dashGreeting)
 *  - search over project names (#dashSearch, accent-insensitive)
 *  - filter chips (AND-combined): Moje projekty · Grafik ▾ (owner, with a
 *    searchable menu) · S manuálem / Bez manuálu (mutually exclusive); "Vše"
 *    resets them
 *  - visible count in the h1 (#dashCount) + the "no results" empty state
 *
 * The header search + count live outside this element (page header), so they
 * are looked up by id.
 */
const fold = (text) => (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default class extends Controller {
    static targets = [
        'card', 'allChip', 'noResults', 'noResultsTitle', 'noResultsIcon', 'clearSearch', 'clearFilters',
        'ownerDrop', 'ownerChip', 'ownerLabel', 'ownerMenu', 'ownerSearch', 'ownerOption', 'ownerNone',
    ];

    static values = { me: String };

    connect() {
        this.state = { q: '', mine: false, owner: '', manual: '' };
        this.searchInput = document.getElementById('dashSearch');
        this.countEl = document.getElementById('dashCount');
        this.onSearch = () => { this.state.q = fold(this.searchInput.value.trim()); this.apply(); };
        if (this.searchInput) {
            this.searchInput.addEventListener('input', this.onSearch);
        }
        this.onOutside = (event) => {
            if (this.hasOwnerDropTarget && !this.ownerMenuTarget.hidden && !this.ownerDropTarget.contains(event.target)) {
                this.closeOwnerMenu(false);
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.hasOwnerMenuTarget && !this.ownerMenuTarget.hidden) {
                this.closeOwnerMenu(true);
            }
        };
        document.addEventListener('pointerdown', this.onOutside);
        document.addEventListener('keydown', this.onKey);
        this.greet();
    }

    disconnect() {
        if (this.searchInput) {
            this.searchInput.removeEventListener('input', this.onSearch);
        }
        document.removeEventListener('pointerdown', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    }

    greet() {
        const el = document.getElementById('dashGreeting');
        if (!el) return;
        const h = new Date().getHours();
        const word = h < 10 ? 'Dobré ráno' : h < 12 ? 'Dobrý den' : h < 18 ? 'Dobré odpoledne' : 'Dobrý večer';
        const name = el.dataset.name || '';
        el.textContent = name ? `${word}, ${name}` : word;
    }

    // ---- chips -------------------------------------------------------------

    toggle(event) {
        const chip = event.currentTarget;
        const f = chip.dataset.filter;
        if (f === 'mine') {
            this.state.mine = !this.state.mine;
        } else if (f === 'manual-yes' || f === 'manual-no') {
            const v = f === 'manual-yes' ? 'yes' : 'no';
            this.state.manual = this.state.manual === v ? '' : v;
        }
        this.apply();
    }

    reset() {
        this.state.mine = false;
        this.state.owner = '';
        this.state.manual = '';
        this.apply();
    }

    clearSearch() {
        if (this.searchInput) {
            this.searchInput.value = '';
            this.searchInput.focus();
        }
        this.state.q = '';
        this.apply();
    }

    // ---- owner (Grafik) dropdown chip -------------------------------------

    toggleOwnerMenu() {
        if (this.ownerMenuTarget.hidden) {
            this.ownerMenuTarget.hidden = false;
            this.ownerChipTarget.setAttribute('aria-expanded', 'true');
            this.ownerSearchTarget.value = '';
            this.searchOwners();
            this.ownerSearchTarget.focus({ preventScroll: true });
        } else {
            this.closeOwnerMenu(false);
        }
    }

    closeOwnerMenu(focusChip) {
        this.ownerMenuTarget.hidden = true;
        this.ownerChipTarget.setAttribute('aria-expanded', 'false');
        if (focusChip) this.ownerChipTarget.focus();
    }

    searchOwners() {
        const q = fold(this.ownerSearchTarget.value.trim());
        let shown = 0;
        this.ownerOptionTargets.forEach((opt) => {
            const isAll = opt.dataset.owner === '';
            const match = isAll ? q === '' : fold(opt.dataset.ownerName).includes(q);
            opt.hidden = !match;
            if (match && !isAll) shown += 1;
        });
        const sep = this.ownerMenuTarget.querySelector('.menu-sep');
        if (sep) sep.hidden = q !== '';
        this.ownerNoneTarget.hidden = shown > 0;
    }

    ownerKeydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            const first = this.ownerOptionTargets.find((o) => !o.hidden && o.dataset.owner !== '');
            if (first) first.click();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            const first = this.ownerOptionTargets.find((o) => !o.hidden);
            if (first) first.focus();
        }
    }

    pickOwner(event) {
        this.state.owner = event.currentTarget.dataset.owner;
        this.closeOwnerMenu(true);
        this.apply();
    }

    // ---- render ------------------------------------------------------------

    apply() {
        const s = this.state;
        let visible = 0;
        this.cardTargets.forEach((card) => {
            const pass = (!s.q || fold(card.dataset.name).includes(s.q))
                && (!s.mine || card.dataset.owner === this.meValue)
                && (!s.owner || card.dataset.owner === s.owner)
                && (!s.manual || (s.manual === 'yes') === (card.dataset.manual === '1'));
            card.hidden = !pass;
            if (pass) visible += 1;
        });

        const anyFilter = s.mine || !!s.owner || !!s.manual;
        this.element.querySelectorAll('.chip[data-filter]').forEach((chip) => {
            const f = chip.dataset.filter;
            const on = f === 'mine' ? s.mine : f === 'manual-yes' ? s.manual === 'yes' : s.manual === 'no';
            chip.classList.toggle('on', on);
            chip.setAttribute('aria-pressed', String(on));
        });
        if (this.hasAllChipTarget) {
            this.allChipTarget.classList.toggle('on', !anyFilter);
            this.allChipTarget.setAttribute('aria-pressed', String(!anyFilter));
        }
        if (this.hasOwnerChipTarget) {
            const opt = this.ownerOptionTargets.find((o) => o.dataset.owner === s.owner);
            this.ownerChipTarget.classList.toggle('on', !!s.owner);
            this.ownerChipTarget.setAttribute('aria-pressed', String(!!s.owner));
            this.ownerLabelTarget.textContent = s.owner && opt ? opt.dataset.ownerName : 'Grafik';
            this.ownerOptionTargets.forEach((o) => o.setAttribute('aria-selected', String(o === opt)));
        }

        if (this.countEl) this.countEl.textContent = String(visible);

        if (this.hasNoResultsTarget) {
            const none = visible === 0;
            this.noResultsTarget.hidden = !none;
            if (none) {
                const raw = this.searchInput ? this.searchInput.value.trim() : '';
                this.noResultsTitleTarget.textContent = raw
                    ? `Žádný projekt neodpovídá „${raw}“`
                    : 'Žádný projekt neodpovídá vybraným filtrům';
                this.clearSearchTarget.hidden = !raw;
                this.clearFiltersTarget.hidden = !anyFilter;
                this.noResultsIconTarget.innerHTML = `<span class="i ${raw ? 'i-search' : 'i-filter'}" aria-hidden="true"></span>`;
            }
        }
    }
}
