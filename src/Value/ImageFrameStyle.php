<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Styl vrstvy" › an image's Zaoblení rohů + Tah — the custom canvas props
 * `frameRadius` (px) and `frameBorder` (`{color, width}`, px), drawn along the
 * VISIBLE frame (a fillable slot's frame, whatever picture fills it) by the
 * classic-script patch `assets/editor/fabric_image_frame.js` on all three
 * surfaces. The border is an INSIDE stroke (Photoshop's Tah › Uvnitř).
 */
final readonly class ImageFrameStyle
{
    public const float MAX_PX = 2000.0;

    public function __construct(
        public float $radius = 0.0,
        /** Lowercase `#rrggbb`, or null = no border. */
        public null|string $borderColor = null,
        public float $borderWidth = 0.0,
    ) {
    }

    /**
     * @param array<array-key, mixed> $object a decoded canvas object
     */
    public static function fromObject(array $object): self
    {
        $radius = $object['frameRadius'] ?? null;
        $border = $object['frameBorder'] ?? null;
        $color = is_array($border) && is_string($border['color'] ?? null) ? RichText::normalizeHexColor($border['color']) : null;
        $width = is_array($border) && is_numeric($border['width'] ?? null) ? (float) $border['width'] : 0.0;

        return new self(
            radius: is_numeric($radius) ? max(0.0, (float) $radius) : 0.0,
            borderColor: $color !== null && $width > 0.0 ? $color : null,
            borderWidth: $color !== null && $width > 0.0 ? $width : 0.0,
        );
    }

    public function isEmpty(): bool
    {
        return $this->radius <= 0.0 && !$this->hasBorder();
    }

    public function hasBorder(): bool
    {
        return $this->borderColor !== null && $this->borderWidth > 0.0;
    }

    /**
     * The canvas custom props (only the set ones).
     *
     * @return array{frameRadius?: float, frameBorder?: array{color: string, width: float}}
     */
    public function toCanvas(): array
    {
        $props = [];

        if ($this->radius > 0.0) {
            $props['frameRadius'] = $this->radius;
        }

        if ($this->borderColor !== null && $this->borderWidth > 0.0) {
            $props['frameBorder'] = ['color' => $this->borderColor, 'width' => $this->borderWidth];
        }

        return $props;
    }
}
