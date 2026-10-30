<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\TemplateDocument\SaveTemplateDocumentFillDraft;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Value\ExportChannel;

#[AsMessageHandler]
readonly final class SaveTemplateDocumentFillDraftHandler
{
    public function __construct(
        private TemplateDocumentExportVersionRepository $versionRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SaveTemplateDocumentFillDraft $message): void
    {
        $document = $this->entityManager->find(TemplateDocument::class, $message->documentId);

        if (!$document instanceof TemplateDocument) {
            return;
        }

        $user = $message->savedByUserId !== null ? $this->entityManager->find(User::class, $message->savedByUserId) : null;
        $now = $this->clock->now();
        $draft = $this->versionRepository->findDraftOf($message->versionId->toString(), $document->id);

        if ($draft !== null) {
            $draft->saveDraft($message->fill, $now, $user);

            return;
        }

        $this->versionRepository->prune($document->id, TemplateDocumentExportVersion::MAX_VERSIONS - 1);
        $version = new TemplateDocumentExportVersion($message->versionId, $document, $user, ExportChannel::Web, $message->fill, $now);
        $version->markDraft();
        $this->versionRepository->add($version);
    }
}
