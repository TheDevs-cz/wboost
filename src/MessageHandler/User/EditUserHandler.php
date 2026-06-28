<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\User\EditUser;
use WBoost\Web\Repository\UserRepository;

#[AsMessageHandler]
readonly final class EditUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(EditUser $message): void
    {
        $user = $this->userRepository->getById(Uuid::fromString($message->userId));

        $user->editProfile($message->name);
        $user->changeRoles($message->roles);
    }
}
