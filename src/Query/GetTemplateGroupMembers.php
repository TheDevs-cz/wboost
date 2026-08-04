<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\DimensionPreset;

readonly final class GetTemplateGroupMembers
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Ordered the way the variant grid presents dimensions: preset variants
     * first (in {@see DimensionPreset} case order), free-form dimensions
     * after, ties broken by age then id.
     *
     * @return list<TemplateVariant>
     */
    public function variants(UuidInterface $groupId): array
    {
        /** @var list<TemplateVariant> $variants */
        $variants = $this->entityManager->createQueryBuilder()
            ->from(TemplateVariant::class, 'variant')
            ->select('variant')
            ->where('variant.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->orderBy('variant.createdAt')
            ->getQuery()
            ->getResult();

        /** @var array<string, int> $presetOrder */
        $presetOrder = [];

        foreach (DimensionPreset::cases() as $index => $case) {
            $presetOrder[$case->value] = $index;
        }

        usort($variants, static function (TemplateVariant $a, TemplateVariant $b) use ($presetOrder): int {
            $aPreset = $a->dimension->preset;
            $bPreset = $b->dimension->preset;

            $byPreset = ($aPreset !== null ? ($presetOrder[$aPreset->value] ?? PHP_INT_MAX) : PHP_INT_MAX)
                <=> ($bPreset !== null ? ($presetOrder[$bPreset->value] ?? PHP_INT_MAX) : PHP_INT_MAX);

            if ($byPreset !== 0) {
                return $byPreset;
            }

            $byAge = $a->createdAt <=> $b->createdAt;

            if ($byAge !== 0) {
                return $byAge;
            }

            return $a->id->toString() <=> $b->id->toString();
        });

        return $variants;
    }

    public function template(UuidInterface $groupId): null|Template
    {
        /** @var null|Template */
        return $this->entityManager->createQueryBuilder()
            ->from(Template::class, 'template')
            ->select('template')
            ->where('template.group = :groupId')
            ->setParameter('groupId', $groupId->toString())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
