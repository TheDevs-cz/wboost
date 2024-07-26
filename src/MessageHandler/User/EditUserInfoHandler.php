<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Message\User\EditUserInfo;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditUserInfoHandler
{
    public function __construct(
    ) {
    }

    public function __invoke(EditUserInfo $message): void
    {
        // $this->userService->update($message);
    }
}
