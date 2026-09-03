import { Controller } from "@hotwired/stimulus";
import { applyChecklistItems, applySampleToCanvasText, checklistItems, itemsFromValue } from './canvas_checklist_sample.js';
import {
    buildFontOptgroups,
    collectAllowedFonts,
    effectiveFontOptions,
    planFontChoice,
    renderFontChoiceList,
} from './canvas_font_choice.js';

/**
 * Editor-side input metadata: name / description / locked / hidable /
 * uppercase. These are the properties consumed by the export form
 * (template-input fields) — they live on the canvas object as custom
 * properties and round-trip through CANVAS_CUSTOM_PROPERTIES.
 *
 * Name↔text smart sync: submitAddText seeds a new textbox's CANVAS text from
 * its name, so as long as the designer hasn't written real stand-in text yet
 * (canvas text still equals the name), renaming keeps the canvas text in
 * step — matching the expectation the seeding itself creates. The link is
 * computed when the selection populates the panel and is broken by any
 * inline canvas edit (Fabric fires text:changed only for interactive edits,
 * never for our programmatic set), after which renaming never touches the
 * designed text again.
 *
 * Font choice ("Uživatel může přepínat písmo"): `allowedFonts` on the
 * textbox = the EXTRA faces the end user may switch to. The checklist
 * (canvas_font_choice.js) shows every project face grouped by font with the
 * designed one locked-checked — never persisted, it follows the canvas font
 * — and the popover's toggle is derived from the pick (empty = off). The
 * same checklist backs the "Přidat text" modal (`#addTextFontChoiceList`)
 * and narrows the "Vzorový text" WYSIWYG's face menu per input.
 */
export default class extends Controller {
    static outlets = ["canvas-editor"];
    static values = {
        // Every project face: { family, fontName, faceName, weight, style, url }.
        fontFaces: { type: Array, default: [] },
    };
    static targets = [
        "name", "description", "locked", "hidable", "uppercase", "richText",
        "fontChoice", "fontChoiceList", "fontChoiceHint", "fontChoiceEmpty",
        "lists", "listConfig", "listBullet", "listBulletPreview", "listBulletPick",
        "listIndent", "listItemSpacing", "listBlockSpacing",
        "listCheckboxes", "checkboxConfig",
        "checkboxImagePreview", "checkboxImageClear",
        "checkboxCheckedImagePreview", "checkboxCheckedImageClear",
        "checklistSection", "checklistToggle", "checklistEditText", "checklistAdd", "checklistRemove",
        "sampleBadge", "sampleHost", "sampleTemplate", "samplePlain",
        "sampleLabel", "sampleIcon", "sampleTitle", "sampleHint", "sampleClear", "checklistTemplate",
    ];

    connect() {
        // The gallery modal's pick in 'bulletImage' mode comes back as a
        // canvas-editor event on the SHARED editor root element (both
        // controllers live on #canvas-container) — no template wiring needed.
        this._onBulletImage = (event) => this.onBulletImageSelected(event);
        this.element.addEventListener('canvas-editor:bullet-image', this._onBulletImage);
        this._onCheckboxImage = (event) => this.onCheckboxImageSelected(event);
        this.element.addEventListener('canvas-editor:checkbox-image', this._onCheckboxImage);

        // Tear the per-open WYSIWYG instance down when the sample modal
        // closes — the next open clones a fresh one with fresh values.
        const sampleModal = document.getElementById('sampleTextModal');
        if (sampleModal) {
            this._onSampleModalHidden = () => {
                if (this.hasSampleHostTarget) this.sampleHostTarget.textContent = '';
            };
            sampleModal.addEventListener('hidden.bs.modal', this._onSampleModalHidden);
        }

        // The "Přidat text" modal's font checklist follows its font select;
        // (re)built on every open so a closed-and-reopened modal starts clean.
        const addTextModal = document.getElementById('addTextModal');
        if (addTextModal) {
            this._onAddTextModalShown = () => this._resetAddTextFontChoice();
            addTextModal.addEventListener('show.bs.modal', this._onAddTextModalShown);
        }

        // A font checklist change anywhere (the popover list dispatches its
        // change on the container) — persist the popover's picks.
        this._onFontChoiceChange = () => this.updateAllowedFonts();
        if (this.hasFontChoiceListTarget) {
            this.fontChoiceListTarget.addEventListener('change', this._onFontChoiceChange);
        }
    }

