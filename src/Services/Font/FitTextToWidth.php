<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Font;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Mcp\Design\Measure\FontMetricsLoader;

/**
 * The metric fit: the font size at which a line of text is as wide in a given
 * face as it was in the source picture —
 *
 *     size = sourceLineWidth ÷ advanceWidth(face, text, 1 px)
 *
 * Arithmetic over the font file's advance widths (the loader `TextMeasurer`
 * uses), no pixels and no browser: this is what lets the review swap a font
 * and re-fit its size inside a web request. The scanner keeps its own pixel
 * fit for ranking.
 *
 * Kerning and ligatures are not in the advance table, so the result differs
 * from the rendered width by the face's kerning share — a few per cent at
 * most for Latin text (measured agreement: X9 in the architecture).
 */
final readonly class FitTextToWidth
{
    public function __construct(
        private FontMetricsLoader $fonts,
    ) {
    }

    /**
     * @param string $faceFamily the face string, e.g. `"Rubik (Bold)"`
     * @param float $charSpacing Fabric `charSpacing` (1/1000 em per character), 0 for none
     * @return null|float the size in px, or null when the face is unknown or the text has no width in it
     */
    public function fit(UuidInterface $projectId, string $faceFamily, string $text, float $sourceLineWidth, float $charSpacing = 0.0): null|float
    {
        if ($sourceLineWidth <= 0.0) {
            return null;
        }

        $unitWidth = $this->advanceAtOnePixel($projectId, $faceFamily, $text, $charSpacing);

        if ($unitWidth === null || $unitWidth <= 0.0) {
            return null;
        }

        return $sourceLineWidth / $unitWidth;
    }

    /**
     * Width of `$text` set in the face at 1 px — multiply by a size to get
     * the width at that size.
     */
    public function advanceAtOnePixel(UuidInterface $projectId, string $faceFamily, string $text, float $charSpacing = 0.0): null|float
    {
        $metrics = $this->fonts->forFamily($projectId, $faceFamily);

        if ($metrics === null || $text === '') {
            return null;
        }

        if (preg_match_all('/\X/u', $text, $matches) === false) {
            return null;
        }

        $width = 0.0;

        foreach ($matches[0] as $grapheme) {
            // The line-count calibration factor is not part of a width: undo it.
            $width += $metrics->advanceOf($grapheme, 1.0) / ($metrics->calibration > 0.0 ? $metrics->calibration : 1.0);
        }

        // Fabric adds charSpacing after every character but the last.
        return $width + max(0, count($matches[0]) - 1) * ($charSpacing / 1000);
    }
}
