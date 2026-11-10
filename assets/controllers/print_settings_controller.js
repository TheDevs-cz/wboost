import { Controller } from '@hotwired/stimulus';
import { clampBleed, formatMm, infoParts, isValidSize, previewSvg } from './print_settings_geometry.js';

/**
 * "Nastavení tisku": the print setup modal of an existing variant / document
 * (`templates/_print_settings.html.twig`; the add forms use the inline row,
 * print_inline_controller.js).
 *
 * The modal's inputs are a DRAFT with no `name`. The committed values live in
 * the hidden `bleedField` / `marksField` inputs: every open starts from them,
 * "Zrušit" just closes, "Uložit" writes them back and submits the form. The
 * size is read-only.
 */
let instances = 0;

export default class extends Controller {
    static targets = ['modal', 'form', 'width', 'height', 'bleedToggle', 'bleedRow', 'bleed', 'marks', 'preview', 'caption', 'legendBleed', 'legendMarks', 'infoSize', 'infoRest', 'bleedField', 'marksField'];

    static values = {
        widthMm: Number,
        heightMm: Number,
        offset: Number,
        length: Number,
        maxBleed: Number,
        defaultBleed: Number,
    };

    connect() {
        this.uid = ++instances;
        this.onShow = () => this.loadDraft();
        this.onHidden = () => this.loadDraft();
        this.modalTarget.addEventListener('show.bs.modal', this.onShow);
        this.modalTarget.addEventListener('hidden.bs.modal', this.onHidden);

        this.loadDraft();
    }

    disconnect() {
        this.modalTarget.removeEventListener('show.bs.modal', this.onShow);
        this.modalTarget.removeEventListener('hidden.bs.modal', this.onHidden);
    }

    readMarks() {
        return this.marksFieldTarget.value === '1';
    }

    writeMarks(marks) {
        this.marksFieldTarget.value = marks ? '1' : '0';
    }

    committedSize() {
        return { width: this.widthMmValue, height: this.heightMmValue };
    }

    committedSetup() {
        return {
            ...this.committedSize(),
            bleed: clampBleed(parseFloat(this.bleedFieldTarget.value), this.maxBleedValue),
            marks: this.readMarks(),
            offset: this.offsetValue,
            length: this.lengthValue,
        };
    }

    draftSetup() {
        return {
            width: parseFloat(this.widthTarget.value) || 0,
            height: parseFloat(this.heightTarget.value) || 0,
            bleed: this.bleedToggleTarget.checked ? clampBleed(parseFloat(this.bleedTarget.value), this.maxBleedValue) : 0,
            marks: this.marksTarget.checked,
            offset: this.offsetValue,
            length: this.lengthValue,
        };
    }

    loadDraft() {
        const setup = this.committedSetup();

        this.widthTarget.value = setup.width > 0 ? Math.round(setup.width * 100) / 100 : '';
        this.heightTarget.value = setup.height > 0 ? Math.round(setup.height * 100) / 100 : '';
        this.widthTarget.disabled = true;
        this.heightTarget.disabled = true;
        this.bleedToggleTarget.checked = setup.bleed > 0;
        this.bleedTarget.value = setup.bleed > 0 ? setup.bleed : this.defaultBleedValue;
        this.marksTarget.checked = setup.marks;
        this.render();
    }

    refresh() {
        this.render();
    }

    /** Leaving the field: an empty value takes the default, others clamp. */
    normalizeBleed() {
        const value = parseFloat(this.bleedTarget.value);

        this.bleedTarget.value = Number.isFinite(value) ? clampBleed(value, this.maxBleedValue) : this.defaultBleedValue;
        this.render();
    }

    /** Enter saves the normalised draft (not a raw native submit). */
    enter(event) {
        if (event.target instanceof HTMLInputElement) {
            event.preventDefault();
            this.save();
        }
    }

    save() {
        this.normalizeBleed();
        const setup = this.draftSetup();

        this.bleedFieldTarget.value = setup.bleed;
        this.writeMarks(setup.marks);
        this.formTarget.requestSubmit();
    }

    render() {
        const setup = this.draftSetup();
        const valid = isValidSize(setup);

        this.bleedRowTarget.hidden = !this.bleedToggleTarget.checked;
        this.legendBleedTarget.hidden = setup.bleed <= 0;
        this.legendMarksTarget.hidden = !setup.marks;
        this.previewTarget.innerHTML = valid ? previewSvg(setup, this.uid) : '';
        this.captionTarget.textContent = valid ? `${formatMm(setup.width)} × ${formatMm(setup.height)} mm` : '';

        if (!valid) {
            this.infoSizeTarget.textContent = 'Zadejte rozměr';
            this.infoRestTarget.textContent = '';

            return;
        }

        const { size, clauses } = infoParts(setup);
        this.infoSizeTarget.textContent = size;
        this.infoRestTarget.textContent = clauses.map((clause) => ` · ${clause}`).join('');
    }
}
