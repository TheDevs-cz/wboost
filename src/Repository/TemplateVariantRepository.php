<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class TemplateVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     */
    public function get(UuidInterface $variantId): TemplateVariant
    {
        $variant = $this->entityManager->find(TemplateVariant::class, $variantId);

        if ($variant instanceof TemplateVariant) {
            return $variant;
        }

        throw new TemplateVariantNotFound();
    }

    public function add(TemplateVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(TemplateVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
