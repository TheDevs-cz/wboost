/*
 * Admin-editor preview of a RICH input's sample ("Vzorový text") run styling.
 *
 * A rich sample (`{"runs":[{"text":"Dárkový "},{"text":"poukaz","color":"#005086"}]}`)
 * is what the export renders whenever nobody fills the input, and the canvas
 * stand-in already carries its plain text (applySampleToCanvasText) — but the
 * per-run colour / face / underline used to stay invisible, so the editor
 * showed the whole text in the base colour while the fill preview and the PNG
 * had the coloured word (prod report 2026-11-08).
 *
 * The runs are converted by the SAME shared code the render template and the
 * fill page use (assets/editor/rich_text_runs.js `toFabric` → grapheme-indexed
 * ranges → fabric.util.stylesFromArray), applied as the live object's
 * per-character `styles`. They are DISPLAY-ONLY: a per-instance `toObject`
 * patch (the canvas_checklist_preview pattern — methods are never serialized)
 * writes the DESIGNED styles back into every serialization (save payload, undo
 * snapshots, clipboard / duplicate clones, group sync), so the sample stays a
 * sample — the stored object never gains per-char styles (which would also
 * trip the MCP decompiler's StyleDropped loss and the font usage scan).
 *
 * Applied only while the canvas text equals the sample's plain text (display
 * form: uppercased when the input uppercases); a legacy divergent sample, a
 * plain sample or no sample shows the designed styling.
 */
import { util } from 'fabric';

function runsModule() {
    const module = typeof window !== 'undefined' ? window.WBoostRichTextRuns : undefined;
    return module && typeof module.toFabric === 'function' ? module : null;
}

/** Runs of a stored rich sample envelope (null for plain / missing / invalid). */
function sampleRuns(raw) {
    if (typeof raw !== 'string' || !raw.trim().startsWith('{')) return null;
    try {
        const decoded = JSON.parse(raw);
        return decoded && Array.isArray(decoded.runs) ? decoded.runs : null;
    } catch (err) {
        return null;
    }
}

function isTextbox(obj) {
    return !!obj && (obj.type || '').toLowerCase() === 'textbox';
}

/** Install the serialization guard once per instance; snapshots the designed styles. */
function guard(textbox) {
    if (textbox.__wboostSampleStylesGuard === true) return;
    textbox.__wboostSampleStylesGuard = true;
    textbox.__wboostDesignedStyles = util.stylesToArray(textbox.styles || {}, textbox.text || '');

    const originalToObject = textbox.toObject.bind(textbox);
    textbox.toObject = function (propertiesToInclude) {
        const serialized = originalToObject(propertiesToInclude);
        if (this.__wboostSampleStyled === true && serialized && typeof serialized === 'object') {
            serialized.styles = JSON.parse(JSON.stringify(this.__wboostDesignedStyles || []));
        }
        return serialized;
    };
}

/**
 * Show (or stop showing) the object's sample run styles on the canvas.
 * Idempotent and safe for any object — only rich, non-checklist textboxes
 * whose canvas text matches a styled sample get styled.
 *
 * @returns {boolean} whether the displayed styling changed.
 */
export function applySampleStyles(textbox) {
    if (!isTextbox(textbox) || textbox.checklist === true) return false;

    const module = runsModule();
    let ranges = null;

    if (module && textbox.richText === true) {
        const raw = sampleRuns(textbox.sampleValue);
        if (raw !== null && module.isStyled(raw)) {
            let runs = module.normalize(raw);
            if (textbox.uppercase === true) runs = module.upper(runs);
            const converted = module.toFabric(runs);
            if (converted.text === textbox.text && converted.ranges.length > 0) {
                ranges = converted.ranges;
            }
        }
    }

    if (ranges === null && textbox.__wboostSampleStyled !== true) return false;

    guard(textbox);

    if (ranges !== null) {
        textbox.styles = util.stylesFromArray(ranges, textbox.text);
        textbox.__wboostSampleStyled = true;
    } else {
        textbox.styles = util.stylesFromArray(textbox.__wboostDesignedStyles || [], textbox.text || '');
        textbox.__wboostSampleStyled = false;
    }

    // Per-char faces change glyph widths → re-wrap, like applyToTextbox.
    if (typeof textbox.initDimensions === 'function') textbox.initDimensions();
    textbox.setCoords();
    textbox.dirty = true;

    return true;
}

/** Sweep the whole canvas (load / undo restore). */
export function sweepSampleStyles(canvas) {
    if (!canvas) return;
    canvas.getObjects().forEach((obj) => applySampleStyles(obj));
}
