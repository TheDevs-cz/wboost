<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\ManualContentPageNotFound;
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
     * @throws ManualContentPageNotFound
     */
    public function get(UuidInterface $pageId): ManualContentPage
    {
        return $this->find($pageId) ?? throw new ManualContentPageNotFound();
    }

    /**
     * The manual's custom pages (no intro), in no particular order — the
     * shared order with the mockup pages is `GetManualPageList`'s job.
     *
     * @return list<ManualContentPage>
     */
    public function customFor(UuidInterface $manualId): array
    {
        /** @var list<ManualContentPage> */
        return $this->entityManager->getRepository(ManualContentPage::class)->findBy([
            'manual' => $manualId,
            'role' => ManualContentPageRole::Custom,
        ]);
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

    public function remove(ManualContentPage $page): void
    {
        $this->entityManager->remove($page);
    }
}
