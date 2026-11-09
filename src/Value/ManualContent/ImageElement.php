<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * A picture on a content page. P1 stores the storage key (`path`) only;
 * `galleryImageId` is reserved for P3, when the pictures move into the
 * project gallery and the path becomes a snapshot of the gallery row's key.
 * Every path written here must be enumerated by BuildStorageReferenceIndex.
 */
readonly final class ImageElement implements ContentElement
{
    public const string TYPE = 'image';
    public const int MAX_ALT_LENGTH = 300;

    /**
     * Bare storage key in a namespace the app writes — never a URL, never
     * a traversal. Which manual it belongs to is checked by the save handler.
     */
    private const string PATH_PATTERN = '~^manuals/[0-9a-f-]{36}/[A-Za-z0-9._/-]{1,200}$~';

    public function __construct(
        public string $id,
        public string $path,
        public ContentImageFit $fit,
        public string $alt = '',
        public null|string $galleryImageId = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    public static function fromArray(array $data): self
    {
        $id = ContentElements::id($data);
        $path = $data['path'] ?? null;
        $fit = is_string($data['fit'] ?? null) ? ContentImageFit::tryFrom($data['fit']) : null;
        $alt = $data['alt'] ?? '';
        $galleryImageId = $data['galleryImageId'] ?? null;

        if (!self::isValidPath($path)) {
            throw new InvalidManualContent('Image path is not a manual storage key.');
        }

        if ($fit === null) {
            throw new InvalidManualContent('Image fit must be natural or cover.');
        }

        if (!is_string($alt)) {
            throw new InvalidManualContent('Image alt must be a string.');
        }

        $alt = ContentElements::plainLine($alt);

        if (mb_strlen($alt) > self::MAX_ALT_LENGTH) {
            throw new InvalidManualContent('Image alt is too long.');
        }

        if ($galleryImageId !== null && (!is_string($galleryImageId) || !Uuid::isValid($galleryImageId))) {
            throw new InvalidManualContent('Gallery image id must be a UUID.');
        }

        /** @var string $path */
        return new self($id, $path, $fit, $alt, $galleryImageId);
    }

    public static function isValidPath(mixed $path): bool
    {
        return is_string($path)
            && preg_match(self::PATH_PATTERN, $path) === 1
            && !str_contains($path, '..')
            && !str_contains($path, '//');
    }

    public function belongsToManual(string $manualId): bool
    {
        return str_starts_with($this->path, 'manuals/' . strtolower($manualId) . '/');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function withPath(string $path): self
    {
        return new self($this->id, $path, $this->fit, $this->alt, $this->galleryImageId);
    }

    /**
     * @return array{id: string, type: string, path: string, galleryImageId: null|string, fit: string, alt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => self::TYPE,
            'path' => $this->path,
            'galleryImageId' => $this->galleryImageId,
            'fit' => $this->fit->value,
            'alt' => $this->alt,
        ];
    }
}
