<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Template;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Message\Template\DeleteTemplateVariant;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateVariantRepository;

#[AsMessageHandler]
readonly final class DeleteTemplateVariantHandler
{
    public function __construct(
        private TemplateVariantRepository $variantRepository,
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    /**
     * @throws TemplateVariantNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(DeleteTemplateVariant $message): void
    {
        $variant = $this->variantRepository->get($message->variantId);

        // The page row would CASCADE away and the page silently vanish from
        // its document — refuse; detaching is an explicit document action.
        $page = $this->documentRepository->pageOfVariant($variant->id);

        if ($page !== null) {
            throw InvalidTemplateDocumentChange::variantIsPage($page->document->name);
        }

        $this->variantRepository->remove($variant);
    }
}
