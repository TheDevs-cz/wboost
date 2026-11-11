<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `upload_calendar_asset` answer: the stored file, described exactly as
 * `get_calendar` assets describes it from now on.
 */
readonly final class UploadCalendarAssetResponse
{
    public function __construct(
        public string $calendarId,
        /** The id to put where an asset is expected (pagePhotos.asset, events.icon, styles.logo.asset…). */
        public string $assetId,
        /** "photo" | "logo" | "icon" | "qr" | "image" | "page". */
        public string $kind,
        /** Public URL of the stored file — for showing a human, not for fetching back. */
        public string $url,
        /** A smaller copy (long edge 1600 px) when the picture is larger than that; null otherwise. */
        public null|string $previewUrl,
        /** Pixel size of what was STORED (a phone photo is rotated upright, a PDF page rasterised); null for an SVG. */
        public null|int $width,
        public null|int $height,
        public string $mime,
        public string $name,
        /** The caption embedded in the file (EXIF / XMP), when it has one — a ready photo caption. */
        public null|string $description,
        /** Uploading changes nothing in the calendar: this says where to reference the id. */
        public string $next,
    ) {
    }
}
