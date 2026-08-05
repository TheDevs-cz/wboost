/*
 * Keeps a CHECKLIST component's two faces in lockstep.
 *
 * A checklist object (added via "Přidat zaškrtávací seznam") is a plain
 * textbox whose canvas `text` is only a stand-in: what the export actually
 * draws is its `sampleValue` envelope
 * (`{"runs":[{"text":"a\nb"}],"lines":["cb","cbx"]}`), because
 * ResolveTextOverrides falls back to the sample whenever the export gets no
 * value for that input. Two sources of truth for the same items — so editing
 * the text inline on the canvas used to change only `text`, the sample kept
 * the ORIGINAL items, and the export silently rendered them (a 3-item sample
 * under a 5-item canvas). The editor's checklist preview draws from
 * `_textLines`, which is exactly why the canvas looked right and the PNG
 * didn't.
 *
 * The rule here: the CANVAS TEXT owns the items, the sample owns the checked
 * states. Every item is one logical line; a line's `cb`/`cbx` flag is carried
 * over BY INDEX, lines the sample doesn't reach default to unchecked. That
 * makes the reconciliation idempotent (re-running it on a consistent object
 * is a no-op), so it can be run on every `text:changed`, on canvas load and
 * right after the item editor writes — no guard flags needed.
 */

/** Concatenated run text + line types of a stored envelope (null when the
 *  value is not one — a legacy plain sample, or no sample at all). */
function parseEnvelope(raw) {
    if (typeof raw !== 'string' || !raw.trim().startsWith('{')) return null;
    try {
        const decoded = JSON.parse(raw);
        if (!decoded || !Array.isArray(decoded.runs)) return null;
        return {
            text: decoded.runs
                .map((run) => (run && typeof run.text === 'string' ? run.text : ''))
                .join(''),
            lines: Array.isArray(decoded.lines) ? decoded.lines : [],
        };
    } catch (err) {
        return null;
    }
}

/** The wire value for a list of `{text, checked}` items — the exact envelope
 *  the fill page's checklist editor writes, so admin and user defaults are
 *  byte-comparable. An empty list stores NO sample (nothing to render). */
function envelopeFor(items) {
    if (items.length === 0) return null;

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

/**
 * The object's items as the editor should show them: texts from the CANVAS
 * (what the designer last typed and sees), checked states from the sample by
 * line index. On a consistent object both agree; on a legacy divergent one
 * this is the healing read.
 *
 * @returns {Array<{text: string, checked: boolean}>}
 */
export function checklistItems(textbox) {
    if (!textbox) return [];

    const text = typeof textbox.text === 'string' ? textbox.text : '';
    if (text === '') return [];

    const envelope = parseEnvelope(textbox.sampleValue);
    const lines = envelope ? envelope.lines : [];

    return text.split('\n').map((line, index) => ({
        text: line,
        checked: lines[index] === 'cbx',
    }));
}

/**
 * Re-derive the sample from the canvas text. Safe to call for any object —
 * non-checklist ones are ignored.
 *
 * @returns {boolean} whether the stored value actually changed.
 */
export function syncChecklistSample(textbox) {
    if (!textbox || textbox.checklist !== true) return false;

    const next = envelopeFor(checklistItems(textbox));
    const current = typeof textbox.sampleValue === 'string' ? textbox.sampleValue : null;
    if (current === next) return false;

    textbox.sampleValue = next;

    return true;
}

/** Sweep the whole canvas (canvas load / undo restore). */
export function sweepChecklistSamples(canvas) {
    if (!canvas) return;
    canvas.getObjects().forEach((obj) => syncChecklistSample(obj));
}

/**
 * Write an item list back onto the object — the item editor's save path.
 * Sets BOTH faces from the same list, so the canvas, the preview and the
 * export agree immediately. Uppercase is applied exactly as an inline edit
 * would (the render uppercases per run again — idempotent).
 */
export function applyChecklistItems(textbox, items) {
    if (!textbox) return;

    const text = items.map((item) => item.text).join('\n');
    const displayed = textbox.uppercase === true ? text.toUpperCase() : text;

    textbox.set('text', displayed);
    if (typeof textbox.initDimensions === 'function') {
        textbox.initDimensions();
    }
    textbox.setCoords();

    textbox.sampleValue = envelopeFor(items.map((item) => ({
        text: textbox.uppercase === true ? item.text.toUpperCase() : item.text,
        checked: item.checked,
    })));

    textbox.dirty = true;
}

/** Items encoded in a wire value written by a checklist editor (the modal's
 *  hidden mirror). An empty string means "no items". */
export function itemsFromValue(value) {
    const envelope = parseEnvelope(value);
    if (envelope === null || envelope.text === '') return [];

    return envelope.text.split('\n').map((line, index) => ({
        text: line,
        checked: envelope.lines[index] === 'cbx',
    }));
}
