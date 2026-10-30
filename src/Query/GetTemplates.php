<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocumentPage;

readonly final class GetTemplates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * `$excludeDocumentPages` leaves out templates attached to a multi-page
     * document — they are presented through their document, not on their own.
     *
     * @return array<Template>
     */
    public function allForProject(UuidInterface $projectId, bool $excludeDocumentPages = false): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Template::class, 'template')
            ->select('template')
            ->join('template.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('template.position');

        if ($excludeDocumentPages) {
            $query->andWhere(sprintf('NOT EXISTS (SELECT page.id FROM %s page WHERE page.template = template)', TemplateDocumentPage::class));
        }

        return $query->getQuery()->getResult();
    }

    /**
     * @return array<Template>
     */
    public function withoutCategoryForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Template::class, 'template')
            ->select('template')
            ->join('template.project', 'project')
            ->where('project.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->andWhere('template.category IS NULL')
            ->orderBy('template.position')
            ->getQuery()
            ->getResult();
    }
}
