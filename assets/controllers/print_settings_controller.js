import { Controller } from '@hotwired/stimulus';
import { clampBleed, formatMm, infoParts, isValidSize, previewSvg, summaryText } from './print_settings_geometry.js';

/**
 * "Nastavení tisku": one modal for every place a print setup is chosen
 * (`templates/_print_settings.html.twig`).
 *
 * The modal's inputs are a DRAFT with no `name`. The committed values live in
 * the `bleedField` / `marksField` targets: the Symfony widgets on the add
 * forms, hidden inputs on an existing variant / document. Every open starts
 * from them and "Zrušit" just closes; "Uložit" writes them back, and where the
 * partial brought its own <form> also submits it. On an add form the modal
 * sits INSIDE the page's form, hence no nested <form>, type="button" only and
 * Enter handled here.
 *
 * The size is read-only on an existing design (`locked`). On an add form it
 * mirrors the page's unit / width / height fields (cm shown as mm) and writes
 * edits back to them on save.
 */
let instances = 0;

export default class extends Controller {
    static targets = ['modal', 'form', 'width', 'height', 'bleedToggle', 'bleedRow', 'bleed', 'marks', 'preview', 'caption', 'legendBleed', 'legendMarks', 'infoSize', 'infoRest', 'summary', 'bleedField', 'marksField'];

    static values = {
        locked: Boolean,
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

        // Unit / size / presets on the add form feed the summary line.
        this.dimensionRoot = this.element.closest('[data-controller~="template-dimension"]');
        this.onDimensionEdit = () => this.renderSummary();
        this.dimensionRoot?.addEventListener('input', this.onDimensionEdit);
        this.dimensionRoot?.addEventListener('change', this.onDimensionEdit);

        this.loadDraft();
        this.renderSummary();
    }

    disconnect() {
        this.modalTarget.removeEventListener('show.bs.modal', this.onShow);
        this.modalTarget.removeEventListener('hidden.bs.modal', this.onHidden);
        this.dimensionRoot?.removeEventListener('input', this.onDimensionEdit);
        this.dimensionRoot?.removeEventListener('change', this.onDimensionEdit);
    }

    /** Checkbox on the add forms, hidden "1" / "0" on the existing ones. */
    readMarks() {
        const field = this.marksFieldTarget;

        return field.type === 'checkbox' ? field.checked : field.value === '1';
    }

    writeMarks(marks) {
        const field = this.marksFieldTarget;

        if (field.type === 'checkbox') {
            field.checked = marks;
        } else {
            field.value = marks ? '1' : '0';
        }
    }

    pageField(name) {
        return this.dimensionRoot?.querySelector(`[data-template-dimension-target~="${name}"]`) ?? null;
    }

    unitFactor() {
        return this.pageField('unit')?.value === 'cm' ? 10 : 1;
    }

    committedSize() {
        if (this.lockedValue) {
            return { width: this.widthMmValue, height: this.heightMmValue };
        }

        const factor = this.unitFactor();
        const mm = (name) => (parseFloat(this.pageField(name)?.value ?? '') || 0) * factor;

        return { width: mm('width'), height: mm('height') };
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
        this.widthTarget.disabled = this.lockedValue;
        this.heightTarget.disabled = this.lockedValue;
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

    /** Enter must save the draft, not submit the form around the modal. */
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

        if (!this.lockedValue) {
            this.writeSizeBack(setup);
        }

        this.renderSummary();

        if (this.hasFormTarget) {
            this.formTarget.requestSubmit();

            return;
        }

        window.bootstrap.Modal.getOrCreateInstance(this.modalTarget).hide();
    }

    writeSizeBack({ width, height }) {
        const factor = this.unitFactor();

        for (const [name, mm] of [['width', width], ['height', height]]) {
            const field = this.pageField(name);

            if (field && mm > 0 && Math.abs((parseFloat(field.value) || 0) * factor - mm) > 0.001) {
                field.value = Math.round(mm / factor * 10000) / 10000;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
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

    renderSummary() {
        if (this.hasSummaryTarget) {
            this.summaryTarget.textContent = summaryText(this.committedSetup());
        }
    }
}
