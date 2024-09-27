<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\SocialNetwork\SortSocialNetworkTemplates;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class SortSocialNetworkTemplatesHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $socialNetworkTemplateRepository,
    ) {
    }

    public function __invoke(SortSocialNetworkTemplates $message): void
    {
        foreach ($message->templates as $position => $templateId) {
            $template = $this->socialNetworkTemplateRepository->get(Uuid::fromString($templateId));
            $template->sort($position);
        }
    }
}
