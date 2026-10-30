<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\TemplateDocument\RecordTemplateDocumentExportVersion;
use WBoost\Web\Value\DocumentFillValues;
use WBoost\Web\Value\ExportChannel;

/**
 * Snapshots a SUCCESSFUL document export as a re-loadable version — the
 * {@see \WBoost\Web\Services\Template\RecordExportVersion} contract: called
 * after the render, never breaks the export (failures swallowed + logged).
 */
final readonly class RecordDocumentExportVersion
{
    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private LoggerInterface $logger,
    ) {
    }

    public function record(TemplateDocument $document, ExportChannel $channel, DocumentFillValues $fill, null|string $draftVersionId = null): void
    {
        try {
            $user = $this->security->getUser();

            $this->bus->dispatch(new RecordTemplateDocumentExportVersion(
                $document->id,
                $user instanceof User ? $user->id : null,
                $channel,
                $fill,
                $draftVersionId !== '' ? $draftVersionId : null,
            ));
        } catch (Throwable $e) {
            $this->logger->error('Failed to record template document export version.', [
                'documentId' => $document->id->toString(),
                'exception' => $e,
            ]);
        }
    }
}
