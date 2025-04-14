<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Exceptions\EmailSignatureTemplateNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class EmailSignatureTemplateRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws EmailSignatureTemplateNotFound
     */
    public function get(UuidInterface $manualId): EmailSignatureTemplate
    {
        $manual = $this->entityManager->find(EmailSignatureTemplate::class, $manualId);

        if ($manual instanceof EmailSignatureTemplate) {
            return $manual;
        }

        throw new EmailSignatureTemplateNotFound();
    }

    public function add(EmailSignatureTemplate $manual): void
    {
        $this->entityManager->persist($manual);
    }

    public function remove(EmailSignatureTemplate $manual): void
    {
        $this->entityManager->remove($manual);
    }
}
