<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;

readonly final class TemplateDocumentRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     */
    public function get(UuidInterface $documentId): TemplateDocument
    {
        $document = $this->entityManager->find(TemplateDocument::class, $documentId);

        if ($document instanceof TemplateDocument) {
            return $document;
        }

        throw new TemplateDocumentNotFound();
    }

    public function add(TemplateDocument $document): void
    {
        $this->entityManager->persist($document);
    }

    public function remove(TemplateDocument $document): void
    {
        $this->entityManager->remove($document);
    }

    /**
     * The page row of a template, whatever document it belongs to.
     */
    public function pageOfTemplate(UuidInterface $templateId): null|TemplateDocumentPage
    {
        $page = $this->entityManager->createQueryBuilder()
            ->select('page')
            ->from(TemplateDocumentPage::class, 'page')
            ->where('page.template = :templateId')
            ->setParameter('templateId', $templateId->toString())
            ->getQuery()
            ->getOneOrNullResult();

        return $page instanceof TemplateDocumentPage ? $page : null;
    }

    /**
     * The page row a variant is rendered by, if any.
     */
    public function pageOfVariant(UuidInterface $variantId): null|TemplateDocumentPage
    {
        $page = $this->entityManager->createQueryBuilder()
            ->select('page')
            ->from(TemplateDocumentPage::class, 'page')
            ->where('page.variant = :variantId')
            ->setParameter('variantId', $variantId->toString())
            ->getQuery()
            ->getOneOrNullResult();

        return $page instanceof TemplateDocumentPage ? $page : null;
    }
}
