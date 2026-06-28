<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;
use WBoost\Web\Entity\User;

final class InviteUserFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $email = '';

    public null|string $name = null;

    public string $role = User::ROLE_DESIGNER;

    /** @var list<string> */
    public array $projectIds = [];
}
