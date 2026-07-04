<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\User\DeleteUser;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class DeleteUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(DeleteUser $message): void
    {
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        $this->userRepository->remove($user);
    }
}
