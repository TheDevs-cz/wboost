<?php

declare(strict_types=1);

namespace WBoost\Web\Message\User;

readonly final class EditUser
{
    /**
     * @param list<string> $roles
     * @param list<string> $mcpScopes
     */
    public function __construct(
        public string $userId,
        public null|string $name,
        public array $roles,
        public array $mcpScopes,
    ) {
    }
}
