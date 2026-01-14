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
            self::Breakfast => '#F59E0B',
            self::Lunch => '#3B82F6',
            self::Snack => '#10B981',
            self::Dinner => '#8B5CF6',
            self::LateDinner => '#6B7280',
        };
    }
}
