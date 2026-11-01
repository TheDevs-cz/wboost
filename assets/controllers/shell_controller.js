import { Controller } from '@hotwired/stimulus';

/*
 * Nocturne app shell (templates/base.html.twig + templates/_shell/*,
 * contract in docs/architecture/app-shell.md):
 *
 *  - collapse the sidebar to the 84 px icon rail (html.sb-rail, persisted in
 *    localStorage 'wb-collapsed' unless persist=false — the editors force it)
 *  - phone/tablet drawer (html.sb-drawer + scrim, ≤ 860 px)
 *  - theme toggle (html[data-theme|data-bs-theme], localStorage 'wb-theme')
 *  - "Rychlá navigace" project switcher popover (search, arrows, Enter, Esc,
 *    last 3 opened projects from localStorage 'wb-recent')
 *  - profile drop-up menu
 *
 * Every popup closes on Esc and on an outside pointerdown.
 */
const RAIL_KEY = 'wb-collapsed';
const THEME_KEY = 'wb-theme';
const RECENT_KEY = 'wb-recent';
const DRAWER_QUERY = '(max-width: 860px)';

const store = {
    get(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
    },
};

const fold = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default class extends Controller {
    static targets = [
        'sidebar', 'collapseButton', 'scrim', 'themeRadio',
        'meButton', 'meMenu',
        'qnTrigger', 'qnPop', 'qnSearch', 'qnRecent', 'qnRecentHead', 'qnAll', 'qnAllHead', 'qnEmpty',
    ];

    static values = { persist: Boolean, projectId: String };

    connect() {
        this.root = document.documentElement;
        this.syncRailButton();
        this.syncTheme();
        this.rememberProject();
    }

    // ---- rail -------------------------------------------------------------

    toggleRail() {
        const rail = !this.root.classList.contains('sb-rail');
        this.root.classList.toggle('sb-rail', rail);
        if (this.persistValue) {
            store.set(RAIL_KEY, rail ? '1' : '0');
        }
        this.syncRailButton();
        this.closeMe();
        this.closeQuickNav();
        // Width-aware screens (canvas zoom auto-fit, charts) listen to window
        // resize; the main column changes width without one, so announce it
        // once the grid transition has settled.
        window.setTimeout(() => window.dispatchEvent(new Event('resize')), 280);
    }

    syncRailButton() {
        if (!this.hasCollapseButtonTarget) return;
        const rail = this.root.classList.contains('sb-rail');
        this.collapseButtonTarget.setAttribute('aria-expanded', rail ? 'false' : 'true');
        this.collapseButtonTarget.setAttribute('aria-label', rail ? 'Rozbalit menu' : 'Sbalit menu');
        this.collapseButtonTarget.title = rail ? 'Rozbalit menu' : 'Sbalit menu';
    }

    // ---- drawer -----------------------------------------------------------

    openDrawer() {
        this.root.classList.add('sb-drawer');
        if (this.hasScrimTarget) this.scrimTarget.hidden = false;
        const first = this.sidebarTarget.querySelector('.nav-i, button');
        if (first) first.focus({ preventScroll: true });
    }

    closeDrawer() {
        if (!this.root.classList.contains('sb-drawer')) return;
        this.root.classList.remove('sb-drawer');
        if (this.hasScrimTarget) this.scrimTarget.hidden = true;
        this.closeMe();
        this.closeQuickNav();
    }

    resized() {
        if (!window.matchMedia(DRAWER_QUERY).matches) {
            this.closeDrawer();
        }
        this.closeQuickNav();
    }

    // ---- theme ------------------------------------------------------------

    currentTheme() {
        return this.root.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    applyTheme(theme) {
        this.root.setAttribute('data-theme', theme);
        this.root.setAttribute('data-bs-theme', theme);
        store.set(THEME_KEY, theme);
        this.syncTheme();
        this.root.dispatchEvent(new CustomEvent('shell:theme', { bubbles: true, detail: { theme } }));
    }

    pickTheme(event) {
        this.applyTheme(event.target.value === 'light' ? 'light' : 'dark');
    }

    toggleTheme() {
        this.applyTheme(this.currentTheme() === 'light' ? 'dark' : 'light');
    }

    syncTheme() {
        const theme = this.currentTheme();
        this.themeRadioTargets.forEach((radio) => {
            radio.checked = radio.value === theme;
            radio.closest('.sb-th')?.classList.toggle('on', radio.checked);
        });
    }

    // ---- profile drop-up --------------------------------------------------

    toggleMe() {
        if (this.meMenuTarget.hidden) {
            this.closeQuickNav();
            this.meMenuTarget.hidden = false;
            this.meButtonTarget.setAttribute('aria-expanded', 'true');
            this.meMenuTarget.querySelector('.mi')?.focus({ preventScroll: true });
        } else {
            this.closeMe();
        }
    }

    closeMe(returnFocus = false) {
        if (!this.hasMeMenuTarget || this.meMenuTarget.hidden) return;
        this.meMenuTarget.hidden = true;
        this.meButtonTarget.setAttribute('aria-expanded', 'false');
        if (returnFocus) this.meButtonTarget.focus();
    }

    meKey(event) {
        const items = [...this.meMenuTarget.querySelectorAll('.mi')];
        const index = items.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            items[(index + step + items.length) % items.length]?.focus();
        }
    }

    // ---- quick navigation -------------------------------------------------

    toggleQuickNav() {
        if (!this.hasQnPopTarget) return;
        if (this.qnPopTarget.hidden) {
            this.openQuickNav();
        } else {
            this.closeQuickNav();
        }
    }

    openQuickNav() {
        this.closeMe();
        this.arrangeRecent();
        this.qnSearchTarget.value = '';
        this.filterQuickNav();
        this.qnPopTarget.hidden = false;
        this.qnTriggerTarget.setAttribute('aria-expanded', 'true');
        this.positionQuickNav();
        this.qnSearchTarget.focus({ preventScroll: true });
    }

    closeQuickNav(returnFocus = false) {
        if (!this.hasQnPopTarget || this.qnPopTarget.hidden) return;
        this.qnPopTarget.hidden = true;
        this.qnTriggerTarget.setAttribute('aria-expanded', 'false');
        if (returnFocus) this.qnTriggerTarget.focus();
    }

    positionQuickNav() {
        const rect = this.qnTriggerTarget.getBoundingClientRect();
        const pop = this.qnPopTarget;
        const rail = this.root.classList.contains('sb-rail') && !window.matchMedia(DRAWER_QUERY).matches;
        const width = Math.min(300, window.innerWidth - 32);
        let left = rail ? rect.right + 14 : rect.left;
        let top = rail ? rect.top : rect.bottom + 6;
        left = Math.max(16, Math.min(left, window.innerWidth - width - 16));
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
        const overflow = top + pop.offsetHeight - (window.innerHeight - 16);
        if (overflow > 0) {
            top = Math.max(16, top - overflow);
            pop.style.top = `${top}px`;
        }
    }

    rows() {
        return [...this.qnPopTarget.querySelectorAll('.qn')];
    }

    arrangeRecent() {
        // Put every row back into the alphabetical list (server order), then
        // lift the last 3 opened projects (other than the open one) into
        // "Naposledy otevřené".
        const all = this.qnAllTarget;
        const rows = this.rows();
        rows.forEach((row, i) => { if (row.dataset.qnOrder === undefined) row.dataset.qnOrder = String(i); });
        rows.sort((a, b) => Number(a.dataset.qnOrder) - Number(b.dataset.qnOrder)).forEach((row) => all.appendChild(row));

        this.recentIds()
            .filter((id) => id !== this.projectIdValue)
            .slice(0, 3)
            .forEach((id) => {
                const row = all.querySelector(`.qn[data-qn-id="${CSS.escape(id)}"]`);
                if (row) this.qnRecentTarget.appendChild(row);
            });
        const hasRecent = this.qnRecentTarget.children.length > 0;
        this.qnRecentHeadTarget.hidden = !hasRecent;
        this.qnAllHeadTarget.textContent = hasRecent ? 'Ostatní projekty' : 'Všechny projekty';
    }

    filterQuickNav() {
        const query = fold(this.qnSearchTarget.value.trim());
        let visible = 0;
        this.rows().forEach((row) => {
            const match = query === '' || fold(row.dataset.qnName || '').includes(query);
            row.hidden = !match;
            row.classList.remove('act');
            if (match) visible += 1;
        });
        const searching = query !== '';
        this.qnRecentHeadTarget.hidden = searching || this.qnRecentTarget.children.length === 0;
        this.qnAllHeadTarget.hidden = searching || this.qnAllTarget.querySelector('.qn') === null;
        this.qnEmptyTarget.hidden = visible > 0;
        this.activate(searching ? this.visibleRows()[0] || null : null);
    }

    visibleRows() {
        // DOM order = recent first, then the rest.
        return [...this.qnRecentTarget.querySelectorAll('.qn'), ...this.qnAllTarget.querySelectorAll('.qn')]
            .filter((row) => !row.hidden);
    }

    activate(row) {
        this.rows().forEach((r) => r.classList.toggle('act', r === row));
        if (row) {
            this.qnSearchTarget.setAttribute('aria-activedescendant', row.id);
            row.scrollIntoView({ block: 'nearest' });
        } else {
            this.qnSearchTarget.removeAttribute('aria-activedescendant');
        }
    }

    quickNavKey(event) {
        const rows = this.visibleRows();
        const index = rows.findIndex((row) => row.classList.contains('act'));
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!rows.length) return;
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const next = index === -1 ? (step > 0 ? 0 : rows.length - 1) : (index + step + rows.length) % rows.length;
            this.activate(rows[next]);
        } else if (event.key === 'Enter') {
            const row = rows[index] || (rows.length === 1 ? rows[0] : null);
            if (row) {
                event.preventDefault();
                window.location.href = row.href;
            }
        }
    }

    recentIds() {
        try {
            const list = JSON.parse(store.get(RECENT_KEY) || '[]');
            return Array.isArray(list) ? list.filter((id) => typeof id === 'string') : [];
        } catch (e) {
            return [];
        }
    }

    rememberProject() {
        if (!this.projectIdValue) return;
        const list = [this.projectIdValue, ...this.recentIds().filter((id) => id !== this.projectIdValue)].slice(0, 6);
        store.set(RECENT_KEY, JSON.stringify(list));
    }

    // ---- global close handlers -------------------------------------------

    escape() {
        if (this.hasQnPopTarget && !this.qnPopTarget.hidden) {
            this.closeQuickNav(true);
        } else if (this.hasMeMenuTarget && !this.meMenuTarget.hidden) {
            this.closeMe(true);
        } else {
            this.closeDrawer();
        }
    }

    outside(event) {
        const target = event.target;
        if (this.hasMeMenuTarget && !this.meMenuTarget.hidden
            && !this.meMenuTarget.contains(target) && !this.meButtonTarget.contains(target)) {
            this.closeMe();
        }
        if (this.hasQnPopTarget && !this.qnPopTarget.hidden
            && !this.qnPopTarget.contains(target) && !this.qnTriggerTarget.contains(target)) {
            this.closeQuickNav();
        }
    }
}
