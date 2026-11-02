import { Controller } from '@hotwired/stimulus';

/**
 * On-screen format controls of the group fill page (prototype "Vyplnit a
 * exportovat": `.fi-bar` + `.fi-grid`). Presentation only — nothing here
 * touches the fill form's fields, and the ZIP always carries every format.
 *
 * - Fit (default): every visible format card is sized so its preview fits the
 *   window height below the grid's top and, when there is room, all visible
 *   cards share one row. Recomputed on resize until the user moves the slider.
 * - Slider: a manual card width (`--fimin` on the grid), remembered per
 *   browser (`wb-fisize`); moving it leaves the fit.
 * - Chips: hide / show a format's card (the last visible one stays).
 *
 * The overlay controller (variant-fill-overlay) re-scales its boxes by
 * itself: it observes every preview's size.
 */
export default class extends Controller {
    static targets = ['grid', 'card', 'chip', 'size', 'fit'];

    connect() {
        this.auto = true;
        try {
            const stored = Number.parseInt(window.localStorage.getItem('wb-fisize') || '', 10);
            if (Number.isFinite(stored)) {
                this.auto = false;
                this.sizePx = stored;
            }
        } catch (e) {
            // Storage unavailable — fit.
        }

        this._onResize = () => { if (this.auto) this._fit(); };
        window.addEventListener('resize', this._onResize);
        this._apply();
    }

    disconnect() {
        window.removeEventListener('resize', this._onResize);
    }

    fit() {
        this.auto = !this.auto;
        if (!this.auto && this.hasSizeTarget) {
            this.sizePx = Number.parseInt(this.sizeTarget.value, 10);
        }
        try {
            if (this.auto) window.localStorage.removeItem('wb-fisize');
            else window.localStorage.setItem('wb-fisize', String(this.sizePx));
        } catch (e) {
            // ignore
        }
        this._apply();
    }

    resize(event) {
        this.auto = false;
        this.sizePx = Number.parseInt(event.currentTarget.value, 10);
        try {
            window.localStorage.setItem('wb-fisize', String(this.sizePx));
        } catch (e) {
            // ignore
        }
        this._apply();
    }

    toggle(event) {
        const chip = event.currentTarget;
        const on = chip.getAttribute('aria-pressed') !== 'true';
        if (!on && this.chipTargets.filter((c) => c.getAttribute('aria-pressed') === 'true').length <= 1) {
            return; // keep at least one format on screen
        }
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        this.cardTargets
            .filter((card) => card.dataset.format === chip.dataset.format)
            .forEach((card) => { card.hidden = !on; });
        if (this.auto) this._fit();
    }

    _apply() {
        this.fitTargets.forEach((button) => button.setAttribute('aria-pressed', this.auto ? 'true' : 'false'));
        if (this.auto) {
            this._fit();
        } else if (this.hasGridTarget) {
            if (this.hasSizeTarget) this.sizeTarget.value = String(this.sizePx);
            this.gridTarget.style.setProperty('--fimin', `${this.sizePx}px`);
        }
    }

    _fit() {
        if (!this.hasGridTarget) return;
        const grid = this.gridTarget;
        const visible = this.cardTargets.filter((card) => !card.hidden);
        if (!visible.length) return;

        const ratios = visible.map((card) => {
            const [w, h] = (card.dataset.ratio || '1/1').split('/').map(Number);
            return w > 0 && h > 0 ? w / h : 1;
        });
        const gap = Number.parseFloat(getComputedStyle(grid).columnGap) || 20;
        const top = grid.getBoundingClientRect().top + window.scrollY;
        // Card chrome: header row + paddings (≈ the prototype's 72 px) and a
        // little air under the grid.
        const height = Math.max(260, window.innerHeight - top - 40) - 72;
        const width = grid.clientWidth;
        const n = visible.length;
        const byHeight = Math.min(...ratios.map((r) => height * r)) + 26;
        const byRow = (width - gap * (n - 1)) / n;
        // Phones fit by WIDTH (one full-width card per row, the page scrolls) —
        // the same rule the overlay's own fit zoom follows on narrow screens.
        const narrow = window.matchMedia?.('(max-width: 700px)').matches;
        const px = narrow ? width : Math.round(Math.max(240, Math.min(byHeight, byRow < 240 ? width : byRow)));

        grid.style.setProperty('--fimin', `${px}px`);
        if (this.hasSizeTarget) this.sizeTarget.value = String(Math.min(820, Math.max(240, px)));
    }
}
