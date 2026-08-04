<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

final readonly class TemplateVariantResponse
{
    /**
     * Template dimensions are either free-form (the designer chose a unit +
     * size, with physical units rasterized at 300 DPI) or a social-network
     * preset: `dimension` is the human label ("210 × 297 mm", or the ratio
     * "1:1" / "4:5" / "9:16" for preset variants), `preset` carries the preset
     * marker (null for free-form), `unit`/`unitWidth`/`unitHeight` carry the
     * exact size, and `width`/`height` are the resulting canvas pixels —
     * the coordinate space of image-input frames and export offsets.
     *
     * @param list<TemplateVariantInputResponse> $inputs
     * @param list<TemplateVariantImageInputResponse> $imageInputs
     * @param list<TemplateVariantContainerResponse> $containers
     */
    public function __construct(
        public string $id,
        public string $dimension,
        public null|string $preset,
        public string $unit,
        public float $unitWidth,
        public float $unitHeight,
        public int $width,
        public int $height,
        public null|string $previewImageUrl,
        public null|string $backgroundImageUrl,
        // Thumbnail served from the API host (preview render, or background as a
        // fallback). Consumers should use this instead of the store URLs above so
        // they never need to reach the object store directly.
        public string $thumbnailUrl,
        public string $exportUrl,
        public array $inputs,
        public array $imageInputs,
        public array $containers = [],
        // Fonts + brand color swatches for rich-text inputs. Null unless at
        // least one input has `richText: true`.
        public null|RichTextOptionsResponse $richTextOptions = null,
    ) {
    }
}
