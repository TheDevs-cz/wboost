import { Controller } from '@hotwired/stimulus';

/**
 * The bar that sizes ONE logo card of the manual preview (the top of the
 * width cascade, `Manual::logoDisplayWidth()`). Mounted once on the manual
 * root; every card's pencil (`_manual_logo_image.html.twig`) opens it for its
 * own slot via action params, and it is placed just above that card.
 *
 * The value is a % of the logo's DEFAULT size in the card (`LogoScale`):
 * the slider runs 10–200 % with 100 % = výchozí near the middle and a detent
 * around it. Dragging / typing previews it live on the card's
 * `<img>` (found by `data-logo-slot`) by setting the same `--logo-scale` the
 * Twig partial renders — app.css does the rest. Uložit POSTs it to
 * `EditManualLogoSlotWidthController` and the page stays where it is.
 * Zrušit / Escape put the original inline style back.
 */
const MIN = 10;
const MAX = 200;
const DEFAULT = 100;
// Slider positions this close to 100 % snap onto it, so "back to default"
// is easy to hit by hand.
const DETENT = 4;

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
        this.numberTarget.placeholder = String(this.fallbackWidth());
        this.rangeTarget.value = this.active.slotWidth ?? this.fallbackWidth();
        this.hintTarget.textContent = this.active.variantWidth !== null
            ? `% výchozí velikosti loga. Prázdné pole = ${this.active.variantWidth} % nastavených pro celou variantu loga v sekci Loga.`
            : '% výchozí velikosti loga (100 % = výchozí, 50 % = poloviční, nejvýše na šířku rámečku). Prázdné pole = výchozí.';
        this.errorTarget.textContent = '';
        this.setSaving(false);

        card.classList.add('manual-logo-width-card-active');
        button.classList.add('active');
        this.barTarget.hidden = false;

        this.placeAboveCard();
        // preventScroll: the focus jump would yank the bar to the viewport
        // edge; scrollIntoView below only moves the page when it must.
        this.numberTarget.focus({ preventScroll: true });
        this.numberTarget.select();
        this.barTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    slide() {
        if (this.active === null) {
            return;
        }

        const width = snap(Number(this.rangeTarget.value));
        this.rangeTarget.value = width;
        this.numberTarget.value = width;
        this.preview(width);
    }

    type() {
        if (this.active === null) {
            return;
        }

        const width = toWidth(this.numberTarget.value);
        this.preview(width);
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
        // A growing logo can grow its row, and a vertically centred card
        // then moves — keep the bar glued to its top edge.
        this.placeAboveCard();
    }

    close() {
        const { card, button } = this.active;
        card.classList.remove('manual-logo-width-card-active');
        button.classList.remove('active');
        this.barTarget.hidden = true;
        this.active = null;
        this.setSaving(false);
    }

    /** The scale the card shows with no number of its own. */
    fallbackWidth() {
        return this.active.variantWidth ?? DEFAULT;
    }

    setSaving(saving) {
        this.saving = saving;
        this.saveTarget.disabled = saving;
        this.saveTarget.textContent = saving ? 'Ukládám…' : 'Uložit';
    }

    /**
     * Pins the bar just above the active card, centred on it but kept inside
     * the manual's content column. Coordinates are relative to the manual
     * root (`.manual-logo-width-root`, the bar's containing block), so the
     * bar scrolls with the page. Re-run on window resize: the column is
     * centred, so a resize moves the card sideways.
     */
    placeAboveCard() {
        if (this.active === null) {
            return;
        }

        const bar = this.barTarget;
        const rootRect = this.element.getBoundingClientRect();
        const cardRect = this.active.card.getBoundingClientRect();
        const columnRect = (this.active.card.closest('.container-fluid') ?? this.element).getBoundingClientRect();
        const gap = 12;

        const centred = cardRect.left + (cardRect.width - bar.offsetWidth) / 2;
        const maxLeft = Math.max(columnRect.left, columnRect.right - bar.offsetWidth);
        const left = Math.min(Math.max(centred, columnRect.left), maxLeft);

        bar.style.left = `${Math.round(left - rootRect.left)}px`;
        bar.style.top = `${Math.round(cardRect.top - rootRect.top - bar.offsetHeight - gap)}px`;
    }
}

/** '' / null / 0 → null (no own width), otherwise an integer clamped to MIN–MAX. */
function toWidth(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const width = Math.round(Number(value));
    if (!Number.isFinite(width) || width <= 0) {
        return null;
    }

    return Math.max(MIN, Math.min(width, MAX));
}

/** Slider value → MIN–MAX, pulled onto 100 % inside the detent. */
function snap(value) {
    const width = Math.max(MIN, Math.min(Math.round(value), MAX));

    return Math.abs(width - DEFAULT) <= DETENT ? DEFAULT : width;
}

/** Same output as `Manual::logoDisplayScale()`: 100 % / none = no style. */
function applyWidth(img, width) {
    if (width === null || width === DEFAULT) {
        img.style.removeProperty('--logo-scale');
        if (img.getAttribute('style') === '') {
            img.removeAttribute('style');
        }
        return;
    }

    img.style.setProperty('--logo-scale', String(width / 100));
}
