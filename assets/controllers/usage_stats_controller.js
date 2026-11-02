import { Controller } from '@hotwired/stimulus';

/*
 * Admin › Statistiky (templates/admin/usage.html.twig).
 *
 *  - underline tabs (Klienti / Aktivita / Úložiště): roving tabindex, arrow
 *    keys, #hash + sessionStorage so a reload (or the "Načíst znovu" POST
 *    round-trip) lands on the same tab
 *  - ApexCharts (window.ApexCharts, classic vendor script) styled from the
 *    Nocturne tokens at runtime: Outfit, --ch-1/--ch-2 series, --line grid,
 *    --subtle ticks, themed tooltip. A chart renders the first time its tab is
 *    visible (Apex measures 0 px inside a hidden pane) and re-renders when the
 *    light/dark theme changes.
 */
const TABS = ['klienti', 'aktivita', 'uloziste'];
const STORE = 'wb-stats-tab';

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

const hex = (value) => {
    const m = /^#?([0-9a-f]{6})$/i.exec(value || '');
    if (!m) return null;
    const n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
};
const mix = (a, b, t) => {
    const x = hex(a);
    const y = hex(b);
    if (!x || !y) return a;
    return '#' + x.map((c, i) => Math.round(c * t + y[i] * (1 - t)).toString(16).padStart(2, '0')).join('');
};
const fmt = (v) => Math.round(v).toLocaleString('cs-CZ');

export default class extends Controller {
    static targets = ['pane', 'chart'];

