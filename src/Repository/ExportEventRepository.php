<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\ExportEvent;

readonly final class ExportEventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(ExportEvent $event): void
    {
        $this->entityManager->persist($event);
    }
}
