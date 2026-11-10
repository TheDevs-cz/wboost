<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * A content page's whole-page background picture
 * (`manual_content_page.background`, JSONB, null = none). Same picture
 * contract as {@see ImageElement}: the gallery row (`galleryImageId`) plus a
 * snapshot of its storage key (`path`, a bare `manuals/…` / `file-upload/…`
 * key) — the save handler re-resolves the id and checks the key belongs to
 * the manual / project. Every path written here must be enumerated by
 * BuildStorageReferenceIndex (`manual_content_page.background`).
 */
readonly final class ContentPageBackground
{
    public function __construct(
        public string $path,
        public ContentBackgroundFit $fit = ContentBackgroundFit::Cover,
        public null|string $galleryImageId = null,
    ) {
    }

    /**
     * @throws InvalidManualContent
     */
    public static function fromArray(mixed $data): null|self
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidManualContent('The page background must be an object.');
        }

        $path = $data['path'] ?? null;
        $fit = is_string($data['fit'] ?? null) ? ContentBackgroundFit::tryFrom($data['fit']) : null;
        $galleryImageId = $data['galleryImageId'] ?? null;

        if (!ImageElement::isValidPath($path)) {
            throw new InvalidManualContent('Background path is not a manual storage key.');
        }

        if ($fit === null) {
            throw new InvalidManualContent('Background fit must be cover or contain.');
        }

        if ($galleryImageId !== null && (!is_string($galleryImageId) || !Uuid::isValid($galleryImageId))) {
            throw new InvalidManualContent('Gallery image id must be a UUID.');
        }

        /** @var string $path */
        return new self($path, $fit, $galleryImageId);
    }

    public function withGalleryImage(null|string $galleryImageId, string $path): self
    {
        return new self($path, $this->fit, $galleryImageId);
    }

    /**
     * @return array{path: string, galleryImageId: null|string, fit: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'galleryImageId' => $this->galleryImageId,
            'fit' => $this->fit->value,
        ];
    }
}
