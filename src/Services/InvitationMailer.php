<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\PasswordResetToken;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\PasswordResetTokenRepository;

/**
 * Mints a fresh +7d set-password token and emails the invitation link. Shared by
 * the invite and resend-invitation handlers so both stay in lock-step.
 */
readonly final class InvitationMailer
{
    public function __construct(
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function sendInvitation(User $user): void
    {
        $token = new PasswordResetToken(
            $this->provideIdentity->next(),
            $user,
            $this->clock->now(),
            $this->clock->now()->modify('+7 days'),
        );

        $this->passwordResetTokenRepository->save($token);

        $setPasswordUrl = $this->urlGenerator->generate('set_password', [
            'token' => $token->id->toString(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $html = $this->twig->render('emails/invitation.html.twig', [
            'user' => $user,
            'setPasswordUrl' => $setPasswordUrl,
        ]);

        $email = (new Email())
            ->to($user->email)
            ->subject('Pozvánka do aplikace WBoost')
            ->html($html);

        $this->mailer->send($email);
    }
}
