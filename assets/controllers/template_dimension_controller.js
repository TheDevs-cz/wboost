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
 */
export default class extends Controller {
    static targets = ['unit', 'width', 'height', 'preset'];

    applyPreset(event) {
        event.preventDefault();

        const { unit, width, height, preset } = event.params;

        this.unitTarget.value = unit;
        this.widthTarget.value = width;
        this.heightTarget.value = height;

        if (this.hasPresetTarget) {
            this.presetTarget.value = preset ?? '';
        }

        [this.unitTarget, this.widthTarget, this.heightTarget].forEach((element) => {
            element.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    clearPreset() {
        if (this.hasPresetTarget) {
            this.presetTarget.value = '';
        }
    }
}
