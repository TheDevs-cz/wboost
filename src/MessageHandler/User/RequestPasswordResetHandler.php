<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use WBoost\Web\Exceptions\UserNotRegistered;
use WBoost\Web\Message\User\RequestPasswordReset;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\User\UserProviderInterface;

#[AsMessageHandler]
readonly final class RequestPasswordResetHandler
{
    public function __construct(
    ) {
    }

    /**
     * @throws UserNotRegistered
     */
    public function __invoke(RequestPasswordReset $message): void
    {
        // $user = $this->userProvider->loadUserByIdentifier($message->email);

        // TODO
        // $token = $this->passwordResetTokenService->create($user->id);
        // TODO send mail
    }
}
