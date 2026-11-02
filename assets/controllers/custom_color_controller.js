import { Controller } from '@hotwired/stimulus';

/**
 * One colour card on the manual palette page (detected and custom colours).
 *
 *   input        the HEX field (text for custom colours, hidden for detected)
 *   colorDisplay the swatch painted with the colour
 *   rgb          optional caption "RGB r, g, b", computed from the HEX
 *   cmyk         optional C, M, Y, K inputs (in this order) — `calc` fills
 *                them from the HEX ("Dopočítat z HEX", a naive conversion;
 *                print values from the designer always win, so it never
 *                overwrites a filled field without the button)
 *
 * The swatch copies the HEX on click (`copy`).
 */
export default class extends Controller {
    static targets = ['input', 'colorDisplay', 'rgb', 'cmyk'];

    connect() {
        this.updateColor();  // Initial color update on load if needed
    }

    updateColor() {
        const hex = this.hex();
        const rgb = hex ? this.toRgb(hex) : null;

        this.colorDisplayTarget.style.backgroundColor = hex ? `#${hex}` : '';
        // Contrast-aware text on the swatch (source label, copy icon).
        if (rgb) {
            const luminance = (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255;
            this.colorDisplayTarget.classList.toggle('is-light', luminance > 0.6);
        }
        if (this.hasRgbTarget) {
            this.rgbTarget.textContent = rgb ? `RGB ${rgb.join(', ')}` : 'RGB —';
        }
        this.element.classList.toggle('is-cmyk-missing', this.hasCmykTarget && this.cmykTargets.every((field) => field.value.trim() === ''));
    }

    calc(event) {
        event.preventDefault();
        const hex = this.hex();
        const rgb = hex ? this.toRgb(hex) : null;
        if (!rgb || !this.hasCmykTarget) return;
        const [r, g, b] = rgb.map((value) => value / 255);
        const k = 1 - Math.max(r, g, b);
        const cmyk = k >= 1
            ? [0, 0, 0, 100]
            : [(1 - r - k) / (1 - k), (1 - g - k) / (1 - k), (1 - b - k) / (1 - k), k].map((value) => Math.round(value * 100));
        this.cmykTargets.forEach((field, index) => {
            field.value = String(cmyk[index] ?? '');
            field.dispatchEvent(new Event('input', { bubbles: true }));
        });
        this.updateColor();
    }

    async copy(event) {
        event.preventDefault();
        const hex = this.hex();
        if (!hex) return;
        try {
            await navigator.clipboard.writeText(`#${hex}`);
            this.colorDisplayTarget.dataset.copied = 'Zkopírováno';
            setTimeout(() => { delete this.colorDisplayTarget.dataset.copied; }, 1400);
        } catch (error) {
            // Clipboard unavailable (insecure context) — nothing to do.
        }
    }

    hex() {
        let colorValue = this.inputTarget.value.trim();

        // Remove any leading '#' characters
        if (colorValue.startsWith('#')) {
            colorValue = colorValue.substring(1);
        }

        return /^[0-9a-f]{6}$/i.test(colorValue) ? colorValue.toUpperCase() : (colorValue === '' ? null : colorValue);
    }

    toRgb(hex) {
        if (!/^[0-9a-f]{6}$/i.test(hex)) return null;
        const n = Number.parseInt(hex, 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }
}
