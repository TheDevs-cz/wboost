<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\FlyerTemplateNotFound;
use WBoost\Web\Message\Flyer\DeleteFlyerTemplate;
use WBoost\Web\Repository\FlyerTemplateRepository;

#[AsMessageHandler]
readonly final class DeleteFlyerTemplateHandler
{
    public function __construct(
        private FlyerTemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws FlyerTemplateNotFound
     */
    public function __invoke(DeleteFlyerTemplate $message): void
    {
        $template = $this->templateRepository->get($message->templateId);

        $this->templateRepository->remove($template);
    }
}
