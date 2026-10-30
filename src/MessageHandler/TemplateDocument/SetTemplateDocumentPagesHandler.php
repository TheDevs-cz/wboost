<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentPages;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentPageList;

#[AsMessageHandler]
readonly final class SetTemplateDocumentPagesHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateDocumentPageList $pageList,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(SetTemplateDocumentPages $message): void
    {
        $this->pageList->set($this->documentRepository->get($message->documentId), $message->templateIds);
    }
}
