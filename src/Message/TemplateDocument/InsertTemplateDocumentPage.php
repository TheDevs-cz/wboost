<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * Attaches ONE existing variant (of an attachable template) as a page at
 * `position` (null = last) — used by "Duplikovat stránku", which knows the
 * exact variant of the fresh copy.
 */
readonly final class InsertTemplateDocumentPage
{
    public function __construct(
        public UuidInterface $documentId,
        public UuidInterface $pageId,
        public UuidInterface $variantId,
        public null|int $position = null,
    ) {
    }
}
