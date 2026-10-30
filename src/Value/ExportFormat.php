<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * What an export hands the user: the lossless PNG (screen / social) or the
 * print PDF (PDF/X-1a, CMYK, bleed + crop marks per the variant's setup).
 */
enum ExportFormat: string
{
    case Png = 'png';
    case Pdf = 'pdf';

    /**
     * The fill forms post the pressed button's `format` value; anything else
     * (absent, unknown) is the historical PNG export.
     */
    public static function fromRequestValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower($value)) ?? self::Png) : self::Png;
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Png => 'image/png',
            self::Pdf => 'application/pdf',
        };
    }
}
