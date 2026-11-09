<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Value\FileSource;

readonly final class FileDirectoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FileDirectoryNotFound
     */
    public function get(UuidInterface $directoryId): FileDirectory
    {
        $directory = $this->entityManager->find(FileDirectory::class, $directoryId);

        if ($directory instanceof FileDirectory) {
            return $directory;
        }

        throw new FileDirectoryNotFound();
    }

    public function add(FileDirectory $directory): void
    {
        $this->entityManager->persist($directory);
    }

    public function remove(FileDirectory $directory): void
    {
        $this->entityManager->remove($directory);
    }

    /**
     * Every folder for a project + source, alphabetically. The caller assembles
     * the tree in PHP (e.g. for the "move to folder" picker). Cheap: a project
     * has at most a handful of folders.
     *
     * @return list<FileDirectory>
     */
    public function listAll(UuidInterface $projectId, FileSource $source): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
            ->from(FileDirectory::class, 'd')
            ->where('IDENTITY(d.project) = :projectId')
            ->andWhere('d.source = :source')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('d.name', 'ASC');

        /** @var list<FileDirectory> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Direct child folders of the given parent (or the project root when
     * `$parent` is null), alphabetically. Powers one level of the gallery tree.
     *
     * @return list<FileDirectory>
     */
    public function listChildren(UuidInterface $projectId, FileSource $source, null|FileDirectory $parent): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
            ->from(FileDirectory::class, 'd')
            ->where('IDENTITY(d.project) = :projectId')
            ->andWhere('d.source = :source')
            ->setParameter('projectId', $projectId)
            ->setParameter('source', $source->value)
            ->orderBy('d.name', 'ASC');

        if ($parent === null) {
            $qb->andWhere('d.parent IS NULL');
        } else {
            $qb->andWhere('IDENTITY(d.parent) = :parentId')
                ->setParameter('parentId', $parent->id);
        }

        /** @var list<FileDirectory> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * The app-maintained folder with this key (see FileDirectory::$systemKey).
     * Folders persisted but not flushed yet in this unit of work count too —
     * one handler may need the same folder several times before its flush
     * (FileSource has a single case, so the pending match skips it).
     */
    public function findBySystemKey(UuidInterface $projectId, FileSource $source, string $systemKey): null|FileDirectory
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof FileDirectory
                && $pending->systemKey === $systemKey
                && $pending->project->id->equals($projectId)
            ) {
                return $pending;
            }
        }

        $directory = $this->entityManager->getRepository(FileDirectory::class)->findOneBy([
            'project' => $projectId,
            'source' => $source,
            'systemKey' => $systemKey,
        ]);

        return $directory instanceof FileDirectory ? $directory : null;
    }
}
