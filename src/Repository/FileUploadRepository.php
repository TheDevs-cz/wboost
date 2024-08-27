<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Exceptions\FileUploadNotFound;

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
}
