<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Message\SocialNetwork\DeleteSocialNetworkTemplate;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class DeleteSocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     */
    public function __invoke(DeleteSocialNetworkTemplate $message): void
    {
        $template = $this->templateRepository->get($message->templateId);

        $this->templateRepository->remove($template);
    }
}
