<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Flyer;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Flyer\SortFlyerTemplates;
use WBoost\Web\Repository\FlyerTemplateRepository;

#[AsMessageHandler]
readonly final class SortFlyerTemplatesHandler
{
    public function __construct(
        private FlyerTemplateRepository $flyerTemplateRepository,
    ) {
    }

    public function __invoke(SortFlyerTemplates $message): void
    {
        foreach ($message->templates as $position => $templateId) {
            $template = $this->flyerTemplateRepository->get(Uuid::fromString($templateId));
            $template->sort($position);
        }
    }
}
