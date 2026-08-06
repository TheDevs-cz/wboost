<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Measure;

use Ramsey\Uuid\UuidInterface;

/**
 * ⚠️ **APPROXIMATE. Never authoritative. Chromium is the arbiter.** ⚠️
 *
 * A server-side estimate of how many lines a text wraps to in a box of a given
 * width, and how tall the wrapped text is — computed from the face's `hmtx`
 * advance widths, without a browser. Its ONLY purpose is to spend microseconds
 * instead of the 1–1.5 s of a Gotenberg round-trip so the design linter (S4-T6)
 * can say *"this headline probably overflows its container"* while the agent is
 * still writing the design (plan §0.5: deterministic lint + server-side text
 * measurement BEFORE spending a render).
 *
 * **Nothing may FAIL on this number.** It may warn, it may suggest, it may
 * decide whether to bother rendering. It must never reject a design, 400 an
 * export or gate a commit: the real render is what decides, and it is allowed to
 * disagree. Anyone tempted to hard-fail on `estimateLines()` should instead
 * render and read the real `CONTAINER_OVERFLOW` signal, which is exact.
 * `null` — "could not measure" — must be treated as "no opinion", never as zero.
 *
 * ## What it models
 *
 * The PHP sibling of `assets/editor/text_measure.js` (the canvas-2D mirror
 * published to API consumers), reproducing the same three layers Fabric v7 +
 * this app's patch use, so the two agree on where a line breaks:
 *
 *  - **paragraphs** split on `/\r?\n/` (Fabric `Text::_reNewline`);
 *  - **words** split on `/[ \t\r]/` (Fabric `Textbox::_wordJoiners`) — a break is
 *    only ever allowed at one of those, never mid-word;
 *  - **over-wide words hard-break** into greedily packed grapheme chunks, which
 *    is `assets/editor/fabric_break_word.js` — the patch applied on all three
 *    rendering surfaces. Without mirroring it, a single unbreakable string (a
 *    URL, a compound word) is exactly where an estimator diverges worst: Fabric
 *    unpatched would widen the whole block to the longest word instead;
 *  - **greedy fill** at the box width, `Textbox::_wrapLine` step for step,
 *    including its two oddities: the limit is `max(boxWidth, largestWordWidth)`,
 *    and a word's own trailing `charSpacing` does not count against the limit.
 *
 * ## What it knowingly ignores — the error budget
 *
 *  - **Kerning, ligatures and every other shaping feature.** `hmtx` is the
 *    isolated advance of a glyph; Chromium measures pairs and applies GPOS.
 *    This is the dominant error term and the reason {@see FontCalibration}
 *    exists.
 *  - **Font fallback.** A codepoint the face has no glyph for is charged
 *    `.notdef`'s advance ({@see FontMetricsLoader::resolveFallbackAdvance()});
 *    Chromium would draw it from a different face at a different width. Never
 *    zero, so text in an unsupported script cannot measure as free.
 *  - **Sub-pixel rounding.** Chromium's `measureText` is a float in device
 *    space; this sums exact font units. Near-tie lines can fall either way,
 *    which is precisely why the contract is ±1 line, not exactness.
 *  - **Per-character styling.** One family and one size for the whole string —
 *    a rich-text value whose runs switch to a bold face wraps wider than this
 *    predicts. (The JS mirror does handle runs; here the DSL text element has a
 *    single font, so the extra machinery would be untested weight.)
 *  - **`textAlign: justify`.** It redistributes space within a line; it cannot
 *    change the line count.
 *
 * `charSpacing` IS modelled (Fabric's unit: 1/1000 em) even though the design
 * DSL carries no such key today, because canvases authored in the editor do and
 * the API exposes it as `inputs[].textStyle.charSpacing` — leaving it out would
 * silently under-measure those.
 *
 * ## Caller's responsibility
 *
 * Measure the value that will actually be DRAWN: truncate to `maxLength` and
 * apply `uppercase` first (case mapping changes both length and glyph widths),
 * and pass the wrap width — a Fabric Textbox's `width` — not the element's
 * bounding box.
 */