    disconnect() {
        this.element.removeEventListener('canvas-editor:bullet-image', this._onBulletImage);
        this.element.removeEventListener('canvas-editor:checkbox-image', this._onCheckboxImage);
        const sampleModal = document.getElementById('sampleTextModal');
        if (sampleModal && this._onSampleModalHidden) {
            sampleModal.removeEventListener('hidden.bs.modal', this._onSampleModalHidden);
        }
        const addTextModal = document.getElementById('addTextModal');
        if (addTextModal && this._onAddTextModalShown) {
            addTextModal.removeEventListener('show.bs.modal', this._onAddTextModalShown);
        }
        if (this.hasFontChoiceListTarget && this._onFontChoiceChange) {
            this.fontChoiceListTarget.removeEventListener('change', this._onFontChoiceChange);
        }
    }

    canvasEditorOutletConnected(outlet) {
        // Apply uppercase live as the user types into a textbox — and break
        // the name↔text link, because an interactive text:changed means the
        // designer is writing real stand-in text.
        this._applyUppercaseOnInput = () => {
            const activeObject = outlet.canvas.getActiveObject();
            if (activeObject && (activeObject.type || '').toLowerCase() === 'textbox') {
                // Our own synthetic text:changed (rename sync) must not
                // unlink — only genuine inline edits do.
                if (!this._syncingText) {
                    this._nameTextLinked = false;
                }
                this._applyUppercase(activeObject);
            }
        };
        outlet.canvas.on('text:changed', this._applyUppercaseOnInput);

        this.updateFromSelection({ detail: { activeObject: outlet.canvas.getActiveObject() } });
    }

    canvasEditorOutletDisconnected(outlet) {
        if (this._applyUppercaseOnInput && outlet.canvas) {
            outlet.canvas.off('text:changed', this._applyUppercaseOnInput);
        }
    }

    updateFromSelection(event) {
        const activeObject = event.detail.activeObject;
        const isTextbox = activeObject && (activeObject.type || '').toLowerCase() === 'textbox';
        if (!isTextbox) return;

        this._nameTextLinked = this._textEqualsName(activeObject);

        if (this.hasLockedTarget)    this.lockedTarget.checked    = activeObject.locked || false;
        if (this.hasUppercaseTarget) this.uppercaseTarget.checked = activeObject.uppercase || false;
        if (this.hasNameTarget)      this.nameTarget.value        = activeObject.name || '';
        if (this.hasDescriptionTarget) this.descriptionTarget.value = activeObject.description || '';
        if (this.hasHidableTarget)   this.hidableTarget.checked   = activeObject.hidable || false;
        if (this.hasRichTextTarget) {
            this.richTextTarget.checked = activeObject.richText || false;
            // Locked inputs are never user-fillable, so the WYSIWYG toggle is
            // meaningless for them — keep the stored flag, just gray the box.
            this.richTextTarget.disabled = activeObject.locked || false;
        }
        this._syncListControls(activeObject);
        this._syncChecklistControls(activeObject);
        this._syncSampleChrome(activeObject);
        this._fontChoiceOpenFor = null;
        this._syncFontChoice(activeObject);
    }

    // --- Font choice ("Uživatel může přepínat písmo") -----------------------

