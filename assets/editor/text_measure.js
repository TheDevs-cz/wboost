/*
 * Text measurement for API consumers — the canvas-2D mirror of the render's
 * Fabric text layout.
 *
 * The render (and the in-app editor/fill surfaces) measure text with Fabric
 * itself; an API consumer that wants live placeholder boxes / container
 * reflow / overflow pre-checks (docs/api/consumer-prompt.md) cannot cheaply
 * ship Fabric, so this module re-implements EXACTLY the parts of Fabric's
 * Textbox layout that determine a text's wrapped height, using a plain
 * canvas 2D context:
 *
 *  - greedy word wrap at the box width (Textbox._wrapLine, splitByGrapheme
 *    off): words split on /[ \t\r]/, paragraphs on /\r?\n/, a word's own
 *    trailing charSpacing does not count against the line limit;
 *  - the break-word patch (fabric_break_word.js): a word wider than the box
 *    hard-breaks into greedily packed grapheme chunks;
 *  - charSpacing is 1/1000 em per grapheme (added to every grapheme,
 *    including spaces);
 *  - line height is fontSize × 1.13 (Fabric's _fontSizeMult) × lineHeight,
 *    except the LAST line which is fontSize × 1.13 (Text.calcTextHeight);
 *  - rich-text runs may switch the font FAMILY per segment (never the size),
 *    so word widths are summed per same-family piece — a bold face wraps
 *    wider, exactly like the render.
 *
 * Parity caveats, in the consumer's hands:
 *  - measure ONLY after loading the real font files (GET
 *    /api/projects/{projectId}/fonts → FontFace) — a fallback face produces
 *    different wrap points;
 *  - apply the input rules BEFORE measuring: truncate to maxLength, apply
 *    `uppercase`; a value the export omits (empty, locked) keeps rendering
 *    the designed text, so keep the designed frame for it.
 *
 * This file is a CONTRACT companion of container_layout.js: any change to the
 * render's Fabric version, the break-word patch or these constants is a
 * contract change — keep the three in sync. Classic script on purpose
 * (attaches to window/globalThis, no ES module syntax) so any consumer stack
 * can vendor or inline it.
 */
