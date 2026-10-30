<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\EditTemplateDocumentPrintSettings;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentPageList;

#[AsMessageHandler]
readonly final class EditTemplateDocumentPrintSettingsHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     */
    public function __invoke(EditTemplateDocumentPrintSettings $message): void
    {
        $document = $this->documentRepository->get($message->documentId);
        $document->changePrintSettings($message->bleedMm, $message->cropMarks);

        foreach ($document->pages() as $page) {
            TemplateDocumentPageList::applyPrintSetup($document, $page->variant);
        }
    }
}
