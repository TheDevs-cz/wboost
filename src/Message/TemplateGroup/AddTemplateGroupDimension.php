<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\TemplateDimension;

readonly final class AddTemplateGroupDimension
{
    /**
     * @param null|string $backgroundImageId Project-gallery FileUpload id
     *   (see ResolveGalleryBackground). Null inherits the group's existing
     *   background picture, cover-fitted for the new dimension.
     */
    public function __construct(
        public UuidInterface $groupId,
        public UuidInterface $variantId,
        public TemplateDimension $dimension,
        public null|string $backgroundImageId,
    ) {
    }
}
