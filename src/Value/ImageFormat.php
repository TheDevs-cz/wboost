<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum ImageFormat: string
{
    case SVG = 'svg';
    case JPG = 'jpg';
    case PNG = 'png';

    public function contentType(): string
    {
        return match ($this) {
            self::SVG => 'image/svg+xml',
            self::JPG => 'image/jpeg',
            self::PNG => 'image/png',
        };
    }
}
