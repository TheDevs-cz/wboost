<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\User;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
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
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
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

        $setPasswordUrl = $this->urlGenerator->generate('set_password', [
            'token' => $token->id->toString(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $html = $this->twig->render('emails/password_reset.html.twig', [
            'user' => $user,
            'setPasswordUrl' => $setPasswordUrl,
        ]);

        $email = (new Email())
            ->to($user->email)
            ->subject('Obnovení hesla')
            ->html($html);

        $this->mailer->send($email);
    }
}
