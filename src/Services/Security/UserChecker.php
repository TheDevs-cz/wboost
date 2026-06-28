<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use WBoost\Web\Entity\User;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $user->confirmed === false) {
            throw new CustomUserMessageAccountStatusException('Váš účet ještě nebyl aktivován. Zkontrolujte e-mail s pozvánkou.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
