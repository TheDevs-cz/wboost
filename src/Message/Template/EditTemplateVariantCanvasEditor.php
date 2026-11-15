<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

readonly final class EditTemplateVariantCanvasEditor
{
    /**
     * @param array<EditorTextInput> $inputs
     * @param array<EditorImageInput> $imageInputs
     * @param string $previewImageDataUri Base64 PNG data URI captured client-side
     *                                    by `canvas.toDataURL()`. The handler
     *                                    decodes and uploads it to Minio. The
     *                                    legacy transport — still accepted
     *                                    from editor tabs opened before the
     *                                    binary upload shipped.
     * @param string $previewImageBytes The same thumbnail as raw PNG bytes
     *                                  (the editors upload it as a file since
     *                                  2026-11-15 — a third less to send than
     *                                  base64). Wins over the data URI; both
     *                                  empty = keep the stored thumbnail.
     */
    public function __construct(
        public UuidInterface $variantId,
        public string $canvas,
        public array $inputs,
        public array $imageInputs,
        public string $previewImageDataUri,
        public string $previewImageBytes = '',
    ) {
    }
}
