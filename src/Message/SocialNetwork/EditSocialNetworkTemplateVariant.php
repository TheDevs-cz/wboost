<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Persists the variant's `backgroundImage` path. Two mutually-exclusive
 * paths to set it:
 *   - Upload a new file (`backgroundImage`): handler stores it under
 *     social-networks/{variantId}/background-{ts}.{ext} and saves that path.
 *     This is the legacy flow; kept for any caller still posting raw uploads.
 *   - Reference an existing FileUpload by path (`backgroundImagePath`): used
 *     by the Stage 7 image gallery, where the user picks an asset that was
 *     already uploaded via project_upload_file. The handler just writes the
 *     path through to the entity, no filesystem work.
 *
 * If both are null the message is a no-op (used to be impossible because the
 * old form always carried a file or nothing; now also possible if the gallery
 * picks no asset). The handler short-circuits in that case.
 */
readonly final class EditSocialNetworkTemplateVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public null|UploadedFile $backgroundImage,
        public null|string $backgroundImagePath = null,
    ) {
    }
}
