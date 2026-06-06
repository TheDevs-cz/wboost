<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

/**
 * A fillable IMAGE placeholder on a variant — the image counterpart of
 * {@see SocialNetworkTemplateVariantInputResponse}. The consumer picks a
 * gallery image (list them via
 * `GET /api/social-network-template-variants/{variantId}/placeholders/{id}/images`)
 * and references it by id in the export `images` map, optionally positioning it
 * within the limits below.
 */
final readonly class SocialNetworkTemplateVariantImageInputResponse
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
        public null|SocialNetworkTemplateVariantImageInputFrameResponse $frame,
        public null|string $defaultImageUrl,
    ) {
    }
}