    connect() {
        this.charts = new Map();
        const fromHash = location.hash.replace('#', '');
        let tab = TABS.includes(fromHash) ? fromHash : null;
        if (!tab) {
            try { tab = sessionStorage.getItem(STORE); sessionStorage.removeItem(STORE); } catch (e) { tab = null; }
        }
        this.show(TABS.includes(tab) ? tab : 'klienti', false);

        this.onHash = () => {
            const h = location.hash.replace('#', '');
            if (TABS.includes(h)) this.show(h, false);
        };
        window.addEventListener('hashchange', this.onHash);

        this.themeObserver = new MutationObserver(() => this.rerender());
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    disconnect() {
        this.themeObserver.disconnect();
        window.removeEventListener('hashchange', this.onHash);
        this.charts.forEach((chart) => chart.destroy());
        this.charts.clear();
    }

    // ---- tabs ----------------------------------------------------------------

    select(event) {
        this.show(event.currentTarget.dataset.tab, true);
    }

    tabKey(event) {
        const i = TABS.indexOf(event.currentTarget.dataset.tab);
        let next = null;
        if (event.key === 'ArrowRight') next = TABS[(i + 1) % TABS.length];
        else if (event.key === 'ArrowLeft') next = TABS[(i + TABS.length - 1) % TABS.length];
        else if (event.key === 'Home') next = TABS[0];
        else if (event.key === 'End') next = TABS[TABS.length - 1];
        if (!next) return;
        event.preventDefault();
        this.show(next, true);
        this.element.querySelector(`[role="tab"][data-tab="${next}"]`).focus();
    }

    show(tab, updateHash) {
        this.element.querySelectorAll('[role="tab"]').forEach((button) => {
            const on = button.dataset.tab === tab;
            button.setAttribute('aria-selected', String(on));
            button.tabIndex = on ? 0 : -1;
        });
        this.paneTargets.forEach((pane) => { pane.hidden = pane.dataset.tab !== tab; });
        if (updateHash) history.replaceState(null, '', tab === 'klienti' ? location.pathname + location.search : `#${tab}`);
        this.renderVisible();
    }

    rememberTab() {
        const current = this.paneTargets.find((pane) => !pane.hidden);
        try { if (current) sessionStorage.setItem(STORE, current.dataset.tab); } catch (e) { /* private mode */ }
    }

    // ---- charts --------------------------------------------------------------

    renderVisible() {
        if (typeof window.ApexCharts === 'undefined') {
            // The vendor script tag sits after this element; wait for it.
            if (!this.waiting) {
                this.waiting = true;
                window.addEventListener('load', () => { this.waiting = false; this.renderVisible(); }, { once: true });
            }
            return;
        }
        this.chartTargets.forEach((el) => {
            if (this.charts.has(el) || el.closest('[hidden]')) return;
            const chart = new window.ApexCharts(el, this.options(el));
            chart.render();
            this.charts.set(el, chart);
        });
    }

    rerender() {
        this.charts.forEach((chart) => chart.destroy());
        this.charts.clear();
        this.chartTargets.forEach((el) => { el.innerHTML = ''; });
        this.renderVisible();
    }

    options(el) {
        const kind = el.dataset.kind;
        const series = JSON.parse(el.dataset.series || '[]');
        const categories = JSON.parse(el.dataset.categories || '[]');
        const light = document.documentElement.dataset.theme === 'light';
        const t = {
            ch1: css('--ch-1') || '#8C75DB',
            ch2: css('--ch-2') || '#D07A3A',
            ghost: css('--ch-ghost'),
            line: css('--line'),
            subtle: css('--subtle'),
            muted: css('--muted'),
            surface: css('--surface') || '#141317',
            font: css('--font') || 'Outfit, system-ui, sans-serif',
        };

        const base = {
            chart: {
                fontFamily: t.font,
                foreColor: t.subtle,
                background: 'transparent',
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 500 },
            },
            theme: { mode: light ? 'light' : 'dark' },
            grid: { borderColor: t.line, strokeDashArray: 4, padding: { left: 4, right: 8 }, xaxis: { lines: { show: false } } },
            dataLabels: { enabled: false },
            legend: { show: false },
            states: { hover: { filter: { type: 'lighten', value: 0.08 } }, active: { filter: { type: 'none' } } },
            tooltip: { theme: light ? 'light' : 'dark', style: { fontFamily: t.font, fontSize: '12px' } },
            xaxis: {
                categories,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: t.subtle, fontSize: '11px', fontFamily: t.font } },
            },
            yaxis: { labels: { style: { colors: t.subtle, fontSize: '11px', fontFamily: t.font }, formatter: (v) => fmt(v) } },
            series,
        };

        if (kind === 'usage') {
            // One stacked series per client: single-hue steps of --ch-1, the
            // aggregated "Ostatní" (if present) in the neutral ghost colour.
            const steps = series.map((s, i) => (s.name === 'Ostatní'
                ? (t.ghost || t.muted)
                : mix(t.ch1, t.surface, Math.max(0.5, 1 - i * (0.5 / Math.max(1, series.length - 1))))));
            const rows = Math.max(1, series.length);
            return {
                ...base,
                chart: { ...base.chart, type: 'bar', height: 340, stacked: true },
                colors: steps,
                fill: { opacity: 1 },
                plotOptions: { bar: { columnWidth: '46%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
                stroke: { show: true, width: rows > 1 ? 1 : 0, colors: [t.surface] },
                legend: { show: true, position: 'bottom', fontFamily: t.font, fontSize: '12px', labels: { colors: t.muted }, markers: { size: 5, shape: 'square' }, itemMargin: { horizontal: 8, vertical: 2 } },
                tooltip: { ...base.tooltip, y: { formatter: (v) => `${fmt(v)} šablon` } },
            };
        }

        if (kind === 'activity') {
            return {
                ...base,
                chart: { ...base.chart, type: 'area', height: 300 },
                colors: [t.ch1, t.ch2],
                stroke: { curve: 'monotoneCubic', width: [2.5, 2] },
                fill: { type: 'gradient', gradient: { shadeIntensity: 0, opacityFrom: 0.28, opacityTo: 0.02, stops: [0, 100] } },
                markers: { size: 0, hover: { size: 5 }, strokeColors: t.surface, strokeWidth: 2 },
                xaxis: { ...base.xaxis, tickAmount: 10, labels: { ...base.xaxis.labels, rotate: 0, hideOverlappingLabels: true } },
                tooltip: { ...base.tooltip, shared: true, y: { formatter: (v) => fmt(v) } },
            };
        }

        // storage: horizontal stacked bars, used --ch-1 / unused --ch-2
        return {
            ...base,
            chart: { ...base.chart, type: 'bar', height: Math.max(220, categories.length * 34 + 60), stacked: true },
            colors: [t.ch1, t.ch2],
            fill: { opacity: 1 },
            plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
            stroke: { show: true, width: 1, colors: [t.surface] },
            grid: { ...base.grid, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            xaxis: { ...base.xaxis, labels: { ...base.xaxis.labels, formatter: (v) => fmt(v) } },
            yaxis: { labels: { style: { colors: t.muted, fontSize: '12px', fontFamily: t.font }, maxWidth: 220 } },
            tooltip: { ...base.tooltip, y: { formatter: (v) => `${v} MB` } },
        };
    }
}
