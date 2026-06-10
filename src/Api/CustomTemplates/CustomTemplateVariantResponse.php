<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

final readonly class CustomTemplateVariantResponse
{
    /**
     * CustomTemplate dimensions are free-form (the designer chose a unit + size, with
     * physical units rasterized at 300 DPI): `dimension` is the human label
     * ("210 × 297 mm"), `unit`/`unitWidth`/`unitHeight` carry the designer's
     * exact choice, and `width`/`height` are the resulting canvas pixels —
     * the coordinate space of image-input frames and export offsets.
     *
     * @param list<CustomTemplateVariantInputResponse> $inputs
     * @param list<CustomTemplateVariantImageInputResponse> $imageInputs
     */
    public function __construct(
        public string $id,
        public string $dimension,
        public string $unit,
        public float $unitWidth,
        public float $unitHeight,
        public int $width,
        public int $height,
        public null|string $previewImageUrl,
        public string $backgroundImageUrl,
        // Thumbnail served from the API host (preview render, or background as a
        // fallback). Consumers should use this instead of the store URLs above so
        // they never need to reach the object store directly.
        public string $thumbnailUrl,
        public string $exportUrl,
        public array $inputs,
        public array $imageInputs,
    ) {
    }
}
