<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Value\TemplateListingItem;

/**
 * The Šablony listing: per category (+ the uncategorized bucket) ONE ordered
 * list of cards — templates and multi-page documents share the sort space.
 * Templates attached to a document as pages are left out (the document card
 * stands for them) unless documents are switched off for the viewer.
 */
readonly final class GetTemplateListing
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<TemplateCategory> $categories
     *
     * @return array{categories: list<array{category: TemplateCategory, items: list<TemplateListingItem>}>, uncategorized: list<TemplateListingItem>}
     */
    public function forProject(UuidInterface $projectId, array $categories, bool $withDocuments): array
    {
        $templates = $this->templates($projectId, $withDocuments);
        $documents = $withDocuments ? $this->documents($projectId) : [];

        $items = [];
        foreach ($templates as $template) {
            $items[] = TemplateListingItem::template($template);
        }
        foreach ($documents as $document) {
            $items[] = TemplateListingItem::document($document);
        }

        usort($items, static fn (TemplateListingItem $a, TemplateListingItem $b): int => [$a->position(), $a->createdAt()] <=> [$b->position(), $b->createdAt()]);

        $byCategory = [];
        $uncategorized = [];

        foreach ($items as $item) {
            $category = $item->category();

            if ($category === null) {
                $uncategorized[] = $item;
            } else {
                $byCategory[$category->id->toString()][] = $item;
            }
        }

        $result = [];
        foreach ($categories as $category) {
            $result[] = ['category' => $category, 'items' => $byCategory[$category->id->toString()] ?? []];
        }

        return ['categories' => $result, 'uncategorized' => $uncategorized];
    }

    /**
     * @return list<Template>
     */
    private function templates(UuidInterface $projectId, bool $excludePages): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(Template::class, 'template')
            ->select('template')
            ->where('template.project = :projectId')
            ->setParameter('projectId', $projectId->toString());

        if ($excludePages) {
            $query->andWhere(sprintf('NOT EXISTS (SELECT page.id FROM %s page WHERE page.template = template)', TemplateDocumentPage::class));
        }

        /** @var list<Template> $templates */
        $templates = $query->getQuery()->getResult();

        return $templates;
    }

    /**
     * @return list<TemplateDocument>
     */
    private function documents(UuidInterface $projectId): array
    {
        /** @var list<TemplateDocument> $documents */
        $documents = $this->entityManager->createQueryBuilder()
            ->from(TemplateDocument::class, 'document')
            ->select('document')
            ->where('document.project = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->getQuery()
            ->getResult();

        return $documents;
    }
}
