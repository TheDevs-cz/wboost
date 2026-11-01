<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\TemplateDocument\RenameTemplateDocumentExportVersion;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;

#[AsMessageHandler]
readonly final class RenameTemplateDocumentExportVersionHandler
{
    public function __construct(
        private TemplateDocumentExportVersionRepository $versionRepository,
    ) {
    }

    public function __invoke(RenameTemplateDocumentExportVersion $message): void
    {
        $this->versionRepository->get($message->versionId)->rename($message->name);
    }
}
