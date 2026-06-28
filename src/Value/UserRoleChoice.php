<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\User;

/**
 * Maps the single "role" form choice to/from the stored roles list.
 *
 * The admin UI exposes one role per user (Uživatel / Designer / Administrátor);
 * internally that is a {@see User} roles array (empty for a plain user, since
 * getRoles() always appends ROLE_USER).
 */
final class UserRoleChoice
{
    public const string USER = 'user';

    /** @var array<string, string> Czech label => stored choice value (for ChoiceType). */
    public const array CHOICES = [
        'Uživatel' => self::USER,
        'Designer' => User::ROLE_DESIGNER,
        'Administrátor' => User::ROLE_ADMIN,
    ];

    /**
     * @return list<string>
     */
    public static function toRoles(string $choice): array
    {
        return match ($choice) {
            User::ROLE_ADMIN => [User::ROLE_ADMIN],
            User::ROLE_DESIGNER => [User::ROLE_DESIGNER],
            default => [],
        };
    }

    /**
     * @param array<string> $roles
     */
    public static function fromRoles(array $roles): string
    {
        if (in_array(User::ROLE_ADMIN, $roles, true)) {
            return User::ROLE_ADMIN;
        }

        if (in_array(User::ROLE_DESIGNER, $roles, true)) {
            return User::ROLE_DESIGNER;
        }

        return self::USER;
    }

    public static function label(string $choice): string
    {
        $label = array_search($choice, self::CHOICES, true);

        return $label === false ? $choice : $label;
    }
}
