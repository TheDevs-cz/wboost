/*
 * Admin-editor preview for CHECKLIST COMPONENT textboxes (inputs added via
 * "Přidat zaškrtávací seznam"). Without it the designer sees plain text lines
 * while the export draws a checkbox before every item and indents the text —
 * i.e. the canvas lies about what the PNG will look like.
 *
 * The preview is a per-INSTANCE `_render` patch, deliberately NOT extra canvas
 * objects: methods are not serialized, so the saved canvas JSON, the inputs
 * payload, the layers panel, snapping targets, undo snapshots and group sync
 * all stay exactly as they were — only the pixels the editor paints change.
 *
 * Geometry mirrors assets/editor/rich_text_blocks.js + the render template:
 * the marker is a rounded square of 0.9 × fontSize in the text color at the
 * box's left edge, vertically centered on the line's GLYPH box
 * (fontSize × _fontSizeMult — Fabric puts a line's leading BELOW its glyphs),
 * and the item text is drawn shifted right by the resolved indent
 * (`listIndent` or the 1.5 × fontSize default of ResolvedListStyle).
 *
 * Known, deliberate approximations (the server render stays authoritative):
 * wrapping still happens at the full designed width, so a long item can wrap
 * one word later than in the export, and custom checkbox IMAGES are shown as
 * the default square (the canvas object stores a storage path, not a URL).
 */

/** Resolved item indent, mirroring ResolvedListStyle::resolve. */
function resolveIndent(textbox) {
    const explicit = textbox.listIndent;
    if (typeof explicit === 'number' && isFinite(explicit) && explicit >= 0) {
        return explicit;
    }
    return Math.round((textbox.fontSize || 0) * 1.5 * 10) / 10;
}

/** Per-line checked flags from the object's sample value ('cbx' = checked).
 *  Lines the sample doesn't cover (designer edited the canvas text since)
 *  simply render unchecked. */
function checkedStates(textbox) {
    const raw = typeof textbox.sampleValue === 'string' ? textbox.sampleValue.trim() : '';
    if (!raw.startsWith('{')) return [];
    try {
        const decoded = JSON.parse(raw);
        return Array.isArray(decoded.lines) ? decoded.lines.map((type) => type === 'cbx') : [];
    } catch (err) {
        return [];
    }
}

function drawMarker(ctx, x, y, size, color, checked) {
    const radius = size * 0.22;
    ctx.save();
    ctx.fillStyle = typeof color === 'string' && color !== '' ? color : '#000000';
    ctx.beginPath();
    if (typeof ctx.roundRect === 'function') {
        ctx.roundRect(x, y, size, size, radius);
    } else {
        ctx.rect(x, y, size, size);
    }
    ctx.fill();

    if (checked) {
        // Same check path the export draws (16×16 design space, white).
        const scale = size / 16;
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2.4 * scale;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(x + 3.2 * scale, y + 8.6 * scale);
        ctx.lineTo(x + 6.6 * scale, y + 12 * scale);
        ctx.lineTo(x + 12.8 * scale, y + 4.4 * scale);
        ctx.stroke();
    }
    ctx.restore();
}

/**
 * Patch ONE checklist textbox so the editor paints its checkboxes. Idempotent
 * (a re-run on an already-patched instance is a no-op) and safe to call for
 * any object — non-checklist ones are ignored.
 */
export function applyChecklistPreview(textbox) {
    if (!textbox || textbox.checklist !== true) return;
    if ((textbox.type || '').toLowerCase() !== 'textbox') return;
    if (textbox.__wboostChecklistPreview === true) return;

    textbox.__wboostChecklistPreview = true;
    // The indented text overflows the object's bbox by `indent`; a cached
    // render would clip it at the right edge. (objectCaching is a rendering
    // hint — harmless even if it ends up in the canvas JSON.)
    textbox.objectCaching = false;

    const originalRender = textbox._render.bind(textbox);

    textbox._render = function (ctx) {
        const indent = resolveIndent(this);

        ctx.save();
        ctx.translate(indent, 0);
        originalRender(ctx);
        ctx.restore();

        const size = Math.round((this.fontSize || 0) * 0.9);
        if (size <= 0) return;
        const mult = typeof this._fontSizeMult === 'number' ? this._fontSizeMult : 1.13;
        const glyphLine = this.fontSize * mult;
        const left = typeof this._getLeftOffset === 'function' ? this._getLeftOffset() : -this.width / 2;
        const states = checkedStates(this);

        let y = typeof this._getTopOffset === 'function' ? this._getTopOffset() : -this.height / 2;
        const lines = this._textLines || [];
        for (let i = 0; i < lines.length; i += 1) {
            drawMarker(
                ctx,
                left,
                y + Math.max(0, (glyphLine - size) / 2),
                size,
                this.fill,
                states[i] === true,
            );
            y += typeof this.getHeightOfLine === 'function' ? this.getHeightOfLine(i) : glyphLine;
        }
    };

    textbox.dirty = true;
}

/** Sweep the whole canvas — cheap (patched instances short-circuit). */
export function sweepChecklistPreviews(canvas) {
    if (!canvas) return;
    canvas.getObjects().forEach((obj) => applyChecklistPreview(obj));
}
