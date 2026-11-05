import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import { withMoveCursor } from './sort_drag_cursor.js';
import { cmykFromRgb, hexToRgb, isLightRgb } from './palette_color_math.js';

/**
 * Paleta barev (F7 P2) — autosave of the colour cards.
 *
 *   card      one `.pal-card` (data-color-id, data-url = its edit endpoint,
 *             absent for read-only members)
 *   grid      a group's card grid ("Z logotypu" / "Přidané barvy"); drag
 *             reorder inside it (SortableJS, forceFallback — see
 *             dragula_controller.js why), posted to `sortUrl`
 *   strip     the palette strip preview (segments by data-color-id)
 *   cmykNote / cmykCount   the "N barvám chybí CMYK pro tisk" note
 *   manualList one segment ("Z logotypu" / "Další barvy") of a manual in the
 *             "Barvy v manuálech" box (F7 P3); drag reorder inside it, or
 *             the row menu's Posunout výš / níž, posted to its data-sort-url
 *             (absent for read-only members); the number badges of that
 *             manual (= `color(n)`) are renumbered after a move
 *
 * The "Sloučit" dialogs (F7 P4) are plain forms; `mergeChoice` only swaps
 * the server-rendered preview of the colour picked to stay.
 *
 * Every change posts ONE field (`field` + `value`, CMYK as `cmyk[]`) with
 * the CSRF token and answers the stored colour; requests are queued one at a
 * time so quick edits never race. Success = toast "Uloženo", failure = toast
 * with the server's message and the field marked `.is-invalid`.
 */
export default class extends Controller {
    static targets = ['card', 'grid', 'strip', 'cmykNote', 'cmykCount', 'manualList'];
    static values = { sortUrl: String, csrf: String };

    connect() {
        this.queue = Promise.resolve();
        this.sortables = [];

        if (this.hasSortUrlValue && this.sortUrlValue) {
            this.gridTargets.forEach((grid) => {
                this.sortables.push(Sortable.create(grid, withMoveCursor({
                    handle: '.pal-grip',
                    forceFallback: true,
                    ghostClass: 'gu-transit',
                    dragClass: 'gu-mirror',
                    onEnd: (event) => {
                        if (event.oldIndex !== event.newIndex) this.sendOrder(grid);
                    },
                })));
            });
        }

        this.manualListTargets.forEach((list) => {
            if (!list.dataset.sortUrl) return;
            this.sortables.push(Sortable.create(list, withMoveCursor({
                handle: '.pal-grip',
                forceFallback: true,
                ghostClass: 'gu-transit',
                dragClass: 'gu-mirror',
                onEnd: (event) => {
                    if (event.oldIndex !== event.newIndex) this.sendManualOrder(list);
                },
            })));
        });

        this.element.querySelectorAll('[data-pick-submit]').forEach((button) => { button.disabled = true; });

        const flashed = this.cardTargets.find((card) => card.classList.contains('flash'));
        if (flashed) {
            flashed.scrollIntoView({ block: 'center' });
            flashed.querySelector('[data-field="hex"]')?.select();
        }
    }

