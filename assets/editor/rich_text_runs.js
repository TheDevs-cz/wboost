/*
 * Rich text runs — the single source of truth for converting a rich-text fill
 * value (a list of "runs": { text, fontFamily, color, underline }, where null
 * style properties inherit the textbox's designed object-level style) into
 * Fabric.js per-character styles, plus the client-side mirrors of the server
 * value pipeline (normalize / truncate / upper — see src/Value/RichText.php,
 * keep the two in sync).
 *
 * This file is deliberately a dependency-free classic script (attaches to
 * window/globalThis, no ES module syntax) because it has three consumers that
 * cannot share one loading mechanism:
 *   1. templates/api/template_variant_render.html.twig — inlined verbatim into
 *      the headless Gotenberg render by TemplateVariantImageRenderer,
 *   2. the user fill page (rich_text_editor_controller.js +
 *      variant_fill_overlay_controller.js measurement) via <script src>,
 *   3. (indirectly) docs/api/consumer-prompt.md documents the same semantics
 *      for external API consumers — changes here are contract changes.
 *
 * TWO COUNTING DOMAINS — do not mix them up:
 *   - truncate()/maxLength work in Unicode CODE POINTS (Array.from), matching
 *     PHP's mb_strlen/mb_substr on the server.
 *   - Fabric style ranges ({start, end}) are indexed by GRAPHEME, matching
 *     fabric.util.stylesFromArray: Intl.Segmenter(undefined, { granularity:
 *     'grapheme' }) when available, else a surrogate-pair-aware split —
 *     mirrored 1:1 from the Fabric 7.3.1 bundle. Using code units (or code
 *     points) here would shift every style boundary after an emoji/diacritic.
 *     NEWLINES are excluded from this index (stylesFromArray splits on /\r?\n/
 *     and counts graphemes per line) — see styleIndexLength.
 *
 * applyToTextbox() ordering contract (empirically load-bearing in Fabric v7):
 * assign `styles` FIRST (not dimension-affecting), then set `text` through
 * obj.set() (dimension-affecting — triggers re-wrap that must already see the
 * per-char fonts), then force initDimensions() + dirty for the case where the
 * text is unchanged and only the styling moved.
 */
