<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Template\ArrangeTemplateFillFields;
use WBoost\Web\Repository\TemplateRepository;

#[AsMessageHandler]
readonly final class ArrangeTemplateFillFieldsHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
    ) {
    }

    public function __invoke(ArrangeTemplateFillFields $message): void
    {
        $this->templateRepository->get($message->templateId)->arrangeFillFields($message->inputIds);
    }
}
