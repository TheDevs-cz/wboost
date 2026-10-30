<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DocumentFieldDefinition;

/**
 * The document's whole set of shared fields, in order (full replace; ids kept
 * for existing fields so filled values stay attached).
 */
readonly final class SetTemplateDocumentFields
{
    /**
     * @param list<DocumentFieldDefinition> $fields
     */
    public function __construct(
        public UuidInterface $documentId,
        public array $fields,
    ) {
    }
}
