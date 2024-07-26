<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Exceptions\UserAlreadyRegistered;
use WBoost\Web\Message\User\RegisterUser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

#[AsMessageHandler]
readonly final class RegisterUserHandler
{
    public function __construct(
        // private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @throws UserAlreadyRegistered
     */
    public function __invoke(RegisterUser $message): void
    {
        /*
        try {
            $this->userProvider->loadUserByIdentifier($message->email);

            throw new UserAlreadyRegistered();
        } catch (UserNotFoundException) {
            // TODO
            // $this->userService->register($message);

            // Manually log in the user
            $user = $this->userProvider->loadUserByIdentifier($message->email);
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $this->tokenStorage->setToken($token);
        }
        */
    }
}
