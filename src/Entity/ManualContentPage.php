<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\ManualContentSegmentsDoctrineType;
use WBoost\Web\Value\ManualContent\ContentElement;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\ManualContent\HeadingElement;
use WBoost\Web\Value\MockupPageLayout;

/**
 * An editable page of a manual built from elements (heading, rich text,
 * image) laid out on the mockup grid's segments — see
 * `docs/architecture/manuals.md` "Manual content pages". The intro (role
 * `intro`) is the first page and exists at most once per manual (partial
 * unique index). Custom pages (role `custom`, P2) share ONE position space
 * with the manual's mockup pages (`GetManualPageList`, `SortManualPages`);
 * the intro is not part of it — it always stays first (position 0).
 *
 * `name` is the plain page title printed in the manual's page heading row
 * (number · manual · NAME) and in listings — separate from the content.
 */
#[Entity]
#[Index(name: 'idx_manual_content_page_manual', columns: ['manual_id'])]
#[UniqueConstraint(name: 'uniq_manual_content_page_intro', columns: ['manual_id'], options: ['where' => "((role)::text = 'intro'::text)"])]
class ManualContentPage
{
    public const int MAX_NAME_LENGTH = 255;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;

    /**
     * @param list<ContentSegment> $segments
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(fetch: 'EXTRA_LAZY', inversedBy: 'contentPages')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Manual $manual,

        #[Column(length: 16)]
        readonly public ManualContentPageRole $role,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16)]
        public MockupPageLayout $layout,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: ManualContentSegmentsDoctrineType::NAME, options: ['default' => '[]'])]
        public array $segments,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => 0])]
        public int $position,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
        $this->segments = self::fitSegments($layout, $segments);
    }

    /**
     * @param list<ContentSegment> $segments
     */
    public function edit(string $name, MockupPageLayout $layout, array $segments, DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->layout = $layout;
        $this->segments = self::fitSegments($layout, $segments);
        $this->updatedAt = $now;
    }

    public function rename(string $name, DateTimeImmutable $now): void
    {
        $this->name = $name;
        $this->updatedAt = $now;
    }

    public function isIntro(): bool
    {
        return $this->role === ManualContentPageRole::Intro;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    /**
     * The segment that fills slot $index of the layout (always present —
     * {@see fitSegments()} keeps one per slot).
     */
    public function segment(int $index): ContentSegment
    {
        return $this->segments[$index] ?? new ContentSegment();
    }

    /**
     * "No content → no page": a page with no element at all is not rendered
     * in the manual (and does not take a page number).
     */
    public function hasContent(): bool
    {
        return $this->hasContentShowing(static fn (ImageElement $image): bool => true);
    }

    /**
     * Same, but an image counts only when $imageShows says so — readers get
     * nothing for a picture in the gallery Koš / without its gallery row, so
     * a page holding only such pictures is empty FOR THEM (content pages P4).
     *
     * @param callable(ImageElement): bool $imageShows
     */
    public function hasContentShowing(callable $imageShows): bool
    {
        foreach ($this->segments as $segment) {
            foreach ($segment->elements as $element) {
                if ($element instanceof ImageElement) {
                    if ($imageShows($element)) {
                        return true;
                    }

                    continue;
                }

                if ($element instanceof HeadingElement && $element->text !== '') {
                    return true;
                }

                if ($element instanceof RichTextElement && !$element->doc->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Points every image element whose key is in $galleryIdsByPath at that
     * gallery row (content pages P3 import). A data link, not an edit — the
     * rendered page is unchanged, so `updatedAt` stays. Returns how many
     * elements changed.
     *
     * @param array<string, string> $galleryIdsByPath storage key → FileUpload id
     */
    public function linkGalleryImages(array $galleryIdsByPath): int
    {
        $changed = 0;
        $segments = [];

        foreach ($this->segments as $segment) {
            $segments[] = $segment->withElements(array_map(
                static function (ContentElement $element) use ($galleryIdsByPath, &$changed): ContentElement {
                    if (!$element instanceof ImageElement) {
                        return $element;
                    }

                    $galleryId = $galleryIdsByPath[$element->path] ?? null;

                    if ($galleryId === null || $element->galleryImageId === $galleryId) {
                        return $element;
                    }

                    $changed++;

                    return $element->withGalleryImage($galleryId, $element->path);
                },
                $segment->elements,
            ));
        }

        if ($changed > 0) {
            $this->segments = $segments;
        }

        return $changed;
    }

    /**
     * @return list<ImageElement> every image element, in reading order
     */
    public function imageElements(): array
    {
        $images = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->elements as $element) {
                if ($element instanceof ImageElement) {
                    $images[] = $element;
                }
            }
        }

        return $images;
    }

    /**
     * @return list<string> storage keys of every image element
     */
    public function imagePaths(): array
    {
        $paths = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->elements as $element) {
                if ($element instanceof ImageElement) {
                    $paths[] = $element->path;
                }
            }
        }

        return $paths;
    }

    /**
     * Exactly one segment per layout slot: missing ones are padded empty, and
     * the elements of segments beyond the layout's slot count are moved into
     * the last slot instead of being dropped (a layout switch never loses
     * content).
     *
     * @param list<ContentSegment> $segments
     * @return list<ContentSegment>
     */
    public static function fitSegments(MockupPageLayout $layout, array $segments): array
    {
        $count = count($layout->slots());
        $fitted = array_slice($segments, 0, $count);

        while (count($fitted) < $count) {
            $fitted[] = new ContentSegment();
        }

        /** @var list<ContentElement> $overflow */
        $overflow = [];

        foreach (array_slice($segments, $count) as $segment) {
            array_push($overflow, ...$segment->elements);
        }

        $last = $overflow !== [] ? array_pop($fitted) : null;

        if ($last !== null) {
            $fitted[] = $last->withElements([...$last->elements, ...$overflow]);
        }

        return $fitted;
    }
}
