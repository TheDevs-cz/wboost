<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

enum WeeklyMenuApprovalStatus: string
{
    case NotRequested = 'not_requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::NotRequested => 'Neodesláno',
            self::Pending => 'Čeká na schválení',
            self::Approved => 'Schváleno',
            self::Denied => 'Zamítnuto',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotRequested => 'bg-secondary',
            self::Pending => 'bg-warning text-dark',
            self::Approved => 'bg-success',
            self::Denied => 'bg-danger',
        };
    }
}
