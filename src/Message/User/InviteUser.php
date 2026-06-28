<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class InviteUser
{
    /**
     * @param list<string> $roles
     * @param list<string> $projectIds
     */
    public function __construct(
        public string $email,
        public null|string $name,
        public array $roles,
        public array $projectIds,
        public string $invitedById,
    ) {
    }
}
