import { Controller } from '@hotwired/stimulus';

/**
 * Template dimension form helper: one-click presets that prefill the
 * unit + width + height fields of the add-variant form. The preset values
 * travel as Stimulus action params on each button:
 *
 *   data-action="template-dimension#applyPreset"
 *   data-template-dimension-unit-param="mm"
 *   data-template-dimension-width-param="210"
 *   data-template-dimension-height-param="297"
 *
 * Social-network presets additionally carry
 * `data-template-dimension-preset-param="1:1"`, recorded into the hidden
 * `preset` field. Any manual edit of unit/width/height clears the marker
 * again (`clearPreset` action) — the size stays, it just stops being "the
 * Instagram format" and becomes a free-form dimension.
 *
 * The optional `bleed` / `cropMarks` targets (print setup for the PDF
 * export) follow the preset kind: an Instagram preset clears both, a print
 * format restores the usual 3 mm bleed + crop marks. The `printFields`
 * wrapper only shows for print units (mm / cm) — px is a screen format.
 */
export default class extends Controller {
    static targets = ['unit', 'width', 'height', 'preset', 'bleed', 'cropMarks', 'printFields'];

    static DEFAULT_BLEED_MM = 3;

    applyPreset(event) {
        event.preventDefault();

        const { unit, width, height, preset } = event.params;

        this.unitTarget.value = unit;
        this.widthTarget.value = width;
        this.heightTarget.value = height;

        if (this.hasPresetTarget) {
            this.presetTarget.value = preset ?? '';
        }

        const print = !preset;
        if (this.hasBleedTarget) {
            this.bleedTarget.value = print ? this.constructor.DEFAULT_BLEED_MM : 0;
        }
        if (this.hasCropMarksTarget) {
            this.cropMarksTarget.checked = print;
        }

        [this.unitTarget, this.widthTarget, this.heightTarget].forEach((element) => {
            element.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    connect() {
        this._syncPrintFields();
        this._onUnitChange = () => this._syncPrintFields();
        this.unitTarget.addEventListener('change', this._onUnitChange);
    }

    disconnect() {
        this.unitTarget.removeEventListener('change', this._onUnitChange);
    }

    _syncPrintFields() {
        if (!this.hasPrintFieldsTarget) return;
        this.printFieldsTarget.classList.toggle('d-none', this.unitTarget.value === 'px');
    }

    clearPreset() {
        if (this.hasPresetTarget) {
            this.presetTarget.value = '';
        }
    }
}
