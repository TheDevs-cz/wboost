<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use WBoost\Web\Exceptions\InvalidPasswordResetToken;
use WBoost\Web\Message\User\ResetPassword;
use WBoost\Web\Repository\PasswordResetTokenRepository;

#[AsMessageHandler]
readonly final class ResetPasswordHandler
{
    public function __construct(
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private Security $security,
    ) {
    }

    /**
     * @throws InvalidPasswordResetToken
     */
    public function __invoke(ResetPassword $message): void
    {
        $token = $this->passwordResetTokenRepository->getValid($message->token, $this->clock->now());

        $user = $token->user;
        $hashedPassword = $this->passwordHasher->hashPassword($user, $message->newPlainTextPassword);

        $user->changePassword($hashedPassword);
        // Activates an invited user; no-op for an already-confirmed user resetting their password.
        $user->confirm();
        $token->use($this->clock->now());

        // Log the (possibly not-yet-authenticated) user in. Safe because this handler runs
        // synchronously inside the request, under the stateful `main` firewall. The
        // authenticator must be named explicitly since the firewall gained a second
        // one (FacebookAuthenticator).
        $this->security->login($user, 'form_login', 'main');
    }
}
