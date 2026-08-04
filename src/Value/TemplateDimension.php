<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;

/**
 * A template variant's dimension: either free-form px / mm / cm chosen by the
 * designer, or one of the social-network {@see DimensionPreset}s (which is
 * just a px size plus the preset marker — the marker drives labels and the
 * social publish flow, nothing else).
 *
 * Physical units are rasterized at {@see DimensionUnit::PRINT_DPI}
 * (print quality).
 */
#[Embeddable]
final class TemplateDimension
{
    /**
     * The size properties are deliberately NOT named `width`/`height`: Twig
     * resolves `dimension.width` to a public property before the `width()`
     * method, and the shared editor/render templates rely on `dimension.width`
     * meaning PIXELS.
     */
    public function __construct(
        #[Column]
        readonly public DimensionUnit $unit,

        #[Column]
        readonly public float $unitWidth,

        #[Column]
        readonly public float $unitHeight,

        #[Column(nullable: true)]
        readonly public null|DimensionPreset $preset = null,
    ) {
    }

    public static function fromPreset(DimensionPreset $preset): self
    {
        return new self(DimensionUnit::Px, $preset->width(), $preset->height(), $preset);
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
        if ($this->preset !== null) {
            return $this->preset->value;
        }

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
