<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * A vector shape the designer opened up for RECOLOURING — the third fillable
 * kind next to {@see TemplateVariantInputResponse} (text) and
 * {@see TemplateVariantImageInputResponse} (images). The consumer repaints it
 * by sending `shapeColors: { "<id>": "#rrggbb" }` on preview / export.
 */
final readonly class TemplateVariantShapeInputResponse
{
    /**
     * @param list<string> $colorOptions lowercase `#rrggbb` swatches to offer:
     *        `defaultColor` first, then the admin's list. Without `freeColor`
     *        a pick outside this list is a 400 `shape_color_not_allowed`.
     */
    public function __construct(
        public string $id,
        /** The admin's layer name — label the field with it (null = unnamed). */
        public null|string $name,
        /**
         * What one pick repaints: `fill`, `stroke` (the border) or `both`
         * (fill and border in the same colour).
         */
        public string $target,
        /** The designed colour of the repainted part — the "reset" swatch. */
        public string $defaultColor,
        public array $colorOptions,
        /** True = any hex colour is accepted; `colorOptions` are suggestions. */
        public bool $freeColor,
        /** The shape's bounding box in canvas px (null when it has no usable size). */
        public null|TemplateVariantImageInputFrameResponse $frame,
        /** Stacking position on the canvas — the same index space as `inputs[].layerIndex`. */
        public int $layerIndex,
    ) {
    }
}
