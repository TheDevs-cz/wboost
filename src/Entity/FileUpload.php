<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\FileSource;

#[Entity]
class FileUpload
{
    /** Days a trashed image survives in the bin before the purge cron removes it for good. */
    public const int TRASH_RETENTION_DAYS = 7;

    /**
     * When the image was moved to the trash bin, or null for a live image.
     * A trashed image is invisible to every consumer surface (gallery browse,
     * placeholder pick lists, fill/export validation) and is purged — row AND
     * storage object — once the retention window passes.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|\DateTimeImmutable $deletedAt = null;

    /**
     * The folder the image lived in when it was trashed, so restore can put it
     * back. Trashing DETACHES the file from its folder (the folder becomes
     * deletable — the bin is its own place); `SET NULL` means a folder deleted
     * in the meantime degrades a restore to the gallery root.
     */
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|FileDirectory $restoreDirectory = null;

    /**
     * Pixel size of the stored picture — both set or both null. Null means
     * "unknown": an upload from before 2026-09 (backfilled lazily by the
     * gallery listing / `app:gallery:backfill-image-size` on first sight), an
     * SVG (a vector has no pixel size), or bytes that could not be read.
     * Recorded at upload from the normalized bytes, so it describes what is
     * actually stored (an iPhone HEIC transcoded to JPEG reports the JPEG's
     * size, EXIF orientation already baked in).
     */
    #[Column(nullable: true)]
    public null|int $width = null;

    #[Column(nullable: true)]
    public null|int $height = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $uploadedAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        readonly public FileSource $source,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        readonly public string $path,

        /**
         * Virtual folder this upload lives in, or `null` for the gallery root.
         * Mutable: the gallery's "move to folder" action re-points it. A folder
         * can only be deleted once empty (the delete handler refuses non-empty
         * folders), so files are never orphaned by a folder delete in normal
         * use; the DB-side `SET NULL` is just a defensive fallback that drops a
         * file to the root rather than cascading it away.
         */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|FileDirectory $directory = null,

        /**
         * The file name the picture was uploaded under ("pozadi-modre.png"),
         * the one thing a user recognises a picture by when two thumbnails
         * look alike. Purely a label: the stored object is named after the
         * row (`path`), and the extension here may disagree with the stored
         * format (an "IMG_4821.HEIC" is stored as a JPEG). Null for uploads
         * from before it was recorded (2026-09) — there is nothing to
         * backfill it from.
         */
        #[Immutable]
        #[Column(nullable: true)]
        readonly public null|string $originalName = null,
        null|int $width = null,
        null|int $height = null,
    ) {
        if ($width !== null && $height !== null) {
            $this->recordPixelSize($width, $height);
        }
    }

    /** Both dimensions are recorded together; a lone axis is not a size. */
    public function recordPixelSize(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            return;
        }

        $this->width = $width;
        $this->height = $height;
    }

    public function hasPixelSize(): bool
    {
        return $this->width !== null && $this->height !== null;
    }

    /** Lower-case extension of the STORED object — the format of the bytes. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function isSvg(): bool
    {
        return $this->extension() === 'svg';
    }

    public function moveToDirectory(null|FileDirectory $directory): void
    {
        $this->directory = $directory;
    }

    public function moveToTrash(\DateTimeImmutable $now): void
    {
        if ($this->deletedAt !== null) {
            return;
        }

        $this->deletedAt = $now;
        $this->restoreDirectory = $this->directory;
        $this->directory = null;
    }

    public function restoreFromTrash(): void
    {
        if ($this->deletedAt === null) {
            return;
        }

        // A folder deleted while the file sat in the bin came back as null
        // (SET NULL) — the restore then lands at the gallery root.
        $this->directory = $this->restoreDirectory;
        $this->deletedAt = null;
        $this->restoreDirectory = null;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt !== null;
    }

    /** When the purge cron will remove this image for good; null for live images. */
    public function purgeAt(): null|\DateTimeImmutable
    {
        return $this->deletedAt?->modify(sprintf('+%d days', self::TRASH_RETENTION_DAYS));
    }
}
