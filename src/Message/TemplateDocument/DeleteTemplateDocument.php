<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

readonly final class DeleteTemplateDocument
{
    /**
     * `deletePages = false` only dissolves the document: its pages return to
     * the listing as ordinary templates. `true` deletes the page templates too.
     */
    public function __construct(
        public UuidInterface $documentId,
        public bool $deletePages,
    ) {
    }
}
