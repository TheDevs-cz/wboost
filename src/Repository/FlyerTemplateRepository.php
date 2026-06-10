<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Exceptions\FlyerTemplateNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class FlyerTemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FlyerTemplateNotFound
     */
    public function get(UuidInterface $templateId): FlyerTemplate
    {
        $template = $this->entityManager->find(FlyerTemplate::class, $templateId);

        if ($template instanceof FlyerTemplate) {
            return $template;
        }

        throw new FlyerTemplateNotFound();
    }

    public function add(FlyerTemplate $template): void
    {
        $this->entityManager->persist($template);
    }

    public function remove(FlyerTemplate $template): void
    {
        $this->entityManager->remove($template);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(template)')
            ->from(FlyerTemplate::class, 'template')
            ->where('template.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
