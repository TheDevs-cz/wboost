<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplate;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class AddSocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $socialNetworkTemplateRepository,
        private ProjectRepository $projectRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     */
    public function __invoke(AddSocialNetworkTemplate $message): void
    {
        $project = $this->projectRepository->get($message->projectId);
        $templateId = $message->templateId;

        $template = new SocialNetworkTemplate(
            $templateId,
            $project,
            $this->clock->now(),
            $message->name,
        );

        $this->socialNetworkTemplateRepository->add($template);
    }
}
