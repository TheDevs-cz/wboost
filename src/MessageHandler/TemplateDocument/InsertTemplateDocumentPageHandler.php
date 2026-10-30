<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\TemplateDocument\InsertTemplateDocumentPage;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentPageList;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;

#[AsMessageHandler]
readonly final class InsertTemplateDocumentPageHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateVariantRepository $variantRepository,
        private TemplateDocumentRules $rules,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     * @throws TemplateVariantNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(InsertTemplateDocumentPage $message): void
    {
        $document = $this->documentRepository->get($message->documentId);
        $variant = $this->variantRepository->get($message->variantId);
        $pages = $document->pages();

        if (count($pages) >= TemplateDocument::MAX_PAGES) {
            throw InvalidTemplateDocumentChange::tooManyPages(TemplateDocument::MAX_PAGES);
        }

        if ($document->pageOfTemplate($variant->template->id) !== null) {
            throw InvalidTemplateDocumentChange::duplicatePage($variant->template->id->toString());
        }

        $this->rules->assertAttachableVariant($document, $variant);

        $position = $message->position === null ? count($pages) : max(0, min($message->position, count($pages)));

        foreach ($pages as $index => $page) {
            $page->moveTo($index >= $position ? $index + 1 : $index);
        }

        TemplateDocumentPageList::applyPrintSetup($document, $variant);
        $document->addPage(new TemplateDocumentPage($message->pageId, $document, $variant->template, $variant, $position));
    }
}
