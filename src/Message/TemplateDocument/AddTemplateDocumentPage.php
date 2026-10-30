<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * "Nová stránka": a brand-new template with ONE blank variant of the
 * document's size (and print setup), attached at `position` (null = last).
 */
readonly final class AddTemplateDocumentPage
{
    public function __construct(
        public UuidInterface $documentId,
        public UuidInterface $pageId,
        public UuidInterface $templateId,
        public UuidInterface $variantId,
        public string $name,
        public null|int $position = null,
    ) {
    }
}
