<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\EmailSignatureTemplate;

readonly final class GetEmailSignatureTemplates
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<EmailSignatureTemplate>
     */
    public function allForProject(UuidInterface $projectId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->from(EmailSignatureTemplate::class, 't')
            ->select('t')
            ->join('t.project', 'p')
            ->where('p.id = :projectId')
            ->setParameter('projectId', $projectId->toString())
            ->getQuery()
            ->getResult();
    }
}
