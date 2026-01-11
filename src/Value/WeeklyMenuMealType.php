<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum WeeklyMenuMealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Snack = 'snack';
    case Dinner = 'dinner';
    case LateDinner = 'late_dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Snídaně',
            self::Lunch => 'Oběd',
            self::Snack => 'Svačina',
            self::Dinner => 'Večeře',
            self::LateDinner => 'Druhá večeře',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Breakfast => 0,
            self::Lunch => 1,
            self::Snack => 2,
            self::Dinner => 3,
            self::LateDinner => 4,
        };
    }

    public function cssColor(): string
    {
        return match ($this) {
            self::Breakfast => '#FEF3C7',
            self::Lunch => '#DBEAFE',
            self::Snack => '#D1FAE5',
            self::Dinner => '#EDE9FE',
            self::LateDinner => '#F3F4F6',
        };
    }
}
