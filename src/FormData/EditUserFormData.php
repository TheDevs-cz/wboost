<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Entity\User;
use WBoost\Web\Value\UserRoleChoice;

final class EditUserFormData
{
    public null|string $name = null;

    public string $role = User::ROLE_DESIGNER;

    /** @var list<string> */
    public array $mcpScopes = User::DEFAULT_MCP_SCOPES;

    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->name = $user->name;
        $data->role = UserRoleChoice::fromRoles($user->getRoles());
        $data->mcpScopes = $user->mcpScopes;

        return $data;
    }
}
