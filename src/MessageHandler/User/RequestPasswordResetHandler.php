<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\PasswordResetToken;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Exceptions\UserNotRegistered;
use WBoost\Web\Message\User\RequestPasswordReset;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Repository\PasswordResetTokenRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class RequestPasswordResetHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ProvideIdentity $provideIdentity,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UserNotRegistered
     */
    public function __invoke(RequestPasswordReset $message): void
    {
        try {
            $user = $this->userRepository->get($message->email);
        } catch (UserNotFound) {
            throw new UserNotRegistered();
        }

        $token = new PasswordResetToken(
            $this->provideIdentity->next(),
            $user,
            $this->clock->now(),
            $this->clock->now()->modify('+8 hours'),
        );

        $this->passwordResetTokenRepository->save($token);

        // TODO: send email
    }
}
