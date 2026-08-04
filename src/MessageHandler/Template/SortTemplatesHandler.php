<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Template\SortTemplates;
use WBoost\Web\Repository\TemplateRepository;

#[AsMessageHandler]
readonly final class SortTemplatesHandler
{
    public function __construct(
        private TemplateRepository $templateRepository,
    ) {
    }

    public function __invoke(SortTemplates $message): void
    {
        foreach ($message->templates as $position => $templateId) {
            $template = $this->templateRepository->get(Uuid::fromString($templateId));
            $template->sort($position);
        }
    }
}
