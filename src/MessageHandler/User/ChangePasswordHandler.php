<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Message\User\ChangePassword;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;

#[AsMessageHandler]
readonly final class ChangePasswordHandler
{
    public function __construct(
        // private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(ChangePassword $message): void
    {
        // $this->userService->changePassword($message->email, $message->newPlainTextPassword);

        // $user = $this->userProvider->loadUserByIdentifier($message->email);
        // $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        // $this->tokenStorage->setToken($token);
    }
}