(function (global) {
    'use strict';

    let segmenter;
    let segmenterProbed = false;

    function getSegmenter() {
        if (!segmenterProbed) {
            segmenterProbed = true;
            segmenter = (typeof Intl !== 'undefined' && 'Segmenter' in Intl)
                ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
                : null;
        }
        return segmenter;
    }

    /** Mirror of Fabric's internal graphemeSplit (Segmenter → surrogate-pair fallback). */
    function graphemeSplit(text) {
        const seg = getSegmenter();
        if (seg) {
            return Array.from(seg.segment(text)).map(function (entry) { return entry.segment; });
        }
        const graphemes = [];
        for (let i = 0; i < text.length; i += 1) {
            const code = text.charCodeAt(i);
            if (code >= 0xd800 && code <= 0xdbff && i + 1 < text.length) {
                graphemes.push(text.slice(i, i + 2));
                i += 1;
            } else {
                graphemes.push(text[i]);
            }
        }
        return graphemes;
    }

    function graphemeLength(text) {
        return graphemeSplit(text).length;
    }

    /**
     * Grapheme count as Fabric's stylesFromArray indexes styles: it splits the
     * text on /\r?\n/ and counts graphemes PER LINE, so newline separators
     * occupy no style position. Style ranges (toFabric) must advance by this,
     * NOT by graphemeLength, or every style boundary after a newline shifts.
     * Text reaching here is normalized to LF.
     */
    function styleIndexLength(text) {
        let count = 0;
        graphemeSplit(text).forEach(function (grapheme) {
            if (grapheme !== '\n') {
                count += 1;
            }
        });
        return count;
    }

    /** Code-point length — parity with PHP mb_strlen (NOT UTF-16 .length). */
    function codePointLength(text) {
        return Array.from(text).length;
    }

    function codePointSlice(text, start, end) {
        return Array.from(text).slice(start, end).join('');
    }

    function sameStyle(a, b) {
        return (a.fontFamily || null) === (b.fontFamily || null)
            && (a.color || null) === (b.color || null)
            && Boolean(a.underline) === Boolean(b.underline);
    }

    /**
     * Coerce + normalize a runs list: string texts (CRLF/CR canonicalized to LF,
     * but newlines PRESERVED — multi-line fill values are supported and Fabric
     * renders `\n` as a hard line break), null-or-string styles, empty runs
     * dropped, adjacent equal-styled runs merged. Mirrors
     * RichText::fromRaw(strict: false) + normalized() on the server.
     */
    function normalize(runs) {
        const result = [];
        (Array.isArray(runs) ? runs : []).forEach(function (raw) {
            if (!raw || typeof raw !== 'object') {
                return;
            }
            const text = typeof raw.text === 'string'
                ? raw.text.replace(/\r\n?/g, '\n')
                : '';
            if (text === '') {
                return;
            }
            const run = {
                text: text,
                fontFamily: typeof raw.fontFamily === 'string' && raw.fontFamily !== '' ? raw.fontFamily : null,
                color: typeof raw.color === 'string' && raw.color !== '' ? raw.color : null,
                underline: raw.underline === true,
            };
            const previous = result[result.length - 1];
            if (previous && sameStyle(previous, run)) {
                previous.text += run.text;
            } else {
                result.push(run);
            }
        });
        return result;
    }

    function plainText(runs) {
        return (Array.isArray(runs) ? runs : []).map(function (run) {
            return run && typeof run.text === 'string' ? run.text : '';
        }).join('');
    }

    function isStyled(runs) {
        return (Array.isArray(runs) ? runs : []).some(function (run) {
            return run && (run.fontFamily || run.color || run.underline === true);
        });
    }

    /** Cut so the plain-text projection is at most maxLength CODE POINTS (mb_substr parity). */
    function truncate(runs, maxLength) {
        let remaining = Math.max(0, maxLength);
        const result = [];
        for (const run of (Array.isArray(runs) ? runs : [])) {
            if (remaining <= 0) {
                break;
            }
            const length = codePointLength(run.text);
            if (length <= remaining) {
                result.push(run);
                remaining -= length;
            } else {
                result.push({
                    text: codePointSlice(run.text, 0, remaining),
                    fontFamily: run.fontFamily || null,
                    color: run.color || null,
                    underline: run.underline === true,
                });
                remaining = 0;
            }
        }
        return normalize(result);
    }

    /** Uppercase PER RUN — case mapping can change length (ß → SS), offsets stay valid. */
    function upper(runs) {
        return normalize((Array.isArray(runs) ? runs : []).map(function (run) {
            return {
                text: String(run.text).toUpperCase(),
                fontFamily: run.fontFamily || null,
                color: run.color || null,
                underline: run.underline === true,
            };
        }));
    }

    /**
     * Convert runs to the Fabric v7 serialized styles shape:
     * { text, ranges: [{ start, end, style }] } with GRAPHEME-indexed,
     * end-exclusive offsets — the exact input fabric.util.stylesFromArray
     * expects. Unstyled runs advance the offset but emit no range (they
     * inherit the textbox's object-level style).
     */
    function toFabric(runs) {
        const normalized = normalize(runs);
        let text = '';
        let offset = 0;
        const ranges = [];

        normalized.forEach(function (run) {
            // Style offsets skip newlines (see styleIndexLength); `text` still
            // carries the LF so Fabric splits lines from it.
            const length = styleIndexLength(run.text);
            const style = {};
            if (run.fontFamily) {
                style.fontFamily = run.fontFamily;
            }
            if (run.color) {
                style.fill = run.color;
            }
            if (run.underline === true) {
                style.underline = true;
            }
            if (Object.keys(style).length > 0 && length > 0) {
                ranges.push({ start: offset, end: offset + length, style: style });
            }
            text += run.text;
            offset += length;
        });

        return { text: text, ranges: ranges };
    }

    /**
     * Apply a rich override onto a live Fabric Textbox. `stylesFromArray` is
     * fabric.util.stylesFromArray, passed in by the caller (this script stays
     * dependency-free). Ordering is a contract — see the header comment.
     */
    function applyToTextbox(obj, runs, stylesFromArray) {
        const converted = toFabric(runs);
        obj.styles = converted.ranges.length > 0 && typeof stylesFromArray === 'function'
            ? stylesFromArray(converted.ranges, converted.text)
            : {};
        obj.set({ text: converted.text });
        obj.initDimensions();
        obj.set('dirty', true);
    }

    /**
     * Drop ALL per-character styles before a plain text override. Fabric never
     * remaps the styles grid when text is set programmatically, so styles keyed
     * to old character positions would smear onto arbitrary characters.
     */
    function clearStyles(obj) {
        obj.styles = {};
        obj.set('dirty', true);
    }

    global.WBoostRichTextRuns = {
        graphemeSplit: graphemeSplit,
        graphemeLength: graphemeLength,
        codePointLength: codePointLength,
        normalize: normalize,
        plainText: plainText,
        isStyled: isStyled,
        truncate: truncate,
        upper: upper,
        toFabric: toFabric,
        applyToTextbox: applyToTextbox,
        clearStyles: clearStyles,
    };
})(typeof window !== 'undefined' ? window : globalThis);
