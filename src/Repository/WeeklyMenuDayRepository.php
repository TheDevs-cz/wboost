<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\WeeklyMenuDay;
use WBoost\Web\Exceptions\WeeklyMenuDayNotFound;

readonly final class WeeklyMenuDayRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WeeklyMenuDayNotFound
     */
    public function get(UuidInterface $dayId): WeeklyMenuDay
    {
        $day = $this->entityManager->find(WeeklyMenuDay::class, $dayId);

        if ($day instanceof WeeklyMenuDay) {
            return $day;
        }

        throw new WeeklyMenuDayNotFound();
    }

    public function remove(WeeklyMenuDay $day): void
    {
        $this->entityManager->remove($day);
        $this->entityManager->flush();
    }
}