(function (global) {
    'use strict';

    // Fabric Text._fontSizeMult — part of every line's height.
    var FONT_SIZE_MULT = 1.13;

    var sharedContext;

    function getContext(ctx) {
        if (ctx) {
            return ctx;
        }
        if (sharedContext === undefined) {
            sharedContext = typeof document === 'undefined'
                ? null
                : document.createElement('canvas').getContext('2d');
        }
        return sharedContext;
    }

    /** Quote the family unless it already carries quotes/commas (Fabric's rule). */
    function fontDeclaration(family, fontSize) {
        var quoted = /['",]/.test(family) ? family : '"' + family + '"';
        return fontSize + 'px ' + quoted;
    }

    /**
     * Normalize the value into styled graphemes: [{ g, family }]. Accepts a
     * plain string or rich segments [{ text, fontFamily|null }] (null = the
     * input's designed family).
     */
    function toStyledGraphemes(value, baseFamily) {
        var segments = typeof value === 'string'
            ? [{ text: value, fontFamily: null }]
            : value;
        var graphemes = [];
        for (var s = 0; s < segments.length; s += 1) {
            var family = segments[s].fontFamily || baseFamily;
            var chars = Array.from(String(segments[s].text));
            for (var c = 0; c < chars.length; c += 1) {
                graphemes.push({ g: chars[c], family: family });
            }
        }
        return graphemes;
    }

    /**
     * Width of a styled-grapheme list: same-family pieces measured together
     * (canvas kerning within a piece), plus charSpacing per grapheme —
     * Fabric's kernedWidth + charSpacing sum.
     */
    function measureStyled(ctx, graphemes, fontSize, spacingPerGrapheme) {
        if (graphemes.length === 0) {
            return 0;
        }
        var width = graphemes.length * spacingPerGrapheme;
        var piece = '';
        var pieceFamily = graphemes[0].family;
        for (var i = 0; i < graphemes.length; i += 1) {
            if (graphemes[i].family !== pieceFamily) {
                ctx.font = fontDeclaration(pieceFamily, fontSize);
                width += ctx.measureText(piece).width;
                piece = '';
                pieceFamily = graphemes[i].family;
            }
            piece += graphemes[i].g;
        }
        ctx.font = fontDeclaration(pieceFamily, fontSize);
        width += ctx.measureText(piece).width;
        return width;
    }

    /** Wrapped line count of one paragraph (a list of styled graphemes). */
    function wrapParagraphLineCount(ctx, graphemes, boxWidth, style) {
        var spacing = (style.fontSize * style.charSpacing) / 1000;

        // Split into words on Fabric's _wordJoiners; keep each separator's own
        // family (it prices the inter-word space of the FOLLOWING join).
        var words = [];
        var current = [];
        var joinerFamilies = [];
        for (var i = 0; i < graphemes.length; i += 1) {
            var g = graphemes[i].g;
            if (g === ' ' || g === '\t' || g === '\r') {
                words.push(current);
                joinerFamilies.push(graphemes[i].family);
                current = [];
            } else {
                current.push(graphemes[i]);
            }
        }
        words.push(current);

        // Break-word patch: pre-slice over-wide words into fitting chunks.
        var entries = []; // { width, joinerFamily } — joinerFamily of the join BEFORE the entry
        var largestWordWidth = 0;
        for (var w = 0; w < words.length; w += 1) {
            var word = words[w];
            var joinerFamily = w === 0 ? null : joinerFamilies[w - 1];
            var wordWidth = measureStyled(ctx, word, style.fontSize, spacing);
            if (wordWidth <= boxWidth || word.length <= 1) {
                entries.push({ width: wordWidth, joinerFamily: joinerFamily });
                largestWordWidth = Math.max(largestWordWidth, wordWidth);
                continue;
            }
            var chunk = [];
            var chunkWidth = 0;
            for (var c = 0; c < word.length; c += 1) {
                var candidate = chunk.concat(word[c]);
                var candidateWidth = measureStyled(ctx, candidate, style.fontSize, spacing);
                if (chunk.length > 0 && candidateWidth > boxWidth) {
                    entries.push({ width: chunkWidth, joinerFamily: joinerFamily });
                    largestWordWidth = Math.max(largestWordWidth, chunkWidth);
                    joinerFamily = null; // chunks pack full-width; no re-inserted space
                    chunk = [word[c]];
                    chunkWidth = measureStyled(ctx, chunk, style.fontSize, spacing);
                } else {
                    chunk = candidate;
                    chunkWidth = candidateWidth;
                }
            }
            if (chunk.length > 0) {
                entries.push({ width: chunkWidth, joinerFamily: joinerFamily });
                largestWordWidth = Math.max(largestWordWidth, chunkWidth);
            }
        }

        // Fabric Textbox._wrapLine: greedy fill; a word's trailing charSpacing
        // doesn't count against the limit (the `- additionalSpace` step).
        var additionalSpace = spacing;
        var maxWidth = Math.max(boxWidth, largestWordWidth);
        var lines = 0;
        var lineWidth = 0;
        var infixWidth = 0;
        var lineJustStarted = true;
        for (var e = 0; e < entries.length; e += 1) {
            lineWidth += infixWidth + entries[e].width - additionalSpace;
            if (lineWidth > maxWidth && !lineJustStarted) {
                lines += 1;
                lineWidth = entries[e].width;
                lineJustStarted = true;
            } else {
                lineWidth += additionalSpace;
            }
            var nextJoiner = e + 1 < entries.length ? entries[e + 1].joinerFamily : null;
            infixWidth = nextJoiner === null
                ? 0
                : measureStyled(ctx, [{ g: ' ', family: nextJoiner }], style.fontSize, spacing);
            lineJustStarted = false;
        }
        if (entries.length > 0) {
            lines += 1;
        }
        return Math.max(1, lines);
    }

    /**
     * Height of `value` wrapped in a box `boxWidth` wide.
     *
     * value: string, or rich segments [{ text, fontFamily|null }] in order.
     * style: the API's `inputs[].textStyle` — { fontFamily, fontSize,
     *        lineHeight, charSpacing }.
     * ctx:   optional CanvasRenderingContext2D to reuse.
     *
     * Returns the height in canvas px, or null when measuring is impossible
     * (no canvas 2D context) — keep the designed frame height then.
     */
    function measureWrappedHeight(value, boxWidth, style, ctx) {
        var context = getContext(ctx);
        if (!context || !(boxWidth > 0) || !(style && style.fontSize > 0)) {
            return null;
        }

        var graphemes = toStyledGraphemes(value, style.fontFamily);

        // Paragraph split on /\r?\n/ (Fabric's _reNewline).
        var paragraphs = [];
        var current = [];
        for (var i = 0; i < graphemes.length; i += 1) {
            if (graphemes[i].g === '\n') {
                paragraphs.push(current);
                current = [];
            } else {
                current.push(graphemes[i]);
            }
        }
        paragraphs.push(current);

        var lineCount = 0;
        for (var p = 0; p < paragraphs.length; p += 1) {
            // \r that survives (not followed by \n) acts as a word joiner —
            // leave it to wrapParagraphLineCount.
            lineCount += wrapParagraphLineCount(context, paragraphs[p], boxWidth, style);
        }

        var fullLine = style.fontSize * FONT_SIZE_MULT * style.lineHeight;
        var lastLine = style.fontSize * FONT_SIZE_MULT;
        return fullLine * (lineCount - 1) + lastLine;
    }

    global.WBoostTextMeasure = {
        measureWrappedHeight: measureWrappedHeight,
    };
})(typeof window !== 'undefined' ? window : globalThis);
