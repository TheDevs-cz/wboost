<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use DateTimeImmutable;
use WBoost\Web\Entity\User;

final readonly class UserOverviewRow
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public null|string $name,
        public array $roles,
        public bool $confirmed,
        public DateTimeImmutable $registeredAt,
        public int $ownedCount,
        public int $sharedCount,
    ) {
    }

    public function displayName(): string
    {
        return $this->name ?? $this->email;
    }

    public function isPending(): bool
    {
        return $this->confirmed === false;
    }

    public function isAdmin(): bool
    {
        return in_array(User::ROLE_ADMIN, $this->roles, true);
    }

    public function isDesigner(): bool
    {
        return in_array(User::ROLE_DESIGNER, $this->roles, true);
    }
}
