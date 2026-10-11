import { Controller } from '@hotwired/stimulus';

/**
 * The bar that sizes ONE logo card of the manual preview (the top of the
 * width cascade, `Manual::logoDisplayWidth()`). Mounted once on the manual
 * root; every card's pencil (`_manual_logo_image.html.twig`) opens it for its
 * own slot via action params.
 *
 * Dragging / typing previews the width live on the card's `<img>` (found by
 * `data-logo-slot`); Uložit POSTs it to `EditManualLogoSlotWidthController`
 * and the page stays where it is. Zrušit / Escape put the original inline
 * style back.
 *
 * The preview writes the same two `!important` declarations the Twig partial
 * renders — `width` must beat the per-slug hacks in app.css and `max-height:
 * none` keeps the height cap from squashing a wide logo (see the partial).
 */
export default class extends Controller {
    static targets = ['bar', 'range', 'number', 'reset', 'save', 'hint', 'error'];
    static values = { url: String, csrf: String };

    connect() {
        this.active = null;
    }

    disconnect() {
        this.cancel();
    }

    open(event) {
        const { slot } = event.params;
        const button = event.currentTarget;

        if (this.active !== null) {
            const sameSlot = this.active.slot === slot;
            this.cancel();
            // A second click on the same pencil closes the bar again.
            if (sameSlot) {
                return;
            }
        }

        const img = this.element.querySelector(`img[data-logo-slot="${CSS.escape(slot)}"]`);
        if (img === null) {
            return;
        }

        const card = img.closest('.manual-image-wrapper') ?? img.parentElement;

        this.active = {
            slot,
            button,
            img,
            card,
            slotWidth: toWidth(event.params.slotWidth),
            variantWidth: toWidth(event.params.variantWidth),
            originalStyle: img.getAttribute('style'),
        };

        this.numberTarget.value = this.active.slotWidth ?? '';
        this.numberTarget.placeholder = this.active.variantWidth ?? 'auto';
        this.rangeTarget.value = this.active.slotWidth ?? this.fallbackWidth();
        this.hintTarget.textContent = this.active.variantWidth !== null
            ? `Prázdné pole = ${this.active.variantWidth} % nastavených pro celou variantu loga v sekci Loga.`
            : 'Prázdné pole = výchozí velikost.';
        this.errorTarget.textContent = '';
        this.setSaving(false);

        card.classList.add('manual-logo-width-card-active');
        button.classList.add('active');
        this.barTarget.hidden = false;

        this.keepCardAboveBar();
        this.numberTarget.focus();
        this.numberTarget.select();
    }

    slide() {
        if (this.active === null) {
            return;
        }

        this.numberTarget.value = this.rangeTarget.value;
        this.preview(Number(this.rangeTarget.value));
    }

    type() {
        if (this.active === null) {
            return;
        }

        const width = toWidth(this.numberTarget.value);
        this.preview(width);
        // Measured AFTER the preview, so a cleared field shows the size the
        // card falls back to, not the one it had a moment ago.
        this.rangeTarget.value = width ?? this.fallbackWidth();
    }

    reset() {
        this.numberTarget.value = '';
        this.type();
        this.numberTarget.focus();
    }

    cancel() {
        if (this.active === null) {
            return;
        }

        const { img, originalStyle } = this.active;
        if (originalStyle === null) {
            img.removeAttribute('style');
        } else {
            img.setAttribute('style', originalStyle);
        }

        this.close();
    }

    async save() {
        if (this.active === null || this.saving) {
            return;
        }

        const active = this.active;
        const body = new FormData();
        body.append('_token', this.csrfValue);
        body.append('slot', active.slot);
        body.append('displayWidth', toWidth(this.numberTarget.value) ?? '');

        this.errorTarget.textContent = '';
        this.setSaving(true);

        let payload = null;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
            });
            payload = await response.json().catch(() => null);

            if (!response.ok) {
                throw new Error(payload?.error ?? 'Uložení se nepovedlo, zkuste to prosím znovu.');
            }
        } catch (error) {
            // The bar may have been closed while the request was in flight.
            if (this.active === active) {
                this.setSaving(false);
                this.errorTarget.textContent = error instanceof TypeError
                    ? 'Uložení se nepovedlo – zkontrolujte připojení a zkuste to znovu.'
                    : error.message;
            }
            return;
        }

        // The server's answer is the truth: a cleared card shows whatever
        // the cascade now resolves to (the variant's width, or nothing).
        applyWidth(active.img, toWidth(payload.displayWidth));
        active.button.dataset.manualLogoWidthSlotWidthParam = payload.slotWidth ?? '';

        if (this.active === active) {
            this.close();
        }

        active.card.classList.add('manual-logo-width-card-saved');
        setTimeout(() => active.card.classList.remove('manual-logo-width-card-saved'), 1200);
    }

    preview(width) {
        applyWidth(this.active.img, width ?? this.active.variantWidth);
    }

    close() {
        const { card, button } = this.active;
        card.classList.remove('manual-logo-width-card-active');
        button.classList.remove('active');
        this.barTarget.hidden = true;
        this.active = null;
        this.setSaving(false);
    }

    /** The width the card shows with no number of its own. */
    fallbackWidth() {
        return this.active.variantWidth ?? measureWidth(this.active.img);
    }

    setSaving(saving) {
        this.saving = saving;
        this.saveTarget.disabled = saving;
        this.saveTarget.textContent = saving ? 'Ukládám…' : 'Uložit';
    }

    /**
     * The bar is fixed over the bottom of the viewport — a card low on the
     * screen would hide behind exactly the control that resizes it.
     */
    keepCardAboveBar() {
        const barTop = this.barTarget.getBoundingClientRect().top;
        const cardBottom = this.active.card.getBoundingClientRect().bottom;
        const overlap = cardBottom - barTop + 24;

        if (overlap > 0) {
            window.scrollBy({ top: overlap, behavior: 'smooth' });
        }
    }
}

/** '' / null / 0 → null (no own width), otherwise an integer clamped to 1–100. */
function toWidth(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const width = Math.round(Number(value));
    if (!Number.isFinite(width) || width <= 0) {
        return null;
    }

    return Math.min(width, 100);
}

function applyWidth(img, width) {
    if (width === null) {
        img.style.removeProperty('width');
        img.style.removeProperty('max-height');
        if (img.getAttribute('style') === '') {
            img.removeAttribute('style');
        }
        return;
    }

    img.style.setProperty('width', `${width}%`, 'important');
    img.style.setProperty('max-height', 'none', 'important');
}

/**
 * The logo's VISIBLE width as a % of its parent's content box. Without an
 * explicit width the stylesheet stretches the `<img>` box across the card and
 * caps its height, and the SVG letterboxes itself inside that box — so the
 * box width overstates the logo. The visible width is what the slider has to
 * start from, or the first nudge would make the logo jump.
 */
function measureWidth(img) {
    const parent = img.parentElement;
    const style = getComputedStyle(parent);
    const inner = parent.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
    const box = img.getBoundingClientRect();

    if (inner <= 0 || box.width <= 0) {
        return 50;
    }

    let visible = box.width;
    if (img.naturalWidth > 0 && img.naturalHeight > 0) {
        visible = Math.min(box.width, box.height * (img.naturalWidth / img.naturalHeight));
    }

    return Math.max(1, Math.min(100, Math.round((visible / inner) * 100)));
}
