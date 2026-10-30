<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Exceptions\TemplateNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class TemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TemplateNotFound
     */
    public function get(UuidInterface $templateId): Template
    {
        $template = $this->entityManager->find(Template::class, $templateId);

        if ($template instanceof Template) {
            return $template;
        }

        throw new TemplateNotFound();
    }

    public function add(Template $template): void
    {
        $this->entityManager->persist($template);
    }

    public function remove(Template $template): void
    {
        $this->entityManager->remove($template);
    }

    /**
     * Where a new listing card goes: last. Templates and multi-page documents
     * share ONE sort space per category on the listing, so both count.
     */
    public function nextPosition(UuidInterface $projectId): int
    {
        $documents = $this->entityManager->createQueryBuilder()
            ->select('COUNT(document)')
            ->from(TemplateDocument::class, 'document')
            ->where('document.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($documents));

        return $this->count($projectId) + $documents;
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(template)')
            ->from(Template::class, 'template')
            ->where('template.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
