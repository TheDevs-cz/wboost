<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\DeleteTemplateDocument;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateDocumentHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateRepository $templateRepository,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     */
    public function __invoke(DeleteTemplateDocument $message): void
    {
        $document = $this->documentRepository->get($message->documentId);

        if ($message->deletePages) {
            // Template removal cascades to its variants (and the page row) at
            // the DB level — the same path "Smazat šablonu" takes.
            foreach ($document->pages() as $page) {
                $this->templateRepository->remove($page->template);
            }
        }

        // Without page deletion this only dissolves the binder: the page rows
        // go (orphan removal + FK CASCADE) and the templates reappear.
        $this->documentRepository->remove($document);
    }
}