    /** Populate the toggle + checklist for the active textbox. The toggle
     *  reflects a NON-EMPTY pick (or a just-opened, still-empty list — the
     *  transient state between ticking the box and ticking a face). */
    _syncFontChoice(activeObject) {
        if (!this.hasFontChoiceTarget || !this.hasFontChoiceListTarget) return;
        const allowed = Array.isArray(activeObject.allowedFonts) ? activeObject.allowedFonts : [];
        const open = allowed.length > 0 || this._fontChoiceOpenFor === activeObject;
        this.fontChoiceTarget.checked = open;
        this.fontChoiceTarget.disabled = activeObject.locked === true;
        if (this.hasFontChoiceHintTarget) {
            this.fontChoiceHintTarget.title = activeObject.richText
                ? 'Editor textu nabízí řezy použitého písma (tučné, kurzíva) vždy; zaškrtnutá písma přibudou do nabídky.'
                : 'Při vyplňování se u pole zobrazí výběr písma: použité písmo + zaškrtnutá.';
        }
        this.fontChoiceListTarget.classList.toggle('d-none', !open);
        if (open) {
            renderFontChoiceList(this.fontChoiceListTarget, planFontChoice(this.fontFacesValue, {
                designedFamily: activeObject.fontFamily || '',
                richText: activeObject.richText === true,
                allowedFonts: allowed,
            }));
        }
        if (this.hasFontChoiceEmptyTarget) {
            this.fontChoiceEmptyTarget.classList.toggle('d-none', !open || allowed.length > 0);
        }
    }

    /** The popover toggle: on opens the (empty) checklist, off clears the
     *  pick — an empty pick IS "no choice", nothing else to persist. */
    toggleFontChoice(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        if (event.target.checked) {
            this._fontChoiceOpenFor = activeObject;
        } else {
            this._fontChoiceOpenFor = null;
            if (Array.isArray(activeObject.allowedFonts) && activeObject.allowedFonts.length > 0) {
                activeObject.allowedFonts = [];
                this.canvasEditorOutlet.markUnsaved();
            }
        }
        this._syncFontChoice(activeObject);
    }

    /** A face checkbox / group checkbox changed in the popover list. */
    updateAllowedFonts() {
        const activeObject = this._getActiveTextbox();
        if (!activeObject || !this.hasFontChoiceListTarget) return;
        const picked = collectAllowedFonts(this.fontChoiceListTarget);
        const before = Array.isArray(activeObject.allowedFonts) ? activeObject.allowedFonts : [];
        if (JSON.stringify(picked) === JSON.stringify(before)) return;
        activeObject.allowedFonts = picked;
        if (picked.length > 0) this._fontChoiceOpenFor = null;
        if (this.hasFontChoiceEmptyTarget) {
            this.fontChoiceEmptyTarget.classList.toggle('d-none', picked.length > 0);
        }
        this.canvasEditorOutlet.markUnsaved();
    }

    /** The designed font changed (popover font select) — the locked "výchozí"
     *  row moves with it; the same after a rich toggle flips whole-family vs
     *  single-face locking. */
    refreshFontChoice() {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        // A face that just became the designed one is implied, not a pick.
        if (Array.isArray(activeObject.allowedFonts) && activeObject.allowedFonts.length > 0) {
            const implied = new Set(effectiveFontOptions(this.fontFacesValue, {
                designedFamily: activeObject.fontFamily || '',
                richText: activeObject.richText === true,
                allowedFonts: [],
            }).map((face) => face.family));
            const kept = activeObject.allowedFonts.filter((family) => !implied.has(family));
            if (kept.length !== activeObject.allowedFonts.length) {
                activeObject.allowedFonts = kept;
                this.canvasEditorOutlet.markUnsaved();
            }
        }
        this._syncFontChoice(activeObject);
    }

    // "Přidat text" modal: the same checklist against the modal's own font
    // select (there is no textbox yet — canvas-editor#submitAddText reads the
    // picks straight from the list).
    _resetAddTextFontChoice() {
        const toggle = document.getElementById('addTextFontChoice');
        const list = document.getElementById('addTextFontChoiceList');
        if (toggle) toggle.checked = false;
        if (list) {
            list.classList.add('d-none');
            list.textContent = '';
        }
    }

    toggleAddTextFontChoice(event) {
        const list = document.getElementById('addTextFontChoiceList');
        if (!list) return;
        list.classList.toggle('d-none', !event.target.checked);
        if (event.target.checked) this.refreshAddTextFontChoice();
    }

