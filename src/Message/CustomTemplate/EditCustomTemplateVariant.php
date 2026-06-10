<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Persists the variant's `backgroundImage` path. Two mutually-exclusive
 * paths to set it:
 *   - Upload a new file (`backgroundImage`): handler stores it under
 *     custom-templates/{variantId}/background-{ts}.{ext} and saves that path.
 *   - Reference an existing FileUpload by path (`backgroundImagePath`): used
 *     by the image gallery, where the user picks an asset that was already
 *     uploaded via project_upload_file. The handler just writes the path
 *     through to the entity, no filesystem work.
 *
 * If both are null the message is a no-op; the handler short-circuits.
 */
readonly final class EditCustomTemplateVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public null|UploadedFile $backgroundImage,
        public null|string $backgroundImagePath = null,
    ) {
    }
}
