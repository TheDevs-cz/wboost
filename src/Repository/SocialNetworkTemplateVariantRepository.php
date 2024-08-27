<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\SocialNetworkTemplateVariantNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class SocialNetworkTemplateVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateVariantNotFound
     */
    public function get(UuidInterface $variantId): SocialNetworkTemplateVariant
    {
        $variant = $this->entityManager->find(SocialNetworkTemplateVariant::class, $variantId);

        if ($variant instanceof SocialNetworkTemplateVariant) {
            return $variant;
        }

        throw new SocialNetworkTemplateVariantNotFound();
    }

    public function add(SocialNetworkTemplateVariant $variant): void
    {
        $this->entityManager->persist($variant);
    }

    public function remove(SocialNetworkTemplateVariant $variant): void
    {
        $this->entityManager->remove($variant);
    }
}
