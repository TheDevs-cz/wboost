<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Storage;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Value\StorageCategory;
use WBoost\Web\Value\StorageScanResult;

/**
 * Rebuilds the {@see \WBoost\Web\Entity\StorageObject} inventory: list the
 * whole bucket, cross-reference every object against every path-bearing column
 * in the database, and record what is still in use versus what nothing points
 * at any more.
 *
 * Writes are UPSERTs keyed by path followed by a delete of everything not
 * touched by this run, rather than a truncate-and-reinsert. That keeps the
 * report readable if a scan dies half-way through — the table then holds a mix
 * of fresh and stale rows instead of nothing at all — and avoids opening a
 * transaction around a listing whose duration is at the mercy of S3.
 *
 * The scan only ever READS from storage. Cleaning up orphans is deliberately
 * not automated: a copied template variant shares its source's background key
 * (see {@see \WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateVariantHandler}),
 * so "delete what looks unused" is a footgun that belongs in a human's hands.
 */
readonly final class ScanStorage
{
    /** Rows per multi-row INSERT. Keeps the statement well under the parameter limit. */
    private const int BATCH_SIZE = 100;

    public function __construct(
        private Filesystem $filesystem,
        private EntityManagerInterface $entityManager,
        private BuildStorageReferenceIndex $buildReferenceIndex,
        private ResolveStorageOwnerByPath $resolveOwnerByPath,
        private ClockInterface $clock,
    ) {
    }

    public function scan(): StorageScanResult
    {
        $scannedAt = $this->clock->now();
        $scanId = Uuid::uuid7()->toString();
        $references = $this->buildReferenceIndex->build();
        $entityOwners = $this->resolveOwnerByPath->buildIndex();

        $fileCount = 0;
        $totalSize = 0;
        $orphanCount = 0;
        $orphanSize = 0;
        $existingPaths = [];
        $batch = [];

        foreach ($this->filesystem->listContents('', true) as $attributes) {
            if (!$attributes instanceof FileAttributes) {
                continue;
            }

            $path = $attributes->path();
            $size = $attributes->fileSize() ?? 0;
            $category = StorageCategory::fromPath($path);
            $reference = $references->find($path);

            // Transient by-products (publish temp files, imagine thumbnails)
            // are unreferenced by design — flagging them as orphans would
            // drown the real leaks in noise.
            $orphaned = $reference === null && !$category->isTransient();
            $owner = $reference !== null
                ? $reference->owner
                : $this->resolveOwnerByPath->resolve($entityOwners, $path);

            $existingPaths[$path] = true;
            $fileCount++;
            $totalSize += $size;

            if ($orphaned) {
                $orphanCount++;
                $orphanSize += $size;
            }

            $batch[] = [
                Uuid::uuid7()->toString(),
                $path,
                $size,
                $this->lastModified($attributes),
                $category->value,
                $reference?->referencedBy,
                $references->countFor($path),
                $owner->projectId,
                $owner->projectName,
                $owner->ownerId,
                $owner->ownerEmail,
                $orphaned,
                $scannedAt->format('Y-m-d H:i:s'),
                $scanId,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insertBatch($batch);
        }

        $this->deleteVanished($scanId);

        return new StorageScanResult(
            $fileCount,
            $totalSize,
            $orphanCount,
            $orphanSize,
            $references->danglingAgainst($existingPaths),
            $scannedAt,
        );
    }

    /**
     * @param list<array{string, string, int, null|string, string, null|string, int, null|string, null|string, null|string, null|string, bool, string, string}> $batch
     */
    private function insertBatch(array $batch): void
    {
        $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'));

        $sql = <<<SQL
            INSERT INTO storage_object (
              id, path, size, last_modified_at, category, referenced_by, reference_count,
              project_id, project_name, owner_id, owner_email, orphaned, scanned_at, scan_id
            )
            VALUES {$placeholders}
            ON CONFLICT (path) DO UPDATE SET
              size = EXCLUDED.size,
              last_modified_at = EXCLUDED.last_modified_at,
              category = EXCLUDED.category,
              referenced_by = EXCLUDED.referenced_by,
              reference_count = EXCLUDED.reference_count,
              project_id = EXCLUDED.project_id,
              project_name = EXCLUDED.project_name,
              owner_id = EXCLUDED.owner_id,
              owner_email = EXCLUDED.owner_email,
              orphaned = EXCLUDED.orphaned,
              scanned_at = EXCLUDED.scanned_at,
              scan_id = EXCLUDED.scan_id
        SQL;

        $parameters = [];
        $types = [];

        foreach ($batch as $row) {
            foreach ($row as $value) {
                $parameters[] = $value;
                // DBAL binds untyped parameters as strings, and `false` would
                // reach Postgres as '' — which is not a valid boolean literal.
                $types[] = is_bool($value) ? ParameterType::BOOLEAN : ParameterType::STRING;
            }
        }

        $this->entityManager->getConnection()->executeStatement($sql, $parameters, $types);
    }

    /**
     * Objects that were in the inventory but are no longer in the bucket. They
     * are identified by not carrying THIS run's id rather than by a path diff,
     * so a listing that grew mid-scan cannot delete a live row — and, unlike a
     * `scanned_at` comparison, two scans inside the same second still tell each
     * other apart (the column is TIMESTAMP(0)).
     */
    private function deleteVanished(string $scanId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM storage_object WHERE scan_id <> ?',
            [$scanId],
        );
    }

    private function lastModified(FileAttributes $attributes): null|string
    {
        $timestamp = $attributes->lastModified();

        if ($timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s');
    }
}
