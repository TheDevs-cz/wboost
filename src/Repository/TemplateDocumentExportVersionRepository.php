<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateDocumentExportVersion;

readonly final class TemplateDocumentExportVersionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(UuidInterface $id): null|TemplateDocumentExportVersion
    {
        return $this->entityManager->find(TemplateDocumentExportVersion::class, $id);
    }

    public function add(TemplateDocumentExportVersion $version): void
    {
        $this->entityManager->persist($version);
    }

    public function remove(TemplateDocumentExportVersion $version): void
    {
        $this->entityManager->remove($version);
    }

    /**
     * An EXPORTED version (never a draft) of the document with these values.
     */
    public function findDuplicate(UuidInterface $documentId, string $hash): null|TemplateDocumentExportVersion
    {
        $version = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(TemplateDocumentExportVersion::class, 'v')
            ->where('v.document = :document')
            ->andWhere('v.fillHash = :hash')
            ->andWhere('v.draft = false')
            ->setParameter('document', $documentId->toString())
            ->setParameter('hash', $hash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $version instanceof TemplateDocumentExportVersion ? $version : null;
    }

    /**
     * The draft a page loaded — only when it IS a draft of THIS document (a
     * loaded export, a foreign or pruned id start a new draft).
     */
    public function findDraftOf(null|string $versionId, UuidInterface $documentId): null|TemplateDocumentExportVersion
    {
        if ($versionId === null || !Uuid::isValid($versionId)) {
            return null;
        }

        $version = $this->find(Uuid::fromString($versionId));

        return $version !== null && $version->draft && $version->document->id->equals($documentId) ? $version : null;
    }

    /**
     * Pinned first (most recently pinned on top), then freshest.
     *
     * @return list<TemplateDocumentExportVersion>
     */
    public function forDocument(UuidInterface $documentId): array
    {
        /** @var list<TemplateDocumentExportVersion> $versions */
        $versions = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from(TemplateDocumentExportVersion::class, 'v')
            ->where('v.document = :document')
            ->setParameter('document', $documentId->toString())
            ->getQuery()
            ->getResult();

        usort($versions, static fn (TemplateDocumentExportVersion $a, TemplateDocumentExportVersion $b): int => [$b->pinnedAt !== null, $b->pinnedAt, $b->lastExportedAt] <=> [$a->pinnedAt !== null, $a->pinnedAt, $a->lastExportedAt]);

        return $versions;
    }

    /**
     * Keeps the newest `$keep` UNPINNED versions of the document.
     */
    public function prune(UuidInterface $documentId, int $keep): void
    {
        $unpinned = array_values(array_filter(
            $this->forDocument($documentId),
            static fn (TemplateDocumentExportVersion $version): bool => $version->pinnedAt === null,
        ));

        foreach (array_slice($unpinned, $keep) as $version) {
            $this->remove($version);
        }
    }
}
