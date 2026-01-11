<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;

readonly final class WeeklyMenuRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function get(UuidInterface $menuId): WeeklyMenu
    {
        $menu = $this->entityManager->find(WeeklyMenu::class, $menuId);

        if ($menu instanceof WeeklyMenu) {
            return $menu;
        }

        throw new WeeklyMenuNotFound();
    }

    public function add(WeeklyMenu $menu): void
    {
        $this->entityManager->persist($menu);
    }

    public function remove(WeeklyMenu $menu): void
    {
        $this->entityManager->remove($menu);
    }
}
