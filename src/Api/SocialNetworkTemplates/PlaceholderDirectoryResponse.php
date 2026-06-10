<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

/**
 * One gallery folder an image placeholder may be filled from / uploaded into.
 * Listed (already resolved — an empty designer allow-list expands to every
 * project folder) on {@see SocialNetworkTemplateVariantImageInputResponse}, so
 * a consumer can let the user choose the upload target folder.
 */
final readonly class PlaceholderDirectoryResponse
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
