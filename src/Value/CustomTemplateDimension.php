<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;

/**
 * Free-form custom-template dimension chosen by the designer in px / mm / cm.
 *
 * Exposes the same `width()` / `height()` pixel accessors as
 * {@see TemplateDimension}, so the canvas editor and the render pipeline
 * consume both interchangeably. Physical units are rasterized at
 * {@see DimensionUnit::PRINT_DPI} (print quality).
 */
#[Embeddable]
final class CustomTemplateDimension
{
    /**
     * The size properties are deliberately NOT named `width`/`height`: Twig
     * resolves `dimension.width` to a public property before the `width()`
     * method, and the shared editor/render templates rely on `dimension.width`
     * meaning PIXELS for both this VO and the TemplateDimension enum.
     */
    public function __construct(
        #[Column]
        readonly public DimensionUnit $unit,

        #[Column]
        readonly public float $unitWidth,

        #[Column]
        readonly public float $unitHeight,
    ) {
    }

    /**
     * Canvas width in pixels.
     */
    public function width(): int
    {
        return $this->unit->toPixels($this->unitWidth);
    }

    /**
     * Canvas height in pixels.
     */
    public function height(): int
    {
        return $this->unit->toPixels($this->unitHeight);
    }

    public function label(): string
    {
        return sprintf(
            '%s × %s %s',
            self::formatNumber($this->unitWidth),
            self::formatNumber($this->unitHeight),
            $this->unit->value,
        );
    }

    private static function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
