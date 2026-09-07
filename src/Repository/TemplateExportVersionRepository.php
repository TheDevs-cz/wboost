<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Exceptions\TemplateExportVersionNotFound;

readonly final class TemplateExportVersionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(TemplateExportVersion $version): void
    {
        $this->entityManager->persist($version);
    }

    public function find(UuidInterface $versionId): null|TemplateExportVersion
    {
        return $this->entityManager->find(TemplateExportVersion::class, $versionId);
    }

    /**
     * @throws TemplateExportVersionNotFound
     */
    public function get(UuidInterface $versionId): TemplateExportVersion
    {
        return $this->find($versionId) ?? throw new TemplateExportVersionNotFound();
    }

    /**
     * An existing version of the SAME subject (variant or group) with the same
     * canonical fill — the row a re-export bumps instead of duplicating.
     */
    public function findDuplicate(
        null|UuidInterface $variantId,
        null|UuidInterface $groupId,
        string $fillValuesHash,
    ): null|TemplateExportVersion {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(TemplateExportVersion::class, 'version')
            ->select('version')
            ->where('version.fillValuesHash = :hash')
            ->setParameter('hash', $fillValuesHash)
            ->setMaxResults(1);

        if ($variantId !== null) {
            $builder->andWhere('version.variant = :variantId')
                ->setParameter('variantId', $variantId->toString());
        } else {
            $builder->andWhere('version.group = :groupId')
                ->setParameter('groupId', $groupId?->toString());
        }

        /** @var null|TemplateExportVersion */
        return $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * Drop the subject's oldest UNPINNED versions beyond `$keep` — called just
     * before a NEW version row is added (so pass the cap minus one), keeping
     * the history bounded per fill surface. Pinned versions are outside the
     * cap altogether: pinning is the user's promise that this one stays.
     */
    public function prune(
        null|UuidInterface $variantId,
        null|UuidInterface $groupId,
        int $keep,
    ): void {
        $builder = $this->entityManager->createQueryBuilder()
            ->from(TemplateExportVersion::class, 'version')
            ->select('version')
            ->where('version.pinnedAt IS NULL')
            ->orderBy('version.lastExportedAt', 'DESC')
            ->addOrderBy('version.id', 'DESC')
            ->setFirstResult($keep);

        if ($variantId !== null) {
            $builder->andWhere('version.variant = :variantId')
                ->setParameter('variantId', $variantId->toString());
        } else {
            $builder->andWhere('version.group = :groupId')
                ->setParameter('groupId', $groupId?->toString());
        }

        /** @var list<TemplateExportVersion> $stale */
        $stale = $builder->getQuery()->getResult();

        foreach ($stale as $version) {
            $this->entityManager->remove($version);
        }
    }
}
