/*
 * Rich text BLOCKS — lists ("odrážky") layered on top of the flat runs model
 * (see rich_text_runs.js). A rich value's envelope may carry per-LINE types:
 * `{ runs: [...], lines: ["p","ul","ol","cb","cbx",...] }`, one entry per
 * line of the plain-text projection (split on "\n"). Consecutive 'p' lines
 * render as ONE paragraph textbox (byte-identical to the pre-lists flat
 * rendering, which is what makes the model backward compatible); consecutive
 * 'ul' / 'ol' lines form a list whose items are individually wrapped
 * textboxes indented by the admin's `indent` (hanging indent: wrapped
 * continuation lines align under the text, not under the bullet) with a
 * bullet object at the line start — a character (•, –, ✓), an ordinal
 * ("1."), or a custom image. 'cb' (unchecked) and 'cbx' (checked) are
 * CHECKBOX items: both belong to the SAME block (a checklist mixes states
 * freely), laid out exactly like 'ul' items, with the bullet element
 * carrying `checked` — the renderer draws a checkbox (admin-picked images
 * per state, or the default rounded square in the item's text color, the
 * checked one with a white check mark).
 *
 * The PHP mirror of the value semantics is src/Value/RichText.php (lineTypes)
 * and of the styling defaults src/Value/ResolvedListStyle.php — keep them in
 * sync. Like rich_text_runs.js this is a dependency-free classic script with
 * the same three consumers (headless render template, fill page overlay,
 * documented API contract for external consumers incl. mfkfm).
 *
 * layoutStack() measurement contract: the `measure(runs, width)` callback is
 * invoked exactly ONCE per text element, in element order — a caller building
 * real Fabric Textboxes may therefore queue the boxes it creates inside
 * measure() and zip them with the returned elements.
 */
