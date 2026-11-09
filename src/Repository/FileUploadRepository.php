<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Value\FileSource;

readonly final class FileUploadRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FileUploadNotFound
     */
    public function get(UuidInterface $fileId): FileUpload
    {
        $file = $this->entityManager->find(FileUpload::class, $fileId);

        if ($file instanceof FileUpload) {
            return $file;
        }

        throw new FileUploadNotFound();
    }

    public function add(FileUpload $project): void
    {
        $this->entityManager->persist($project);
    }

    public function remove(FileUpload $file): void
    {
        $this->entityManager->remove($file);
    }

    /**
     * Returns all LIVE (non-trashed) FileUploads for a given project + source,
     * newest first. Powers the project image gallery (Stage 7).
     *
     * @return list<FileUpload>
     */
    public function listByProjectAndSource(UuidInterface $projectId, FileSource $source): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('f.uploadedAt', 'DESC');

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Number of LIVE (non-trashed) FileUploads for a project + source, without
     * hydrating the rows. Powers the gallery tile on the project dashboard.
     */
    public function countByProjectAndSource(UuidInterface $projectId, FileSource $source): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(f.id)')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * LIVE (non-trashed) FileUploads for a project + source that live directly
     * inside the given directory (or at the gallery root when `$directory` is
     * null), newest first. Powers one level of the nested gallery tree
     * (Stage 8). The trashed filter is load-bearing at the ROOT level: a
     * trashed file detaches to `directory = NULL`, which without the filter
     * would surface every bin entry as a root file.
     *
     * @return list<FileUpload>
     */
    public function listByProjectSourceAndDirectory(
        UuidInterface $projectId,
        FileSource $source,
        null|FileDirectory $directory,
    ): array {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('f.uploadedAt', 'DESC');

        if ($directory === null) {
            $qb->andWhere('f.directory IS NULL');
        } else {
            $qb->andWhere('IDENTITY(f.directory) = :directoryId')
                ->setParameter('directoryId', $directory->id);
        }

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * FileUploads for a project + source sitting in ANY of the given
     * directories — plus, with `$includeRoot`, files in NO directory (the
     * gallery root) — newest first. Powers the per-placeholder gallery — a slot
     * only offers images from the folders the designer allowed for it; an
     * UNRESTRICTED slot (empty allow-list) also pulls from the root. An empty
     * id list without `$includeRoot` yields an empty result (no allowed
     * folders → nothing to pick).
     *
     * @param list<UuidInterface> $directoryIds
     * @return list<FileUpload>
     */
    public function listByProjectSourceAndDirectories(
        UuidInterface $projectId,
        FileSource $source,
        array $directoryIds,
        bool $includeRoot = false,
    ): array {
        if ($directoryIds === [] && !$includeRoot) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('f.uploadedAt', 'DESC');

        if ($directoryIds === []) {
            $qb->andWhere('f.directory IS NULL');
        } else {
            $directoryCondition = 'IDENTITY(f.directory) IN (:directoryIds)';
            if ($includeRoot) {
                $directoryCondition = '(' . $directoryCondition . ' OR f.directory IS NULL)';
            }

            $qb->andWhere($directoryCondition)
                ->setParameter('directoryIds', array_map(static fn (UuidInterface $id): string => $id->toString(), $directoryIds));
        }

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Every FileUpload sitting directly inside the given directory, regardless
     * of source. Used to check whether a folder is empty before allowing its
     * deletion (a non-empty folder is refused). Trashed files never block a
     * folder delete — trashing detaches them (`directory = NULL`), so they
     * cannot appear here; their `restoreDirectory` FK is SET NULL.
     *
     * @return list<FileUpload>
     */
    public function listByDirectory(FileDirectory $directory): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.directory) = :directoryId')
            ->setParameter('directoryId', $directory->id);

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Bin contents for a project + source, closest-to-purge first.
     *
     * @return list<FileUpload>
     */
    public function listTrashed(UuidInterface $projectId, FileSource $source): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('f.deletedAt', 'ASC');

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /** Number of bin entries for a project + source (the Koš card badge). */
    public function countTrashed(UuidInterface $projectId, FileSource $source): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(f.id)')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->andWhere('f.source = :source')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Rows (across ALL projects, bin included) that have no recorded pixel size
     * and are not SVGs — the `app:gallery:backfill-image-size` work list. An SVG
     * never gets a size, so listing it would make every sweep report it again.
     *
     * @return list<FileUpload>
     */
    public function listMissingPixelSize(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('f.width IS NULL')
            ->andWhere("LOWER(f.path) NOT LIKE '%.svg'")
            ->orderBy('f.uploadedAt', 'ASC');

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Bin entries (across ALL projects) trashed on or before the given moment
     * — the purge cron's work list. Pass `now - FileUpload::TRASH_RETENTION_DAYS`.
     *
     * @return list<FileUpload>
     */
    public function listTrashedBefore(\DateTimeImmutable $deadline): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('f')
            ->from(FileUpload::class, 'f')
            ->where('f.deletedAt IS NOT NULL')
            ->andWhere('f.deletedAt <= :deadline')
            ->setParameter('deadline', $deadline);

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function find(UuidInterface $fileId): null|FileUpload
    {
        $file = $this->entityManager->find(FileUpload::class, $fileId);

        return $file instanceof FileUpload ? $file : null;
    }

    /**
     * The gallery row (live OR trashed) of a project that references this
     * storage key — how a manual image is matched to its gallery picture.
     */
    public function findByProjectAndPath(UuidInterface $projectId, string $path): null|FileUpload
    {
        $file = $this->entityManager->getRepository(FileUpload::class)->findOneBy([
            'project' => $projectId,
            'path' => $path,
        ]);

        return $file instanceof FileUpload ? $file : null;
    }

    /**
     * Rows (any project, live or trashed) other than $file that reference the
     * same storage key. A gallery row created over an existing key (the
     * manual import) may share it — purging one must not delete the object
     * the other still shows.
     */
    public function countOthersWithPath(FileUpload $file): int
    {
        /** @var int|string $count */
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(FileUpload::class, 'f')
            ->where('f.path = :path')
            ->andWhere('f.id <> :id')
            ->setParameter('path', $file->path)
            ->setParameter('id', $file->id)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Rows of one project (live AND trashed) whose id or path is in the
     * given sets — the availability lookup behind a manual's images.
     *
     * @param list<string> $ids
     * @param list<string> $paths
     * @return list<FileUpload>
     */
    public function listByProjectIdsOrPaths(UuidInterface $projectId, array $ids, array $paths): array
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)));

        if ($ids === [] && $paths === []) {
            return [];
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(FileUpload::class, 'f')
            ->where('IDENTITY(f.project) = :projectId')
            ->setParameter('projectId', $projectId);

        $or = [];

        if ($ids !== []) {
            $or[] = 'f.id IN (:ids)';
            $qb->setParameter('ids', $ids);
        }

        if ($paths !== []) {
            $or[] = 'f.path IN (:paths)';
            $qb->setParameter('paths', $paths);
        }

        $qb->andWhere(implode(' OR ', $or));

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
