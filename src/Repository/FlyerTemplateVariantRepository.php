<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\FlyerTemplateVariant;
use WBoost\Web\Exceptions\FlyerTemplateVariantNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class FlyerTemplateVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FlyerTemplateVariantNotFound
     */
    public function get(UuidInterface $variantId): FlyerTemplateVariant
    {
        $variant = $this->entityManager->find(FlyerTemplateVariant::class, $variantId);

        if ($variant instanceof FlyerTemplateVariant) {
            return $variant;
        }

        throw new FlyerTemplateVariantNotFound();
    }

    public function add(FlyerTemplateVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(FlyerTemplateVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
