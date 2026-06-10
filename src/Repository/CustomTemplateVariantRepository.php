<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Exceptions\CustomTemplateVariantNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class CustomTemplateVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CustomTemplateVariantNotFound
     */
    public function get(UuidInterface $variantId): CustomTemplateVariant
    {
        $variant = $this->entityManager->find(CustomTemplateVariant::class, $variantId);

        if ($variant instanceof CustomTemplateVariant) {
            return $variant;
        }

        throw new CustomTemplateVariantNotFound();
    }

    public function add(CustomTemplateVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(CustomTemplateVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
