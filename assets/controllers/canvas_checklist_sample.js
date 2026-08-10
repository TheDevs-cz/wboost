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

/** Concatenated run text + line types + raw runs of a stored envelope (null
 *  when the value is not one — a legacy plain sample, or no sample at all). */
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
            runs: decoded.runs,
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

/*
 * ---------------------------------------------------------------------------
 * The same lockstep, generalized to ORDINARY (non-checklist) text inputs
 * (2026-08-10). A sample ("Vzorový text") is what the export renders whenever
 * the input isn't overridden — so a canvas stand-in showing DIFFERENT text is
 * an editor preview that silently lies about the PNG (the prod "changed the
 * sample and the canvas didn't correspond" report). Two directions:
 *
 *   applySampleToCanvasText — modal-save: the stand-in follows the sample.
 *   syncTextSample          — inline canvas edit: the sample follows the text.
 *
 * Deliberately NOT swept on canvas load: legacy samples routinely differ from
 * the designed text (that was the old model), and a load sweep would silently
 * rewrite admin-authored samples en masse. Only explicit edits sync.
 */

/** Plain concatenated text of any stored sample value (envelope or plain). */
export function samplePlainText(raw) {
    if (typeof raw !== 'string') return '';
    const envelope = parseEnvelope(raw);
    return envelope ? envelope.text : raw;
}

/**
 * Modal-save direction: point the canvas stand-in at the sample's plain text
 * (uppercase applied for display, like an inline edit — the render uppercases
 * again, idempotent). Rich styling stays invisible on the stand-in — the
 * single-styled designed textbox has nowhere to put per-run styles; the fill
 * preview and the export are where they show.
 *
 * @returns {boolean} whether the canvas text actually changed.
 */
export function applySampleToCanvasText(textbox) {
    if (!textbox || textbox.checklist === true) return false;
    if (typeof textbox.text !== 'string') return false;

    const plain = samplePlainText(textbox.sampleValue);
    if (plain === '') return false;

    const displayed = textbox.uppercase === true ? plain.toUpperCase() : plain;
    if (textbox.text === displayed) return false;

    textbox.set('text', displayed);
    if (typeof textbox.initDimensions === 'function') {
        textbox.initDimensions();
    }
    textbox.setCoords();
    textbox.dirty = true;

    return true;
}

/**
 * Inline-edit direction: a sampled input's canvas text was just edited — the
 * sample follows, or the export keeps rendering the OLD text (the checklist
 * bug, generalized). Styling: a plain sample stays plain; an envelope keeps
 * its LEAD (first) run's whole-text style — after a content rewrite a
 * multi-run partial mapping has nothing to attach to, and keeping stale text
 * would be the worse lie. List line types carry over by index, 'p'-filled
 * (the checklist rule). No-op when the texts already agree, which is exactly
 * what keeps a multi-run sample intact right after applySampleToCanvasText.
 *
 * @returns {boolean} whether the stored sample changed.
 */
export function syncTextSample(textbox) {
    if (!textbox || textbox.checklist === true) return false;

    const current = typeof textbox.sampleValue === 'string' ? textbox.sampleValue : null;
    if (current === null || current === '') return false;
    if (typeof textbox.text !== 'string') return false;

    const text = textbox.text;
    const plain = samplePlainText(current);
    const displayed = textbox.uppercase === true ? plain.toUpperCase() : plain;
    if (displayed === text) return false;

    let next;

    if (text === '') {
        // The designer blanked the stand-in — an empty sample is "no sample".
        next = null;
    } else {
        const envelope = parseEnvelope(current);
        const lead = envelope && envelope.runs[0] && typeof envelope.runs[0] === 'object'
            ? envelope.runs[0]
            : {};
        const run = {
            text,
            fontFamily: typeof lead.fontFamily === 'string' ? lead.fontFamily : null,
            color: typeof lead.color === 'string' ? lead.color : null,
            underline: lead.underline === true,
        };

        const lineCount = text.split('\n').length;
        const lines = [];
        let hasList = false;
        for (let i = 0; i < lineCount; i += 1) {
            const type = envelope ? envelope.lines[i] : undefined;
            const kept = type === 'ul' || type === 'ol' || type === 'cb' || type === 'cbx' ? type : 'p';
            if (kept !== 'p') hasList = true;
            lines.push(kept);
        }

        const styled = run.fontFamily !== null || run.color !== null || run.underline;
        if (styled || hasList) {
            next = JSON.stringify(hasList ? { runs: [run], lines } : { runs: [run] });
        } else {
            // Unstyled single run degrades to the plain wire shape, the same
            // rule the fill WYSIWYG's _sync applies.
            next = text;
        }
    }

    if (next === current) return false;
    textbox.sampleValue = next;

    return true;
}
