/**
 * "Uživatel může přepínat písmo" — the admin's per-input font choice.
 *
 * The pure half (`planFontChoice`, `effectiveFontOptions`,
 * `designedFontOf`) mirrors ResolveRichTextOptions::computeInputFonts so the
 * checklist the designer sees and the offer the fill page renders agree: the
 * DESIGNED font is always offered and therefore shown locked-checked — a rich
 * input's whole family (bold / italic are separate faces the WYSIWYG's B/I
 * buttons switch between), a plain input's exact face — and `allowedFonts`
 * adds faces on top. The DOM half renders that plan as a grouped checklist
 * (font name header with a tri-state group checkbox, faces beneath, each
 * previewed in its own face) and reads the picks back.
 *
 * `faces` everywhere = the editor page's `rich_toolbar.fonts` (EVERY project
 * face: `{ family, fontName, faceName, weight, style, url }`).
 */

/**
 * Which project font the canvas `fontFamily` names: the exact face when the
 * string is a "<Font> (<Face>)" family, the font's faces when it is a bare
 * font name (nothing says which face), nothing for a non-project font.
 *
 * @returns {{ face: object|null, fontName: string|null }}
 */
export function designedFontOf(faces, designedFamily) {
    if (typeof designedFamily !== 'string' || designedFamily === '') {
        return { face: null, fontName: null };
    }
    const face = faces.find((option) => option.family === designedFamily) || null;
    if (face) return { face, fontName: face.fontName };
    const bare = faces.find((option) => option.fontName === designedFamily) || null;
    return { face: null, fontName: bare ? bare.fontName : null };
}

/**
 * Is this face part of the always-offered designed set for the input?
 */
function isLockedFace(face, designed, richText) {
    if (designed.face) {
        return richText ? face.fontName === designed.fontName : face.family === designed.face.family;
    }
    return designed.fontName !== null && face.fontName === designed.fontName;
}

/**
 * The checklist plan: every project face grouped by font, with `locked`
 * (designed — always offered) and `checked` (locked or picked) per row.
 *
 * @returns {{ groups: Array<{ name: string, faces: Array<{ family, faceName, checked, locked }> }>, extras: number }}
 */
export function planFontChoice(faces, { designedFamily, richText = false, allowedFonts = [] }) {
    const designed = designedFontOf(faces, designedFamily);
    const picked = new Set(Array.isArray(allowedFonts) ? allowedFonts : []);
    const groups = [];
    const byName = new Map();
    let extras = 0;

    faces.forEach((face) => {
        const locked = isLockedFace(face, designed, richText);
        const checked = locked || picked.has(face.family);
        if (checked && !locked) extras += 1;
        let group = byName.get(face.fontName);
        if (!group) {
            group = { name: face.fontName, faces: [] };
            byName.set(face.fontName, group);
            groups.push(group);
        }
        group.faces.push({ family: face.family, faceName: face.faceName, checked, locked });
    });

    return { groups, extras };
}

/**
 * The faces the FILL surfaces will offer for this input — the JS twin of
 * ResolveRichTextOptions::computeInputFonts (designed font first, then the
 * picks in project order; a rich input with nothing resolvable falls back
 * to every project face so the WYSIWYG's face buttons keep a target).
 */
export function effectiveFontOptions(faces, { designedFamily, richText = false, allowedFonts = [] }) {
    const designed = designedFontOf(faces, designedFamily);
    const picked = new Set(Array.isArray(allowedFonts) ? allowedFonts : []);
    const base = faces.filter((face) => isLockedFace(face, designed, richText));
    const extras = faces.filter((face) => picked.has(face.family) && !base.includes(face));
    const options = [...base, ...extras];
    if (options.length === 0 && richText) return faces.slice();
    return options;
}

/**
 * Render the plan into `container` (replacing its content). Face rows are
 * previewed in their own face; locked rows are checked + disabled and wear a
 * "výchozí" badge. Group headers carry a tri-state checkbox that toggles
 * every UNLOCKED face of the font. Changes bubble as a single `change` on
 * the container — read the picks with `collectAllowedFonts`.
 */