    disconnect() {
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];
    }

    // ---- fields ---------------------------------------------------------

    blurOnEnter(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.target.blur();
        }
    }

    previewHex(event) {
        const card = event.target.closest('.pal-card');
        const rgb = hexToRgb(event.target.value);
        event.target.classList.remove('is-invalid');
        if (rgb) this.paintCard(card, event.target.value.trim().replace(/^#/, ''), rgb);
    }

    save(event) {
        const field = event.target;
        const card = field.closest('.pal-card');
        const body = new FormData();
        body.append('field', field.dataset.field);
        body.append('value', field.value);
        this.post(card, body, field);
    }

    saveCmyk(event) {
        const card = event.target.closest('.pal-card');
        this.postCmyk(card, this.cmykFields(card).map((input) => input.value), event.target);
    }

    calcCmyk(event) {
        event.preventDefault();
        const card = event.target.closest('.pal-card');
        const rgb = hexToRgb(card.dataset.hex);
        if (!rgb) return;
        const cmyk = cmykFromRgb(rgb);
        this.cmykFields(card).forEach((input, index) => { input.value = String(cmyk[index]); });
        this.postCmyk(card, cmyk.map(String), null, 'CMYK dopočítáno — pro tisk ho ověřte');
    }

    postCmyk(card, values, field, message) {
        const body = new FormData();
        body.append('field', 'cmyk');
        values.forEach((value) => body.append('cmyk[]', value));
        this.post(card, body, field, message);
    }

    post(card, body, field, message = 'Uloženo') {
        body.append('_token', this.csrfValue);
        const url = card.dataset.url;
        if (!url) return;

        this.queue = this.queue.then(async () => {
            let payload = null;
            try {
                const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
                payload = await response.json().catch(() => null);
                if (!response.ok) {
                    throw new Error(payload?.error ?? (response.status === 403 ? 'Na tuto změnu nemáte oprávnění.' : 'Změnu se nepodařilo uložit. Zkuste to znovu.'));
                }
            } catch (error) {
                field?.classList.add('is-invalid');
                this.toast(error instanceof TypeError ? 'Změnu se nepodařilo uložit — zkontrolujte připojení.' : error.message, true);
                return;
            }

            field?.classList.remove('is-invalid');
            this.applyColor(card, payload.color);
            this.toast(message);
        });
    }

    applyColor(card, color) {
        card.dataset.hex = color.hex;
        const hexInput = card.querySelector('[data-field="hex"]');
        if (hexInput && document.activeElement !== hexInput) hexInput.value = `#${color.hex.toUpperCase()}`;
        if (color.rgb) this.paintCard(card, color.hex, color.rgb);

        const missing = color.cmyk === null;
        card.classList.toggle('is-cmyk-missing', missing);
        this.updateCmykNote();

        const segment = this.stripSegment(card.dataset.colorId);
        if (segment) {
            const role = color.role === 'primary' ? ' · Primární' : (color.role === 'secondary' ? ' · Sekundární' : '');
            segment.title = `#${color.hex.toUpperCase()}${role}`;
        }
    }

    paintCard(card, hex, rgb) {
        const swatch = card.querySelector('.pal-sw');
        swatch.style.background = `#${hex}`;
        swatch.classList.toggle('is-light', isLightRgb(rgb));
        swatch.title = `Kopírovat #${hex.toUpperCase()}`;
        const caption = card.querySelector('[data-rgb]');
        if (caption) caption.textContent = `RGB ${rgb.join(', ')}`;
        const segment = this.stripSegment(card.dataset.colorId);
        if (segment) {
            segment.style.background = `#${hex}`;
            segment.classList.toggle('is-light', isLightRgb(rgb));
            const label = segment.querySelector('b');
            if (label) label.textContent = `#${hex.toUpperCase()}`;
        }
    }

    updateCmykNote() {
        if (!this.hasCmykNoteTarget) return;
        const count = this.cardTargets.filter((card) => card.classList.contains('is-cmyk-missing')).length;
        this.cmykNoteTarget.hidden = count === 0;
        if (this.hasCmykCountTarget) this.cmykCountTarget.textContent = count === 1 ? '1 barvě chybí' : `${count} barvám chybí`;
    }

    cmykFields(card) {
        return Array.from(card.querySelectorAll('[data-cmyk]'));
    }

    // ---- swatch / menu --------------------------------------------------

    async copy(event) {
        event.preventDefault();
        const card = event.target.closest('.pal-card');
        const hex = `#${card.dataset.hex.toUpperCase()}`;
        try {
            await navigator.clipboard.writeText(hex);
            this.toast(`${hex} zkopírováno`);
        } catch (error) {
            this.toast(hex);
        }
    }

    moveUp(event) {
        this.move(event, -1);
    }

    moveDown(event) {
        this.move(event, 1);
    }

    move(event, step) {
        event.preventDefault();
        const card = event.target.closest('.pal-card');
        const grid = card.parentElement;
        const cards = Array.from(grid.querySelectorAll(':scope > .pal-card'));
        const target = cards[cards.indexOf(card) + step];
        if (!target) {
            this.toast(step < 0 ? 'Barva už je první ve skupině' : 'Barva už je poslední ve skupině');
            return;
        }
        if (step < 0) grid.insertBefore(card, target); else grid.insertBefore(target, card);
        this.sendOrder(grid);
    }

    sendOrder(grid) {
        const sorted = Array.from(grid.querySelectorAll(':scope > .pal-card')).map((card) => card.dataset.colorId);

        this.queue = this.queue.then(async () => {
            try {
                const response = await fetch(this.sortUrlValue, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ sorted, _token: this.csrfValue }),
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload) throw new Error();
                this.sortStrip(payload.order);
                this.toast('Pořadí uloženo');
            } catch (error) {
                this.toast('Pořadí se nepodařilo uložit. Zkuste to znovu.', true);
            }
        });
    }

    sortStrip(order) {
        if (!this.hasStripTarget) return;
        order.forEach((id) => {
            const segment = this.stripSegment(id);
            if (segment) this.stripTarget.appendChild(segment);
        });
    }

    stripSegment(id) {
        return this.hasStripTarget ? this.stripTarget.querySelector(`[data-color-id="${id}"]`) : null;
    }

    // ---- "Barvy v manuálech" (F7 P3) -----------------------------------

    moveColorUp(event) {
        this.moveColor(event, -1);
    }

    moveColorDown(event) {
        this.moveColor(event, 1);
    }

    moveColor(event, step) {
        event.preventDefault();
        const row = event.target.closest('.pal-mrow');
        const list = row.parentElement;
        const rows = Array.from(list.querySelectorAll(':scope > .pal-mrow'));
        const target = rows[rows.indexOf(row) + step];
        if (!target) {
            this.toast(step < 0 ? 'Barva už je v této skupině první' : 'Barva už je v této skupině poslední');
            return;
        }
        if (step < 0) list.insertBefore(row, target); else list.insertBefore(target, row);
        this.sendManualOrder(list);
    }

    sendManualOrder(list) {
        const sorted = Array.from(list.querySelectorAll(':scope > .pal-mrow')).map((row) => row.dataset.linkId);
        this.renumber(list.closest('.pal-man'));

        this.queue = this.queue.then(async () => {
            try {
                const response = await fetch(list.dataset.sortUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ sorted, _token: this.csrfValue }),
                });
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload) throw new Error();
                this.toast('Pořadí barev manuálu uloženo');
            } catch (error) {
                this.toast('Pořadí se nepodařilo uložit. Zkuste to znovu.', true);
            }
        });
    }

    /** Badges 1…n across both segments of one manual (logo colours first). */
    renumber(section) {
        if (!section) return;
        section.querySelectorAll('.pal-mrow [data-manual-number]').forEach((badge, index) => {
            badge.textContent = String(index + 1);
            badge.title = `Barva ${index + 1} manuálu`;
            badge.classList.toggle('key', index < 3);
        });
    }

    /** "Přidat z palety": the submit button needs at least one colour ticked. */
    pickChanged(event) {
        const form = event.currentTarget;
        const button = form.querySelector('[data-pick-submit]');
        if (button) button.disabled = form.querySelector('input[name="colors[]"]:checked') === null;
    }

    // ---- "Sloučit" (F7 P4) ---------------------------------------------

    /** The merge dialog: show the preview of the colour picked to stay. */
    mergeChoice(event) {
        if (event.target.name !== 'survivor') return;
        const form = event.currentTarget;
        form.querySelectorAll('[data-merge-preview]').forEach((preview) => {
            preview.hidden = preview.dataset.mergePreview !== event.target.value;
        });
    }

    // ---- toast (kit .toasts / .toast-n, design-system §3.10) -------------

    toast(message, error = false) {
        let stack = document.querySelector('.toasts');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toasts';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        const toast = document.createElement('div');
        toast.className = error ? 'toast-n err' : 'toast-n';
        toast.setAttribute('role', error ? 'alert' : 'status');
        const icon = document.createElement('span');
        icon.className = `i ${error ? 'i-alert' : 'i-check-circle'} s18`;
        icon.setAttribute('aria-hidden', 'true');
        toast.append(icon, document.createTextNode(message));
        stack.appendChild(toast);
        setTimeout(() => toast.remove(), error ? 5000 : 2400);
    }
}
