<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum DimensionUnit: string
{
    case Px = 'px';
    case Mm = 'mm';
    case Cm = 'cm';

    /**
     * Print resolution used to rasterize physical units onto the Fabric canvas.
     */
    public const int PRINT_DPI = 300;

    public function toPixels(float $value): int
    {
        return match($this) {
            self::Px => (int) round($value),
            self::Mm => (int) round($value / 25.4 * self::PRINT_DPI),
            self::Cm => (int) round($value * 10 / 25.4 * self::PRINT_DPI),
        };
    }

    public function label(): string
    {
        return $this->value;
    }
}
