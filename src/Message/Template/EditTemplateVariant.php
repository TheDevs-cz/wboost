<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Template;

use Ramsey\Uuid\UuidInterface;

/**
 * Persists the variant's `backgroundImage` path. Two mutually-exclusive
 * paths to set it, both referencing the project gallery:
 *   - By FileUpload id (`backgroundImageId`): the edit form's gallery picker
 *     submits the picked file's id; the handler resolves it (project +
 *     not-trashed guarded, see ResolveGalleryBackground) into its path.
 *   - By path (`backgroundImagePath`): the editor's "Pozadí" pick posts the
 *     chosen asset's path directly. The handler writes it through.
 *
 * If both are null the message is a no-op; the handler short-circuits.
 */
readonly final class EditTemplateVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public null|string $backgroundImageId,
        public null|string $backgroundImagePath = null,
    ) {
    }
}
