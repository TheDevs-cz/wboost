<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Entity\User;

final class UserProfileFormData
{
    public null|string $name = null;

    public static function fromUser(User $user): self
    {
        $self = new self();
        $self->name = $user->name;

        return $self;
    }
}
