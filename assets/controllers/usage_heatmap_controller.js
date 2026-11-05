import { Controller } from '@hotwired/stimulus';

/*
 * Admin › Statistiky › Klienti po měsících (templates/admin/usage.html.twig).
 *
 *  - client rows are disclosure buttons: a click anywhere on the row (or
 *    Enter/Space on the focused button) shows/hides that client's project
 *    <tbody>; "Rozbalit vše / Sbalit vše" does it for every visible client
 *  - search filters as you type over client name + e-mail and project names
 *    (case- and diacritics-insensitive). A client that matches only through a
 *    project is opened automatically and shows just the matching projects.
 *  - metric toggle Stažení / Šablony flips data-metric on the card; the cells
 *    carry both numbers and both levels server-side, CSS picks the active pair.
 *    The choice is remembered in localStorage.
 */
const METRICS = ['downloads', 'templates'];
const STORE = 'wb-usage-metric';

const norm = (value) => (value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

export default class extends Controller {
    static targets = ['search', 'group', 'projects', 'gap', 'none', 'query', 'scroll', 'allButton', 'allLabel', 'metricButton'];

    connect() {
        this.open = new Set(); // owners the user opened
        this.closed = new Set(); // owners the user closed while a search auto-opened them
        this.auto = new Set(); // owners opened by the current search
        this.index = this.groupTargets.map((group) => {
            const owner = group.dataset.owner;
            const projects = this.projectsTargets.find((el) => el.dataset.owner === owner);
            const client = group.querySelector('tr.hm-cl');
            return {
                owner,
                group,
                projects,
                button: client.querySelector('.hm-tg'),
                gap: group.querySelector('tr.hm-gap'),
                text: norm(client.dataset.q),
                rows: [...projects.querySelectorAll('tr')].map((row) => ({ row, text: norm(row.dataset.q) })),
            };
        });

        let metric = null;
        try { metric = localStorage.getItem(STORE); } catch (e) { metric = null; }
        this.applyMetric(METRICS.includes(metric) ? metric : 'downloads');
        this.render();
    }

    // ---- expand / collapse ---------------------------------------------------

    toggle(event) {
        const entry = this.index.find((e) => e.group.contains(event.currentTarget));
        if (!entry) return;
        const isOpen = !entry.projects.hidden;
        if (isOpen) {
            this.open.delete(entry.owner);
            if (this.auto.has(entry.owner)) this.closed.add(entry.owner);
        } else {
            this.open.add(entry.owner);
            this.closed.delete(entry.owner);
        }
        this.render();
    }

    toggleAll() {
        const visible = this.index.filter((e) => !e.group.hidden);
        const expand = visible.some((e) => e.projects.hidden);
        visible.forEach((e) => {
            if (expand) { this.open.add(e.owner); this.closed.delete(e.owner); } else { this.open.delete(e.owner); if (this.auto.has(e.owner)) this.closed.add(e.owner); }
        });
        this.render();
    }

    // ---- search --------------------------------------------------------------

    filter() {
        this.closed.clear();
        this.render();
    }

    clearSearch() {
        this.searchTarget.value = '';
        this.filter();
        this.searchTarget.focus();
    }

    render() {
        const q = norm(this.searchTarget.value);
        this.auto.clear();
        let first = true;
        let anyCollapsed = false;

        this.index.forEach((e) => {
            const clientHit = !q || e.text.includes(q);
            const projectHits = q ? e.rows.filter((r) => r.text.includes(q)) : [];
            const visible = clientHit || projectHits.length > 0;
            if (!clientHit && projectHits.length > 0) this.auto.add(e.owner);

            // A client found by name shows all its projects; one found only via
            // projects shows just those.
            e.rows.forEach((r) => { r.row.hidden = !clientHit && !projectHits.includes(r); });

            const open = visible && !this.closed.has(e.owner) && (this.open.has(e.owner) || this.auto.has(e.owner));
            e.group.hidden = !visible;
            e.projects.hidden = !open;
            e.button.setAttribute('aria-expanded', String(open));
            if (visible) {
                e.gap.hidden = first;
                first = false;
                if (!open) anyCollapsed = true;
            }
        });

        const none = first; // no client is visible
        this.noneTarget.hidden = !none;
        this.scrollTarget.hidden = none;
        this.queryTarget.textContent = this.searchTarget.value.trim();
        this.allButtonTarget.hidden = none;
        this.allButtonTarget.setAttribute('aria-expanded', String(!anyCollapsed));
        this.allLabelTarget.textContent = anyCollapsed ? 'Rozbalit vše' : 'Sbalit vše';
    }

    // ---- metric --------------------------------------------------------------

    setMetric(event) {
        const metric = event.currentTarget.dataset.metric;
        if (!METRICS.includes(metric)) return;
        this.applyMetric(metric);
        try { localStorage.setItem(STORE, metric); } catch (e) { /* private mode */ }
    }

    applyMetric(metric) {
        this.element.dataset.metric = metric;
        this.metricButtonTargets.forEach((button) => {
            const on = button.dataset.metric === metric;
            button.classList.toggle('on', on);
            button.setAttribute('aria-pressed', String(on));
        });
    }
}
