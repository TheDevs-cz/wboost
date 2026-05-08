<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\EditorTextInput;

readonly final class EditSocialNetworkTemplateVariantCanvasEditor
{
    /**
     * @param string $previewImageDataUri Base64 PNG data URI captured client-side
     *                                    by `canvas.toDataURL()`. The handler
     *                                    decodes and uploads it to Minio.
     */
    public function __construct(
        public UuidInterface $variantId,
        public string $canvas,
        /** @var array<EditorTextInput> */
        public array $inputs,
        public string $previewImageDataUri,
    ) {
    }
}
