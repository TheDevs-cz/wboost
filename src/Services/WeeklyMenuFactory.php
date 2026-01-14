<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Entity\WeeklyMenuDay;

readonly class WeeklyMenuFactory
{
    public function __construct(
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    public function create(
        Project $project,
        UuidInterface $menuId,
        string $name,
        \DateTimeImmutable $validFrom,
        \DateTimeImmutable $validTo,
        null|string $createdBy = null,
        null|string $approvedBy = null,
    ): WeeklyMenu {
        $menu = new WeeklyMenu(
            $menuId,
            $project,
            $this->clock->now(),
            $name,
            $validFrom,
            $validTo,
            null,
            $createdBy,
            $approvedBy,
        );

        for ($dayOfWeek = 1; $dayOfWeek <= 7; $dayOfWeek++) {
            $day = new WeeklyMenuDay(
                $this->provideIdentity->next(),
                $menu,
                $dayOfWeek,
            );
            $menu->addDay($day);
        }

        return $menu;
    }
}
