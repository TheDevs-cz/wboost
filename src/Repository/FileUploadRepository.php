<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
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

    /**
     * Returns all FileUploads for a given project + source, newest first.
     * Powers the project image gallery (Stage 7).
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
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('f.uploadedAt', 'DESC');

        /** @var list<FileUpload> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * FileUploads for a project + source that live directly inside the given
     * directory (or at the gallery root when `$directory` is null), newest
     * first. Powers one level of the nested gallery tree (Stage 8).
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
     * Every FileUpload sitting directly inside the given directory, regardless
     * of source. Used when a directory is deleted to lift its files up to the
     * parent instead of orphaning them.
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
}
