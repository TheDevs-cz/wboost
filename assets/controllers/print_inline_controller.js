import { Controller } from '@hotwired/stimulus';
import { clampBleed, isValidSize, infoParts } from './print_settings_geometry.js';

const round = (value) => Math.round(value * 10) / 10;

/**
 * Inline print setup on the add forms (`templates/_print_settings_inline.html.twig`).
 *
 * The stepper input is the real `bleed` form field: 0 means "no bleed", so
 * switching Spadávka off writes 0 and remembers the last value for switching
 * it back on. The size (unit / width / height of the surrounding
 * template-dimension wrapper) only feeds the "PDF w × h mm" summary.
 */
export default class extends Controller {
    static targets = ['toggle', 'stepper', 'bleed', 'marks', 'summary'];

    static values = { max: Number, default: Number, offset: Number, length: Number };

    connect() {
        this.last = this.current() > 0 ? this.current() : this.defaultValue;
        if (this.current() > 0) {
            this.bleedTarget.value = round(this.current()); // "5.0" → "5"
        }
        this.dimensionRoot = this.element.closest('[data-controller~="template-dimension"]');
        this.onDimensionEdit = () => this.render();
        this.dimensionRoot?.addEventListener('input', this.onDimensionEdit);
        this.dimensionRoot?.addEventListener('change', this.onDimensionEdit);
        this.sync();
    }

    disconnect() {
        this.dimensionRoot?.removeEventListener('input', this.onDimensionEdit);
        this.dimensionRoot?.removeEventListener('change', this.onDimensionEdit);
    }

    current() {
        const value = parseFloat(this.bleedTarget.value);

        return Number.isFinite(value) ? clampBleed(value, this.maxValue) : 0;
    }

    /** The field changed from outside (a preset) or by typing: follow it. */
    sync() {
        const value = this.current();

        if (value > 0) {
            this.last = value;
        }
        this.toggleTarget.checked = value > 0;
        this.render();
    }

    toggle() {
        if (this.toggleTarget.checked) {
            this.bleedTarget.value = round(this.last > 0 ? this.last : this.defaultValue);
        } else {
            if (this.current() > 0) {
                this.last = this.current();
            }
            this.bleedTarget.value = 0;
        }
        this.render();
    }

    /** Leaving the input: empty / 0 switches the bleed off, others clamp. */
    normalize() {
        if (!this.toggleTarget.checked) {
            return;
        }
        const value = this.current();

        this.bleedTarget.value = value > 0 ? round(value) : 0;
        this.sync();
    }

    decrement() {
        this.step(-1);
    }

    increment() {
        this.step(1);
    }

    step(delta) {
        const next = Math.min(this.maxValue, Math.max(1, Math.round(this.current() + delta)));

        this.bleedTarget.value = next;
        this.last = next;
        this.render();
    }

    render() {
        const on = this.toggleTarget.checked;
        const bleed = on ? this.current() : 0;

        this.stepperTarget.hidden = !on;
        this.stepperTarget.querySelectorAll('.tp-stepper-btn').forEach((button, index) => {
            button.disabled = index === 0 ? bleed <= 1 : bleed >= this.maxValue;
        });

        const factor = this.dimensionRoot?.querySelector('[data-template-dimension-target~="unit"]')?.value === 'cm' ? 10 : 1;
        const mm = (name) => (parseFloat(this.dimensionRoot?.querySelector(`[data-template-dimension-target~="${name}"]`)?.value ?? '') || 0) * factor;
        const setup = { width: mm('width'), height: mm('height'), bleed, marks: this.marksTarget.checked, offset: this.offsetValue, length: this.lengthValue };

        this.summaryTarget.textContent = isValidSize(setup) ? `PDF ${infoParts(setup).size}` : '';
    }
}
