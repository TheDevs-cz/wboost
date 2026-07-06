<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;

readonly final class GetTemplateGroups
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<TemplateGroupListItem>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        /** @var list<TemplateGroup> $groups */
        $groups = $this->entityManager->createQueryBuilder()
            ->from(TemplateGroup::class, 'templateGroup')
            ->select('templateGroup')
            ->where('templateGroup.project = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->orderBy('templateGroup.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        if ($groups === []) {
            return [];
        }

        /** @var list<SocialNetworkTemplateVariant> $socialVariants */
        $socialVariants = $this->entityManager->createQueryBuilder()
            ->from(SocialNetworkTemplateVariant::class, 'variant')
            ->select('variant')
            ->where('variant.group IN (:groups)')
            ->setParameter('groups', $groups)
            ->orderBy('variant.createdAt')
            ->getQuery()
            ->getResult();

        /** @var list<CustomTemplateVariant> $customVariants */
        $customVariants = $this->entityManager->createQueryBuilder()
            ->from(CustomTemplateVariant::class, 'variant')
            ->select('variant')
            ->where('variant.group IN (:groups)')
            ->setParameter('groups', $groups)
            ->orderBy('variant.createdAt')
            ->getQuery()
            ->getResult();

        $items = [];

        foreach ($groups as $group) {
            $groupSocial = array_values(array_filter(
                $socialVariants,
                static fn (SocialNetworkTemplateVariant $variant): bool => $variant->group === $group,
            ));

            $groupCustom = array_values(array_filter(
                $customVariants,
                static fn (CustomTemplateVariant $variant): bool => $variant->group === $group,
            ));

            $items[] = new TemplateGroupListItem($group, $groupSocial, $groupCustom);
        }

        return $items;
    }
}