    refreshAddTextFontChoice() {
        const toggle = document.getElementById('addTextFontChoice');
        const list = document.getElementById('addTextFontChoiceList');
        const select = document.getElementById('addTextFont');
        if (!toggle || !toggle.checked || !list) return;
        // Keep the picks across a font change; the newly designed face is
        // implied and drops out of the pick on its own.
        const previous = collectAllowedFonts(list);
        renderFontChoiceList(list, planFontChoice(this.fontFacesValue, {
            designedFamily: select ? select.value : '',
            richText: false,
            allowedFonts: previous,
        }));
    }

    /** Checklist COMPONENT inputs: show the capability toggles, hide the
     *  rich/lists enable-toggles (forced true for the component — unchecking
     *  them would silently break its rendering). */
    _syncChecklistControls(activeObject) {
        const isChecklist = activeObject.checklist === true;
        if (this.hasChecklistSectionTarget) {
            this.checklistSectionTarget.classList.toggle('d-none', !isChecklist);
        }
        if (this.hasChecklistToggleTarget) this.checklistToggleTarget.checked = activeObject.checklistToggle !== false;
        if (this.hasChecklistEditTextTarget) this.checklistEditTextTarget.checked = activeObject.checklistEditText !== false;
        if (this.hasChecklistAddTarget) this.checklistAddTarget.checked = activeObject.checklistAdd !== false;
        if (this.hasChecklistRemoveTarget) this.checklistRemoveTarget.checked = activeObject.checklistRemove !== false;

        const richWrapper = this.element.querySelector('[data-richtext-wrapper]');
        if (richWrapper) richWrapper.classList.toggle('d-none', isChecklist);
        const checkboxesWrapper = this.element.querySelector('[data-checkboxes-wrapper]');
        if (checkboxesWrapper) checkboxesWrapper.classList.toggle('d-none', isChecklist);
        if (isChecklist) {
            const listsWrapper = this.hasListsTarget ? this.listsTarget.closest('[data-lists-wrapper]') : null;
            if (listsWrapper) listsWrapper.classList.add('d-none');
        }
    }

