<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Value\CmykColor;

/**
 * A vector PDF printed by Chromium (DeviceRGB, sRGB pictures) → the same
 * PDF in DeviceCMYK for offset print: text stays text, shapes stay shapes,
 * pictures keep their pixels. Nothing is rasterised and nothing is drawn
 * again — only colours change:
 *
 * - **vector colours** (fills, strokes, text, gradient stops, colour
 *   tables) follow {@see CmykColorRules}: neutral → black ink only, brand
 *   colour → the palette's CMYK, the rest → ICC (sRGB → Coated FOGRA39);
 * - **pictures** go through the ICC conversion pixel by pixel — a JPEG is
 *   re-encoded as a CMYK JPEG, a losslessly stored picture stays lossless —
 *   except a lossless picture whose every pixel is neutral (a QR code, a
 *   black shadow), which becomes DeviceGray: black ink only. Soft masks stay;
 * - **luminosity soft masks** (how Chromium writes a gradient's opacity)
 *   become DeviceGray groups with the same luminosity, so opacity is exact;
 * - **transparency groups** blend in DeviceCMYK;
 * - **crop marks**, when asked for, are stroked in the registration colour.
 *
 * Files in, files out, one chunk of a calendar at a time; pictures are
 * streamed through work files. Whatever has no rule throws
 * {@see CmykConversionFailed} — and {@see PdfRgbVerifier} checks the result
 * independently.
 */
readonly final class PdfCmykConverter
{
    public function __construct(
        private IccColorTransform $icc,
    ) {
    }

    /**
     * The state of one export: its colour table and its converted pictures.
     *
     * @param array<string, CmykColor> $brandColors lowercase `#rrggbb` → the palette's CMYK
     * @param string $directory an existing directory for work files
     */
    public function pass(array $brandColors, string $directory): CmykPass
    {
        return new CmykPass(new CmykColorRules($this->icc, $brandColors), $directory);
    }

    /** The profile the colours were converted to — the file's output intent. */
    public function outputProfile(): string
    {
        return $this->icc->outputProfile();
    }

    /**
     * @param null|CropMarkLines $marks drawn on every page
     *
     * @throws CmykConversionFailed something in the file has no conversion rule; the target is removed
     * @throws PdfStructureNotSupported the file is not built the way this class reads; the target is removed
     */
    public function convert(string $source, string $target, CmykPass $pass, null|CropMarkLines $marks = null): void
    {
        $reader = new PdfReader($source);
        $writer = new PdfWriter($target);

        try {
            (new CmykChunkConversion($reader, $writer, $pass, $this->icc, $marks))->run();
        } catch (\Throwable $failure) {
            $writer->abort();
            @unlink($target);

            throw $failure;
        } finally {
            $pass->endChunk();
        }
    }
}
