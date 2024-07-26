<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class UserNotRegistered extends UserNotFoundException
{
}
