<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\User\RegisterUser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class RegisterUserHandler
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(RegisterUser $message): void
    {
        $user = new User(
            Uuid::uuid7(),
            $message->email,
        );

        $hashedPassword = $this->passwordHasher->hashPassword($user, $message->plainTextPassword);

        $user->changePassword($hashedPassword);

        $this->userRepository->save($user);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
    }
}
