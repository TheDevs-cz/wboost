<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Value\StorageCategory;

/**
 * The individual-file view of the storage inventory, behind the admin file
 * list. Filterable by project, category and "unreferenced only", because the
 * whole point of listing 600+ objects is being able to narrow to the ones that
 * are actually a problem.
 */
readonly final class GetStorageFiles
{
    public const int PER_PAGE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function page(
        null|string $projectId = null,
        null|bool $orphaned = null,
        null|StorageCategory $category = null,
        null|string $search = null,
        int $page = 1,
    ): StorageFilesPage {
        $connection = $this->entityManager->getConnection();

        $conditions = [];
        $parameters = [];

        if ($projectId === 'none') {
            // The un-billable leftovers: bytes whose owning project is gone.
            $conditions[] = 'project_id IS NULL';
        } elseif ($projectId !== null && $projectId !== '') {
            $conditions[] = 'project_id = :projectId';
            $parameters['projectId'] = $projectId;
        }

        if ($orphaned !== null) {
            $conditions[] = $orphaned ? 'orphaned = true' : 'orphaned = false';
        }

        if ($category !== null) {
            $conditions[] = 'category = :category';
            $parameters['category'] = $category->value;
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = 'path ILIKE :search';
            $parameters['search'] = '%' . trim($search) . '%';
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $totals = $connection
            ->executeQuery("SELECT COUNT(*) AS total_count, COALESCE(SUM(size), 0) AS total_size FROM storage_object {$where}", $parameters)
            ->fetchAssociative();

        $totalCount = $totals === false ? 0 : $this->asInt($totals['total_count']);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $connection->executeQuery(
            "SELECT path, size, last_modified_at, category, referenced_by, reference_count,
                    project_id, project_name, owner_email, orphaned
             FROM storage_object
             {$where}
             ORDER BY size DESC, path ASC
             LIMIT " . self::PER_PAGE . ' OFFSET ' . $offset,
            $parameters,
        )->fetchAllAssociative();

        return new StorageFilesPage(
            array_values(array_filter(array_map($this->toRow(...), $rows))),
            $totalCount,
            $totals === false ? 0 : $this->asInt($totals['total_size']),
            $page,
            self::PER_PAGE,
            $this->projects(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRow(array $row): null|StorageFileRow
    {
        $category = StorageCategory::tryFrom($this->asString($row['category']));

        if ($category === null) {
            return null;
        }

        $lastModifiedAt = $row['last_modified_at'];

        return new StorageFileRow(
            $this->asString($row['path']),
            $this->asInt($row['size']),
            is_string($lastModifiedAt) ? new DateTimeImmutable($lastModifiedAt) : null,
            $category,
            is_string($row['referenced_by']) ? $row['referenced_by'] : null,
            $this->asInt($row['reference_count']),
            is_string($row['project_id']) ? $row['project_id'] : null,
            is_string($row['project_name']) ? $row['project_name'] : null,
            is_string($row['owner_email']) ? $row['owner_email'] : null,
            (bool) $row['orphaned'],
        );
    }

    /**
     * Projects present in the inventory, for the filter dropdown — taken from
     * the inventory itself so the options can never offer an empty result.
     *
     * @return list<array{id: string, name: string}>
     */
    private function projects(): array
    {
        $rows = $this->entityManager->getConnection()->executeQuery(
            "SELECT project_id, (array_agg(project_name))[1] AS project_name
             FROM storage_object
             WHERE project_id IS NOT NULL
             GROUP BY project_id
             ORDER BY 2",
        )->fetchAllAssociative();

        $projects = [];

        foreach ($rows as $row) {
            $projects[] = [
                'id' => $this->asString($row['project_id']),
                'name' => $this->asString($row['project_name']),
            ];
        }

        return $projects;
    }

    private function asString(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }

    private function asInt(mixed $value): int
    {
        assert(is_numeric($value));

        return (int) $value;
    }
}
