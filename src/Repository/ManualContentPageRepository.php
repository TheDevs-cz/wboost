<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;

readonly final class ManualContentPageRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(UuidInterface $pageId): null|ManualContentPage
    {
        return $this->entityManager->find(ManualContentPage::class, $pageId);
    }

    /**
     * The manual's intro page, or null while the manual has none (a manual
     * that never had an intro image and whose intro was never edited).
     */
    public function introFor(UuidInterface $manualId): null|ManualContentPage
    {
        return $this->entityManager->getRepository(ManualContentPage::class)->findOneBy([
            'manual' => $manualId,
            'role' => ManualContentPageRole::Intro,
        ]);
    }

    public function add(ManualContentPage $page): void
    {
        $this->entityManager->persist($page);
    }
}
