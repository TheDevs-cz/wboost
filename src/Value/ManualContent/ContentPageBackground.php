<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Exceptions\InvalidManualContent;

/**
 * A content page's whole-page background (`manual_content_page.background`,
 * JSONB, null = "Žádné"). One of three kinds ({@see ContentBackgroundKind}):
 *
 * - `color` — one palette colour (`#rrggbb`);
 * - `gradient` — two palette colours, linear (`angle` 0–359°) or radial
 *   (`center`, a 3 × 3 point);
 * - `image` — a picture, same contract as {@see ImageElement}: the gallery row
 *   (`galleryImageId`) plus a snapshot of its storage key (`path`, a bare
 *   `manuals/…` / `file-upload/…` key) — the save handler re-resolves the id
 *   and checks the key belongs to the manual / project. Every path written
 *   here must be enumerated by BuildStorageReferenceIndex
 *   (`manual_content_page.background`, it reads `->>'path'`, so colour /
 *   gradient rows have none).
 *
 * Rows stored before 2026-11-11 have no `type` key and are pictures. Only
 * the fields of the kind are kept (canonical JSON). Whether the colours are
 * in the project palette is the save handler's call (the palette changes).
 */
readonly final class ContentPageBackground
{
    private function __construct(
        public ContentBackgroundKind $kind,
        public null|string $path = null,
        public ContentBackgroundFit $fit = ContentBackgroundFit::Cover,
        public null|string $galleryImageId = null,
        public null|string $color = null,
        public ContentGradientShape $shape = ContentGradientShape::Linear,
        public null|string $from = null,
        public null|string $to = null,
        public int $angle = 135,
        public ContentGradientCenter $center = ContentGradientCenter::Center,
    ) {
    }

    public static function image(string $path, ContentBackgroundFit $fit = ContentBackgroundFit::Cover, null|string $galleryImageId = null): self
    {
        return new self(ContentBackgroundKind::Image, path: $path, fit: $fit, galleryImageId: $galleryImageId);
    }

    public static function color(string $color): self
    {
        return new self(ContentBackgroundKind::Color, color: RichTextDoc::normalizeColor($color) ?? throw new \InvalidArgumentException('Not a #rrggbb colour.'));
    }

    public static function gradient(ContentGradientShape $shape, string $from, string $to, int $angle = 135, ContentGradientCenter $center = ContentGradientCenter::Center): self
    {
        return new self(
            ContentBackgroundKind::Gradient,
            shape: $shape,
            from: RichTextDoc::normalizeColor($from) ?? throw new \InvalidArgumentException('Not a #rrggbb colour.'),
            to: RichTextDoc::normalizeColor($to) ?? throw new \InvalidArgumentException('Not a #rrggbb colour.'),
            angle: (($angle % 360) + 360) % 360,
            center: $center,
        );
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

        $kind = ContentBackgroundKind::Image;

        if (array_key_exists('type', $data)) {
            $kind = is_string($data['type']) ? ContentBackgroundKind::tryFrom($data['type']) : null;

            if ($kind === null) {
                throw new InvalidManualContent('Background type must be color, gradient or image.');
            }
        }

        return match ($kind) {
            ContentBackgroundKind::Image => self::imageFromArray($data),
            ContentBackgroundKind::Color => self::color(self::colorField($data, 'color')),
            ContentBackgroundKind::Gradient => self::gradientFromArray($data),
        };
    }

    public function isImage(): bool
    {
        return $this->kind === ContentBackgroundKind::Image && $this->path !== null;
    }

    public function withGalleryImage(null|string $galleryImageId, string $path): self
    {
        return self::image($path, $this->fit, $galleryImageId);
    }

    /**
     * The colours this background paints with (empty for a picture).
     *
     * @return list<string>
     */
    public function colors(): array
    {
        return match ($this->kind) {
            ContentBackgroundKind::Color => [(string) $this->color],
            ContentBackgroundKind::Gradient => [(string) $this->from, (string) $this->to],
            ContentBackgroundKind::Image => [],
        };
    }

    /**
     * Inline CSS for a colour / gradient background (null for a picture —
     * its URL is resolved by the template). Every value comes from the
     * validated enums / normalised `#rrggbb`, nothing is user text.
     */
    public function paintCss(): null|string
    {
        return match ($this->kind) {
            ContentBackgroundKind::Color => 'background-color: ' . $this->color,
            ContentBackgroundKind::Gradient => $this->shape === ContentGradientShape::Linear
                ? sprintf('background-image: linear-gradient(%ddeg, %s, %s)', $this->angle, $this->from, $this->to)
                : sprintf('background-image: radial-gradient(circle farthest-corner at %s, %s, %s)', $this->center->cssPosition(), $this->from, $this->to),
            ContentBackgroundKind::Image => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->kind) {
            ContentBackgroundKind::Image => [
                'type' => 'image',
                'path' => $this->path,
                'galleryImageId' => $this->galleryImageId,
                'fit' => $this->fit->value,
            ],
            ContentBackgroundKind::Color => ['type' => 'color', 'color' => $this->color],
            ContentBackgroundKind::Gradient => [
                'type' => 'gradient',
                'shape' => $this->shape->value,
                'from' => $this->from,
                'to' => $this->to,
                'angle' => $this->angle,
                'center' => $this->center->value,
            ],
        };
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    private static function imageFromArray(array $data): self
    {
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
        return self::image($path, $fit, $galleryImageId);
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    private static function gradientFromArray(array $data): self
    {
        $shape = is_string($data['shape'] ?? null) ? ContentGradientShape::tryFrom($data['shape']) : null;

        if ($shape === null) {
            throw new InvalidManualContent('Gradient shape must be linear or radial.');
        }

        $angle = $data['angle'] ?? 135;

        if (!is_int($angle) || $angle < 0 || $angle > 359) {
            throw new InvalidManualContent('Gradient angle must be a whole number of degrees 0–359.');
        }

        $center = ContentGradientCenter::Center;

        if (array_key_exists('center', $data)) {
            $center = is_string($data['center']) ? ContentGradientCenter::tryFrom($data['center']) : null;

            if ($center === null) {
                throw new InvalidManualContent('Gradient centre is not one of the nine positions.');
            }
        }

        return self::gradient($shape, self::colorField($data, 'from'), self::colorField($data, 'to'), $angle, $center);
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManualContent
     */
    private static function colorField(array $data, string $key): string
    {
        return RichTextDoc::normalizeColor($data[$key] ?? null)
            ?? throw new InvalidManualContent(sprintf('Background "%s" must be a #rrggbb colour.', $key));
    }
}