    updateChecklistFlag(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        const prop = event.params && event.params.prop;
        if (!['checklistToggle', 'checklistEditText', 'checklistAdd', 'checklistRemove'].includes(prop)) return;
        activeObject[prop] = event.target.checked;
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Populate + show/hide the list config (only for rich inputs; the
     *  spacing fields show the stored explicit values — blank = derived
     *  default, see ResolvedListStyle). */
    _syncListControls(activeObject) {
        if (this.hasListsTarget) {
            this.listsTarget.checked = activeObject.lists || false;
            this.listsTarget.disabled = !(activeObject.richText || false);
            const wrapper = this.listsTarget.closest('[data-lists-wrapper]');
            if (wrapper) wrapper.classList.toggle('d-none', !(activeObject.richText || false));
        }
        if (this.hasListConfigTarget) {
            this.listConfigTarget.classList.toggle('d-none', !(activeObject.richText && activeObject.lists));
        }
        if (this.hasListBulletTarget) {
            this.listBulletTarget.value = activeObject.listBullet || 'disc';
        }
        this._syncBulletPreview(activeObject);
        if (this.hasListIndentTarget) this.listIndentTarget.value = this._spacingDisplay(activeObject.listIndent);
        if (this.hasListItemSpacingTarget) this.listItemSpacingTarget.value = this._spacingDisplay(activeObject.listItemSpacing);
        if (this.hasListBlockSpacingTarget) this.listBlockSpacingTarget.value = this._spacingDisplay(activeObject.listBlockSpacing);
        if (this.hasListCheckboxesTarget) {
            this.listCheckboxesTarget.checked = activeObject.listCheckboxes || false;
        }
        if (this.hasCheckboxConfigTarget) {
            this.checkboxConfigTarget.classList.toggle('d-none', !(activeObject.richText && activeObject.lists && activeObject.listCheckboxes));
        }
        this._syncCheckboxPreviews(activeObject);
    }

    /** Per-state checkbox image chips: the green check + clear button show
     *  only while a custom image is picked (otherwise the default drawn
     *  checkbox applies and there is nothing to clear). */
    _syncCheckboxPreviews(activeObject) {
        const sync = (path, previewTarget, clearTarget) => {
            if (previewTarget) {
                previewTarget.classList.toggle('d-none', !path);
                previewTarget.title = path || '';
            }
            if (clearTarget) {
                clearTarget.classList.toggle('d-none', !path);
            }
        };
        sync(
            activeObject.listCheckboxImage || null,
            this.hasCheckboxImagePreviewTarget ? this.checkboxImagePreviewTarget : null,
            this.hasCheckboxImageClearTarget ? this.checkboxImageClearTarget : null,
        );
        sync(
            activeObject.listCheckboxCheckedImage || null,
            this.hasCheckboxCheckedImagePreviewTarget ? this.checkboxCheckedImagePreviewTarget : null,
            this.hasCheckboxCheckedImageClearTarget ? this.checkboxCheckedImageClearTarget : null,
        );
    }

    _syncBulletPreview(activeObject) {
        const isImage = (activeObject.listBullet || 'disc') === 'image';
        if (this.hasListBulletPickTarget) {
            this.listBulletPickTarget.classList.toggle('d-none', !isImage);
        }
        if (this.hasListBulletPreviewTarget) {
            const path = activeObject.listBulletImage || null;
            this.listBulletPreviewTarget.classList.toggle('d-none', !isImage || !path);
            this.listBulletPreviewTarget.title = path || '';
        }
    }

    _spacingDisplay(value) {
        return typeof value === 'number' && isFinite(value) ? String(value) : '';
    }

    updateLists(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.lists = event.target.checked;
        this._syncListControls(activeObject);
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateListBullet(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.listBullet = event.target.value || null;
        this._syncBulletPreview(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Blank input = derived default (null); anything non-negative sticks. */
    updateListSpacing(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        const prop = event.params && event.params.prop;
        if (!['listIndent', 'listItemSpacing', 'listBlockSpacing'].includes(prop)) return;
        const raw = event.target.value.trim();
        const parsed = raw === '' ? null : Number.parseFloat(raw.replace(',', '.'));
        activeObject[prop] = parsed !== null && isFinite(parsed) && parsed >= 0 ? parsed : null;
        this.canvasEditorOutlet.markUnsaved();
    }

    updateListCheckboxes(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.listCheckboxes = event.target.checked;
        this._syncListControls(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Open the shared gallery modal to pick a checkbox STATE image; the
     *  state param ('unchecked'|'checked') is remembered until the pick
     *  comes back via canvas-editor:checkbox-image. */
    pickCheckboxImage(event) {
        if (!this.hasCanvasEditorOutlet) return;
        this._checkboxPickState = event.params && event.params.state === 'checked' ? 'checked' : 'unchecked';
        this.canvasEditorOutlet.galleryMode = 'checkboxImage';
        const modal = new bootstrap.Modal('#imageGalleryModal');
        modal.show();
    }

    onCheckboxImageSelected(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        const { path } = event.detail || {};
        if (!path) return;
        const prop = this._checkboxPickState === 'checked' ? 'listCheckboxCheckedImage' : 'listCheckboxImage';
        activeObject[prop] = path;
        this._syncCheckboxPreviews(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Back to the default drawn checkbox for one state. */
    clearCheckboxImage(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        const state = event.params && event.params.state === 'checked' ? 'checked' : 'unchecked';
        activeObject[state === 'checked' ? 'listCheckboxCheckedImage' : 'listCheckboxImage'] = null;
        this._syncCheckboxPreviews(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    /** Open the shared gallery modal in bulletImage mode; the pick comes back
     *  via the canvas-editor:bullet-image event handled below. */
    pickBulletImage() {
        if (!this.hasCanvasEditorOutlet) return;
        this.canvasEditorOutlet.galleryMode = 'bulletImage';
        const modal = new bootstrap.Modal('#imageGalleryModal');
        modal.show();
    }

    onBulletImageSelected(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        const { path } = event.detail || {};
        if (!path) return;
        activeObject.listBullet = 'image';
        activeObject.listBulletImage = path;
        this._syncListControls(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    // --- Vzorový text (sample value) ---------------------------------------

    /**
     * Open the sample modal. Rich inputs get a FRESH fill-page WYSIWYG: the
     * <template> skeleton is cloned, the active textbox's values are stamped
     * on the clone (Stimulus connects it on insert), and the editor writes
     * its wire value into the [data-sample-mirror] hidden input — the exact
     * envelope/plain format the render pipeline consumes. Plain inputs edit
     * a simple textarea instead.
     *
     * CHECKLIST components take the same modal but the fill page's per-item
     * editor ("Položky seznamu"): items ARE the sample for them, so a raw
     * WYSIWYG over the envelope would be a needlessly sharp tool. The admin
     * always gets every capability here — the four flags gate the USER, not
     * the designer authoring the defaults.
     */
    openSampleModal() {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        this._sampleObject = activeObject;
        const stored = typeof activeObject.sampleValue === 'string' ? activeObject.sampleValue : '';
        const checklist = activeObject.checklist === true && this.hasChecklistTemplateTarget;
        const rich = !checklist && activeObject.richText === true && this.hasSampleTemplateTarget;

        if (this.hasSampleHostTarget) this.sampleHostTarget.textContent = '';
        if (this.hasSamplePlainTarget) {
            this.samplePlainTarget.classList.toggle('d-none', rich || checklist);
            this.samplePlainTarget.value = rich || checklist ? '' : stored;
        }
        this._syncSampleChrome(activeObject);

        if (checklist) {
            const clone = this.checklistTemplateTarget.content.firstElementChild.cloneNode(true);
            const items = checklistItems(activeObject);
            clone.dataset.checklistEditorInputIdValue = activeObject.inputId || '';
            clone.dataset.checklistEditorItemsValue = JSON.stringify(items);
            clone.dataset.checklistEditorMaxLengthValue = String(activeObject.maxLength || 0);
            const mirror = clone.querySelector('[data-sample-mirror]');
            if (mirror) {
                mirror.setAttribute('data-text-mirror', activeObject.inputId || '');
                // Seeded from the RECONCILED items, not the stored value: a
                // checklist that diverged before the sync existed then heals
                // on a plain open + Uložit, with no edit needed.
                mirror.value = this._checklistValue(items);
            }
            this.sampleHostTarget.appendChild(clone);
        }

        if (rich) {
            const seed = this._parseSampleSeed(stored);
            const clone = this.sampleTemplateTarget.content.firstElementChild.cloneNode(true);
            clone.dataset.richTextEditorInputIdValue = activeObject.inputId || '';
            clone.dataset.richTextEditorMaxLengthValue = String(activeObject.maxLength || 0);
            clone.dataset.richTextEditorUppercaseValue = activeObject.uppercase ? 'true' : 'false';
            clone.dataset.richTextEditorListsValue = activeObject.lists ? 'true' : 'false';
            clone.dataset.richTextEditorCheckboxesValue = activeObject.lists && activeObject.listCheckboxes ? 'true' : 'false';
            clone.dataset.richTextEditorRunsValue = JSON.stringify(seed.runs);
            clone.dataset.richTextEditorLinesValue = JSON.stringify(seed.lines);
            clone.dataset.richTextEditorDesignFontValue = activeObject.fontFamily || '';
            // The face menu is THIS input's offer (designed family + picks),
            // exactly what the fill page will show — not every project face.
            const inputFonts = effectiveFontOptions(this.fontFacesValue, {
                designedFamily: activeObject.fontFamily || '',
                richText: true,
                allowedFonts: Array.isArray(activeObject.allowedFonts) ? activeObject.allowedFonts : [],
            });
            clone.dataset.richTextEditorFontsValue = JSON.stringify(inputFonts);
            buildFontOptgroups(clone.querySelector('[data-rich-text-editor-target="fontSelect"]'), inputFonts, { defaultLabel: 'Výchozí písmo' });
            const listButtons = clone.querySelector('[data-sample-lists]');
            if (listButtons) listButtons.classList.toggle('d-none', !activeObject.lists);
            const checkboxButton = clone.querySelector('[data-sample-checkboxes]');
            if (checkboxButton) checkboxButton.classList.toggle('d-none', !(activeObject.lists && activeObject.listCheckboxes));
            const mirror = clone.querySelector('[data-sample-mirror]');
            if (mirror) {
                mirror.setAttribute('data-text-mirror', activeObject.inputId || '');
                mirror.value = stored;
            }
            this.sampleHostTarget.appendChild(clone);
        }

        const modal = new bootstrap.Modal('#sampleTextModal');
        modal.show();
    }

    _parseSampleSeed(stored) {
        if (stored.trim().startsWith('{')) {
            try {
                const decoded = JSON.parse(stored);
                if (decoded && Array.isArray(decoded.runs)) {
                    return { runs: decoded.runs, lines: Array.isArray(decoded.lines) ? decoded.lines : [] };
                }
            } catch (err) {
                // Fall through — treat as plain text.
            }
        }
        return { runs: stored === '' ? [] : [{ text: stored }], lines: [] };
    }

    saveSample() {
        const obj = this._sampleObject || this._getActiveTextbox();
        if (!obj) return;
        const mirror = this.hasSampleHostTarget ? this.sampleHostTarget.querySelector('[data-sample-mirror]') : null;

        // A checklist writes BOTH faces — the items are the canvas text and
        // the sample at once — and announces the change so container reflow
        // and the group editor treat it like typing.
        if (obj.checklist === true && mirror) {
            applyChecklistItems(obj, itemsFromValue(mirror.value));
            this._syncSampleChrome(obj);
            if (this.hasCanvasEditorOutlet) {
                this.canvasEditorOutlet.canvas.fire('text:changed', { target: obj });
                this.canvasEditorOutlet.canvas.renderAll();
                this.canvasEditorOutlet.markUnsaved();
            }
            this._hideSampleModal();

            return;
        }

        let value;
        if (obj.richText === true && mirror) {
            value = mirror.value;
        } else {
            value = this.hasSamplePlainTarget ? this.samplePlainTarget.value : '';
        }
        obj.sampleValue = value.trim() === '' ? null : value;

        // The sample is what the export renders (absent a user override) —
        // the canvas stand-in follows it, or the editor shows one text and
        // the PNG another. Clearing the sample keeps the designed text,
        // which is then again exactly what renders. text:changed makes
        // container reflow + group propagation treat it like typing (and
        // syncTextSample sees equal texts, so styling survives untouched).
        if (obj.sampleValue !== null && applySampleToCanvasText(obj) && this.hasCanvasEditorOutlet) {
            this.canvasEditorOutlet.canvas.fire('text:changed', { target: obj });
            this.canvasEditorOutlet.canvas.renderAll();
        }

        this._syncSampleChrome(obj);
        if (this.hasCanvasEditorOutlet) this.canvasEditorOutlet.markUnsaved();
        this._hideSampleModal();
    }

    clearSample() {
        const obj = this._sampleObject || this._getActiveTextbox();
        if (!obj) return;
        obj.sampleValue = null;
        this._syncSampleChrome(obj);
        if (this.hasCanvasEditorOutlet) this.canvasEditorOutlet.markUnsaved();
        this._hideSampleModal();
    }

    /** The wire value for a list of items — mirrors checklist_editor's own
     *  `_sync`, so the seeded mirror and an edited one are the same shape. */
    _checklistValue(items) {
        if (items.length === 0) return '';

        return JSON.stringify({
            runs: [{
                text: items.map((item) => item.text).join('\n'),
                fontFamily: null,
                color: null,
                underline: false,
            }],
            lines: items.map((item) => (item.checked ? 'cbx' : 'cb')),
        });
    }

    _hideSampleModal() {
        const modalElement = document.getElementById('sampleTextModal');
        const modal = modalElement ? bootstrap.Modal.getInstance(modalElement) : null;
        if (modal) modal.hide();
    }

    /**
     * The sample button + modal wear two hats. For an ordinary input they are
     * "Vzorový text" — an optional default, hence the "nastaven" badge and the
     * clear button. For a CHECKLIST they are "Položky seznamu": the items are
     * the component's content, so an empty sample is not a state worth
     * offering (clearing one would export the stand-in text as plain
     * paragraphs, checkboxes gone).
     */
    _syncSampleChrome(activeObject) {
        const checklist = activeObject.checklist === true;

        if (this.hasSampleBadgeTarget) {
            const hasSample = typeof activeObject.sampleValue === 'string' && activeObject.sampleValue !== '';
            this.sampleBadgeTarget.classList.toggle('d-none', checklist || !hasSample);
        }
        if (this.hasSampleLabelTarget) {
            this.sampleLabelTarget.textContent = checklist ? 'Položky seznamu' : 'Vzorový text';
        }
        if (this.hasSampleIconTarget) {
            this.sampleIconTarget.classList.toggle('mdi-text-box-outline', !checklist);
            this.sampleIconTarget.classList.toggle('mdi-format-list-checks', checklist);
        }
        if (this.hasSampleTitleTarget) {
            this.sampleTitleTarget.textContent = checklist ? 'Položky seznamu' : 'Vzorový text';
        }
        if (this.hasSampleHintTarget) {
            this.sampleHintTarget.textContent = checklist
                ? 'Výchozí položky seznamu. Zobrazí se v náhledu i exportu, dokud je uživatel nezmění — a text položek se propíše i na plátno.'
                : 'Zobrazí se v náhledu i exportu, dokud uživatel pole nevyplní. Podporuje vše co vyplňování — u formátovatelných polí včetně stylů a seznamů.';
        }
        if (this.hasSampleClearTarget) {
            this.sampleClearTarget.classList.toggle('d-none', checklist);
        }
    }

    updateLocked(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.locked = event.target.checked;
        if (this.hasRichTextTarget) this.richTextTarget.disabled = activeObject.locked;
        if (this.hasFontChoiceTarget) this.fontChoiceTarget.disabled = activeObject.locked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    /**
     * Inline lock toggle from the floating mini-toolbar. Flips locked, mirrors
     * the change onto the popover checkbox so both stay in sync.
     */
    toggleLocked() {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.locked = !activeObject.locked;
        if (this.hasLockedTarget) this.lockedTarget.checked = activeObject.locked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateHidable(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.hidable = event.target.checked;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateRichText(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.richText = event.target.checked;
        this.refreshFontChoice();
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateName(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.name = event.target.value;

        // Seeded state: the canvas text is still just the (old) name — keep
        // it following the rename. An empty mid-typing value passes through
        // (the element visibly empties, and the link self-heals: '' === '').
        if (this._nameTextLinked) {
            const canvas = this.canvasEditorOutlet.canvas;
            activeObject.set('text', activeObject.uppercase
                ? event.target.value.toUpperCase()
                : event.target.value);
            if (typeof activeObject.initDimensions === 'function') {
                activeObject.initDimensions();
            }
            activeObject.setCoords();
            // Synthetic announce so container reflow and the group editor
            // treat this like typing (our programmatic set fires nothing).
            this._syncingText = true;
            canvas.fire('text:changed', { target: activeObject });
            this._syncingText = false;
        }

        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    _textEqualsName(textbox) {
        const name = textbox.name || '';
        const text = textbox.text || '';
        return text === name || (textbox.uppercase === true && text === name.toUpperCase());
    }

    updateDescription(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.description = event.target.value;
        this.canvasEditorOutlet.canvas.renderAll();
        this.canvasEditorOutlet.markUnsaved();
    }

    updateUppercase(event) {
        const activeObject = this._getActiveTextbox();
        if (!activeObject) return;
        activeObject.uppercase = event.target.checked;
        this._applyUppercase(activeObject);
        this.canvasEditorOutlet.markUnsaved();
    }

    _applyUppercase(textbox) {
        if (textbox.uppercase) {
            textbox.text = textbox.text.toUpperCase();
        }
        this.canvasEditorOutlet.canvas.renderAll();
    }

    _getActiveTextbox() {
        if (!this.hasCanvasEditorOutlet) return null;
        const activeObject = this.canvasEditorOutlet.canvas.getActiveObject();
        if (!activeObject || (activeObject.type || '').toLowerCase() !== 'textbox') return null;
        return activeObject;
    }
}
