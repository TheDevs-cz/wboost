<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\Calendar\CalendarDocument;
use WBoost\Web\Services\Image\ImageDecodeLimits;
use WBoost\Web\Services\Image\NormalizeImageFormat;
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
 * Two sources: a file from the user's disk ({@see self::hold()}) and a
 * picture of the project's gallery ({@see self::holdGalleryFile()}) — the
 * gallery file's BYTES are copied, so the proposal does not depend on what
 * happens to the gallery file later (Koš, purge) and nothing in the gallery
 * changes.
 *
 * JPEG, PNG, WebP — judged by their bytes — and SVG (logos in a gallery
 * usually are one, and a calendar takes an SVG logo). A held picture is
 * served from the application's own origin for the preview, so the image
 * route answers it with a sandboxing `Content-Security-Policy`: opened as a
 * document, an SVG runs no script there.
 */
final readonly class CalendarScanPictures
{
    private const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const string SVG = 'image/svg+xml';

    public function __construct(
        private Connection $connection,
        private AiScanStorage $storage,
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemReader $gallery,
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
     * A file from the user's disk.
     *
     * @throws AiScanRefused `invalid_fix` (`field`: `slot` | `size` | `type`), `wrong_state`
     */
    public function hold(AiScan $scan, string $slot, UploadedFile $file): void
    {
        if (!$file->isValid() || $file->getSize() > CalendarDocument::MAX_FILE_SIZE || $file->getSize() < 1) {
            $this->assertSlot($scan, $slot);

            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'size']);
        }

        $this->holdBytes($scan, $slot, $file->getContent(), $file->getClientOriginalName());
    }

    /**
     * A picture of the project's gallery: its bytes are copied to the scan.
     * A file of another project, a trashed one or one that is no gallery
     * picture is `invalid_fix` (`field: picture`) — as if it did not exist.
     *
     * @throws AiScanRefused `invalid_fix` (`field`: `slot` | `picture` | `size` | `type`), `wrong_state`
     */
    public function holdGalleryFile(AiScan $scan, string $slot, null|FileUpload $file): void
    {
        $this->assertSlot($scan, $slot);

        if ($file === null || $file->isTrashed() || !$file->source->isGalleryPicture() || !$file->project->id->equals($scan->project->id)) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'picture']);
        }

        try {
            if ($this->gallery->fileSize($file->path) > CalendarDocument::MAX_FILE_SIZE) {
                throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'size']);
            }

            $bytes = $this->gallery->read($file->path);
        } catch (FilesystemException) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'picture']);
        }

        $this->holdBytes($scan, $slot, $bytes, $file->originalName ?? basename($file->path));
    }

    /**
     * @throws AiScanRefused `invalid_fix` (`field`: `slot` | `size` | `type`), `wrong_state`
     */
    private function holdBytes(AiScan $scan, string $slot, string $bytes, string $originalName): void
    {
        $this->assertSlot($scan, $slot);

        if ($bytes === '' || strlen($bytes) > CalendarDocument::MAX_FILE_SIZE) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'size']);
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = is_string($type) ? (self::TYPES[$type] ?? null) : null;
        $size = null;

        if ($extension === null && NormalizeImageFormat::looksLikeSvg($bytes)) {
            // No pixel size: an SVG scales. Its bytes are never decoded here.
            [$type, $extension] = [self::SVG, 'svg'];
        } elseif ($extension === null) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'type']);
        } else {
            // The size the header announces — nothing is decoded here.
            $size = ImageDecodeLimits::pixelSize($bytes);

            if ($size === null || ImageDecodeLimits::exceeds($size[0], $size[1])) {
                throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => $size === null ? 'type' : 'size']);
            }
        }

        $name = sprintf('picture-%s.%s', $slot, $extension);
        $this->storage->write(self::directory($scan), $name, $bytes);

        $info = [
            'file' => $name,
            'name' => mb_substr(basename($originalName), 0, 120),
            'contentType' => $type,
            'bytes' => strlen($bytes),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
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
     * @throws AiScanRefused `invalid_fix` (`field: slot`)
     */
    private function assertSlot(AiScan $scan, string $slot): void
    {
        if (!in_array($slot, CalendarScanProposal::slots($scan), true)) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'slot']);
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