readonly final class TextMeasurer
{
    /**
     * Fabric `Text::_fontSizeMult` — the ratio between a line's glyph box and
     * the font size. Pinned to Fabric v7.3.1's default (the committed bundle),
     * same constant the editor, the fill overlay and
     * `assets/editor/rich_text_blocks.js` all hard-code.
     */
    public const float FONT_SIZE_MULT = 1.13;

    public function __construct(
        private FontMetricsLoader $fonts,
    ) {
    }

    /**
     * Estimated number of wrapped lines, or **null when the text could not be
     * measured** — the project has no such face, its file is gone or
     * unparseable, or the geometry is degenerate. Null means "no opinion";
     * callers skip the check, they do not substitute a guess.
     *
     * An empty string is one line, exactly as Fabric reports it.
     *
     * @param string $fontFamily The `"Family (Face)"` wire string, e.g. `"Rubik (Rubik Bold)"`.
     * @param float $width The Textbox wrap width in canvas px.
     * @param float $charSpacing Fabric units: 1/1000 em.
     */
    public function estimateLines(
        UuidInterface $projectId,
        string $fontFamily,
        float $fontSize,
        float $width,
        string $text,
        float $charSpacing = 0.0,
    ): null|int {
        if ($fontSize <= 0.0 || $width <= 0.0) {
            return null;
        }

        $metrics = $this->fonts->forFamily($projectId, $fontFamily);

        if ($metrics === null) {
            return null;
        }

        $spacing = ($fontSize * $charSpacing) / 1000;
        $paragraphs = preg_split('/\r?\n/', $text);

        if ($paragraphs === false) {
            return null;
        }

        $lines = 0;

        foreach ($paragraphs as $paragraph) {
            $lines += $this->countParagraphLines($metrics, $paragraph, $fontSize, $width, $spacing);
        }

        return $lines;
    }

    /**
     * Estimated wrapped height in canvas px, or null on the same terms as
     * {@see estimateLines()}.
     */
    public function estimateHeight(
        UuidInterface $projectId,
        string $fontFamily,
        float $fontSize,
        float $lineHeight,
        float $width,
        string $text,
        float $charSpacing = 0.0,
    ): null|float {
        $lines = $this->estimateLines($projectId, $fontFamily, $fontSize, $width, $text, $charSpacing);

        if ($lines === null) {
            return null;
        }

        return self::heightOfLines($lines, $fontSize, $lineHeight);
    }

    /**
     * Height of `$lines` lines — **Fabric's rule, last line included.**
     *
     * `Text::calcTextHeight()` sums `getHeightOfLine(i)` for every line EXCEPT
     * the last, which contributes `getHeightOfLineImpl(i)` — i.e. the last line
     * gets no `lineHeight` multiplier, because Fabric puts a line's leading
     * BELOW its glyphs and the box ends at the last line's glyph box:
     *
     *     height = fontSize × 1.13 × lineHeight × (n − 1)  +  fontSize × 1.13
     *
     * So a one-line Textbox is `fontSize × 1.13` tall at ANY `lineHeight`.
     *
     * That asymmetry is the thing to get right, and it has already been shipped
     * wrong once in each direction. Re-inserting the leading BETWEEN elements is
     * what `assets/editor/rich_text_blocks.js` calls `geom.lineLeading` (its
     * absence was the 2026-08-05 "line height ignored in export" bug — a block
     * stack a whole leading per element too SHORT); applying `lineHeight` to
     * every line instead, including the last, makes a box a leading too TALL
     * (16 % on a single line at 1.16). Same formula as
     * `text_measure.js::measureWrappedHeight()`; pinned against Chromium's own
     * `calcTextHeight()` in `TextMeasurerTest`.
     */
    public static function heightOfLines(int $lines, float $fontSize, float $lineHeight): float
    {
        $glyphBox = $fontSize * self::FONT_SIZE_MULT;

        return $glyphBox * $lineHeight * (max(1, $lines) - 1) + $glyphBox;
    }

    /**
     * One paragraph (no newlines) → its wrapped line count. Always ≥ 1.
     */
    private function countParagraphLines(
        FontMetrics $metrics,
        string $paragraph,
        float $fontSize,
        float $width,
        float $spacing,
    ): int {
        $words = preg_split('/[ \t\r]/', $paragraph);

        if ($words === false) {
            return 1;
        }

        $spaceWidth = $metrics->advanceOf(' ', $fontSize) + $spacing;

        /** @var list<array{width: float, joined: bool}> $entries */
        $entries = [];
        $largestWordWidth = 0.0;

        foreach ($words as $index => $word) {
            $graphemes = self::graphemes($word);
            // Whether a space precedes this entry on the same source line. The
            // first word of a paragraph has none, and neither do the 2nd..nth
            // chunks of a hard-broken word (the break-word patch packs chunks
            // full-width and never re-inserts an infix space).
            $joined = $index > 0;

            $wordWidth = $this->widthOf($metrics, $graphemes, $fontSize, $spacing);

            if ($wordWidth <= $width || count($graphemes) <= 1) {
                $entries[] = ['width' => $wordWidth, 'joined' => $joined];
                $largestWordWidth = max($largestWordWidth, $wordWidth);

                continue;
            }

            // fabric_break_word.js: greedily fill grapheme chunks up to the box
            // width. A single grapheme wider than the box is emitted on its own
            // (it cannot be broken further) and then dominates largestWordWidth,
            // which is what widens the wrap limit below — same as Fabric.
            $chunkWidth = 0.0;
            $chunkLength = 0;

            foreach ($graphemes as $grapheme) {
                $graphemeWidth = $metrics->advanceOf($grapheme, $fontSize) + $spacing;

                if ($chunkLength > 0 && $chunkWidth + $graphemeWidth > $width) {
                    $entries[] = ['width' => $chunkWidth, 'joined' => $joined];
                    $largestWordWidth = max($largestWordWidth, $chunkWidth);
                    $joined = false;
                    $chunkWidth = $graphemeWidth;
                    $chunkLength = 1;

                    continue;
                }

                $chunkWidth += $graphemeWidth;
                $chunkLength++;
            }

            // The trailing chunk. Guarded in the JS patch; unconditional here
            // because this branch is only reached for words of 2+ graphemes, so
            // a chunk is always open.
            $entries[] = ['width' => $chunkWidth, 'joined' => $joined];
            $largestWordWidth = max($largestWordWidth, $chunkWidth);
        }

        // Fabric Textbox::_wrapLine, transliterated. `maxWidth` really is the
        // MAXIMUM of the box and the widest word — an unbreakable word wider
        // than the box raises the limit for the whole paragraph rather than
        // wrapping every following word early.
        //
        // (Fabric's own `lineJustStarted = true` inside the wrap branch is
        // omitted below, not lost: it is overwritten at the bottom of the same
        // iteration and only ever gated whether the infix SPACE gets pushed
        // into the line's character array — which is content, not width.)
        $maxWidth = max($width, $largestWordWidth);
        $count = count($entries);
        $lines = 0;
        $lineWidth = 0.0;
        $infixWidth = 0.0;
        $lineJustStarted = true;

        for ($i = 0; $i < $count; $i++) {
            $entryWidth = $entries[$i]['width'];
            $lineWidth += $infixWidth + $entryWidth - $spacing;

            if ($lineWidth > $maxWidth && !$lineJustStarted) {
                $lines++;
                $lineWidth = $entryWidth;
            } else {
                $lineWidth += $spacing;
            }

            $infixWidth = ($i + 1 < $count && $entries[$i + 1]['joined']) ? $spaceWidth : 0.0;
            $lineJustStarted = false;
        }

        // Fabric closes the last (never-pushed) line here under `if (i)`; the
        // guard is unconditional in this port because `preg_split()` always
        // yields at least one word — even for an empty paragraph, which is why
        // an empty string is one line, exactly as Fabric reports it.
        return $lines + 1;
    }

    /**
     * @param list<string> $graphemes
     */
    private function widthOf(FontMetrics $metrics, array $graphemes, float $fontSize, float $spacing): float
    {
        $width = 0.0;

        foreach ($graphemes as $grapheme) {
            $width += $metrics->advanceOf($grapheme, $fontSize) + $spacing;
        }

        return $width;
    }

    /**
     * Extended grapheme clusters, matching Fabric's `graphemeSplit` closely
     * enough that a hard break never lands inside a cluster (which would split
     * a base character from its combining mark).
     *
     * @return list<string>
     */
    private static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            // Invalid UTF-8 — fall back to bytes so a mangled string still
            // measures as something rather than vanishing.
            return str_split($text);
        }

        return $matches[0];
    }
}
