<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Message\Template\DeleteTemplate;
use WBoost\Web\Repository\TemplateRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws TemplateNotFound
     */
    public function __invoke(DeleteTemplate $message): void
    {
        $template = $this->templateRepository->get($message->templateId);

        $this->templateRepository->remove($template);
    }
}
