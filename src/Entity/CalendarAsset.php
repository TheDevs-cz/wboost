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
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarAssetKind;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;

/**
 * A file uploaded into a calendar (photo, logo, icon, QR code, header /
 * background picture, special-page graphic). The calendar's layout and
 * content JSON refer to it by `id`; the file lives under
 * `calendars/{calendarId}/{id}.{ext}` (docs/calendar/architecture.md §C.1,
 * §C.7). Never deleted on its own — it goes with the calendar.
 */
#[Entity]
#[Table(name: 'calendar_asset')]
#[Index(name: 'idx_calendar_asset_calendar', columns: ['calendar_id'])]
class CalendarAsset
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Calendar $calendar,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: CalendarAssetKind::class)]
        public CalendarAssetKind $kind,

        #[Immutable]
        #[Column(length: 255)]
        public string $path,

        /** A derivative with the long edge ≤ 1600 px, only for larger rasters. */
        #[Immutable]
        #[Column(length: 255, nullable: true)]
        public null|string $previewPath,

        #[Immutable]
        #[Column(length: 64)]
        public string $mimeType,

        #[Immutable]
        #[Column(length: 255)]
        public string $originalName,

        /** Pixels of the stored file; null only for SVG. */
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $width,

        #[Immutable]
        #[Column(nullable: true)]
        public null|int $height,

        /** The caption embedded in the upload (EXIF ImageDescription / XMP dc:description). */
        #[Immutable]
        #[Column(length: 255, nullable: true)]
        public null|string $description,

        #[Immutable]
        #[Column]
        public int $sizeBytes,

        #[Immutable]
        #[Column(type: 'string', length: 16, enumType: CalendarAuthorKind::class)]
        public CalendarAuthorKind $uploadedByKind,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|User $uploadedBy,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }
}
