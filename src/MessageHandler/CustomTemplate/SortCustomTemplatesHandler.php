<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\CustomTemplate;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\CustomTemplate\SortCustomTemplates;
use WBoost\Web\Repository\CustomTemplateRepository;

#[AsMessageHandler]
readonly final class SortCustomTemplatesHandler
{
    public function __construct(
        private CustomTemplateRepository $customTemplateRepository,
    ) {
    }

    public function __invoke(SortCustomTemplates $message): void
    {
        foreach ($message->templates as $position => $templateId) {
            $template = $this->customTemplateRepository->get(Uuid::fromString($templateId));
            $template->sort($position);
        }
    }
}
