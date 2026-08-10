<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\TemplateDimension;

readonly final class AddTemplateVariant
{
    /**
     * @param null|string $backgroundImageId Project-gallery FileUpload id the
     *   add forms submit (the background is picked from / uploaded into the
     *   gallery, never a raw file upload) — resolved and referenced by path,
     *   see ResolveGalleryBackground.
     */
    public function __construct(
        public UuidInterface $templateId,
        public UuidInterface $variantId,
        public TemplateDimension $dimension,
        public null|string $backgroundImageId,
    ) {
    }
}
