<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\SocialNetwork;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplate;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;

#[AsMessageHandler]
readonly final class EditSocialNetworkTemplateHandler
{
    public function __construct(
        private SocialNetworkTemplateRepository $socialNetworkTemplateRepository,
    ) {
    }

    /**
     * @throws SocialNetworkTemplateNotFound
     */
    public function __invoke(EditSocialNetworkTemplate $message): void
    {
        $template = $this->socialNetworkTemplateRepository->get($message->templateId);

        $template->edit($message->name);
    }
}
