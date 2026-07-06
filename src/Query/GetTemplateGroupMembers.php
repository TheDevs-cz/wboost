<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Value\TemplateDimension;

readonly final class GetTemplateGroupMembers
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Ordered by dimension case order (the order the variant grid uses), then age.
     *
     * @return list<SocialNetworkTemplateVariant>
     */
    public function socialVariants(UuidInterface $groupId): array
    {
        /** @var list<SocialNetworkTemplateVariant> $variants */
        $variants = $this->entityManager->createQueryBuilder()
            ->from(SocialNetworkTemplateVariant::class, 'variant')
            ->select('variant')
            ->where('variant.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->orderBy('variant.createdAt')
            ->getQuery()
            ->getResult();

        /** @var array<string, int> $dimensionOrder */
        $dimensionOrder = [];

        foreach (TemplateDimension::cases() as $index => $case) {
            $dimensionOrder[$case->value] = $index;
        }

        usort($variants, static function (SocialNetworkTemplateVariant $a, SocialNetworkTemplateVariant $b) use ($dimensionOrder): int {
            $byDimension = ($dimensionOrder[$a->dimension->value] ?? PHP_INT_MAX) <=> ($dimensionOrder[$b->dimension->value] ?? PHP_INT_MAX);

            if ($byDimension !== 0) {
                return $byDimension;
            }

            return $a->createdAt <=> $b->createdAt;
        });

        return $variants;
    }

    /**
     * @return list<CustomTemplateVariant>
     */
    public function customVariants(UuidInterface $groupId): array
    {
        /** @var list<CustomTemplateVariant> $variants */
        $variants = $this->entityManager->createQueryBuilder()
            ->from(CustomTemplateVariant::class, 'variant')
            ->select('variant')
            ->where('variant.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->orderBy('variant.createdAt')
            ->getQuery()
            ->getResult();

        return $variants;
    }

    public function socialTemplate(UuidInterface $groupId): null|SocialNetworkTemplate
    {
        /** @var null|SocialNetworkTemplate */
        return $this->entityManager->createQueryBuilder()
            ->from(SocialNetworkTemplate::class, 'template')
            ->select('template')
            ->where('template.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function customTemplate(UuidInterface $groupId): null|CustomTemplate
    {
        /** @var null|CustomTemplate */
        return $this->entityManager->createQueryBuilder()
            ->from(CustomTemplate::class, 'template')
            ->select('template')
            ->where('template.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
