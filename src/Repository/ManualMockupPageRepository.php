<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Exceptions\ManualMockupPageNotFound;
use Doctrine\ORM\EntityManagerInterface;

readonly final class ManualMockupPageRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ManualMockupPageNotFound
     */
    public function get(UuidInterface $pageId): ManualMockupPage
    {
        $page = $this->entityManager->find(ManualMockupPage::class, $pageId);

        if ($page instanceof ManualMockupPage) {
            return $page;
        }

        throw new ManualMockupPageNotFound();
    }

    public function add(ManualMockupPage $page): void
    {
        $this->entityManager->persist($page);
    }

    public function remove(ManualMockupPage $page): void
    {
        $this->entityManager->remove($page);
    }
}
