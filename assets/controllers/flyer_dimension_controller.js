import { Controller } from '@hotwired/stimulus';

/**
 * Free-form flyer dimension form helper: one-click A5/A4/A3 presets that
 * prefill the unit + width + height fields of the add-variant form. The
 * preset values travel as Stimulus action params on each button:
 *
 *   data-action="flyer-dimension#applyPreset"
 *   data-flyer-dimension-unit-param="mm"
 *   data-flyer-dimension-width-param="210"
 *   data-flyer-dimension-height-param="297"
 */
export default class extends Controller {
    static targets = ['unit', 'width', 'height'];

    applyPreset(event) {
        event.preventDefault();

        const { unit, width, height } = event.params;

        this.unitTarget.value = unit;
        this.widthTarget.value = width;
        this.heightTarget.value = height;

        [this.unitTarget, this.widthTarget, this.heightTarget].forEach((element) => {
            element.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
}
