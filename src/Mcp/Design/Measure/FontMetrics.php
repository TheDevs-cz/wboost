<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Measure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The horizontal metrics of ONE font face, flattened to what text measurement
 * needs: a codepoint => advance-width table (in font units) plus the em size
 * those units are relative to.
 *
 * Built once per face file by {@see FontMetricsLoader} from the `cmap`
 * (codepoint => glyph id) and `hmtx` (glyph id => advance) tables, so measuring
 * never touches the font file again.
 *
 * **Unshaped by construction.** `hmtx` is the advance of a glyph in isolation:
 * no kerning (GPOS), no ligatures, no contextual substitution. Chromium applies
 * all three. That is the dominant source of error in {@see TextMeasurer} and the
 * reason {@see FontCalibration} exists.
 *
 * `#[Exclude]` keeps `config/services.php`'s `src/Mcp/Design/` directory load
 * from registering this as a service — it is a value, produced per face file by
 * the loader, and its scalar constructor arguments cannot be autowired.
 */
#[Exclude]
readonly final class FontMetrics
{
    /**
     * @param array<int, int> $advanceWidths Unicode codepoint => advance in font units.
     * @param int $fallbackAdvance Advance for a codepoint the face has no glyph for.
     *     Never zero: an unmapped character must not measure as free (see
     *     {@see FontMetricsLoader::resolveFallbackAdvance()}).
     */
    public function __construct(
        public null|string $postScriptName,
        public int $unitsPerEm,
        private array $advanceWidths,
        private int $fallbackAdvance,
        /** @see FontCalibration */
        public float $calibration,
    ) {
    }

    /**
     * Advance width of one grapheme cluster at `$fontSize`, in canvas px.
     *
     * A cluster is summed codepoint by codepoint, which is how Fabric's own
     * per-grapheme measurement behaves for the combining-mark case: a base plus
     * a zero-advance combining mark comes out as the base's width. Czech
     * diacritics are precomposed single codepoints in NFC, so in a face with
     * Latin Extended-A coverage they are ordinary `cmap` entries and measure
     * exactly — {@see hasGlyphFor()} is how a caller checks that rather than
     * assuming it.
     */
    public function advanceOf(string $grapheme, float $fontSize): float
    {
        $units = 0;

        foreach (mb_str_split($grapheme, 1, 'UTF-8') as $character) {
            // `(int)` covers `mb_ord()`'s documented `false` on a byte sequence
            // that is not a character: codepoint 0 is not in any `cmap`, so it
            // falls through to the fallback advance rather than to zero.
            $units += $this->advanceWidths[(int) mb_ord($character, 'UTF-8')] ?? $this->fallbackAdvance;
        }

        return ($units / $this->unitsPerEm) * $fontSize * $this->calibration;
    }

    /**
     * Whether this face really has a glyph for `$codepoint`, i.e. whether it is
     * measured or charged the fallback advance. Lets a caller distinguish "the
     * measurement is solid" from "this face cannot draw the designer's copy at
     * all" — a face with no `ř` renders through browser fallback in a visibly
     * different typeface, which is a design problem before it is a measurement
     * one.
     */
    public function hasGlyphFor(int $codepoint): bool
    {
        return array_key_exists($codepoint, $this->advanceWidths);
    }
}
