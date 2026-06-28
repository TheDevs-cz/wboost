<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Usage;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Message\Usage\RecordTemplateExport;
use WBoost\Web\Repository\ExportEventRepository;

#[AsMessageHandler]
readonly final class RecordTemplateExportHandler
{
    public function __construct(
        private ExportEventRepository $exportEventRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordTemplateExport $message): void
    {
        $this->exportEventRepository->add(new ExportEvent(
            Uuid::uuid7(),
            $this->clock->now(),
            $message->templateType,
            $message->channel,
            $message->templateId,
            $message->templateName,
            $message->variantId,
            $message->projectId,
            $message->projectName,
            $message->ownerId,
            $message->ownerEmail,
            $message->triggeredByUserId,
        ));
    }
}
