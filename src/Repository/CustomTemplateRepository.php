<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class CustomTemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CustomTemplateNotFound
     */
    public function get(UuidInterface $templateId): CustomTemplate
    {
        $template = $this->entityManager->find(CustomTemplate::class, $templateId);

        if ($template instanceof CustomTemplate) {
            return $template;
        }

        throw new CustomTemplateNotFound();
    }

    public function add(CustomTemplate $template): void
    {
        $this->entityManager->persist($template);
    }

    public function remove(CustomTemplate $template): void
    {
        $this->entityManager->remove($template);
    }

    public function count(UuidInterface $projectId): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(template)')
            ->from(CustomTemplate::class, 'template')
            ->where('template.project = :projectId')
            ->setParameter('projectId', $projectId)
            ->getQuery()
            ->getSingleScalarResult();

        assert(is_int($count));

        return $count;
    }
}
