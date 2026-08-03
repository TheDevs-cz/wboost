<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\StorageCategory;

/**
 * One row per object that actually exists in the S3/Minio bucket — the
 * inventory behind the admin storage report.
 *
 * This is a derived, disposable mirror of the bucket, not a source of truth:
 * {@see \WBoost\Web\Services\Storage\ScanStorage} rebuilds the whole table from
 * a bucket listing cross-referenced against every DB column that stores a path.
 * Nothing writes here at upload time, on purpose — an inventory built from what
 * the app *believes* it wrote could never surface the objects it forgot about,
 * and those are exactly what the report is for (deleting a project, manual or
 * template has never removed its files, and every `Edit*` handler that re-uploads
 * abandons the previous key).
 *
 * Owner / project labels are denormalised like {@see ExportEvent}: the report
 * aggregates without joins, and an orphan's project may not exist any more.
 * There are deliberately no FK associations.
 */
#[Entity]
#[Table(name: 'storage_object')]
#[Index(name: 'idx_storage_object_owner', columns: ['owner_id'])]
#[Index(name: 'idx_storage_object_project', columns: ['project_id'])]
#[Index(name: 'idx_storage_object_orphaned', columns: ['orphaned'])]
class StorageObject
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        /** Storage key relative to the bucket root, e.g. `file-upload/{projectId}/{fileId}.png`. */
        #[Column(length: 512, unique: true)]
        readonly public string $path,

        /**
         * Object size in bytes. INTEGER, not BIGINT: a single object is capped
         * far below 2 GB by the upload limits, and Doctrine hydrates BIGINT as
         * a string. Totals are summed in SQL, where the overflow headroom of a
         * 64-bit sum applies.
         */
        #[Column(type: Types::INTEGER)]
        readonly public int $size,

        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        readonly public null|DateTimeImmutable $lastModifiedAt,

        #[Column(type: 'string', enumType: StorageCategory::class)]
        readonly public StorageCategory $category,

        /**
         * The `table.column` that points at this object, e.g. `manual.logo` —
         * null when nothing references it (an orphan). When several rows share
         * one key (a copied template variant reuses its source's background
         * image rather than duplicating the bytes) this names the first one
         * found; {@see $referenceCount} carries how many there are.
         */
        #[Column(nullable: true)]
        readonly public null|string $referencedBy,

        #[Column(type: Types::INTEGER)]
        readonly public int $referenceCount,

        #[Column(type: UuidType::NAME, nullable: true)]
        readonly public null|UuidInterface $projectId,

        #[Column(nullable: true)]
        readonly public null|string $projectName,

        #[Column(type: UuidType::NAME, nullable: true)]
        readonly public null|UuidInterface $ownerId,

        #[Column(nullable: true)]
        readonly public null|string $ownerEmail,

        /**
         * No DB row references this key. Transient by-products
         * ({@see StorageCategory::isTransient()}) are never flagged — they are
         * unreferenced by design.
         */
        #[Column(type: Types::BOOLEAN)]
        readonly public bool $orphaned,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $scannedAt,

        /**
         * Identifies the scan run that last touched this row. What the scan
         * deletes by, instead of `scannedAt`: that column is TIMESTAMP(0), so
         * two runs within the same second would be indistinguishable and stale
         * rows would survive.
         */
        #[Column(type: UuidType::NAME)]
        readonly public UuidInterface $scanId,
    ) {
    }
}
