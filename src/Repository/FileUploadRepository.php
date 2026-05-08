<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
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
}
