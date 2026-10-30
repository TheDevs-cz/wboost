<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\AddTemplateDocumentPage;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class AddTemplateDocumentPageHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateRepository $templateRepository,
        private TemplateVariantRepository $variantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(AddTemplateDocumentPage $message): void
    {
        $document = $this->documentRepository->get($message->documentId);
        $pages = $document->pages();

        if (count($pages) >= TemplateDocument::MAX_PAGES) {
            throw InvalidTemplateDocumentChange::tooManyPages(TemplateDocument::MAX_PAGES);
        }

        $now = $this->clock->now();

        // The page template sits in the document's category, so detaching it
        // later drops it next to the document on the listing.
        $template = new Template(
            $message->templateId,
            $document->project,
            $document->category,
            $now,
            $message->name,
            null,
            $this->templateRepository->nextPosition($document->project->id),
        );
        $this->templateRepository->add($template);

        // Blank, layer-mode (no background) — the add-variant default — in the
        // document's dimension, print setup included.
        $variant = new TemplateVariant($message->variantId, $template, $document->dimension, null, $now, BackgroundMode::Layer);
        $this->variantRepository->add($variant);

        $position = $message->position === null ? count($pages) : max(0, min($message->position, count($pages)));

        foreach ($pages as $index => $page) {
            $page->moveTo($index >= $position ? $index + 1 : $index);
        }

        $document->addPage(new TemplateDocumentPage($message->pageId, $document, $template, $variant, $position));
    }
}
