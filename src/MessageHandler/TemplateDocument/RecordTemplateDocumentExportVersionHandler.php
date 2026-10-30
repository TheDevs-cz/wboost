<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\TemplateDocument\RecordTemplateDocumentExportVersion;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;

/**
 * The {@see \WBoost\Web\MessageHandler\Template\RecordTemplateExportVersionHandler}
 * rules for a document: identical fills dedupe (bump), a loaded draft is
 * promoted (or merged into an identical exported version), unpinned history is
 * capped at MAX_VERSIONS.
 */
#[AsMessageHandler]
readonly final class RecordTemplateDocumentExportVersionHandler
{
    public function __construct(
        private TemplateDocumentExportVersionRepository $versionRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordTemplateDocumentExportVersion $message): void
    {
        $document = $this->entityManager->find(TemplateDocument::class, $message->documentId);

        if (!$document instanceof TemplateDocument) {
            return;
        }

        $user = $message->exportedByUserId !== null ? $this->entityManager->find(User::class, $message->exportedByUserId) : null;
        $now = $this->clock->now();
        $duplicate = $this->versionRepository->findDuplicate($document->id, $message->fill->hash());
        $draft = $this->versionRepository->findDraftOf($message->draftVersionId, $document->id);

        if ($draft !== null) {
            if ($duplicate !== null) {
                $duplicate->bump($now, $user, $message->channel);
                $duplicate->name ??= $draft->name;
                if ($draft->pinnedAt !== null) {
                    $duplicate->pin($now);
                }
                $this->versionRepository->remove($draft);

                return;
            }

            $draft->promoteToExport($message->fill, $now, $user, $message->channel);

            return;
        }

        if ($duplicate !== null) {
            $duplicate->bump($now, $user, $message->channel);

            return;
        }

        $this->versionRepository->prune($document->id, TemplateDocumentExportVersion::MAX_VERSIONS - 1);
        $this->versionRepository->add(new TemplateDocumentExportVersion(Uuid::uuid7(), $document, $user, $message->channel, $message->fill, $now));
    }
}
