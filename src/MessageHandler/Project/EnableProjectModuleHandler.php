<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\ProjectModuleChange;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\Project\EnableProjectModule;
use WBoost\Web\Repository\ProjectModuleChangeRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class EnableProjectModuleHandler
{
    /**
     * @param list<string> $adminNotificationRecipients
     */
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ProjectModuleChangeRepository $changeRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private array $adminNotificationRecipients,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     */
    public function __invoke(EnableProjectModule $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $user = $this->userRepository->getById($message->userId);

        if (!$project->enableModule($message->module)) {
            return;
        }

        $this->changeRepository->add(new ProjectModuleChange(
            $this->provideIdentity->next(),
            $project,
            $message->module,
            true,
            $this->clock->now(),
            $user,
        ));

        // Admins switch modules on for customers all the time — only a
        // customer discovering a module on their own is news worth a mail.
        if (in_array(User::ROLE_ADMIN, $user->getRoles(), true) || $this->adminNotificationRecipients === []) {
            return;
        }

        $html = $this->twig->render('emails/project_module_enabled.html.twig', [
            'userName' => $user->getDisplayName(),
            'userEmail' => $user->email,
            'projectName' => $project->name,
            'moduleLabel' => $message->module->label(),
            'projectUrl' => $this->urlGenerator->generate(
                'edit_project',
                ['id' => $project->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ) . '#project-modules',
        ]);

        $email = (new Email())
            ->to(...$this->adminNotificationRecipients)
            ->subject(sprintf('Modul %s zapnut v projektu %s', $message->module->label(), $project->name))
            ->html($html);

        $this->mailer->send($email);
    }
}
