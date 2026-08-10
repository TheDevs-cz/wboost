<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class GroupVariantSelection
{
    public function __construct(
        public TemplateDimension $dimension,
        // Project-gallery FileUpload id (see ResolveGalleryBackground). Null
        // when the group is created from an existing template — the handler
        // then copies the source variant's background instead.
        public null|string $backgroundImageId,
    ) {
    }
}