(function (global) {
    'use strict';

    var BULLET_CHARS = { disc: '•', dash: '–', check: '✓' };

    function isListType(t) {
        return t === 'ul' || t === 'ol' || t === 'cb' || t === 'cbx';
    }

    /** 'cb' and 'cbx' lines share one BLOCK type ('cb') — a checklist mixes
     *  checked and unchecked items inside the same list. */
    function blockType(t) {
        return t === 'cbx' ? 'cb' : t;
    }

    function hasListLines(lines) {
        return Array.isArray(lines) && lines.some(isListType);
    }

    /** Line count of the runs' plain-text projection. */
    function lineCount(runs) {
        var plain = (Array.isArray(runs) ? runs : []).map(function (r) {
            return r && typeof r.text === 'string' ? r.text : '';
        }).join('');
        return plain.split('\n').length;
    }

    /** Slice/pad line types to the runs' actual line count ('p' fill).
     *  Returns null when the result carries no list lines. */
    function normalizeLines(runs, lines) {
        var count = lineCount(runs);
        var result = [];
        for (var i = 0; i < count; i += 1) {
            var t = Array.isArray(lines) ? lines[i] : 'p';
            result.push(isListType(t) ? t : 'p');
        }
        return hasListLines(result) ? result : null;
    }

    /** Split flat runs into per-line runs arrays (styles preserved, "\n"
     *  separators dropped — they belong to the structure, not the lines). */
    function splitLines(runs) {
        var lines = [[]];
        (Array.isArray(runs) ? runs : []).forEach(function (run) {
            var text = run && typeof run.text === 'string' ? run.text : '';
            var parts = text.split('\n');
            parts.forEach(function (part, index) {
                if (index > 0) {
                    lines.push([]);
                }
                if (part !== '') {
                    lines[lines.length - 1].push({
                        text: part,
                        fontFamily: (run && run.fontFamily) || null,
                        color: (run && run.color) || null,
                        underline: Boolean(run && run.underline),
                    });
                }
            });
        });
        return lines;
    }

    /**
     * Group lines into render blocks: consecutive 'p' lines merge back into
     * one paragraph (runs re-joined with "\n" separators so the paragraph
     * wraps and renders exactly like the flat pre-lists value); consecutive
     * same-type list lines become one list block with per-item runs ('cb'
     * and 'cbx' count as the same type — the block records per-item
     * `checked` flags instead).
     * Returns [{ type: 'p', runs }
     *        | { type: 'ul'|'ol', items: [runs] }
     *        | { type: 'cb', items: [runs], checked: [bool] }].
     */
    function groupBlocks(runs, lines) {
        var perLine = splitLines(runs);
        var types = normalizeLines(runs, lines);
        if (!types) {
            return [{ type: 'p', runs: Array.isArray(runs) ? runs : [] }];
        }

        var blocks = [];
        perLine.forEach(function (lineRuns, index) {
            var lineType = types[index] || 'p';
            var type = blockType(lineType);
            var last = blocks[blocks.length - 1];
            if (type === 'p') {
                if (last && last.type === 'p') {
                    // Re-insert the separator with the previous run's style so
                    // normalization can merge it away.
                    var style = last.runs.length > 0 ? last.runs[last.runs.length - 1] : {};
                    last.runs = last.runs.concat([{
                        text: '\n',
                        fontFamily: style.fontFamily || null,
                        color: style.color || null,
                        underline: Boolean(style.underline),
                    }]).concat(lineRuns);
                } else {
                    blocks.push({ type: 'p', runs: lineRuns.slice() });
                }
            } else if (last && last.type === type) {
                last.items.push(lineRuns);
                if (type === 'cb') {
                    last.checked.push(lineType === 'cbx');
                }
            } else if (type === 'cb') {
                blocks.push({ type: type, items: [lineRuns], checked: [lineType === 'cbx'] });
            } else {
                blocks.push({ type: type, items: [lineRuns] });
            }
        });
        return blocks;
    }

    /** The bullet's text for character/ordinal bullets; null = image bullet
     *  (only 'ul' items use the image — 'ol' is always the ordinal) OR a
     *  checkbox ('cb' items never render as text — the caller draws the
     *  checkbox visuals from the element's `checked` flag). */
    function bulletText(config, listType, ordinal) {
        if (listType === 'ol') {
            return String(ordinal) + '.';
        }
        if (listType === 'cb') {
            return null;
        }
        var bullet = (config && config.bullet) || 'disc';
        if (bullet === 'image') {
            return null;
        }
        return BULLET_CHARS[bullet] || BULLET_CHARS.disc;
    }

    /** Font family + color the bullet inherits: the item's first run when it
     *  overrides them, the designed base otherwise. */
    function itemLeadStyle(itemRuns) {
        var first = (Array.isArray(itemRuns) ? itemRuns : []).find(function (r) { return r && r.text !== ''; });
        return {
            fontFamily: (first && first.fontFamily) || null,
            color: (first && first.color) || null,
        };
    }

    /**
     * Lay the blocks out as a vertical stack anchored at (0, 0) of the input's
     * designed box. `config` = the RESOLVED list style ({ bullet, indent,
     * itemSpacing, blockSpacing } in canvas px — server-resolved, see
     * ResolvedListStyle); `geom` = { width } (the designed wrap width);
     * `measure(runs, width)` returns the wrapped height of a runs fragment.
     *
     * Returns { height, elements } — elements in paint order:
     *   { kind: 'text', runs, left, top, width }
     *   { kind: 'bullet', listType, ordinal, checked, left: 0, top, item }
     * (`checked` is a boolean for listType 'cb' and undefined otherwise.)
     */
    function layoutStack(blocks, config, geom, measure) {
        var y = 0;
        var elements = [];
        var indent = Math.max(0, (config && config.indent) || 0);
        var itemSpacing = Math.max(0, (config && config.itemSpacing) || 0);
        var blockSpacing = Math.max(0, (config && config.blockSpacing) || 0);
        var itemWidth = Math.max(10, geom.width - indent);

        (Array.isArray(blocks) ? blocks : []).forEach(function (block, blockIndex) {
            if (blockIndex > 0) {
                y += blockSpacing;
            }
            if (block.type === 'p') {
                var height = measure(block.runs, geom.width);
                elements.push({ kind: 'text', runs: block.runs, left: 0, top: y, width: geom.width });
                y += height;
                return;
            }
            (block.items || []).forEach(function (itemRuns, itemIndex) {
                if (itemIndex > 0) {
                    y += itemSpacing;
                }
                var itemHeight = measure(itemRuns, itemWidth);
                var bullet = { kind: 'bullet', listType: block.type, ordinal: itemIndex + 1, left: 0, top: y, item: itemRuns };
                if (block.type === 'cb') {
                    bullet.checked = Boolean(block.checked && block.checked[itemIndex]);
                }
                elements.push(bullet);
                elements.push({ kind: 'text', runs: itemRuns, left: indent, top: y, width: itemWidth });
                y += itemHeight;
            });
        });

        return { height: y, elements: elements };
    }

    global.WBoostRichTextBlocks = {
        isListType: isListType,
        hasListLines: hasListLines,
        lineCount: lineCount,
        normalizeLines: normalizeLines,
        splitLines: splitLines,
        groupBlocks: groupBlocks,
        bulletText: bulletText,
        itemLeadStyle: itemLeadStyle,
        layoutStack: layoutStack,
    };
})(typeof window !== 'undefined' ? window : globalThis);
