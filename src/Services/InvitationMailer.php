<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\PasswordResetToken;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\PasswordResetTokenRepository;

/**
 * Mints a fresh +7d set-password token and emails the invitation link. Shared by
 * the invite and resend-invitation handlers so both stay in lock-step.
 *
 * When the invite pre-shares projects (share dialog / admin invite), the mail
 * names them and the inviter so the recipient knows what they are joining.
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

    /**
     * @param list<Project> $projects
     */
    public function sendInvitation(User $user, array $projects = [], null|User $invitedBy = null): void
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
            'projectNames' => array_map(static fn (Project $project): string => $project->name, $projects),
            'invitedByName' => $invitedBy?->getDisplayName(),
        ]);

        $subject = count($projects) === 1
            ? sprintf('Pozvánka do projektu %s v aplikaci WBoost', $projects[0]->name)
            : 'Pozvánka do aplikace WBoost';

        $email = (new Email())
            ->to($user->email)
            ->subject($subject)
            ->html($html);

        $this->mailer->send($email);
    }
}
