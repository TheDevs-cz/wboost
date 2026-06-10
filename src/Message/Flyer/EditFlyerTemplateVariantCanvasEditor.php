<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

readonly final class EditFlyerTemplateVariantCanvasEditor
{
    /**
     * @param array<EditorTextInput> $inputs
     * @param array<EditorImageInput> $imageInputs
     * @param string $previewImageDataUri Base64 PNG data URI captured client-side
     *                                    by `canvas.toDataURL()`. The handler
     *                                    decodes and uploads it to Minio.
     */
    public function __construct(
        public UuidInterface $variantId,
        public string $canvas,
        public array $inputs,
        public array $imageInputs,
        public string $previewImageDataUri,
    ) {
    }
}
