<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Exceptions\InvalidPasswordResetToken;
use WBoost\Web\Exceptions\UserNotRegistered;
use WBoost\Web\Message\User\ResetPassword;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;

#[AsMessageHandler]
readonly final class ResetPasswordHandler
{
    public function __construct(
        // private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @throws InvalidPasswordResetToken
     * @throws UserNotRegistered
     */
    public function __invoke(ResetPassword $message): void
    {
        // $userId = $this->passwordResetTokenService->getTokenUserId($message->token);
        // $email = $this->userService->getEmailById($userId);

        // $this->userService->changePassword($email, $message->newPlainTextPassword);

        // $this->passwordResetTokenService->useToken($message->token);

        // TODO

        // Manually log in the user
        // $user = $this->userProvider->loadUserByIdentifier('');
        // $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        // $this->tokenStorage->setToken($token);
    }
}
