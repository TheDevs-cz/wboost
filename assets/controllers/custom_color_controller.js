import { Controller } from '@hotwired/stimulus';
import { isLightRgb } from './palette_color_math.js';

/**
 * Live swatch next to a HEX field (the logo colour-mapping component,
 * `LogoColorsMapping.html.twig`).
 *
 *   input        the HEX field
 *   colorDisplay the swatch painted with the colour
 */
export default class extends Controller {
    static targets = ['input', 'colorDisplay'];

    connect() {
        this.updateColor();  // Initial color update on load if needed
    }

    updateColor() {
        const hex = this.hex();
        const rgb = hex ? this.toRgb(hex) : null;

        this.colorDisplayTarget.style.backgroundColor = hex ? `#${hex}` : '';
        // Contrast-aware text on the swatch.
        if (rgb) {
            this.colorDisplayTarget.classList.toggle('is-light', isLightRgb(rgb));
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
