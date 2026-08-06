<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The `canvas` block: the pixel size the design is authored against, plus the
 * optional canvas-level background shorthand.
 *
 * {@see $width} / {@see $height} are **canvas pixels** — the same numbers
 * `TemplateDimension::width()` / `height()` return, so a print variant is
 * authored at its 300-DPI raster size (A4 = 2480 × 3508), never in mm.
 *
 * The background shorthand is flattened from `canvas.background {image, fill}`
 * because two nullables inside a nullable buy nothing:
 * - {@see $backgroundImageAssetId} is the gallery image the compiler turns
 *   into the layer-mode background object (plan §4.3). Declaring it here is a
 *   convenience alternative to a `kind: "background"` element — never both,
 *   since both compile to the same stack-index-0 layer.
 * - {@see $backgroundFill} is a flat canvas colour, normalized to lowercase
 *   `#rrggbb`. It composes fine WITH a background element (a transparent PNG
 *   over a colour), which is why only the image half conflicts.
 */
readonly final class CanvasSpec
{
    /** Guard against a canvas nothing can render; A3 @ 300 DPI is 3508 × 4961. */
    public const int MAX_SIDE = 20000;

    public function __construct(
        public int $width,
        public int $height,
        public null|string $backgroundImageAssetId = null,
        public null|string $backgroundFill = null,
    ) {
    }

    /**
     * @return array{width: int, height: int, background: null|array{image: null|string, fill: null|string}}
     */
    public function toArray(): array
    {
        $background = null;

        if ($this->backgroundImageAssetId !== null || $this->backgroundFill !== null) {
            $background = [
                'image' => $this->backgroundImageAssetId,
                'fill' => $this->backgroundFill,
            ];
        }

        return [
            'width' => $this->width,
            'height' => $this->height,
            'background' => $background,
        ];
    }
}
