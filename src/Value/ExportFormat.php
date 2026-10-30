<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What an export hands the user: the lossless PNG (screen / social), the
 * print PDF (PDF/X-1a, CMYK, bleed + crop marks per the variant's setup) or
 * the screen PDF ("PDF k odeslání": sRGB JPEG pages, documents only).
 */
enum ExportFormat: string
{
    case Png = 'png';
    case Pdf = 'pdf';
    case PdfScreen = 'pdf-screen';

    /**
     * The fill forms post the pressed button's `format` value; anything else
     * (absent, unknown) is the historical PNG export.
     */
    public static function fromRequestValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower($value)) ?? self::Png) : self::Png;
    }

    /**
     * The single-variant / group exports (one file per variant) know only PNG
     * and the print PDF — the screen PDF is a document format. A
     * `pdf-screen` posted there is treated like any other unknown value:
     * the historical PNG export.
     */
    public static function fromVariantRequestValue(mixed $value): self
    {
        $format = self::fromRequestValue($value);

        return $format->isVariantFormat() ? $format : self::Png;
    }

    /**
     * Whether a single-variant export (web, API, MCP, group members) can
     * produce this format.
     */
    public function isVariantFormat(): bool
    {
        return match ($this) {
            self::Png, self::Pdf => true,
            self::PdfScreen => false,
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Pdf, self::PdfScreen => 'pdf',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Pdf, self::PdfScreen => 'application/pdf',
        };
    }
}
