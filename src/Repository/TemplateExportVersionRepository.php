<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
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
     * An existing EXPORTED version of the SAME subject (variant or group) with
     * the same canonical fill — the row a re-export bumps instead of
     * duplicating. Drafts never match: they are saved work, not exports.
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
            ->andWhere('version.draft = false')
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
     * The draft `$versionId` — but only when it IS a draft of THIS subject
     * (the id is client-supplied: a loaded export, a foreign or a pruned id
     * all mean "no draft", i.e. a save starts a new one).
     */
    public function findDraftOf(
        string $versionId,
        null|UuidInterface $variantId,
        null|UuidInterface $groupId,
    ): null|TemplateExportVersion {
        if (!Uuid::isValid($versionId)) {
            return null;
        }

        $version = $this->find(Uuid::fromString($versionId));

        if ($version === null || !$version->draft) {
            return null;
        }

        $sameSubject = $variantId !== null
            ? $version->variant?->id->equals($variantId) === true
            : ($groupId !== null && $version->group?->id->equals($groupId) === true);

        return $sameSubject ? $version : null;
    }

    public function remove(TemplateExportVersion $version): void
    {
        $this->entityManager->remove($version);
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
