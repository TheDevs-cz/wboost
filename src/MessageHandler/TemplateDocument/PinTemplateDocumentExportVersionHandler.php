<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\TemplateDocument\PinTemplateDocumentExportVersion;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;

#[AsMessageHandler]
readonly final class PinTemplateDocumentExportVersionHandler
{
    public function __construct(
        private TemplateDocumentExportVersionRepository $versionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PinTemplateDocumentExportVersion $message): void
    {
        $version = $this->versionRepository->get($message->versionId);

        if ($message->pinned) {
            $version->pin($this->clock->now());
        } else {
            $version->unpin();
        }
    }
}
