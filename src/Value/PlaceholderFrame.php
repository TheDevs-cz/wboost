<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * The axis-aligned rectangle (in canvas pixel coordinates) an image placeholder
 * occupies — the designer's fixed "frame". The user's chosen picture is fitted
 * object-contain into this box, clipped to it, and may be panned / zoomed /
 * rotated within it. Derived from the Fabric placeholder object's displayed
 * bounding box by {@see \WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry}.
 */
readonly final class PlaceholderFrame
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }

    /**
     * The frame a BACKGROUND slot's fill covers: the whole canvas plus the
     * print bleed on every side (the same box the background layer itself
     * covers), so a user's picture reaches the cut line too.
     */
    public static function backgroundCover(TemplateDimension $dimension): self
    {
        $bleed = $dimension->bleedPixels();

        return new self(-$bleed, -$bleed, $dimension->width() + 2 * $bleed, $dimension->height() + 2 * $bleed);
    }

    public function centerX(): float
    {
        return $this->x + $this->width / 2;
    }

    public function centerY(): float
    {
        return $this->y + $this->height / 2;
    }
}
