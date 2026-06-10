<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

/**
 * A fillable IMAGE placeholder on a custom-template variant — the image counterpart of
 * {@see CustomTemplateVariantInputResponse}. The consumer picks a gallery image
 * (list them via
 * `GET /api/custom-template-variants/{variantId}/placeholders/{id}/images`)
 * and references it by id in the export `images` map, optionally positioning it
 * within the limits below.
 */
final readonly class CustomTemplateVariantImageInputResponse
{
    /**
     * @param list<string> $allowedDirectoryIds gallery folder ids this slot may pull from;
     *        an empty list means UNRESTRICTED — every gallery folder in the project is
     *        offered (use `GET …/placeholders/{id}/images` to list the actual pickable images)
     */
    public function __construct(
        public string $id,
        public null|string $name,
        public null|string $description,
        public bool $allowMove,
        public bool $allowResize,
        public bool $allowRotate,
        public bool $hidable,
        public array $allowedDirectoryIds,
        public null|CustomTemplateVariantImageInputFrameResponse $frame,
        public null|string $defaultImageUrl,
    ) {
    }
}
