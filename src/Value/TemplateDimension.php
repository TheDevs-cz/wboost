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
 *
 * It also carries the variant's print setup for the PDF export: a bleed
 * ("spadávka") in millimetres and whether crop marks are drawn. Both are
 * optional (0 / false = plain trim-size PDF) and exist only for PRINT
 * formats (mm / cm) — a px canvas is a screen format: no PDF export, and
 * {@see withPrintSettings()} keeps it without a print setup. The bleed is
 * NOT part of the canvas: canvas coordinates stay trim-relative, the bleed
 * is extra canvas area around them (negative / past-edge coordinates)
 * that only the print render and the editor's bleed zone show.
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

        #[Column(options: ['default' => 0])]
        readonly public float $bleedMm = 0.0,

        #[Column(options: ['default' => false])]
        readonly public bool $cropMarks = false,
    ) {
    }

    public function withPrintSettings(float $bleedMm, bool $cropMarks): self
    {
        if (!$this->isPrintFormat()) {
            return new self($this->unit, $this->unitWidth, $this->unitHeight, $this->preset);
        }

        return new self($this->unit, $this->unitWidth, $this->unitHeight, $this->preset, max(0.0, $bleedMm), $cropMarks);
    }

    /**
     * Physical units = a print format: the PDF export, bleed and crop marks
     * exist only for these.
     */
    /**
     * Same physical size (unit + both sides) — what a multi-page document
     * matches its pages on. The preset and the print setup are ignored: an
     * A4 page is an A4 page whatever its bleed.
     */
    public function hasSameSizeAs(self $other): bool
    {
        return $this->unit === $other->unit
            && abs($this->unitWidth - $other->unitWidth) < 0.001
            && abs($this->unitHeight - $other->unitHeight) < 0.001;
    }

    public function isPrintFormat(): bool
    {
        return $this->unit !== DimensionUnit::Px;
    }

    public function hasBleed(): bool
    {
        return $this->bleedPixels() > 0;
    }

    /**
     * Bleed on each side in canvas pixels (300 DPI, like every physical size).
     */
    public function bleedPixels(): int
    {
        return (int) round($this->bleedMm / 25.4 * DimensionUnit::PRINT_DPI);
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
