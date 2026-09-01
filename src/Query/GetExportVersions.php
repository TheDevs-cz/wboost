<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateExportVersion;

/**
 * Read side of the export history ("Historie exportů"): freshest-first
 * version lists for the two fill surfaces, and "latest export per …" lookups
 * for the listing pages.
 */
readonly final class GetExportVersions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<TemplateExportVersion>
     */
    public function forVariant(UuidInterface $variantId, int $limit = 15): array
    {
        return $this->history('version.variant = :subjectId', $variantId, $limit);
    }

    /**
     * @return list<TemplateExportVersion>
     */
    public function forGroup(UuidInterface $groupId, int $limit = 15): array
    {
        return $this->history('version.group = :subjectId', $groupId, $limit);
    }

    /**
     * The freshest version per template of one project — the listing cards'
     * "naposledy exportováno" line. Keyed by template id (string).
     *
     * @return array<string, array{versionId: string, variantId: null|string, groupId: null|string, lastExportedAt: DateTimeImmutable}>
     */
    public function latestForProjectTemplates(UuidInterface $projectId): array
    {
        $sql = <<<SQL
            SELECT DISTINCT ON (version.template_id)
              version.template_id,
              version.id,
              version.variant_id,
              version.group_id,
              version.last_exported_at
            FROM template_export_version version
            JOIN template ON template.id = version.template_id
            WHERE template.project_id = :projectId
            ORDER BY version.template_id, version.last_exported_at DESC
        SQL;

        /** @var list<array{template_id: string, id: string, variant_id: null|string, group_id: null|string, last_exported_at: string}> $rows */
        $rows = $this->entityManager->getConnection()
            ->fetchAllAssociative($sql, ['projectId' => $projectId->toString()]);

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row['template_id']] = [
                'versionId' => $row['id'],
                'variantId' => $row['variant_id'],
                'groupId' => $row['group_id'],
                'lastExportedAt' => new DateTimeImmutable($row['last_exported_at']),
            ];
        }

        return $latest;
    }

    /**
     * The freshest version per VARIANT of one template — the variant listing's
     * per-row "naposledy exportováno" link. Keyed by variant id (string).
     *
     * @return array<string, array{versionId: string, lastExportedAt: DateTimeImmutable}>
     */
    public function latestForTemplateVariants(UuidInterface $templateId): array
    {
        $sql = <<<SQL
            SELECT DISTINCT ON (variant_id) variant_id, id, last_exported_at
            FROM template_export_version
            WHERE template_id = :templateId AND variant_id IS NOT NULL
            ORDER BY variant_id, last_exported_at DESC
        SQL;

        /** @var list<array{variant_id: string, id: string, last_exported_at: string}> $rows */
        $rows = $this->entityManager->getConnection()
            ->fetchAllAssociative($sql, ['templateId' => $templateId->toString()]);

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row['variant_id']] = [
                'versionId' => $row['id'],
                'lastExportedAt' => new DateTimeImmutable($row['last_exported_at']),
            ];
        }

        return $latest;
    }

    /**
     * @return list<TemplateExportVersion>
     */
    private function history(string $subjectCondition, UuidInterface $subjectId, int $limit): array
    {
        /** @var list<TemplateExportVersion> */
        return $this->entityManager->createQueryBuilder()
            ->from(TemplateExportVersion::class, 'version')
            // Join-fetch the exporter: the history dropdown prints a name per
            // row, and 15 lazy loads per page view would be silly.
            ->select('version', 'exportedBy')
            ->leftJoin('version.exportedBy', 'exportedBy')
            ->where($subjectCondition)
            ->setParameter('subjectId', $subjectId->toString())
            ->orderBy('version.lastExportedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
