<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Image\ImageDecodeLimits;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Pictures the user adds to a calendar proposal's places (logo, header
 * picture, background picture) BEFORE the layout is applied.
 *
 * They are **held with the scan** — in its private directory, named in
 * `ai_scan.pictures` — and become calendar assets only when the layout is
 * applied ({@see \WBoost\Web\MessageHandler\AiScan\AcceptCalendarScanHandler}).
 * Calendar assets can never be deleted, so nothing may become one for a
 * proposal that is then thrown away: discarding or expiring the scan deletes
 * the directory and with it every held picture.
 *
 * Raster pictures only (JPEG, PNG, WebP), judged by their bytes: a held
 * picture is served from the application's own origin for the preview, where
 * an SVG could run script.
 */
final readonly class CalendarScanPictures
{
    private const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private Connection $connection,
        private AiScanStorage $storage,
    ) {
    }

    /**
     * Slot → what is held for it.
     *
     * @return array<string, array{file: string, name: string, contentType: string, bytes: int, width: null|int, height: null|int}>
     */
    public static function held(AiScan $scan): array
    {
        $slots = is_array($scan->pictures['slots'] ?? null) ? $scan->pictures['slots'] : [];
        $held = [];

        foreach ($slots as $slot => $info) {
            if (!is_string($slot) || !isset(CalendarScanProposal::SLOTS[$slot]) || !is_array($info) || !is_string($info['file'] ?? null)) {
                continue;
            }

            $held[$slot] = [
                'file' => $info['file'],
                'name' => is_string($info['name'] ?? null) ? $info['name'] : $info['file'],
                'contentType' => is_string($info['contentType'] ?? null) ? $info['contentType'] : 'application/octet-stream',
                'bytes' => is_int($info['bytes'] ?? null) ? $info['bytes'] : 0,
                'width' => is_int($info['width'] ?? null) ? $info['width'] : null,
                'height' => is_int($info['height'] ?? null) ? $info['height'] : null,
            ];
        }

        return $held;
    }

    /**
     * @throws AiScanRefused `invalid_fix` (`field`: `slot` | `size` | `type`), `wrong_state`
     */
    public function hold(AiScan $scan, string $slot, UploadedFile $file): void
    {
        if (!in_array($slot, CalendarScanProposal::slots($scan), true)) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'slot']);
        }

        if (!$file->isValid() || $file->getSize() > CalendarDocument::MAX_FILE_SIZE || $file->getSize() < 1) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'size']);
        }

        $bytes = $file->getContent();
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = is_string($type) ? (self::TYPES[$type] ?? null) : null;

        if ($extension === null) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'type']);
        }

        // The size the header announces — nothing is decoded here.
        $size = ImageDecodeLimits::pixelSize($bytes);

        if ($size === null || ImageDecodeLimits::exceeds($size[0], $size[1])) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => $size === null ? 'type' : 'size']);
        }

        $name = sprintf('picture-%s.%s', $slot, $extension);
        $this->storage->write(self::directory($scan), $name, $bytes);

        $info = [
            'file' => $name,
            'name' => mb_substr(basename($file->getClientOriginalName()), 0, 120),
            'contentType' => $type,
            'bytes' => strlen($bytes),
            'width' => $size[0],
            'height' => $size[1],
        ];

        $stored = $this->connection->executeStatement(
            "UPDATE ai_scan
                SET pictures = jsonb_set(
                    CASE WHEN jsonb_typeof(pictures -> 'slots') = 'object' THEN pictures ELSE '{\"slots\": {}}'::jsonb END,
                    CAST(:path AS TEXT[]), CAST(:info AS JSONB))
              WHERE id = :id AND status = 'ready' AND kind = 'calendar'",
            ['id' => $scan->id->toString(), 'path' => sprintf('{slots,%s}', $slot), 'info' => json_encode($info, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );

        if ($stored !== 1) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }
    }

    /**
     * @throws AiScanRefused `wrong_state`
     */
    public function remove(AiScan $scan, string $slot): void
    {
        if (!isset(CalendarScanProposal::SLOTS[$slot])) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'slot']);
        }

        $removed = $this->connection->executeStatement(
            "UPDATE ai_scan SET pictures = pictures #- CAST(:path AS TEXT[])
              WHERE id = :id AND status = 'ready' AND kind = 'calendar' AND pictures IS NOT NULL",
            ['id' => $scan->id->toString(), 'path' => sprintf('{slots,%s}', $slot)],
        );

        if ($removed !== 1 && $scan->pictures !== null) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }
    }

    /**
     * @return null|array{bytes: string, contentType: string, name: string}
     */
    public function read(AiScan $scan, string $slot): null|array
    {
        $held = self::held($scan)[$slot] ?? null;

        if ($held === null) {
            return null;
        }

        $bytes = $this->storage->read(self::directory($scan), $held['file']);

        return $bytes === null ? null : ['bytes' => $bytes, 'contentType' => $held['contentType'], 'name' => $held['name']];
    }

    private static function directory(AiScan $scan): string
    {
        return AiScanStorage::directory($scan->project->id->toString(), $scan->id->toString(), $scan->storageSecret);
    }
}
