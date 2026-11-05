<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;

/**
 * Short "X s vámi sdílí projekt Y" notice for an already-registered user who
 * was just given access. People without an account get the invitation e-mail
 * ({@see InvitationMailer}) instead.
 */
readonly final class ProjectSharedMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function sendShareNotification(Project $project, User $user, null|User $sharedBy): void
    {
        $html = $this->twig->render('emails/project_shared.html.twig', [
            'projectName' => $project->name,
            'sharedByName' => $sharedBy?->getDisplayName(),
            'projectUrl' => $this->urlGenerator->generate('project_dashboard', [
                'id' => $project->id->toString(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        $email = (new Email())
            ->to($user->email)
            ->subject(sprintf('Sdílený projekt %s ve WBoost', $project->name))
            ->html($html);

        $this->mailer->send($email);
    }
}
