import { Controller } from '@hotwired/stimulus';

/**
 * Interactive mockup page editor (add + edit).
 *
 * Renders the chosen layout as a real-proportion page where every segment is
 * a click/drop upload zone with instant preview and dimension feedback
 * (expected vs. actual size, crop estimate, low-resolution warning). Files
 * live in the regular hidden Symfony form inputs, so saving stays one POST.
 *
 * Slot geometry comes from MockupPageLayout::exportGeometry() — the same
 * source that renders the manual page itself.
 */
export default class extends Controller {
    static targets = [
        'stage', 'stageEmpty', 'fileInput', 'removeFlag',
        'layoutRadio', 'layoutOption', 'extraNotice',
        'fillSummary', 'dirtyBadge',
        'downloadList', 'downloadInput', 'downloadRemoveFlag',
        'pageDownloadInput', 'pageDownloadRemoveFlag',
    ];

    static values = {
        geometry: Object,
        existingImages: Array,
        existingDownloads: Array,
        // `{}` means "no file attached" — a Stimulus Object value cannot be null.
        existingPageDownload: Object,
        fixedLayout: String,
        maxFileSize: Number,
        maxDownloadSize: Number,
    };

    connect() {
        // Reset here (not in initialize) — Stimulus reuses the instance when
        // the controller reconnects to the same element, and stale slot state
        // must not survive a reconnect.
        this.slotStates?.forEach((state) => {
            if (state.file?.objectUrl) {
                URL.revokeObjectURL(state.file.objectUrl);
            }
        });
        this.slotStates = [];
        this.slotElements = [];
        this.dirty = false;
        this.submitting = false;

        for (let i = 0; i < this.fileInputTargets.length; i++) {
            this.slotStates.push({
                existingUrl: this.existingImagesValue[i] ?? null,
                existingDims: null,
                removed: (this.removeFlagTargets[i]?.value ?? '0') === '1',
                file: null,
                error: null,
            });
        }

        // Downloadable attachments: one state per slot plus the page-level one.
        // They carry no preview, only a name/size, so they are their own list
        // of rows below the stage rather than chrome inside a slot.
        this.downloadStates = [];
        for (let i = 0; i < this.downloadInputTargets.length; i++) {
            this.downloadStates.push({
                existing: this.existingDownloadsValue[i] ?? null,
                removed: (this.downloadRemoveFlagTargets[i]?.value ?? '0') === '1',
                file: null,
                error: null,
            });
        }

        const pageDownload = this.existingPageDownloadValue;
        this.pageDownloadState = {
            existing: pageDownload && pageDownload.name ? pageDownload : null,
            removed: (this.pageDownloadRemoveFlagTarget?.value ?? '0') === '1',
            file: null,
            error: null,
        };

        this.onBeforeUnload = (event) => {
            if (this.dirty && !this.submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);

        this.onNameInput = (event) => {
            if (event.target.matches('input[type="text"]')) {
                this.markDirty();
            }
        };
        this.element.addEventListener('input', this.onNameInput);

        this.renderStage();
        this.renderDownloads();
        this.updateMiniGrids();
        this.updateSummary();

        this.slotStates.forEach((state, index) => {
            if (state.existingUrl !== null) {
                this.probeExistingImage(index, state.existingUrl);
            }
        });
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.element.removeEventListener('input', this.onNameInput);
        this.slotStates.forEach((state) => {
            if (state.file?.objectUrl) {
                URL.revokeObjectURL(state.file.objectUrl);
            }
        });
    }

    // --- layout -----------------------------------------------------------

    get currentLayout() {
        if (this.fixedLayoutValue !== '') {
            return this.fixedLayoutValue;
        }

        return this.layoutRadioTargets.find((radio) => radio.checked)?.value ?? null;
    }

    get currentSlots() {
        const layout = this.currentLayout;

        return layout === null ? [] : (this.geometryValue[layout] ?? []);
    }

    layoutChanged() {
        this.markDirty();
        this.renderStage();
        this.renderDownloads();
        this.updateMiniGrids();
        this.updateSummary();
    }

    // --- stage rendering ----------------------------------------------------

    renderStage() {
        const slots = this.currentSlots;

        this.stageTarget.innerHTML = '';
        this.slotElements = [];

        const hasLayout = slots.length > 0;
        this.stageTarget.parentElement.classList.toggle('d-none', !hasLayout);
        if (this.hasStageEmptyTarget) {
            this.stageEmptyTarget.classList.toggle('d-none', hasLayout);
        }

        slots.forEach((slot, index) => {
            const element = this.buildSlotElement(slot, index);
            this.stageTarget.appendChild(element);
            this.slotElements.push(element);
            this.refreshSlot(index);
        });

        this.updateExtraNotice();
    }

    buildSlotElement(slot, index) {
        const element = document.createElement('div');
        element.className = 'mockup-editor-slot';
        element.style.gridColumn = `${slot.column} / span ${slot.columnSpan}`;
        element.style.gridRow = `${slot.row} / span ${slot.rowSpan}`;

        element.innerHTML = `
            <img class="mockup-editor-slot-img" alt="" hidden>
            <div class="mockup-editor-slot-empty">
                <i class="ri-image-add-line"></i>
                <strong>Nahrát obrázek</strong>
                <span class="mockup-editor-slot-dims">doporučeno ${slot.recommendedWidth} × ${slot.recommendedHeight} px</span>
                <span class="mockup-editor-slot-error text-danger" hidden></span>
            </div>
            <div class="mockup-editor-slot-removed" hidden>
                <i class="ri-delete-bin-line"></i>
                <strong>Obrázek bude po uložení odebrán</strong>
            </div>
            <span class="mockup-editor-slot-number">${index + 1}</span>
            <span class="mockup-editor-slot-attach" hidden><i class="mdi mdi-paperclip"></i></span>
            <span class="mockup-editor-slot-chip" hidden></span>
            <div class="mockup-editor-slot-meta" hidden></div>
            <div class="mockup-editor-slot-actions">
                <button type="button" class="btn btn-sm btn-light" data-role="replace"><i class="ri-upload-2-line me-1"></i>Vyměnit</button>
                <button type="button" class="btn btn-sm btn-light text-danger" data-role="remove"><i class="ri-delete-bin-line me-1"></i>Odebrat</button>
                <button type="button" class="btn btn-sm btn-light" data-role="restore"><i class="ri-arrow-go-back-line me-1"></i>Vrátit původní</button>
            </div>
        `;

        element.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-role]');

            if (button === null) {
                this.openPicker(index);
                return;
            }

            if (button.dataset.role === 'replace') {
                this.openPicker(index);
            } else if (button.dataset.role === 'remove') {
                this.removeImage(index);
            } else if (button.dataset.role === 'restore') {
                this.restoreImage(index);
            }
        });

        element.addEventListener('dragover', (event) => {
            event.preventDefault();
            element.classList.add('is-dragover');
        });
        element.addEventListener('dragleave', () => element.classList.remove('is-dragover'));
        element.addEventListener('drop', (event) => {
            event.preventDefault();
            element.classList.remove('is-dragover');

            const file = event.dataTransfer.files[0] ?? null;

            if (file !== null) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                this.fileInputTargets[index].files = transfer.files;
                this.fileInputTargets[index].dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        return element;
    }

    refreshSlot(index) {
        const element = this.slotElements[index];
        const slot = this.currentSlots[index];

        if (element === undefined || slot === undefined) {
            return;
        }

        const state = this.slotStates[index];
        const displayUrl = state.file?.objectUrl ?? (state.removed ? null : state.existingUrl);
        const isRemovedView = state.file === null && state.removed && state.existingUrl !== null;

        const image = element.querySelector('.mockup-editor-slot-img');
        image.hidden = displayUrl === null;
        image.src = displayUrl ?? '';

        element.classList.toggle('has-image', displayUrl !== null);
        element.classList.toggle('is-removed', isRemovedView);

        element.querySelector('.mockup-editor-slot-empty').hidden = displayUrl !== null || isRemovedView;
        element.querySelector('.mockup-editor-slot-removed').hidden = !isRemovedView;

        const errorElement = element.querySelector('.mockup-editor-slot-error');
        errorElement.hidden = state.error === null;
        errorElement.textContent = state.error ?? '';

        const dims = state.file !== null
            ? { width: state.file.width, height: state.file.height }
            : (displayUrl !== null ? state.existingDims : null);

        const chip = element.querySelector('.mockup-editor-slot-chip');
        const meta = element.querySelector('.mockup-editor-slot-meta');

        element.classList.remove('level-success', 'level-warning', 'level-danger');

        if (dims !== null && displayUrl !== null) {
            const verdict = this.verdict(dims, slot);
            element.classList.add(`level-${verdict.level}`);

            chip.hidden = false;
            chip.className = `mockup-editor-slot-chip chip-${verdict.level}`;
            chip.innerHTML = `${verdict.icon} ${verdict.short}`;

            meta.hidden = false;
            const name = state.file !== null ? `${this.truncate(state.file.name, 28)} · ` : '';
            meta.textContent = `${name}${dims.width} × ${dims.height} px · ${verdict.notes.join(' · ')}`;
        } else {
            chip.hidden = true;
            meta.hidden = displayUrl === null;
            meta.textContent = '';
        }

        const removeButton = element.querySelector('button[data-role="remove"]');
        removeButton.hidden = displayUrl === null;
        removeButton.innerHTML = state.file !== null
            ? '<i class="ri-close-line me-1"></i>Zrušit výběr'
            : '<i class="ri-delete-bin-line me-1"></i>Odebrat';

        element.querySelector('button[data-role="replace"]').hidden = displayUrl === null;
        element.querySelector('button[data-role="restore"]').hidden = !isRemovedView;

        // Mirrors the row below the stage, so the designer can see at a glance
        // which segment carries a downloadable file.
        const attachment = element.querySelector('.mockup-editor-slot-attach');
        const download = this.effectiveDownload(this.downloadStates[index]);
        attachment.hidden = download === null;
        attachment.title = download === null ? '' : `Soubor ke stažení: ${this.describeDownload(download)}`;
    }

    // --- file handling ------------------------------------------------------

    openPicker(index) {
        this.fileInputTargets[index]?.click();
    }

    fileChanged(event) {
        const index = this.fileInputTargets.indexOf(event.target);

        if (index === -1) {
            return;
        }

        const state = this.slotStates[index];
        const file = event.target.files[0] ?? null;
        state.error = null;

        if (file === null) {
            this.clearPickedFile(index);
            this.afterSlotChange(index);
            return;
        }

        if (!file.type.startsWith('image/')) {
            event.target.value = '';
            state.error = 'Vybraný soubor není obrázek.';
            this.afterSlotChange(index);
            return;
        }

        if (file.size > this.maxFileSizeValue) {
            event.target.value = '';
            // Decimal MB throughout, matching Symfony's `maxSize: '10m'`.
            const limitMb = Math.round(this.maxFileSizeValue / 1e6);
            state.error = `Obrázek má ${(file.size / 1e6).toFixed(1)} MB — maximum je ${limitMb} MB. Zmenšete ho a nahrajte znovu.`;
            this.afterSlotChange(index);
            return;
        }

        if (state.file?.objectUrl) {
            URL.revokeObjectURL(state.file.objectUrl);
        }

        const objectUrl = URL.createObjectURL(file);
        const probe = new Image();
        probe.onload = () => {
            state.file = {
                objectUrl,
                width: probe.naturalWidth,
                height: probe.naturalHeight,
                name: file.name,
            };
            state.removed = false;
            this.setRemoveFlag(index, false);
            this.markDirty();
            this.afterSlotChange(index);
        };
        probe.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            event.target.value = '';
            state.error = 'Obrázek se nepodařilo načíst.';
            this.afterSlotChange(index);
        };
        probe.src = objectUrl;
    }

    removeImage(index) {
        const state = this.slotStates[index];

        if (state.file !== null) {
            this.clearPickedFile(index);
        } else if (state.existingUrl !== null) {
            state.removed = true;
            this.setRemoveFlag(index, true);
        }

        this.markDirty();
        this.afterSlotChange(index);
    }

    restoreImage(index) {
        const state = this.slotStates[index];
        state.removed = false;
        this.setRemoveFlag(index, false);
        this.markDirty();
        this.afterSlotChange(index);
    }

    clearPickedFile(index) {
        const state = this.slotStates[index];

        if (state.file?.objectUrl) {
            URL.revokeObjectURL(state.file.objectUrl);
        }

        state.file = null;
        this.fileInputTargets[index].value = '';
    }

    setRemoveFlag(index, removed) {
        const flag = this.removeFlagTargets[index];

        if (flag !== undefined) {
            flag.value = removed ? '1' : '0';
        }
    }

    afterSlotChange(index) {
        this.refreshSlot(index);
        this.updateMiniGrids();
        this.updateSummary();
        this.updateExtraNotice();
    }

    probeExistingImage(index, url) {
        const probe = new Image();
        probe.onload = () => {
            this.slotStates[index].existingDims = {
                width: probe.naturalWidth,
                height: probe.naturalHeight,
            };
            this.refreshSlot(index);
        };
        probe.src = url;
    }

    // --- downloadable attachments -------------------------------------------

    /**
     * The file a row will end up with once saved: a freshly picked one wins
     * over the stored one, and the remove flag hides the stored one.
     */
    effectiveDownload(state) {
        if (state === undefined || state === null) {
            return null;
        }

        if (state.file !== null) {
            return state.file;
        }

        return state.removed ? null : state.existing;
    }

    describeDownload(download) {
        const size = this.formatSize(download.size ?? 0);

        return size === '' ? download.name : `${download.name} · ${size}`;
    }

    renderDownloads() {
        if (!this.hasDownloadListTarget) {
            return;
        }

        this.downloadListTarget.innerHTML = '';

        // The page-level row first, then one per slot of the CURRENT layout —
        // slots the layout does not have are sliced away server-side anyway.
        this.downloadListTarget.appendChild(
            this.buildDownloadRow('page', 'Celá stránka', 'mdi-file-document-outline'),
        );

        this.currentSlots.forEach((slot, index) => {
            this.downloadListTarget.appendChild(
                this.buildDownloadRow(index, `Obrázek ${index + 1}`, 'mdi-image-outline'),
            );
        });

        this.refreshDownloadRow('page');
        this.currentSlots.forEach((slot, index) => this.refreshDownloadRow(index));
    }

    buildDownloadRow(key, label, icon) {
        const element = document.createElement('div');
        element.className = 'mockup-download-row';
        element.dataset.downloadKey = String(key);

        element.innerHTML = `
            <span class="mockup-download-row-label"><i class="mdi ${icon} me-1"></i>${label}</span>
            <span class="mockup-download-row-file"></span>
            <span class="mockup-download-row-error text-danger" hidden></span>
            <span class="mockup-download-row-actions">
                <button type="button" class="btn btn-sm btn-light" data-role="pick"></button>
                <button type="button" class="btn btn-sm btn-light text-danger" data-role="remove" hidden><i class="ri-delete-bin-line me-1"></i>Odebrat</button>
                <button type="button" class="btn btn-sm btn-light" data-role="restore" hidden><i class="ri-arrow-go-back-line me-1"></i>Vrátit původní</button>
            </span>
        `;

        element.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-role]');

            if (button === null) {
                return;
            }

            if (button.dataset.role === 'pick') {
                this.downloadInputFor(key)?.click();
            } else if (button.dataset.role === 'remove') {
                this.removeDownload(key);
            } else if (button.dataset.role === 'restore') {
                this.restoreDownload(key);
            }
        });

        return element;
    }

    downloadStateFor(key) {
        return key === 'page' ? this.pageDownloadState : this.downloadStates[key];
    }

    downloadInputFor(key) {
        return key === 'page'
            ? (this.hasPageDownloadInputTarget ? this.pageDownloadInputTarget : null)
            : (this.downloadInputTargets[key] ?? null);
    }

    downloadRemoveFlagFor(key) {
        return key === 'page'
            ? (this.hasPageDownloadRemoveFlagTarget ? this.pageDownloadRemoveFlagTarget : null)
            : (this.downloadRemoveFlagTargets[key] ?? null);
    }

    downloadRowFor(key) {
        if (!this.hasDownloadListTarget) {
            return null;
        }

        return this.downloadListTarget.querySelector(`[data-download-key="${key}"]`);
    }

    refreshDownloadRow(key) {
        const element = this.downloadRowFor(key);
        const state = this.downloadStateFor(key);

        if (element === null || state === undefined) {
            return;
        }

        const download = this.effectiveDownload(state);
        const isRemovedView = state.file === null && state.removed && state.existing !== null;

        const file = element.querySelector('.mockup-download-row-file');
        if (download !== null) {
            file.textContent = this.describeDownload(download);
            file.className = 'mockup-download-row-file has-file';
        } else if (isRemovedView) {
            file.textContent = 'Soubor bude po uložení odebrán';
            file.className = 'mockup-download-row-file is-removed';
        } else {
            file.textContent = 'Bez souboru';
            file.className = 'mockup-download-row-file';
        }

        const error = element.querySelector('.mockup-download-row-error');
        error.hidden = state.error === null;
        error.textContent = state.error ?? '';

        const pick = element.querySelector('button[data-role="pick"]');
        pick.innerHTML = download === null
            ? '<i class="ri-attachment-2 me-1"></i>Vybrat soubor'
            : '<i class="ri-upload-2-line me-1"></i>Vyměnit';

        element.querySelector('button[data-role="remove"]').hidden = download === null;
        element.querySelector('button[data-role="restore"]').hidden = !isRemovedView;
    }

    downloadChanged(event) {
        const key = event.target === (this.hasPageDownloadInputTarget ? this.pageDownloadInputTarget : null)
            ? 'page'
            : this.downloadInputTargets.indexOf(event.target);

        if (key === -1) {
            return;
        }

        const state = this.downloadStateFor(key);

        if (state === undefined) {
            return;
        }

        const file = event.target.files[0] ?? null;
        state.error = null;

        if (file === null) {
            state.file = null;
            this.afterDownloadChange(key);
            return;
        }

        if (file.size > this.maxDownloadSizeValue) {
            event.target.value = '';
            state.file = null;
            // Decimal MB, matching Symfony's `maxSize: '20m'`.
            const limitMb = Math.round(this.maxDownloadSizeValue / 1e6);
            state.error = `Soubor má ${(file.size / 1e6).toFixed(1)} MB — maximum je ${limitMb} MB.`;
            this.afterDownloadChange(key);
            return;
        }

        state.file = { name: file.name, size: file.size };
        state.removed = false;
        this.setDownloadRemoveFlag(key, false);
        this.markDirty();
        this.afterDownloadChange(key);
    }

    removeDownload(key) {
        const state = this.downloadStateFor(key);

        if (state === undefined) {
            return;
        }

        if (state.file !== null) {
            state.file = null;
            const input = this.downloadInputFor(key);

            if (input !== null) {
                input.value = '';
            }
        } else if (state.existing !== null) {
            state.removed = true;
            this.setDownloadRemoveFlag(key, true);
        }

        state.error = null;
        this.markDirty();
        this.afterDownloadChange(key);
    }

    restoreDownload(key) {
        const state = this.downloadStateFor(key);

        if (state === undefined) {
            return;
        }

        state.removed = false;
        this.setDownloadRemoveFlag(key, false);
        this.markDirty();
        this.afterDownloadChange(key);
    }

    setDownloadRemoveFlag(key, removed) {
        const flag = this.downloadRemoveFlagFor(key);

        if (flag !== null) {
            flag.value = removed ? '1' : '0';
        }
    }

    afterDownloadChange(key) {
        this.refreshDownloadRow(key);
        this.updateExtraNotice();

        if (key !== 'page') {
            this.refreshSlot(key);
        }
    }

    /**
     * Mirrors the PHP `file_size` Twig filter (binary units, Czech decimal
     * comma) so a file reads the same here and in the manual.
     */
    formatSize(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '';
        }

        if (bytes < 1024) {
            return `${bytes} B`;
        }

        const units = ['kB', 'MB', 'GB', 'TB'];
        let value = bytes / 1024;
        let unit = 0;

        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }

        return `${value.toFixed(value >= 100 ? 0 : 1).replace('.', ',')} ${units[unit]}`;
    }

    // --- feedback -----------------------------------------------------------

    verdict(dims, slot) {
        const levels = { success: 0, warning: 1, danger: 2 };
        let level = 'success';
        const notes = [];
        const raise = (candidate) => {
            if (levels[candidate] > levels[level]) {
                level = candidate;
            }
        };

        const ratio = (dims.width / dims.height) / (slot.width / slot.height);
        let cropPercent = 0;
        let short = 'Sedí';

        if (ratio > 1.02) {
            cropPercent = Math.round((1 - 1 / ratio) * 100);
            notes.push(`ořízne se ~${cropPercent} % šířky`);
        } else if (ratio < 0.98) {
            cropPercent = Math.round((1 - ratio) * 100);
            notes.push(`ořízne se ~${cropPercent} % výšky`);
        } else {
            notes.push('poměr stran sedí');
        }

        if (cropPercent >= 25) {
            raise('danger');
            short = `Ořez ~${cropPercent} %`;
        } else if (cropPercent >= 5) {
            raise('warning');
            short = `Ořez ~${cropPercent} %`;
        }

        if (dims.width < slot.width || dims.height < slot.height) {
            raise('danger');
            short = 'Nízké rozlišení';
            notes.push(`nízké rozlišení — doporučeno alespoň ${slot.recommendedWidth} × ${slot.recommendedHeight} px`);
        } else if (dims.width < slot.recommendedWidth * 0.97 || dims.height < slot.recommendedHeight * 0.97) {
            raise('warning');
            notes.push(`pro ostré zobrazení doporučujeme ${slot.recommendedWidth} × ${slot.recommendedHeight} px`);
        }

        const icons = {
            success: '<i class="ri-checkbox-circle-fill"></i>',
            warning: '<i class="ri-error-warning-fill"></i>',
            danger: '<i class="ri-close-circle-fill"></i>',
        };

        return { level, short, notes, icon: icons[level] };
    }

    // --- summary / mini previews ---------------------------------------------

    slotHasImage(index) {
        const state = this.slotStates[index];

        return state !== undefined
            && (state.file !== null || (state.existingUrl !== null && !state.removed));
    }

    updateMiniGrids() {
        this.layoutOptionTargets.forEach((option) => {
            option.querySelectorAll('[data-slot-index]').forEach((cell) => {
                cell.classList.toggle('is-filled', this.slotHasImage(Number(cell.dataset.slotIndex)));
            });
        });
    }

    updateSummary() {
        if (!this.hasFillSummaryTarget) {
            return;
        }

        const total = this.currentSlots.length;

        if (total === 0) {
            this.fillSummaryTarget.textContent = 'Vyberte rozložení stránky';
            return;
        }

        let filled = 0;
        for (let i = 0; i < total; i++) {
            if (this.slotHasImage(i)) {
                filled++;
            }
        }

        this.fillSummaryTarget.textContent = `Nahráno ${filled} z ${total} obrázků`;
    }

    updateExtraNotice() {
        if (!this.hasExtraNoticeTarget) {
            return;
        }

        const total = this.currentSlots.length;
        const hasExtra = total > 0 && (
            this.slotStates.some((state, index) => index >= total && state.file !== null)
            || this.downloadStates.some((state, index) => index >= total && this.effectiveDownload(state) !== null)
        );

        this.extraNoticeTarget.classList.toggle('d-none', !hasExtra);
    }

    // --- dirty tracking -------------------------------------------------------

    markDirty() {
        this.dirty = true;

        if (this.hasDirtyBadgeTarget) {
            this.dirtyBadgeTarget.classList.remove('d-none');
        }
    }

    formSubmitted() {
        this.submitting = true;
    }

    truncate(text, length) {
        return text.length > length ? `${text.slice(0, length - 1)}…` : text;
    }
}
