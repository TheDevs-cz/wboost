<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateDocument;

use Ramsey\Uuid\UuidInterface;

/**
 * Crop marks for the document AND every page variant — one PDF never
 * mixes print setups.
 */
readonly final class EditTemplateDocumentPrintSettings
{
    public function __construct(
        public UuidInterface $documentId,
        public bool $cropMarks,
    ) {
    }
}
