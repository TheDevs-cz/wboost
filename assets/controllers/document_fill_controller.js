import { Controller } from '@hotwired/stimulus';

/**
 * The document fill page's page sidebar (Apple-Preview style): a thumbnail
 * per page that scrolls to it, follows the page's latest preview render,
 * mirrors its "Text přetéká" badge and highlights the page in view.
 *
 * Everything about filling and rendering stays with group-fill (lazy mode)
 * and variant-fill-overlay on the same <form>; this only reads their DOM.
 */
export default class extends Controller {
    static targets = ['page', 'thumb', 'thumbImage', 'thumbBadge'];

    connect() {
        this._onPreviewLoad = (event) => this._syncThumb(event.target);
        this._previews = Array.from(this.element.querySelectorAll('img[data-group-fill-target~="preview"]'));
        this._previews.forEach((img) => img.addEventListener('load', this._onPreviewLoad));

        // The overlay toggles each page card's overflow badge — mirror it.
        this._badgeObserver = new MutationObserver(() => this._syncBadges());
        this.pageTargets.forEach((page) => {
            const badge = page.querySelector('[data-fill-overflow-badge]');
            if (badge) this._badgeObserver.observe(badge, { attributes: true, attributeFilter: ['class'] });
        });

        if ('IntersectionObserver' in window) {
            // A page is "current" while it crosses the band just above the
            // viewport's middle.
            this._activeObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) this._markActive(entry.target.dataset.variantId);
                });
            }, { rootMargin: '-40% 0px -55% 0px' });
            this.pageTargets.forEach((page) => this._activeObserver.observe(page));
        }
    }

    disconnect() {
        (this._previews || []).forEach((img) => img.removeEventListener('load', this._onPreviewLoad));
        if (this._badgeObserver) this._badgeObserver.disconnect();
        if (this._activeObserver) this._activeObserver.disconnect();
    }

    scrollTo(event) {
        event.preventDefault();
        const variantId = event.currentTarget.dataset.variantId;
        const page = this.pageTargets.find((element) => element.dataset.variantId === variantId);
        if (!page) return;
        // An instant jump, not a smooth scroll: smooth scrolling sweeps every
        // page in between through the viewport, and each of those would
        // start its (lazy) preview render on the way. Explicit 'instant':
        // Bootstrap's reboot sets `scroll-behavior: smooth` on :root, which
        // the default 'auto' would follow.
        page.scrollIntoView({ behavior: 'instant', block: 'start' });
        this._markActive(variantId);
    }

    _syncThumb(preview) {
        const variantId = preview.dataset.variantId;
        const thumb = this.thumbImageTargets.find((img) => img.dataset.variantId === variantId);
        if (thumb && preview.currentSrc) thumb.src = preview.currentSrc;
    }

    _syncBadges() {
        this.pageTargets.forEach((page) => {
            const source = page.querySelector('[data-fill-overflow-badge]');
            const badge = this.thumbBadgeTargets.find((element) => element.dataset.variantId === page.dataset.variantId);
            if (source && badge) badge.classList.toggle('d-none', source.classList.contains('d-none'));
        });
    }

    _markActive(variantId) {
        this.thumbTargets.forEach((thumb) => {
            const active = thumb.dataset.variantId === variantId;
            thumb.classList.toggle('is-active', active);
            if (active) {
                thumb.setAttribute('aria-current', 'true');
            } else {
                thumb.removeAttribute('aria-current');
            }
        });
    }
}
