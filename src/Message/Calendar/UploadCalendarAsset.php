<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarAssetKind;

/**
 * Stores an uploaded calendar file. The bytes are already normalised (HEIC
 * & co. transcoded, a PDF page rasterised, SVG kept) and both paths already
 * carry the right extension: `calendars/{calendarId}/{assetId}.{ext}` and,
 * for a large raster, `…/{assetId}-preview.{ext}`. Uploading never changes
 * the calendar document — the client references the id and saves.
 */
readonly final class UploadCalendarAsset
{
    public function __construct(
        public UuidInterface $assetId,
        public UuidInterface $calendarId,
        public CalendarAssetKind $kind,
        public string $path,
        public string $contents,
        public null|string $previewPath,
        public null|string $previewContents,
        public string $mimeType,
        public string $originalName,
        public null|int $width,
        public null|int $height,
        public null|string $description,
        public CalendarActor $actor,
    ) {
    }
}
