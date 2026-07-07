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
     * @param list<PlaceholderDirectoryResponse> $directories the RESOLVED folders (with
     *        names) — `allowedDirectoryIds` expanded/intersected with the project's real
     *        folders. Together with the root (when `includesRoot`) these are the valid
     *        `directoryId` upload targets for this slot — let the user choose one.
     *        A restricted slot with several folders REQUIRES `directoryId` on upload;
     *        an unrestricted slot defaults to the gallery root (omit `directoryId`).
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
        public array $directories,
        public bool $includesRoot,
        public null|SocialNetworkTemplateVariantImageInputFrameResponse $frame,
        public null|string $defaultImageUrl,
        /**
         * Stacking position of this placeholder's object on the variant canvas
         * (0 = backmost, higher = painted on top). Shares one index space with
         * `inputs[].layerIndex` — sort both arrays together by this value to
         * rebuild the design's layer stack. Null when the object cannot be
         * located on the canvas.
         */
        public null|int $layerIndex = null,
    ) {
    }
}