export function renderFontChoiceList(container, plan, { emptyText = 'V projektu nejsou nahraná žádná písma.' } = {}) {
    container.textContent = '';
    if (plan.groups.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'text-muted small';
        empty.textContent = emptyText;
        container.appendChild(empty);
        return;
    }

    plan.groups.forEach((group) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'font-choice__group';

        const header = document.createElement('label');
        header.className = 'font-choice__header';
        const groupBox = document.createElement('input');
        groupBox.type = 'checkbox';
        groupBox.className = 'form-check-input mt-0';
        groupBox.dataset.fontChoiceGroup = group.name;
        header.appendChild(groupBox);
        const title = document.createElement('span');
        title.textContent = group.name;
        header.appendChild(title);
        wrapper.appendChild(header);

        group.faces.forEach((face) => {
            const row = document.createElement('label');
            row.className = 'font-choice__face';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.className = 'form-check-input mt-0';
            box.value = face.family;
            box.checked = face.checked;
            box.disabled = face.locked;
            box.dataset.fontChoiceFace = face.family;
            row.appendChild(box);
            const name = document.createElement('span');
            name.className = 'font-choice__name';
            name.style.fontFamily = `"${face.family}"`;
            name.textContent = face.faceName;
            row.appendChild(name);
            if (face.locked) {
                const badge = document.createElement('span');
                badge.className = 'badge bg-light text-secondary font-choice__badge';
                badge.textContent = 'výchozí';
                badge.title = 'Písmo použité na plátně — v nabídce je vždy';
                row.appendChild(badge);
            }
            wrapper.appendChild(row);
        });

        container.appendChild(wrapper);
        syncGroupBox(wrapper);
    });

    if (!container.dataset.fontChoiceBound) {
        container.dataset.fontChoiceBound = '1';
        container.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) return;
            const wrapper = target.closest('.font-choice__group');
            if (!wrapper) return;
            if (target.dataset.fontChoiceGroup !== undefined) {
                wrapper.querySelectorAll('input[data-font-choice-face]:not(:disabled)').forEach((box) => {
                    box.checked = target.checked;
                });
            }
            syncGroupBox(wrapper);
        });
    }
}

/** Tri-state group checkbox: all / some / none of the font's faces checked. */
function syncGroupBox(wrapper) {
    const groupBox = wrapper.querySelector('input[data-font-choice-group]');
    if (!groupBox) return;
    const boxes = [...wrapper.querySelectorAll('input[data-font-choice-face]')];
    const checked = boxes.filter((box) => box.checked).length;
    groupBox.checked = boxes.length > 0 && checked === boxes.length;
    groupBox.indeterminate = checked > 0 && checked < boxes.length;
    // A font whose every face is locked (the designed family of a rich
    // input) has nothing to toggle.
    groupBox.disabled = boxes.length > 0 && boxes.every((box) => box.disabled);
}

/**
 * The picks: every checked, UNLOCKED face family in project order. Locked
 * (designed) faces are implied server-side and never persisted, so a later
 * font change on the canvas moves the "výchozí" row with it.
 */
export function collectAllowedFonts(container) {
    if (!container) return [];
    return [...container.querySelectorAll('input[data-font-choice-face]')]
        .filter((box) => box.checked && !box.disabled)
        .map((box) => box.value);
}

/**
 * Rebuild a font <select>'s <optgroup>s from a list of face options (the
 * fill-page / sample-modal menu shape). `defaultLabel` adds the "" option
 * first when given.
 */
export function buildFontOptgroups(select, options, { defaultLabel = null } = {}) {
    if (!select) return;
    const keep = select.value;
    select.textContent = '';
    if (defaultLabel !== null) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = defaultLabel;
        select.appendChild(option);
    }
    const groups = new Map();
    options.forEach((face) => {
        let group = groups.get(face.fontName);
        if (!group) {
            group = document.createElement('optgroup');
            group.label = face.fontName;
            groups.set(face.fontName, group);
            select.appendChild(group);
        }
        const option = document.createElement('option');
        option.value = face.family;
        option.textContent = face.faceName;
        option.style.fontFamily = `"${face.family}"`;
        group.appendChild(option);
    });
    if ([...select.options].some((option) => option.value === keep)) {
        select.value = keep;
    }
}
