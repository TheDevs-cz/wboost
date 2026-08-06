<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * Who the personal access token belongs to.
 *
 * `role` is the user's HIGHEST role (`ROLE_ADMIN` > `ROLE_DESIGNER` >
 * `ROLE_USER`), not the raw role array: the only question an agent asks of it
 * is "may this account author templates", and a single ordered value answers
 * that without inviting a client to reason about role sets.
 */
readonly final class ContextUserResponse
{
    public function __construct(
        public string $id,
        public string $email,
        public null|string $name,
        public string $role,
    ) {
    }
}
