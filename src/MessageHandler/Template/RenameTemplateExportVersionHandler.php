<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Template\RenameTemplateExportVersion;
use WBoost\Web\Repository\TemplateExportVersionRepository;

#[AsMessageHandler]
readonly final class RenameTemplateExportVersionHandler
{
    public function __construct(
        private TemplateExportVersionRepository $versionRepository,
    ) {
    }

    public function __invoke(RenameTemplateExportVersion $message): void
    {
        $this->versionRepository->get($message->versionId)->rename($message->name);
    }
}
