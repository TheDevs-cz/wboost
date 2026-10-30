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
     * @param list<TemplateVariantShapeInputResponse> $shapeInputs
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
        // True when this variant is a member dimension of the template's
        // group (`TemplateResponse::$group`). A variant added by hand to a
        // grouped template is NOT a member: fill it on its own via
        // `/api/template-variants/{id}/…`.
        public bool $groupMember = false,
        // Shapes the user may recolour (`shapeColors` on preview / export).
        // Empty when the designer opened none up.
        public array $shapeInputs = [],
        // Print format (mm / cm): the variant also exports a print PDF
        // (`format: "pdf"` on export). False for px (screen) formats.
        public bool $printFormat = false,
        // Print setup of the PDF: bleed per side in mm (0 = none) and whether
        // crop marks are drawn. Always 0 / false for screen formats. The
        // bleed is drawn past the canvas edges — canvas coordinates stay the
        // trim's, so the background slot's frame starts at -bleed px.
        public float $bleedMm = 0.0,
        public bool $cropMarks = false,
    ) {
    }
}
