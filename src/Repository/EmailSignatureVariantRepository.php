<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Exceptions\EmailSignatureVariantNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class EmailSignatureVariantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws EmailSignatureVariantNotFound
     */
    public function get(UuidInterface $manualId): EmailSignatureVariant
    {
        $manual = $this->entityManager->find(EmailSignatureVariant::class, $manualId);

        if ($manual instanceof EmailSignatureVariant) {
            return $manual;
        }

        throw new EmailSignatureVariantNotFound();
    }

    public function add(EmailSignatureVariant $manual): void
    {
        $this->entityManager->persist($manual);
    }

    public function remove(EmailSignatureVariant $manual): void
    {
        $this->entityManager->remove($manual);
    }
}
